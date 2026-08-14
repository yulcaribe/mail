<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$secureCookie = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

require_once __DIR__ . '/lib/EasClient.php';
$config = require __DIR__ . '/config.php';

/** @param array<string, mixed> $data */
function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** @return array<string, mixed> */
function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        respond(['ok' => false, 'message' => 'Geçersiz istek gövdesi.'], 400);
    }
    return $data;
}

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        respond(['ok' => false, 'message' => 'Bu işlem için geçersiz istek yöntemi.'], 405);
    }
}

function requireCsrf(): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        respond(['ok' => false, 'message' => 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.'], 419);
    }
}

/** @param array<string, mixed> $body
 *  @return list<string>
 */
function operationIds(array $body): array
{
    $ids = $body['ids'] ?? null;
    if (!is_array($ids) || $ids === [] || count($ids) > 200) {
        respond(['ok' => false, 'message' => 'Bir işlemde 1 ile 200 arasında mail seçebilirsiniz.'], 400);
    }

    $clean = [];
    foreach ($ids as $id) {
        if (!is_string($id) || trim($id) === '' || strlen($id) > 512) {
            respond(['ok' => false, 'message' => 'Geçersiz mail seçimi.'], 400);
        }
        $clean[trim($id)] = trim($id);
    }
    return array_values($clean);
}

/** @return array{username:string,password:string} */
function authSession(): array
{
    $auth = $_SESSION['mail_auth'] ?? null;
    if (!is_array($auth) || !isset($auth['username'], $auth['password'])) {
        respond(['ok' => false, 'message' => 'Oturum açmanız gerekiyor.'], 401);
    }

    return [
        'username' => (string) $auth['username'],
        'password' => (string) $auth['password'],
    ];
}

function clearMailSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function attachmentContentType(string $name): string
{
    return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        default => 'application/octet-stream',
    };
}

$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));

try {
    if ($action === 'status') {
        respond([
            'ok' => true,
            'authenticated' => isset($_SESSION['mail_auth']['username']),
            'username' => (string) ($_SESSION['mail_auth']['username'] ?? ''),
            'csrf' => isset($_SESSION['mail_auth']['username']) ? (string) $_SESSION['csrf_token'] : '',
        ]);
    }

    if ($action === 'login') {
        requireMethod('POST');
        $body = jsonBody();
        $username = EasClient::normaliseUsername((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($password === '') {
            respond(['ok' => false, 'message' => 'Parolanızı yazın.'], 400);
        }

        $client = new EasClient($config, $username, $password);
        $folders = $client->listFolders();

        session_regenerate_id(true);
        $_SESSION['mail_auth'] = [
            'username' => $username,
            'password' => $password,
        ];
        $_SESSION['sync_keys'] = [];

        respond([
            'ok' => true,
            'username' => $username,
            'folders' => $folders,
            'csrf' => (string) $_SESSION['csrf_token'],
        ]);
    }

    if ($action === 'logout') {
        requireMethod('POST');
        requireCsrf();
        clearMailSession();
        respond(['ok' => true]);
    }

    $auth = authSession();
    $client = new EasClient($config, $auth['username'], $auth['password']);

    if ($action === 'folders') {
        requireMethod('GET');
        respond([
            'ok' => true,
            'username' => $auth['username'],
            'folders' => $client->listFolders(),
            'csrf' => (string) $_SESSION['csrf_token'],
        ]);
    }

    if ($action === 'messages') {
        requireMethod('GET');
        $folderId = trim((string) ($_GET['folderId'] ?? ''));
        $result = $client->listMessages($folderId);
        $_SESSION['sync_keys'][$folderId] = $result['syncKey'];
        unset($result['syncKey']);
        respond(['ok' => true] + $result);
    }

    if ($action === 'attachment') {
        requireMethod('GET');
        $attachmentId = trim((string) ($_GET['id'] ?? ''));
        $requestedName = trim((string) ($_GET['name'] ?? 'ek'));
        $safeName = preg_replace('/[\x00-\x1F\x7F\\\/]+/u', '_', $requestedName) ?: 'ek';
        $safeName = mb_substr($safeName, 0, 180, 'UTF-8');
        $bytes = $client->fetchAttachment($attachmentId);

        header('Content-Type: ' . attachmentContentType($safeName));
        header('Content-Length: ' . strlen($bytes));
        header("Content-Disposition: attachment; filename=\"attachment\"; filename*=UTF-8''" . rawurlencode($safeName));
        echo $bytes;
        exit;
    }

    if (in_array($action, ['delete', 'mark', 'move'], true)) {
        requireMethod('POST');
        requireCsrf();
        $body = jsonBody();
        $folderId = trim((string) ($body['folderId'] ?? ''));
        $ids = operationIds($body);

        if ($action === 'move') {
            $destinationFolderId = trim((string) ($body['destinationFolderId'] ?? ''));
            $count = $client->moveMessages($folderId, $destinationFolderId, $ids);
            respond(['ok' => true, 'count' => $count]);
        }

        $syncKey = (string) ($_SESSION['sync_keys'][$folderId] ?? '');
        if ($syncKey === '') {
            respond(['ok' => false, 'message' => 'Klasör işlem için hazır değil. Yenileyip tekrar deneyin.'], 409);
        }

        if ($action === 'delete') {
            $permanent = (bool) ($body['permanent'] ?? false);
            $nextSyncKey = $client->deleteMessages($folderId, $syncKey, $ids, !$permanent);
            $_SESSION['sync_keys'][$folderId] = $nextSyncKey;
            respond(['ok' => true, 'count' => count($ids)]);
        }

        $read = (bool) ($body['read'] ?? false);
        $nextSyncKey = $client->setReadState($folderId, $syncKey, $ids, $read);
        $_SESSION['sync_keys'][$folderId] = $nextSyncKey;
        respond(['ok' => true, 'count' => count($ids), 'read' => $read]);
    }

    respond(['ok' => false, 'message' => 'İşlem bulunamadı.'], 404);
} catch (InvalidArgumentException $exception) {
    respond(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
    $status = str_contains($message, 'Kullanıcı adı veya parola') ? 401 : 502;
    if ($status === 401) {
        unset($_SESSION['mail_auth']);
    }
    respond(['ok' => false, 'message' => $message], $status);
} catch (Throwable $exception) {
    error_log('Beyan Mail: ' . $exception->getMessage());
    respond(['ok' => false, 'message' => 'Beklenmeyen bir sunucu hatası oluştu.'], 500);
}
