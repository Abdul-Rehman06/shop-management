<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth/auth_check.php';

app_require_auth();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_base_url(): string
{
    $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $docRootReal = $docRoot !== '' ? realpath($docRoot) : false;
    $appRootReal = realpath(__DIR__ . '/..');

    if ($docRootReal !== false && $appRootReal !== false) {
        $doc = rtrim(str_replace('\\', '/', $docRootReal), '/');
        $app = rtrim(str_replace('\\', '/', $appRootReal), '/');
        $docLower = strtolower($doc);
        $appLower = strtolower($app);

        if ($docLower !== '' && $appLower !== '' && strpos($appLower, $docLower) === 0) {
            $rel = substr($app, strlen($doc));
            $rel = trim($rel, '/');
            return $rel === '' ? '' : '/' . $rel;
        }
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $parts = explode('/', trim($scriptName, '/'));
    $first = $parts[0] ?? '';
    if ($first === '') {
        return '';
    }
    return '/' . $first;
}

function app_url(string $path): string
{
    $path = ltrim($path, '/');
    $base = app_base_url();
    if ($base === '') {
        return '/' . $path;
    }
    return $base . '/' . $path;
}

function app_redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function flash_set(string $key, string $message): void
{
    app_start_session();
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): string
{
    app_start_session();
    $message = (string) ($_SESSION['flash'][$key] ?? '');
    unset($_SESSION['flash'][$key]);
    return $message;
}
