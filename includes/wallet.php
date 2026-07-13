<?php

declare(strict_types=1);

function wallet_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_name VARCHAR(120) NOT NULL,
            account_type ENUM('easypaisa','jazzcash','bank','cash') NOT NULL,
            account_number VARCHAR(80) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_accounts_name (account_name),
            KEY idx_accounts_type (account_type),
            KEY idx_accounts_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wallet_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            customer_name VARCHAR(120) NULL,
            number VARCHAR(50) NULL,
            transaction_id VARCHAR(120) NULL,
            type ENUM('opening','receiving','sending') NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            charges DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            commission_method VARCHAR(30) NOT NULL DEFAULT 'separate_cash',
            account_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_status VARCHAR(20) NOT NULL DEFAULT 'completed',
            completed_at DATETIME NULL,
            remarks VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wallet_date (date),
            KEY idx_wallet_account (account_id),
            KEY idx_wallet_type (type),
            KEY idx_wallet_status (payment_status),
            KEY idx_wallet_number (number),
            KEY idx_wallet_transaction_id (transaction_id),
            CONSTRAINT fk_wallet_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM wallet_transactions LIKE 'commission_method'");
        if (!(bool) $stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN commission_method VARCHAR(30) NOT NULL DEFAULT 'separate_cash' AFTER charges");
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM wallet_transactions LIKE 'account_amount'");
        if (!(bool) $stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN account_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER commission_method");
            $pdo->exec("UPDATE wallet_transactions SET account_amount = amount WHERE account_amount = 0");
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM wallet_transactions LIKE 'payment_status'");
        if (!(bool) $stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER account_amount");
            $pdo->exec("ALTER TABLE wallet_transactions ADD KEY idx_wallet_status (payment_status)");
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM wallet_transactions LIKE 'completed_at'");
        if (!(bool) $stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN completed_at DATETIME NULL AFTER payment_status");
            $pdo->exec("UPDATE wallet_transactions SET completed_at = created_at WHERE completed_at IS NULL AND payment_status = 'completed' AND type IN ('receiving','sending')");
        }
    } catch (Throwable $e) {
    }

    $count = (int) $pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO accounts (account_name, account_type, account_number, status)
            VALUES (:name, :type, :number, 'active')
        ");
        $defaults = [
            ['EasyPaisa Account 1', 'easypaisa', null],
            ['EasyPaisa Account 2', 'easypaisa', null],
            ['JazzCash Account 1', 'jazzcash', null],
            ['JazzCash Account 2', 'jazzcash', null],
            ['UBL Account', 'bank', null],
            ['Meezan Account', 'bank', null],
            ['Bank Alfalah Account', 'bank', null],
            ['Cash', 'cash', null],
        ];
        foreach ($defaults as [$name, $type, $number]) {
            $stmt->execute([
                ':name' => $name,
                ':type' => $type,
                ':number' => $number,
            ]);
        }
    }

    $walletCount = (int) $pdo->query("SELECT COUNT(*) FROM wallet_transactions")->fetchColumn();
    if ($walletCount !== 0) {
        return;
    }

    $hasEasy = (bool) $pdo->query("SHOW TABLES LIKE 'easypaisa_transactions'")->fetchColumn();
    $hasJazz = (bool) $pdo->query("SHOW TABLES LIKE 'jazzcash_transactions'")->fetchColumn();
    $hasBank = (bool) $pdo->query("SHOW TABLES LIKE 'bank_transactions'")->fetchColumn();

    if (!$hasEasy && !$hasJazz && !$hasBank) {
        return;
    }

    $pdo->beginTransaction();
    try {
        if ($hasEasy) {
            $accountId = wallet_find_or_create_account_id($pdo, 'EasyPaisa Account 1', 'easypaisa', null);
            $rows = $pdo->query("SELECT date, customer_name, number, transaction_id, type, amount, charges, remarks, created_at FROM easypaisa_transactions ORDER BY id ASC")->fetchAll();
            wallet_bulk_insert($pdo, $accountId, $rows);
        }

        if ($hasJazz) {
            $accountId = wallet_find_or_create_account_id($pdo, 'JazzCash Account 1', 'jazzcash', null);
            $rows = $pdo->query("SELECT date, customer_name, number, transaction_id, type, amount, charges, remarks, created_at FROM jazzcash_transactions ORDER BY id ASC")->fetchAll();
            wallet_bulk_insert($pdo, $accountId, $rows);
        }

        if ($hasBank) {
            $rows = $pdo->query("SELECT date, bank_name, account_number, transaction_id, type, amount, charges, remarks, created_at FROM bank_transactions ORDER BY id ASC")->fetchAll();
            foreach ($rows as $r) {
                $bankName = trim((string) ($r['bank_name'] ?? ''));
                $accNo = trim((string) ($r['account_number'] ?? ''));
                $name = $bankName !== '' ? ($bankName . ' Account') : 'Bank Account';
                $accountId = wallet_find_or_create_account_id($pdo, $name, 'bank', $accNo !== '' ? $accNo : null);
                wallet_bulk_insert($pdo, $accountId, [[
                    'date' => $r['date'],
                    'customer_name' => null,
                    'number' => null,
                    'transaction_id' => $r['transaction_id'],
                    'type' => $r['type'],
                    'amount' => $r['amount'],
                    'charges' => $r['charges'],
                    'remarks' => $r['remarks'],
                    'created_at' => $r['created_at'],
                ]]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

function wallet_find_or_create_account_id(PDO $pdo, string $name, string $type, ?string $number): int
{
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE account_name = :name LIMIT 1");
    $stmt->execute([':name' => $name]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $ins = $pdo->prepare("
        INSERT INTO accounts (account_name, account_type, account_number, status)
        VALUES (:name, :type, :number, 'active')
    ");
    $ins->execute([
        ':name' => $name,
        ':type' => $type,
        ':number' => $number,
    ]);
    return (int) $pdo->lastInsertId();
}

function wallet_bulk_insert(PDO $pdo, int $accountId, array $rows): void
{
    if (!$rows) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions
            (account_id, date, customer_name, number, transaction_id, type, amount, charges, commission_method, account_amount, payment_status, completed_at, remarks, created_at)
        VALUES
            (:account_id, :date, :customer_name, :number, :transaction_id, :type, :amount, :charges, :commission_method, :account_amount, :payment_status, :completed_at, :remarks, :created_at)
    ");

    foreach ($rows as $r) {
        $stmt->execute([
            ':account_id' => $accountId,
            ':date' => (string) $r['date'],
            ':customer_name' => ($r['customer_name'] ?? null) !== '' ? ($r['customer_name'] ?? null) : null,
            ':number' => ($r['number'] ?? null) !== '' ? ($r['number'] ?? null) : null,
            ':transaction_id' => ($r['transaction_id'] ?? null) !== '' ? ($r['transaction_id'] ?? null) : null,
            ':type' => (string) $r['type'],
            ':amount' => (float) ($r['amount'] ?? 0),
            ':charges' => (float) ($r['charges'] ?? 0),
            ':commission_method' => (string) ($r['commission_method'] ?? 'separate_cash'),
            ':account_amount' => (float) ($r['account_amount'] ?? $r['amount'] ?? 0),
            ':payment_status' => (string) ($r['payment_status'] ?? 'completed'),
            ':completed_at' => (string) ($r['completed_at'] ?? $r['created_at'] ?? date('Y-m-d H:i:s')),
            ':remarks' => ($r['remarks'] ?? null) !== '' ? ($r['remarks'] ?? null) : null,
            ':created_at' => (string) ($r['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }
}

function wallet_accounts(PDO $pdo, string $type, bool $onlyActive = true): array
{
    $sql = "SELECT id, account_name, account_type, account_number, status FROM accounts WHERE account_type = :type";
    $params = [':type' => $type];
    if ($onlyActive) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY account_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function wallet_account(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT id, account_name, account_type, account_number, status FROM accounts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function wallet_transaction(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT wt.*, a.account_name, a.account_type, a.account_number
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE wt.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function wallet_balance_summary(PDO $pdo, int $accountId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='opening' THEN amount ELSE 0 END), 0) AS opening_total,
            COALESCE(SUM(CASE WHEN type='receiving' AND payment_status <> 'cancelled' THEN amount ELSE 0 END), 0) AS receiving_total,
            COALESCE(SUM(CASE WHEN type='sending' AND payment_status = 'completed' THEN amount ELSE 0 END), 0) AS sending_total,
            COALESCE(SUM(CASE WHEN type='sending' AND payment_status = 'completed' THEN account_amount ELSE 0 END), 0) AS account_deduction_total,
            COALESCE(SUM(CASE WHEN type <> 'opening' AND payment_status <> 'cancelled' AND (type <> 'sending' OR payment_status = 'completed') THEN charges ELSE 0 END), 0) AS charges_total
        FROM wallet_transactions
        WHERE account_id = :account_id
    ");
    $stmt->execute([':account_id' => $accountId]);
    $row = $stmt->fetch() ?: [];

    $opening = (float) ($row['opening_total'] ?? 0);
    $receiving = (float) ($row['receiving_total'] ?? 0);
    $sending = (float) ($row['sending_total'] ?? 0);
    $accountDeduction = (float) ($row['account_deduction_total'] ?? 0);
    $charges = (float) ($row['charges_total'] ?? 0);
    $closing = $opening + $receiving - $accountDeduction;

    return [
        'opening' => $opening,
        'receiving' => $receiving,
        'sending' => $sending,
        'account_deduction' => $accountDeduction,
        'closing' => $closing,
        'commission' => $charges,
    ];
}

function wallet_recent_transactions(PDO $pdo, int $accountId, int $limit = 50): array
{
    $stmt = $pdo->prepare("
        SELECT id, date, customer_name, number, transaction_id, type, amount, charges, remarks
             , commission_method, account_amount, payment_status, completed_at
        FROM wallet_transactions
        WHERE account_id = :account_id
        ORDER BY date DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':account_id' => $accountId]);
    return $stmt->fetchAll();
}

function wallet_combined_summary(PDO $pdo, ?string $accountType = null, bool $onlyActive = true): array
{
    $params = [];
    $where = "WHERE 1=1";
    if ($accountType !== null) {
        $where .= " AND a.account_type = :account_type";
        $params[':account_type'] = $accountType;
    }
    if ($onlyActive) {
        $where .= " AND a.status = 'active'";
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN wt.type='opening' THEN wt.amount ELSE 0 END), 0) AS opening_total,
            COALESCE(SUM(CASE WHEN wt.type='receiving' AND wt.payment_status <> 'cancelled' THEN wt.amount ELSE 0 END), 0) AS receiving_total,
            COALESCE(SUM(CASE WHEN wt.type='sending' AND wt.payment_status = 'completed' THEN wt.amount ELSE 0 END), 0) AS sending_total,
            COALESCE(SUM(CASE WHEN wt.type='sending' AND wt.payment_status = 'completed' THEN wt.account_amount ELSE 0 END), 0) AS account_deduction_total,
            COALESCE(SUM(CASE WHEN wt.type <> 'opening' AND wt.payment_status <> 'cancelled' AND (wt.type <> 'sending' OR wt.payment_status = 'completed') THEN wt.charges ELSE 0 END), 0) AS charges_total
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        {$where}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    $opening = (float) ($row['opening_total'] ?? 0);
    $receiving = (float) ($row['receiving_total'] ?? 0);
    $sending = (float) ($row['sending_total'] ?? 0);
    $accountDeduction = (float) ($row['account_deduction_total'] ?? 0);
    $charges = (float) ($row['charges_total'] ?? 0);

    return [
        'opening' => $opening,
        'receiving' => $receiving,
        'sending' => $sending,
        'account_deduction' => $accountDeduction,
        'commission' => $charges,
        'closing' => $opening + $receiving - $accountDeduction,
    ];
}

function wallet_search_transactions(PDO $pdo, int $accountId, string $query, int $limit = 200): array
{
    $q = trim($query);
    if ($q === '') {
        return wallet_recent_transactions($pdo, $accountId, $limit);
    }

    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $stmt = $pdo->prepare("
        SELECT id, date, customer_name, number, transaction_id, type, amount, charges, remarks,
               commission_method, account_amount, payment_status, completed_at
        FROM wallet_transactions
        WHERE account_id = :account_id
          AND (
                customer_name LIKE :q_name ESCAPE '\\\\'
             OR number LIKE :q_number ESCAPE '\\\\'
             OR transaction_id LIKE :q_tx ESCAPE '\\\\'
          )
        ORDER BY date DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([
        ':account_id' => $accountId,
        ':q_name' => $like,
        ':q_number' => $like,
        ':q_tx' => $like,
    ]);
    return $stmt->fetchAll();
}

function wallet_search_totals(PDO $pdo, int $accountId, string $query): array
{
    $q = trim($query);
    if ($q === '') {
        return [
            'receiving' => 0.0,
            'sending' => 0.0,
            'account_deduction' => 0.0,
            'commission' => 0.0,
            'pending_count' => 0,
            'pending_amount' => 0.0,
            'count' => 0,
        ];
    }

    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(CASE WHEN type='receiving' AND payment_status <> 'cancelled' THEN amount ELSE 0 END), 0) AS receiving_total,
            COALESCE(SUM(CASE WHEN type='sending' AND payment_status = 'completed' THEN amount ELSE 0 END), 0) AS sending_total,
            COALESCE(SUM(CASE WHEN type='sending' AND payment_status = 'completed' THEN account_amount ELSE 0 END), 0) AS account_deduction_total,
            COALESCE(SUM(CASE WHEN type <> 'opening' AND payment_status <> 'cancelled' AND (type <> 'sending' OR payment_status = 'completed') THEN charges ELSE 0 END), 0) AS commission_total,
            COALESCE(SUM(CASE WHEN type='receiving' AND payment_status='pending' THEN amount ELSE 0 END), 0) AS pending_amount_total,
            COALESCE(SUM(CASE WHEN type='receiving' AND payment_status='pending' THEN 1 ELSE 0 END), 0) AS pending_rows
        FROM wallet_transactions
        WHERE account_id = :account_id
          AND (
                customer_name LIKE :q_name ESCAPE '\\\\'
             OR number LIKE :q_number ESCAPE '\\\\'
             OR transaction_id LIKE :q_tx ESCAPE '\\\\'
          )
    ");
    $stmt->execute([
        ':account_id' => $accountId,
        ':q_name' => $like,
        ':q_number' => $like,
        ':q_tx' => $like,
    ]);
    $row = $stmt->fetch() ?: [];

    return [
        'receiving' => (float) ($row['receiving_total'] ?? 0),
        'sending' => (float) ($row['sending_total'] ?? 0),
        'account_deduction' => (float) ($row['account_deduction_total'] ?? 0),
        'commission' => (float) ($row['commission_total'] ?? 0),
        'pending_count' => (int) ($row['pending_rows'] ?? 0),
        'pending_amount' => (float) ($row['pending_amount_total'] ?? 0),
        'count' => (int) ($row['total_rows'] ?? 0),
    ];
}
