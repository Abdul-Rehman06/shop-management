<?php

declare(strict_types=1);

function bill_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bill_companies (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                category_name VARCHAR(80) NOT NULL DEFAULT '',
                company_name VARCHAR(120) NOT NULL,
                short_code VARCHAR(30) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bill_companies_name (company_name),
                KEY idx_bill_companies_category (category_name),
                KEY idx_bill_companies_active (is_active),
                KEY idx_bill_companies_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bill_payments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                bill_id VARCHAR(80) NOT NULL,
                customer_name VARCHAR(120) NOT NULL,
                company_name VARCHAR(120) NOT NULL,
                bill_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                service_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_date DATE NOT NULL,
                due_date DATE NULL,
                received_in_type VARCHAR(30) NOT NULL DEFAULT 'cash',
                received_in_account_id BIGINT UNSIGNED NULL,
                status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
                paid_from_type VARCHAR(30) NULL,
                paid_from_account_id BIGINT UNSIGNED NULL,
                notes VARCHAR(255) NULL,
                collected_wallet_txn_id BIGINT UNSIGNED NULL,
                paid_wallet_txn_id BIGINT UNSIGNED NULL,
                paid_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bill_payments_bill_id (bill_id),
                KEY idx_bill_payments_payment_date (payment_date),
                KEY idx_bill_payments_due_date (due_date),
                KEY idx_bill_payments_company_name (company_name),
                KEY idx_bill_payments_status (status),
                KEY idx_bill_payments_customer_name (customer_name),
                KEY idx_bill_payments_paid_at (paid_at),
                KEY idx_bill_payments_collected_txn (collected_wallet_txn_id),
                KEY idx_bill_payments_paid_txn (paid_wallet_txn_id),
                KEY idx_bill_payments_created_by (created_by),
                CONSTRAINT fk_bill_payments_created_by FOREIGN KEY (created_by) REFERENCES admins(id) ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
    }

    $columns = [
        'bill_id' => "ALTER TABLE bill_payments ADD COLUMN bill_id VARCHAR(80) NOT NULL AFTER id",
        'customer_name' => "ALTER TABLE bill_payments ADD COLUMN customer_name VARCHAR(120) NOT NULL AFTER bill_id",
        'company_name' => "ALTER TABLE bill_payments ADD COLUMN company_name VARCHAR(120) NOT NULL AFTER customer_name",
        'bill_amount' => "ALTER TABLE bill_payments ADD COLUMN bill_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER company_name",
        'service_charge' => "ALTER TABLE bill_payments ADD COLUMN service_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER bill_amount",
        'total_received' => "ALTER TABLE bill_payments ADD COLUMN total_received DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER service_charge",
        'payment_date' => "ALTER TABLE bill_payments ADD COLUMN payment_date DATE NOT NULL AFTER total_received",
        'due_date' => "ALTER TABLE bill_payments ADD COLUMN due_date DATE NULL AFTER payment_date",
        'received_in_type' => "ALTER TABLE bill_payments ADD COLUMN received_in_type VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER due_date",
        'received_in_account_id' => "ALTER TABLE bill_payments ADD COLUMN received_in_account_id BIGINT UNSIGNED NULL AFTER received_in_type",
        'status' => "ALTER TABLE bill_payments ADD COLUMN status ENUM('pending','paid') NOT NULL DEFAULT 'pending' AFTER received_in_account_id",
        'paid_from_type' => "ALTER TABLE bill_payments ADD COLUMN paid_from_type VARCHAR(30) NULL AFTER status",
        'paid_from_account_id' => "ALTER TABLE bill_payments ADD COLUMN paid_from_account_id BIGINT UNSIGNED NULL AFTER paid_from_type",
        'notes' => "ALTER TABLE bill_payments ADD COLUMN notes VARCHAR(255) NULL AFTER paid_from_account_id",
        'collected_wallet_txn_id' => "ALTER TABLE bill_payments ADD COLUMN collected_wallet_txn_id BIGINT UNSIGNED NULL AFTER notes",
        'paid_wallet_txn_id' => "ALTER TABLE bill_payments ADD COLUMN paid_wallet_txn_id BIGINT UNSIGNED NULL AFTER collected_wallet_txn_id",
        'paid_at' => "ALTER TABLE bill_payments ADD COLUMN paid_at DATETIME NULL AFTER paid_wallet_txn_id",
        'created_by' => "ALTER TABLE bill_payments ADD COLUMN created_by INT UNSIGNED NULL AFTER paid_at",
        'updated_at' => "ALTER TABLE bill_payments ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($columns as $column => $sql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM bill_payments LIKE " . $pdo->quote($column));
            if (!(bool) $stmt->fetchColumn()) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }

    $indexes = [
        "ALTER TABLE bill_payments ADD UNIQUE KEY uq_bill_payments_bill_id (bill_id)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_payment_date (payment_date)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_due_date (due_date)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_company_name (company_name)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_received_in_type (received_in_type)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_received_in_account (received_in_account_id)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_status (status)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_paid_from_type (paid_from_type)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_paid_from_account (paid_from_account_id)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_customer_name (customer_name)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_paid_at (paid_at)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_collected_txn (collected_wallet_txn_id)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_paid_txn (paid_wallet_txn_id)",
        "ALTER TABLE bill_payments ADD KEY idx_bill_payments_created_by (created_by)",
    ];

    foreach ($indexes as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
        }
    }

    $companyColumns = [
        'category_name' => "ALTER TABLE bill_companies ADD COLUMN category_name VARCHAR(80) NOT NULL DEFAULT '' AFTER id",
        'company_name' => "ALTER TABLE bill_companies ADD COLUMN company_name VARCHAR(120) NOT NULL AFTER category_name",
        'short_code' => "ALTER TABLE bill_companies ADD COLUMN short_code VARCHAR(30) NULL AFTER company_name",
        'is_active' => "ALTER TABLE bill_companies ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER short_code",
        'sort_order' => "ALTER TABLE bill_companies ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active",
        'updated_at' => "ALTER TABLE bill_companies ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($companyColumns as $column => $sql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM bill_companies LIKE " . $pdo->quote($column));
            if (!(bool) $stmt->fetchColumn()) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }

    $companyIndexes = [
        "ALTER TABLE bill_companies ADD UNIQUE KEY uq_bill_companies_name (company_name)",
        "ALTER TABLE bill_companies ADD KEY idx_bill_companies_category (category_name)",
        "ALTER TABLE bill_companies ADD KEY idx_bill_companies_active (is_active)",
        "ALTER TABLE bill_companies ADD KEY idx_bill_companies_sort (sort_order)",
    ];
    foreach ($companyIndexes as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
        }
    }

    foreach (bill_default_companies() as $index => $company) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO bill_companies (category_name, company_name, short_code, is_active, sort_order)
                VALUES (:category_name, :company_name, :short_code, 1, :sort_order)
                ON DUPLICATE KEY UPDATE
                    category_name = VALUES(category_name),
                    short_code = VALUES(short_code),
                    is_active = 1
            ");
            $stmt->execute([
                ':category_name' => $company['category_name'],
                ':company_name' => $company['company_name'],
                ':short_code' => $company['short_code'],
                ':sort_order' => $index + 1,
            ]);
        } catch (Throwable $e) {
        }
    }
}

