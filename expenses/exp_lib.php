<?php

declare(strict_types=1);

function exp_default_categories(): array
{
    return [
        'Shop Rent',
        'Electricity',
        'Internet',
        'Staff Salary',
        'Water Bill',
        'Gas Bill',
        'Maintenance',
        'Other',
    ];
}

function exp_categories(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT category_name
            FROM expense_categories
            WHERE is_active = 1
            ORDER BY sort_order ASC, category_name ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $categories = array_values(array_filter(array_map(static function (array $row): string {
        return trim((string) ($row['category_name'] ?? ''));
    }, $rows)));

    if ($categories) {
        return $categories;
    }

    return exp_default_categories();
}

function exp_category_rows(PDO $pdo, bool $onlyActive = true): array
{
    try {
        $stmt = $pdo->query("
            SELECT id, category_name, is_active, sort_order
            FROM expense_categories
            " . ($onlyActive ? 'WHERE is_active = 1' : '') . "
            ORDER BY sort_order ASC, category_name ASC, id ASC
        ");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function exp_category_find(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, category_name, is_active, sort_order
        FROM expense_categories
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function exp_status_label(string $status): string
{
    return $status === 'paid' ? 'Paid' : 'Unpaid';
}

function exp_status_badge_class(string $status): string
{
    return $status === 'paid' ? 'bg-success' : 'bg-warning text-dark';
}

function exp_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT e.*, paid_acc.account_name AS payment_account_name, paid_acc.account_type AS payment_account_type
        FROM expenses e
        LEFT JOIN accounts paid_acc ON paid_acc.id = e.payment_source_account_id
        WHERE e.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function exp_total(PDO $pdo, string $from, string $to, string $category = ''): float
{
    $params = [
        ':from' => $from,
        ':to' => $to,
    ];
    $where = 'WHERE date >= :from AND date <= :to';
    if ($category !== '') {
        $where .= ' AND category = :category';
        $params[':category'] = $category;
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses {$where}");
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function exp_summary(PDO $pdo, string $from, string $to, string $category = ''): array
{
    $params = [
        ':from' => $from,
        ':to' => $to,
    ];
    $where = 'WHERE date >= :from AND date <= :to';
    if ($category !== '') {
        $where .= ' AND category = :category';
        $params[':category'] = $category;
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(amount), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END), 0) AS paid_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count
        FROM expenses
        {$where}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
        'count' => (int) ($row['total_rows'] ?? 0),
        'total_amount' => (float) ($row['total_amount'] ?? 0),
        'paid_amount' => (float) ($row['paid_amount'] ?? 0),
        'unpaid_amount' => (float) ($row['unpaid_amount'] ?? 0),
        'paid_count' => (int) ($row['paid_count'] ?? 0),
        'unpaid_count' => (int) ($row['unpaid_count'] ?? 0),
    ];
}

function exp_category_summary(PDO $pdo, string $from, string $to): array
{
    $stmt = $pdo->prepare("
        SELECT
            category,
            COALESCE(SUM(amount), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END), 0) AS paid_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_amount,
            COUNT(*) AS total_rows
        FROM expenses
        WHERE date >= :from AND date <= :to
        GROUP BY category
        ORDER BY total_amount DESC, category ASC
    ");
    $stmt->execute([
        ':from' => $from,
        ':to' => $to,
    ]);
    return $stmt->fetchAll();
}

function exp_method_labels(): array
{
    return [
        'cash' => 'Cash Drawer',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'bank' => 'Bank Account',
        'other' => 'Other Account',
    ];
}

function exp_accounts_by_method(PDO $pdo): array
{
    return [
        'cash' => wallet_accounts($pdo, 'cash', true),
        'jazzcash' => wallet_accounts($pdo, 'jazzcash', true),
        'easypaisa' => wallet_accounts($pdo, 'easypaisa', true),
        'bank' => wallet_accounts($pdo, 'bank', true),
    ];
}

function exp_account_id_for_method(PDO $pdo, string $method, int $selectedAccountId = 0): ?int
{
    if ($method === 'other') {
        return null;
    }
    if ($method === 'cash') {
        $accounts = wallet_accounts($pdo, 'cash', true);
        $cashId = (int) ($accounts[0]['id'] ?? 0);
        if ($cashId > 0) {
            return $cashId;
        }
        return wallet_find_or_create_account_id($pdo, 'Cash', 'cash', null);
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

function exp_payment_txn_note(string $billName, string $category, ?string $notes = null): string
{
    $note = 'Expense Bill - ' . trim($billName) . ' [' . trim($category) . ']';
    if ($notes !== null && trim($notes) !== '') {
        $note .= ' - ' . trim($notes);
    }
    return $note;
}

function exp_insert_payment_txn(
    PDO $pdo,
    string $paymentSourceType,
    ?int $paymentSourceAccountId,
    string $paymentDate,
    string $billName,
    string $category,
    float $amount,
    ?string $notes = null
): ?int {
    $paymentSourceType = trim($paymentSourceType);
    if ($paymentSourceType === '' || $paymentSourceType === 'other') {
        return null;
    }

    $accountId = exp_account_id_for_method($pdo, $paymentSourceType, (int) ($paymentSourceAccountId ?? 0));
    if ($accountId === null || $accountId <= 0) {
        throw new RuntimeException('Please select a valid payment account.');
    }

    $transactionId = 'EXP-' . date('YmdHis') . '-' . random_int(1000, 9999);
    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions
            (account_id, date, customer_name, number, transaction_id, type, amount, charges, commission_method, account_amount, payment_status, completed_at, entry_context, remarks)
        VALUES
            (:account_id, :date, :customer_name, NULL, :transaction_id, 'sending', :txn_amount, 0, 'separate_cash', :account_amount, 'completed', NOW(), :entry_context, :remarks)
    ");
    $stmt->execute([
        ':account_id' => $accountId,
        ':date' => $paymentDate,
        ':customer_name' => $billName,
        ':transaction_id' => $transactionId,
        ':txn_amount' => $amount,
        ':account_amount' => $amount,
        ':entry_context' => $paymentSourceType === 'cash' ? 'external' : 'expense_bill_payment',
        ':remarks' => exp_payment_txn_note($billName, $category, $notes),
    ]);

    return (int) $pdo->lastInsertId();
}

function exp_update_payment_txn(
    PDO $pdo,
    int $txnId,
    string $paymentSourceType,
    ?int $paymentSourceAccountId,
    string $paymentDate,
    string $billName,
    string $category,
    float $amount,
    ?string $notes = null
): void {
    $paymentSourceType = trim($paymentSourceType);
    if ($paymentSourceType === '' || $paymentSourceType === 'other') {
        throw new RuntimeException('Please select a valid payment account.');
    }

    $accountId = exp_account_id_for_method($pdo, $paymentSourceType, (int) ($paymentSourceAccountId ?? 0));
    if ($accountId === null || $accountId <= 0) {
        throw new RuntimeException('Please select a valid payment account.');
    }

    $stmt = $pdo->prepare("
        UPDATE wallet_transactions
        SET account_id = :account_id,
            date = :date,
            customer_name = :customer_name,
            amount = :txn_amount,
            account_amount = :account_amount,
            payment_status = 'completed',
            completed_at = COALESCE(completed_at, NOW()),
            entry_context = :entry_context,
            remarks = :remarks
        WHERE id = :id
    ");
    $stmt->execute([
        ':account_id' => $accountId,
        ':date' => $paymentDate,
        ':customer_name' => $billName,
        ':txn_amount' => $amount,
        ':account_amount' => $amount,
        ':entry_context' => $paymentSourceType === 'cash' ? 'external' : 'expense_bill_payment',
        ':remarks' => exp_payment_txn_note($billName, $category, $notes),
        ':id' => $txnId,
    ]);
}

function exp_sync_payment_txn(PDO $pdo, ?array $before, array $after): ?int
{
    $paymentStatus = trim((string) ($after['payment_status'] ?? 'unpaid'));
    $paymentSourceType = trim((string) ($after['payment_source_type'] ?? 'cash'));
    $paymentSourceAccountId = (int) ($after['payment_source_account_id'] ?? 0);
    $paymentDate = trim((string) ($after['payment_date'] ?? $after['date'] ?? date('Y-m-d')));
    $billName = trim((string) ($after['bill_name'] ?? ''));
    $category = trim((string) ($after['category'] ?? 'Other'));
    $amount = (float) ($after['amount'] ?? 0);
    $notes = trim((string) ($after['notes'] ?? $after['description'] ?? ''));
    $existingTxnId = (int) (($before['linked_wallet_txn_id'] ?? 0) ?: ($after['linked_wallet_txn_id'] ?? 0));

    if ($paymentStatus !== 'paid') {
        if ($existingTxnId > 0) {
            $stmt = $pdo->prepare('DELETE FROM wallet_transactions WHERE id = :id');
            $stmt->execute([':id' => $existingTxnId]);
        }
        return null;
    }

    if ($existingTxnId > 0) {
        exp_update_payment_txn(
            $pdo,
            $existingTxnId,
            $paymentSourceType,
            $paymentSourceAccountId > 0 ? $paymentSourceAccountId : null,
            $paymentDate,
            $billName,
            $category,
            $amount,
            $notes !== '' ? $notes : null
        );
        return $existingTxnId;
    }

    return exp_insert_payment_txn(
        $pdo,
        $paymentSourceType,
        $paymentSourceAccountId > 0 ? $paymentSourceAccountId : null,
        $paymentDate,
        $billName,
        $category,
        $amount,
        $notes !== '' ? $notes : null
    );
}

