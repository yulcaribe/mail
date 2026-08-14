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

$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));

try {
    if ($action === 'status') {
        respond([
            'ok' => true,
            'authenticated' => isset($_SESSION['mail_auth']['username']),
            'username' => (string) ($_SESSION['mail_auth']['username'] ?? ''),
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

        respond([
            'ok' => true,
            'username' => $username,
            'folders' => $folders,
        ]);
    }

    if ($action === 'logout') {
        requireMethod('POST');
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
        ]);
    }

    if ($action === 'messages') {
        requireMethod('GET');
        $folderId = trim((string) ($_GET['folderId'] ?? ''));
        $result = $client->listMessages($folderId);
        respond(['ok' => true] + $result);
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
