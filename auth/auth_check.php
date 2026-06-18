<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = app_is_https();

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function app_is_logged_in(): bool
{
    app_start_session();
    return !empty($_SESSION['admin_id']);
}

function app_current_admin(): ?array
{
    app_start_session();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['admin_id'],
        'name' => (string) ($_SESSION['admin_name'] ?? ''),
        'email' => (string) ($_SESSION['admin_email'] ?? ''),
        'role' => (string) ($_SESSION['admin_role'] ?? 'owner'),
    ];
}

function app_login_admin(array $admin): void
{
    app_start_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = (string) $admin['name'];
    $_SESSION['admin_email'] = (string) $admin['email'];
    $_SESSION['admin_role'] = (string) ($admin['role'] ?? 'owner');
}

function app_admin_role(): string
{
    $admin = app_current_admin();
    $role = (string) ($admin['role'] ?? 'owner');
    if ($role !== 'owner' && $role !== 'staff') {
        return 'owner';
    }
    return $role;
}

function app_is_owner(): bool
{
    return app_admin_role() === 'owner';
}

function app_can_edit_delete_records(): bool
{
    return app_is_owner();
}

function app_can_view_profit(): bool
{
    return app_is_owner();
}

function app_can_manage_stock(): bool
{
    return app_is_owner();
}

function app_require_owner_access(): void
{
    if (app_is_owner()) {
        return;
    }

    if (function_exists('flash_set') && function_exists('app_redirect')) {
        flash_set('error', 'Access denied.');
        app_redirect('dashboard/index.php');
    }

    http_response_code(403);
    exit;
}

function app_require_edit_delete_access(): void
{
    if (app_can_edit_delete_records()) {
        return;
    }

    if (function_exists('flash_set') && function_exists('app_redirect')) {
        flash_set('error', 'Access denied.');
        app_redirect('dashboard/index.php');
    }

    http_response_code(403);
    exit;
}

function app_require_stock_access(): void
{
    if (app_can_manage_stock()) {
        return;
    }

    if (function_exists('flash_set') && function_exists('app_redirect')) {
        flash_set('error', 'Access denied.');
        app_redirect('dashboard/index.php');
    }

    http_response_code(403);
    exit;
}

function app_ensure_schema(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'");
        $hasRole = (bool) $stmt->fetchColumn();
        if (!$hasRole) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN role ENUM('owner','staff') NOT NULL DEFAULT 'owner'");
        }
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bank_deposits (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                bank_account_id BIGINT UNSIGNED NULL,
                bank_name VARCHAR(120) NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                deposit_date DATE NOT NULL,
                note VARCHAR(255) NULL,
                bank_wallet_transaction_id BIGINT UNSIGNED NULL,
                cash_wallet_transaction_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bank_deposits_date (deposit_date),
                KEY idx_bank_deposits_bank_account (bank_account_id),
                KEY idx_bank_deposits_bank_txn (bank_wallet_transaction_id),
                KEY idx_bank_deposits_cash_txn (cash_wallet_transaction_id),
                CONSTRAINT fk_bank_deposits_bank_account FOREIGN KEY (bank_account_id) REFERENCES accounts(id) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_bank_deposits_bank_txn FOREIGN KEY (bank_wallet_transaction_id) REFERENCES wallet_transactions(id) ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_bank_deposits_cash_txn FOREIGN KEY (cash_wallet_transaction_id) REFERENCES wallet_transactions(id) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_returns (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sale_id BIGINT UNSIGNED NOT NULL,
                quantity INT NOT NULL,
                return_date DATE NOT NULL,
                reason ENUM('return','exchange') NOT NULL DEFAULT 'return',
                notes VARCHAR(255) NULL,
                profit_adjustment DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sales_returns_sale_id (sale_id),
                KEY idx_sales_returns_date (return_date),
                CONSTRAINT fk_sales_returns_sale_id FOREIGN KEY (sale_id) REFERENCES sales(id) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sales_exchanges (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                return_id BIGINT UNSIGNED NOT NULL,
                new_sale_id BIGINT UNSIGNED NOT NULL,
                exchange_date DATE NOT NULL,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sales_exchanges_return_id (return_id),
                KEY idx_sales_exchanges_new_sale_id (new_sale_id),
                KEY idx_sales_exchanges_date (exchange_date),
                CONSTRAINT fk_sales_exchanges_return_id FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_sales_exchanges_new_sale_id FOREIGN KEY (new_sale_id) REFERENCES sales(id) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }
}

function app_forget_remember_cookie(): void
{
    $secure = app_is_https();
    setcookie('shop_remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function app_issue_remember_token(int $adminId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);

    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $userAgentHash = hash('sha256', $userAgent);
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $expiresAt = (new DateTimeImmutable('now'))->modify('+30 days');

    $pdo = db();
    $stmt = $pdo->prepare('
        INSERT INTO admin_remember_tokens (admin_id, selector, token_hash, user_agent_hash, ip_address, expires_at)
        VALUES (:admin_id, :selector, :token_hash, :user_agent_hash, :ip_address, :expires_at)
    ');
    $stmt->execute([
        ':admin_id' => $adminId,
        ':selector' => $selector,
        ':token_hash' => $tokenHash,
        ':user_agent_hash' => $userAgentHash,
        ':ip_address' => $ipAddress !== '' ? $ipAddress : null,
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    $secure = app_is_https();
    setcookie('shop_remember', $selector . ':' . $validator, [
        'expires' => $expiresAt->getTimestamp(),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function app_attempt_remember_login(): bool
{
    if (app_is_logged_in()) {
        return true;
    }

    $cookie = (string) ($_COOKIE['shop_remember'] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') {
        return false;
    }

    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT id, admin_id, token_hash, user_agent_hash, expires_at
        FROM admin_remember_tokens
        WHERE selector = :selector
        LIMIT 1
    ');
    $stmt->execute([':selector' => $selector]);
    $row = $stmt->fetch();
    if (!$row) {
        app_forget_remember_cookie();
        return false;
    }

    $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['expires_at']) ?: null;
    if (!$expiresAt || $expiresAt->getTimestamp() < time()) {
        $del = $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id = :id');
        $del->execute([':id' => (int) $row['id']]);
        app_forget_remember_cookie();
        return false;
    }

    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $userAgentHash = hash('sha256', $userAgent);
    if (!hash_equals((string) $row['user_agent_hash'], $userAgentHash)) {
        $del = $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id = :id');
        $del->execute([':id' => (int) $row['id']]);
        app_forget_remember_cookie();
        return false;
    }

    $validatorHash = hash('sha256', $validator);
    if (!hash_equals((string) $row['token_hash'], $validatorHash)) {
        $del = $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id = :id');
        $del->execute([':id' => (int) $row['id']]);
        app_forget_remember_cookie();
        return false;
    }

    $admin = null;
    try {
        $stmt = $pdo->prepare('SELECT id, name, email, role FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $row['admin_id']]);
        $admin = $stmt->fetch();
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('SELECT id, name, email FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $row['admin_id']]);
        $admin = $stmt->fetch();
    }
    if (!$admin) {
        $del = $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id = :id');
        $del->execute([':id' => (int) $row['id']]);
        app_forget_remember_cookie();
        return false;
    }

    app_login_admin($admin);

    $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id = :id')->execute([':id' => (int) $row['id']]);
    app_issue_remember_token((int) $admin['id']);
    return true;
}

function app_require_auth(): void
{
    app_start_session();
    app_attempt_remember_login();

    if (!app_is_logged_in()) {
        $currentDir = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $loginUrl = rtrim(dirname($currentDir), '/\\') . '/../auth/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
}
