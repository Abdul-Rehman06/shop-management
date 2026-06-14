<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

app_start_session();

$cookie = (string) ($_COOKIE['shop_remember'] ?? '');
if ($cookie !== '' && strpos($cookie, ':') !== false) {
    [$selector] = explode(':', $cookie, 2);
    if ($selector !== '') {
        $pdo = db();
        $stmt = $pdo->prepare('DELETE FROM admin_remember_tokens WHERE selector = :selector');
        $stmt->execute([':selector' => $selector]);
    }
}

app_forget_remember_cookie();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => (bool) ($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

header('Location: ' . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/login.php');
exit;