function bill_default_companies(): array
{
    return [
        ['category_name' => 'Electricity', 'company_name' => 'K-Electric', 'short_code' => 'KE'],
        ['category_name' => 'Gas', 'company_name' => 'Sui Southern Gas Company', 'short_code' => 'SSGC'],
        ['category_name' => 'Water', 'company_name' => 'Karachi Water and Sewerage Board', 'short_code' => 'KWSB'],
        ['category_name' => 'Water', 'company_name' => 'City District Government Karachi', 'short_code' => 'CDGK/KMC'],
    ];
}

function bill_cash_account_id(PDO $pdo): int
{
    $accounts = wallet_accounts($pdo, 'cash', true);
    $cashId = (int) ($accounts[0]['id'] ?? 0);
    if ($cashId > 0) {
        return $cashId;
    }
    return wallet_find_or_create_account_id($pdo, 'Cash', 'cash', null);
}

function bill_method_labels(): array
{
    return [
        'cash' => 'Cash',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'bank' => 'Bank Account',
        'other' => 'Other',
    ];
}

function bill_method_label(string $method): string
{
    $labels = bill_method_labels();
    return $labels[$method] ?? ucfirst(str_replace('_', ' ', $method));
}

function bill_accounts_by_method(PDO $pdo): array
{
    return [
        'cash' => wallet_accounts($pdo, 'cash', true),
        'jazzcash' => wallet_accounts($pdo, 'jazzcash', true),
        'easypaisa' => wallet_accounts($pdo, 'easypaisa', true),
        'bank' => wallet_accounts($pdo, 'bank', true),
    ];
}

