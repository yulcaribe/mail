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
require_once __DIR__ . '/lib/EwsClient.php';
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

function attachmentExtensionFromContentType(string $contentType): string
{
    $type = strtolower(trim(explode(';', $contentType, 2)[0] ?? ''));
    return match ($type) {
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        default => '',
    };
}

function normaliseAttachmentName(string $name, string $contentType = ''): string
{
    $safeName = preg_replace('/[\x00-\x1F\x7F\\\/]+/u', '_', trim($name)) ?: 'ek';
    $safeName = rtrim($safeName, ". \t\n\r\0\x0B");
    if ($safeName === '') {
        $safeName = 'ek';
    }

    if (pathinfo($safeName, PATHINFO_EXTENSION) === '') {
        $extension = attachmentExtensionFromContentType($contentType);
        if ($extension !== '') {
            $safeName .= '.' . $extension;
        }
    }

    return mb_substr($safeName, 0, 180, 'UTF-8');
}

function attachmentAsciiFallback(string $name): string
{
    $fallback = $name;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($converted) && $converted !== '') {
            $fallback = $converted;
        }
    }
    $fallback = preg_replace('/[^A-Za-z0-9._()\- ]+/', '_', $fallback) ?: 'attachment';
    $fallback = str_replace(['"', '\\'], '_', $fallback);
    return trim($fallback) !== '' ? trim($fallback) : 'attachment';
}


