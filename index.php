<?php

declare(strict_types=1);

require_once __DIR__ . '/auth/auth_check.php';

app_start_session();
app_attempt_remember_login();

$baseDir = str_replace('\\', '/', (string) dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$baseDir = rtrim($baseDir, '/');
if ($baseDir === '') {
    $baseDir = '';
}

$target = app_is_logged_in() ? 'dashboard/index.php' : 'auth/login.php';
header('Location: ' . $baseDir . '/' . $target);
exit;