function bill_account_id_for_method(PDO $pdo, string $method, int $selectedAccountId = 0): ?int
{
    if ($method === 'other') {
        return null;
    }
    if ($method === 'cash') {
        return bill_cash_account_id($pdo);
    }
    if (!in_array($method, ['jazzcash', 'easypaisa', 'bank'], true)) {
        return null;
    }
    if ($selectedAccountId > 0) {
        $account = wallet_account($pdo, $selectedAccountId);
        if ($account && (string) ($account['account_type'] ?? '') === $method) {
            return $selectedAccountId;
        }
    }
    $accounts = wallet_accounts($pdo, $method, true);
    return isset($accounts[0]['id']) ? (int) $accounts[0]['id'] : null;
}

function bill_generate_id(): string
{
    return 'BILL-' . date('Ymd-His');
}

function bill_insert_collection_txn(PDO $pdo, string $receivedInType, ?int $receivedAccountId, string $billId, string $customerName, string $companyName, string $paymentDate, float $totalReceived, float $serviceCharge, ?string $notes = null): ?int
{
    $receivedInType = trim($receivedInType);
    if ($receivedInType === 'other') {
        return null;
    }
    $accountId = bill_account_id_for_method($pdo, $receivedInType, (int) ($receivedAccountId ?? 0));
    if ($accountId === null || $accountId <= 0) {
        throw new RuntimeException('Please select a valid collection account.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions
            (account_id, date, customer_name, number, transaction_id, type, amount, charges, commission_method, account_amount, payment_status, completed_at, entry_context, remarks)
        VALUES
            (:account_id, :date, :customer_name, NULL, :transaction_id, 'receiving', :txn_amount, :charges, 'separate_cash', :account_amount, 'completed', NOW(), :entry_context, :remarks)
    ");
    $stmt->execute([
        ':account_id' => $accountId,
        ':date' => $paymentDate,
        ':customer_name' => $customerName,
        ':transaction_id' => $billId,
        ':txn_amount' => $totalReceived,
        ':account_amount' => $totalReceived,
        ':charges' => $serviceCharge,
        ':entry_context' => $receivedInType === 'cash' ? 'external' : 'bill_collection_online',
        ':remarks' => 'Bill Collection #' . $billId . ' [' . bill_method_label($receivedInType) . '] - ' . $companyName . ($notes !== null && trim($notes) !== '' ? ' - ' . trim($notes) : ''),
    ]);
    return (int) $pdo->lastInsertId();
}

function bill_insert_payment_txn(PDO $pdo, string $paidFromType, ?int $paidFromAccountId, string $billId, string $customerName, string $companyName, string $paidDate, float $billAmount, ?string $notes = null): ?int
{
    $paidFromType = trim($paidFromType);
    if ($paidFromType === '' || $paidFromType === 'other') {
        return null;
    }
    $accountId = bill_account_id_for_method($pdo, $paidFromType, (int) ($paidFromAccountId ?? 0));
    if ($accountId === null || $accountId <= 0) {
        throw new RuntimeException('Please select a valid payment account.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions
            (account_id, date, customer_name, number, transaction_id, type, amount, charges, commission_method, account_amount, payment_status, completed_at, entry_context, remarks)
        VALUES
            (:account_id, :date, :customer_name, NULL, :transaction_id, 'sending', :txn_amount, 0, 'separate_cash', :account_amount, 'completed', NOW(), :entry_context, :remarks)
    ");
    $stmt->execute([
        ':account_id' => $accountId,
        ':date' => $paidDate,
        ':customer_name' => $customerName,
        ':transaction_id' => $billId . '-PAID',
        ':txn_amount' => $billAmount,
        ':account_amount' => $billAmount,
        ':entry_context' => $paidFromType === 'cash' ? 'external' : 'bill_payment_online',
        ':remarks' => 'Bill Paid #' . $billId . ' [' . bill_method_label($paidFromType) . '] - ' . $companyName . ($notes !== null && trim($notes) !== '' ? ' - ' . trim($notes) : ''),
    ]);
    return (int) $pdo->lastInsertId();
}

function bill_fetch_companies(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT company_name
            FROM bill_companies
            WHERE is_active = 1
            ORDER BY sort_order ASC, company_name ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $names = array_values(array_filter(array_map(static function (array $row): string {
        return trim((string) ($row['company_name'] ?? ''));
    }, $rows)));

    if ($names) {
        return $names;
    }

    try {
        $legacyRows = $pdo->query("SELECT DISTINCT company_name FROM bill_payments WHERE company_name <> '' ORDER BY company_name ASC")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_filter(array_map(static function (array $row): string {
        return trim((string) ($row['company_name'] ?? ''));
    }, $legacyRows)));
}

function bill_company_rows(PDO $pdo, bool $onlyActive = true): array
{
    $where = $onlyActive ? 'WHERE is_active = 1' : '';
    try {
        $stmt = $pdo->query("
            SELECT id, category_name, company_name, short_code, is_active, sort_order
            FROM bill_companies
            {$where}
            ORDER BY sort_order ASC, category_name ASC, company_name ASC, id ASC
        ");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function bill_company_find(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, category_name, company_name, short_code, is_active, sort_order
            FROM bill_companies
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function bill_build_where(array $filters, array &$params, bool $usePaidDate = false): string
{
    $where = [];
    $dateColumn = $usePaidDate ? 'DATE(bp.paid_at)' : 'bp.payment_date';

    $from = trim((string) ($filters['from'] ?? ''));
    if ($from !== '') {
        $where[] = $dateColumn . ' >= :from';
        $params[':from'] = $from;
    }

    $to = trim((string) ($filters['to'] ?? ''));
    if ($to !== '') {
        $where[] = $dateColumn . ' <= :to';
        $params[':to'] = $to;
    }

    $company = trim((string) ($filters['company'] ?? ''));
    if ($company !== '') {
        $where[] = 'bp.company_name = :company';
        $params[':company'] = $company;
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'bp.status = :status';
        $params[':status'] = $status;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $params[':q_customer'] = $like;
        $params[':q_bill_id'] = $like;
        $where[] = "(bp.customer_name LIKE :q_customer ESCAPE '\\\\' OR bp.bill_id LIKE :q_bill_id ESCAPE '\\\\')";
    }

    return $where ? ('WHERE ' . implode(' AND ', $where)) : '';
}

function bill_list(PDO $pdo, array $filters = [], int $limit = 300): array
{
    $params = [];
    $where = bill_build_where($filters, $params, false);

    $stmt = $pdo->prepare("
        SELECT bp.*, a.name AS created_by_name,
               recv_acc.account_name AS received_in_account_name,
               recv_acc.account_type AS received_in_account_type,
               paid_acc.account_name AS paid_from_account_name,
               paid_acc.account_type AS paid_from_account_type
        FROM bill_payments bp
        LEFT JOIN admins a ON a.id = bp.created_by
        LEFT JOIN accounts recv_acc ON recv_acc.id = bp.received_in_account_id
        LEFT JOIN accounts paid_acc ON paid_acc.id = bp.paid_from_account_id
        {$where}
        ORDER BY bp.payment_date DESC, bp.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function bill_summary(PDO $pdo, array $filters = []): array
{
    $params = [];
    $where = bill_build_where($filters, $params, false);

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(bp.bill_amount), 0) AS bill_amount_total,
            COALESCE(SUM(bp.service_charge), 0) AS service_charge_total,
            COALESCE(SUM(bp.total_received), 0) AS total_received_total,
            COALESCE(SUM(CASE WHEN bp.status = 'pending' THEN bp.bill_amount ELSE 0 END), 0) AS pending_amount_total,
            COALESCE(SUM(CASE WHEN bp.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count_total,
            COALESCE(SUM(CASE WHEN bp.status = 'paid' THEN bp.bill_amount ELSE 0 END), 0) AS paid_amount_total
        FROM bill_payments bp
        {$where}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
        'count' => (int) ($row['total_rows'] ?? 0),
        'bill_amount' => (float) ($row['bill_amount_total'] ?? 0),
        'service_charge' => (float) ($row['service_charge_total'] ?? 0),
        'total_received' => (float) ($row['total_received_total'] ?? 0),
        'pending_amount' => (float) ($row['pending_amount_total'] ?? 0),
        'pending_count' => (int) ($row['pending_count_total'] ?? 0),
        'paid_amount' => (float) ($row['paid_amount_total'] ?? 0),
    ];
}

function bill_paid_amount_by_date(PDO $pdo, ?string $from = null, ?string $to = null): float
{
    $params = [];
    $where = ["bp.status = 'paid'", 'bp.paid_at IS NOT NULL'];
    if ($from !== null && trim($from) !== '') {
        $where[] = 'DATE(bp.paid_at) >= :from';
        $params[':from'] = trim($from);
    }
    if ($to !== null && trim($to) !== '') {
        $where[] = 'DATE(bp.paid_at) <= :to';
        $params[':to'] = trim($to);
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(bp.bill_amount), 0)
        FROM bill_payments bp
        WHERE " . implode(' AND ', $where)
    );
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function bill_current_overview(PDO $pdo): array
{
    $summary = bill_summary($pdo, ['status' => 'pending']);
    return [
        'pending_amount' => (float) ($summary['pending_amount'] ?? 0),
        'pending_count' => (int) ($summary['pending_count'] ?? 0),
    ];
}