/** @return array{bytes:string,contentType:string} */
function fetchRemoteMailImage(string $url, bool $verifyTls): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Hostingde PHP cURL eklentisi etkin değil.');
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Geçersiz imza görseli adresi.');
    }

    if ($host === 'localhost') {
        throw new InvalidArgumentException('Geçersiz imza görseli adresi.');
    }

    $resolved = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if ($resolved === []) {
        throw new RuntimeException('İmza görselinin sunucusu çözümlenemedi.');
    }

    $publicIp = '';
    foreach ($resolved as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $publicIp = $ip;
            break;
        }
    }
    if ($publicIp === '') {
        throw new InvalidArgumentException('İmza görseli yalnızca herkese açık HTTPS adreslerinden alınabilir.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('İmza görseli bağlantısı başlatılamadı.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: image/*', 'User-Agent: BeyanPhpMail/2.0'],
        CURLOPT_SSL_VERIFYPEER => $verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_MAXFILESIZE => 4 * 1024 * 1024,
        CURLOPT_RESOLVE => [$host . ':443:' . $publicIp],
    ]);

    $bytes = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($bytes) || $status < 200 || $status >= 300) {
        throw new RuntimeException('İmza görseli indirilemedi' . ($error !== '' ? ': ' . $error : '.'));
    }
    if (strlen($bytes) > 4 * 1024 * 1024) {
        throw new RuntimeException('İmza görseli çok büyük.');
    }

    $baseType = strtolower(trim(explode(';', $contentType, 2)[0] ?? ''));
    if (!in_array($baseType, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
        throw new RuntimeException('İmza görselinin dosya türü desteklenmiyor.');
    }

    return ['bytes' => $bytes, 'contentType' => $baseType];
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
        $quotaExceeded = false;
        try {
            $folders = $client->listFolders();
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Exchange 113')) {
                throw $exception;
            }
            // ActiveSync FolderSync kota doluyken 113 döndürebiliyor.
            // Kullanıcının EWS tabanlı temizlik araçlarına yine de girebilmesi
            // için oturumu aç ve arayüzü kurtarma modunda başlat.
            $folders = [];
            $quotaExceeded = true;
        }

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
            'quotaExceeded' => $quotaExceeded,
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
        $quotaExceeded = false;
        try {
            $folders = $client->listFolders();
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Exchange 113')) {
                throw $exception;
            }
            $folders = [];
            $quotaExceeded = true;
        }

        respond([
            'ok' => true,
            'username' => $auth['username'],
            'folders' => $folders,
            'quotaExceeded' => $quotaExceeded,
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
        $requestedType = trim((string) ($_GET['type'] ?? ''));
        $inline = (string) ($_GET['inline'] ?? '') === '1';
        $safeName = normaliseAttachmentName($requestedName, $requestedType);
        $bytes = $client->fetchAttachment($attachmentId);
        $requestedBaseType = strtolower(trim(explode(';', $requestedType, 2)[0] ?? ''));
        $contentType = attachmentExtensionFromContentType($requestedBaseType) !== ''
            ? $requestedBaseType
            : attachmentContentType($safeName);
        $fallbackName = attachmentAsciiFallback($safeName);
        $disposition = $inline ? 'inline' : 'attachment';

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($bytes));
        header("Content-Disposition: " . $disposition . "; filename=\"" . $fallbackName . "\"; filename*=UTF-8''" . rawurlencode($safeName));
        echo $bytes;
        exit;
    }

    if ($action === 'remote-image') {
        requireMethod('GET');
        $url = trim((string) ($_GET['url'] ?? ''));
        $image = fetchRemoteMailImage($url, (bool) ($config['verify_tls'] ?? true));
        header('Content-Type: ' . $image['contentType']);
        header('Content-Length: ' . strlen($image['bytes']));
        header('Cache-Control: private, max-age=3600');
        echo $image['bytes'];
        exit;
    }

    if ($action === 'mailbox-cleanup') {
        requireMethod('POST');
        requireCsrf();
        $body = jsonBody();
        if (($body['confirmCleanup'] ?? false) !== true) {
            respond(['ok' => false, 'message' => 'Posta kutusu temizliği onaylanmadı.'], 400);
        }
        $hours = filter_var($body['hours'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 8760],
        ]);
        if ($hours === false) {
            respond(['ok' => false, 'message' => 'Temizlik süresi 1 ile 8760 saat arasında olmalıdır.'], 400);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . $hours . 'H'));
        $ews = new EwsClient($config, $auth['username'], $auth['password']);
        $result = $ews->moveReceivedMessagesBefore($cutoff, 500);
        respond([
            'ok' => true,
            'count' => $result['count'],
            'hasMore' => $result['more'],
            'folderCount' => $result['folderCount'],
            'cutoff' => $cutoff->format(DateTimeInterface::ATOM),
        ]);
    }

    if ($action === 'empty-trash') {
        requireMethod('POST');
        requireCsrf();
        $body = jsonBody();
        if (($body['confirmPermanent'] ?? false) !== true) {
            respond(['ok' => false, 'message' => 'Kalıcı silme işlemi onaylanmadı.'], 400);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $ews = new EwsClient($config, $auth['username'], $auth['password']);
        $ews->emptyDeletedItems();
        $_SESSION['sync_keys'] = [];
        respond(['ok' => true]);
    }

    if ($action === 'rules') {
        requireMethod('GET');
        $ews = new EwsClient($config, $auth['username'], $auth['password']);
        $ruleFolders = $ews->listMailFolders();
        $result = $ews->listInboxRules($ruleFolders);

        $rulesById = [];
        foreach ($result['rules'] as $rule) {
            $rulesById[(string) $rule['id']] = $rule;
        }
        $foldersById = [];
        $publicFolders = [];
        foreach ($ruleFolders as $folder) {
            $foldersById[$folder['id']] = $folder;
            $publicFolders[] = [
                'id' => $folder['id'],
                'name' => $folder['name'],
                'path' => $folder['path'],
            ];
        }

        $_SESSION['ews_rule_cache'] = [
            'rules' => $rulesById,
            'raw' => $result['raw'],
            'folders' => $foldersById,
            'outlookRuleBlobExists' => $result['outlookRuleBlobExists'],
        ];

        respond([
            'ok' => true,
            'rules' => $result['rules'],
            'folders' => $publicFolders,
            'outlookRuleBlobExists' => $result['outlookRuleBlobExists'],
        ]);
    }

    if (in_array($action, ['rule-create', 'rule-update', 'rule-toggle', 'rule-delete'], true)) {
        requireMethod('POST');
        requireCsrf();
        $body = jsonBody();
        $cache = $_SESSION['ews_rule_cache'] ?? null;
        if (!is_array($cache) || !isset($cache['rules'], $cache['raw'], $cache['folders'])) {
            respond(['ok' => false, 'message' => 'Kural listesi güncel değil. Ayarları yenileyip tekrar deneyin.'], 409);
        }

        $blobExists = (bool) ($cache['outlookRuleBlobExists'] ?? false);
        $confirmed = ($body['confirmOutlookRuleBlobRemoval'] ?? false) === true;
        if ($blobExists && !$confirmed) {
            respond([
                'ok' => false,
                'message' => 'Outlook kural verisi kaldırılmadan değişiklik yapılamaz. Uyarıyı onaylayın.',
                'requiresRuleBlobConfirmation' => true,
            ], 409);
        }

        $ews = new EwsClient($config, $auth['username'], $auth['password']);
        $ruleId = trim((string) ($body['ruleId'] ?? ''));
        $removeBlob = $blobExists && $confirmed;

        if ($action === 'rule-create') {
            $priorities = array_map(static fn(array $rule): int => (int) ($rule['priority'] ?? 0), array_values($cache['rules']));
            $priority = ($priorities !== [] ? max($priorities) : 0) + 1;
            $data = is_array($body['rule'] ?? null) ? $body['rule'] : [];
            $moveFolderId = trim((string) ($data['moveFolderId'] ?? ''));
            $moveFolder = $moveFolderId !== '' ? ($cache['folders'][$moveFolderId] ?? null) : null;
            $ews->createSimpleRule($data, $priority, is_array($moveFolder) ? $moveFolder : null, $removeBlob);
        } elseif ($action === 'rule-update') {
            $existing = $cache['rules'][$ruleId] ?? null;
            if (!is_array($existing) || !($existing['editable'] ?? false)) {
                respond(['ok' => false, 'message' => 'Bu karmaşık kural güvenli biçimde düzenlenemiyor.'], 400);
            }
            $data = is_array($body['rule'] ?? null) ? $body['rule'] : [];
            $moveFolderId = trim((string) ($data['moveFolderId'] ?? ''));
            $moveFolder = $moveFolderId !== '' ? ($cache['folders'][$moveFolderId] ?? null) : null;
            $ews->updateSimpleRule($ruleId, $data, (int) $existing['priority'], is_array($moveFolder) ? $moveFolder : null, $removeBlob);
        } elseif ($action === 'rule-toggle') {
            $existing = $cache['rules'][$ruleId] ?? null;
            $raw = (string) ($cache['raw'][$ruleId] ?? '');
            if (!is_array($existing) || ($existing['notSupported'] ?? false) || $raw === '') {
                respond(['ok' => false, 'message' => 'Bu kural Exchange tarafından değiştirilebilir olarak sunulmadı.'], 400);
            }
            $ews->toggleRule($raw, (bool) ($body['enabled'] ?? false), $removeBlob);
        } else {
            if (!isset($cache['rules'][$ruleId])) {
                respond(['ok' => false, 'message' => 'Kural bulunamadı.'], 404);
            }
            $ews->deleteRule($ruleId, $removeBlob);
        }

        unset($_SESSION['ews_rule_cache']);
        respond(['ok' => true]);
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
