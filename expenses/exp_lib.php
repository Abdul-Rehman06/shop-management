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

function exp_active_accounts(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT id, account_name, account_type, account_number, status
            FROM accounts
            WHERE status = 'active'
            ORDER BY
                CASE account_type
                    WHEN 'cash' THEN 1
                    WHEN 'jazzcash' THEN 2
                    WHEN 'easypaisa' THEN 3
                    WHEN 'bank' THEN 4
                    ELSE 9
                END,
                account_name ASC
        ");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function exp_account_options_by_type(PDO $pdo): array
{
    $grouped = [
        'cash' => [],
        'jazzcash' => [],
        'easypaisa' => [],
        'bank' => [],
    ];

    foreach (exp_active_accounts($pdo) as $account) {
        $type = (string) ($account['account_type'] ?? '');
        if (!array_key_exists($type, $grouped)) {
            $grouped[$type] = [];
        }
        $grouped[$type][] = $account;
    }

    return $grouped;
}

function exp_grouped_account_label(array $account): string
{
    $label = trim((string) ($account['account_name'] ?? ''));
    $number = trim((string) ($account['account_number'] ?? ''));
    if ($number !== '') {
        $label .= ' (' . $number . ')';
    }
    return $label;
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
        'multiple' => 'Multiple Accounts',
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

function exp_payment_source_display(?string $type, ?string $accountName = null): string
{
    $labels = [
        'cash' => 'Cash Drawer',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'bank' => 'Bank Account',
        'multiple' => 'Multiple Accounts',
        'other' => 'Other Account',
    ];

    $key = trim((string) $type);
    $label = $labels[$key] ?? ucfirst($key);
    $accountName = trim((string) $accountName);
    if ($accountName !== '' && $key !== 'multiple') {
        return $label . ' - ' . $accountName;
    }
    return $label;
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

function exp_insert_payment_txn_for_account(
    PDO $pdo,
    int $accountId,
    string $paymentDate,
    string $billName,
    string $category,
    float $amount,
    ?string $notes = null
): int {
    $account = wallet_account($pdo, $accountId);
    if (!$account) {
        throw new RuntimeException('Please select a valid payment account.');
    }

    $accountType = trim((string) ($account['account_type'] ?? ''));
    if (!in_array($accountType, ['cash', 'jazzcash', 'easypaisa', 'bank'], true)) {
        throw new RuntimeException('Unsupported payment account selected.');
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
        ':entry_context' => $accountType === 'cash' ? 'external' : 'expense_bill_payment',
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

function exp_payment_history(PDO $pdo, int $expenseId): array
{
    if ($expenseId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                ep.id AS payment_id,
                ep.expense_id,
                ep.payment_date,
                ep.total_amount,
                ep.paid_by,
                ep.notes,
                ep.status,
                ep.reversed_at,
                ep.reversed_by,
                ep.created_at,
                epi.id AS item_id,
                epi.payment_source_type,
                epi.payment_source_account_id,
                epi.amount AS item_amount,
                epi.linked_wallet_txn_id,
                a.account_name,
                a.account_type
            FROM expense_payments ep
            LEFT JOIN expense_payment_items epi ON epi.payment_id = ep.id
            LEFT JOIN accounts a ON a.id = epi.payment_source_account_id
            WHERE ep.expense_id = :expense_id
            ORDER BY ep.created_at DESC, ep.id DESC, epi.id ASC
        ");
        $stmt->execute([':expense_id' => $expenseId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $history = [];
    foreach ($rows as $row) {
        $paymentId = (int) ($row['payment_id'] ?? 0);
        if ($paymentId <= 0) {
            continue;
        }
        if (!isset($history[$paymentId])) {
            $history[$paymentId] = [
                'payment_id' => $paymentId,
                'expense_id' => (int) ($row['expense_id'] ?? 0),
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'total_amount' => (float) ($row['total_amount'] ?? 0),
                'paid_by' => (string) ($row['paid_by'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => (string) ($row['status'] ?? 'paid'),
                'reversed_at' => (string) ($row['reversed_at'] ?? ''),
                'reversed_by' => (string) ($row['reversed_by'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'items' => [],
            ];
        }

        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemId > 0) {
            $history[$paymentId]['items'][] = [
                'item_id' => $itemId,
                'payment_source_type' => (string) ($row['payment_source_type'] ?? ''),
                'payment_source_account_id' => (int) ($row['payment_source_account_id'] ?? 0),
                'amount' => (float) ($row['item_amount'] ?? 0),
                'linked_wallet_txn_id' => (int) ($row['linked_wallet_txn_id'] ?? 0),
                'account_name' => (string) ($row['account_name'] ?? ''),
                'account_type' => (string) ($row['account_type'] ?? ''),
            ];
        }
    }

    return array_values($history);
}

function exp_payment_history_map(PDO $pdo, array $expenseIds): array
{
    $expenseIds = array_values(array_filter(array_map('intval', $expenseIds), static fn (int $id): bool => $id > 0));
    if (!$expenseIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($expenseIds), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT
                ep.id AS payment_id,
                ep.expense_id,
                ep.payment_date,
                ep.total_amount,
                ep.paid_by,
                ep.notes,
                ep.status,
                ep.reversed_at,
                ep.reversed_by,
                ep.created_at,
                epi.id AS item_id,
                epi.payment_source_type,
                epi.payment_source_account_id,
                epi.amount AS item_amount,
                epi.linked_wallet_txn_id,
                a.account_name,
                a.account_type
            FROM expense_payments ep
            LEFT JOIN expense_payment_items epi ON epi.payment_id = ep.id
            LEFT JOIN accounts a ON a.id = epi.payment_source_account_id
            WHERE ep.expense_id IN ({$placeholders})
            ORDER BY ep.created_at DESC, ep.id DESC, epi.id ASC
        ");
        $stmt->execute($expenseIds);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $expenseId = (int) ($row['expense_id'] ?? 0);
        $paymentId = (int) ($row['payment_id'] ?? 0);
        if ($expenseId <= 0 || $paymentId <= 0) {
            continue;
        }
        if (!isset($map[$expenseId])) {
            $map[$expenseId] = [];
        }
        if (!isset($map[$expenseId][$paymentId])) {
            $map[$expenseId][$paymentId] = [
                'payment_id' => $paymentId,
                'expense_id' => $expenseId,
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'total_amount' => (float) ($row['total_amount'] ?? 0),
                'paid_by' => (string) ($row['paid_by'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => (string) ($row['status'] ?? 'paid'),
                'reversed_at' => (string) ($row['reversed_at'] ?? ''),
                'reversed_by' => (string) ($row['reversed_by'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'items' => [],
            ];
        }

        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemId > 0) {
            $map[$expenseId][$paymentId]['items'][] = [
                'item_id' => $itemId,
                'payment_source_type' => (string) ($row['payment_source_type'] ?? ''),
                'payment_source_account_id' => (int) ($row['payment_source_account_id'] ?? 0),
                'amount' => (float) ($row['item_amount'] ?? 0),
                'linked_wallet_txn_id' => (int) ($row['linked_wallet_txn_id'] ?? 0),
                'account_name' => (string) ($row['account_name'] ?? ''),
                'account_type' => (string) ($row['account_type'] ?? ''),
            ];
        }
    }

    foreach ($map as $expenseId => $payments) {
        $map[$expenseId] = array_values($payments);
    }

    return $map;
}

function exp_active_payment_splits(PDO $pdo, int $expenseId): array
{
    $history = exp_payment_history($pdo, $expenseId);
    foreach ($history as $payment) {
        if ((string) ($payment['status'] ?? '') === 'paid') {
            return $payment['items'] ?? [];
        }
    }
    return [];
}

function exp_normalize_payment_allocations(PDO $pdo, array $accountIds, array $amounts, float $expectedAmount): array
{
    $accountsById = [];
    foreach (exp_active_accounts($pdo) as $account) {
        $accountsById[(int) ($account['id'] ?? 0)] = $account;
    }

    $allocations = [];
    $sum = 0.0;
    $rowCount = max(count($accountIds), count($amounts));
    for ($i = 0; $i < $rowCount; $i++) {
        $accountId = (int) ($accountIds[$i] ?? 0);
        $amount = (float) ($amounts[$i] ?? 0);
        if ($accountId <= 0 && $amount <= 0) {
            continue;
        }
        if ($accountId <= 0) {
            throw new RuntimeException('Please select a payment account for every split row.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Split amount must be greater than zero.');
        }
        $account = $accountsById[$accountId] ?? null;
        if (!$account) {
            throw new RuntimeException('Selected payment account is invalid or inactive.');
        }
        $allocations[] = [
            'account_id' => $accountId,
            'account_type' => (string) ($account['account_type'] ?? ''),
            'account_name' => (string) ($account['account_name'] ?? ''),
            'amount' => round($amount, 2),
        ];
        $sum += round($amount, 2);
    }

    if (!$allocations) {
        throw new RuntimeException('Please add at least one paid from account.');
    }

    if (abs(round($sum, 2) - round($expectedAmount, 2)) > 0.01) {
        throw new RuntimeException('Split payment total must exactly match the bill amount.');
    }

    return $allocations;
}

function exp_reverse_payment_history(PDO $pdo, int $expenseId, ?string $reversedBy = null): void
{
    if ($expenseId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT ep.id AS payment_id, epi.linked_wallet_txn_id
        FROM expense_payments ep
        LEFT JOIN expense_payment_items epi ON epi.payment_id = ep.id
        WHERE ep.expense_id = :expense_id
          AND ep.status = 'paid'
        ORDER BY ep.id DESC, epi.id DESC
    ");
    $stmt->execute([':expense_id' => $expenseId]);
    $rows = $stmt->fetchAll();

    $legacyStmt = $pdo->prepare("SELECT linked_wallet_txn_id FROM expenses WHERE id = :id LIMIT 1");
    $legacyStmt->execute([':id' => $expenseId]);
    $legacyLinkedWalletTxnId = (int) ($legacyStmt->fetchColumn() ?: 0);

    $paymentIds = [];
    foreach ($rows as $row) {
        $paymentId = (int) ($row['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $paymentIds[$paymentId] = true;
        }
        $walletTxnId = (int) ($row['linked_wallet_txn_id'] ?? 0);
        if ($walletTxnId > 0) {
            $del = $pdo->prepare('DELETE FROM wallet_transactions WHERE id = :id');
            $del->execute([':id' => $walletTxnId]);
        }
    }

    if ($legacyLinkedWalletTxnId > 0) {
        $del = $pdo->prepare('DELETE FROM wallet_transactions WHERE id = :id');
        $del->execute([':id' => $legacyLinkedWalletTxnId]);
    }

    if ($paymentIds) {
        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $params = array_fill(0, count($paymentIds), null);
        $paymentIdValues = array_map('intval', array_keys($paymentIds));
        foreach ($paymentIdValues as $index => $value) {
            $params[$index] = $value;
        }
        $sql = "
            UPDATE expense_payments
            SET status = 'reversed',
                reversed_at = NOW(),
                reversed_by = ?
            WHERE id IN ({$placeholders})
        ";
        array_unshift($params, $reversedBy !== null && trim($reversedBy) !== '' ? trim($reversedBy) : null);
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
    }
}

function exp_apply_payment_history(PDO $pdo, int $expenseId, array $expenseRow, array $allocations): array
{
    if ($expenseId <= 0) {
        throw new RuntimeException('Invalid expense bill.');
    }

    $billName = trim((string) ($expenseRow['bill_name'] ?? ''));
    $category = trim((string) ($expenseRow['category'] ?? 'Other'));
    $paymentDate = trim((string) ($expenseRow['payment_date'] ?? $expenseRow['date'] ?? date('Y-m-d')));
    $paidBy = trim((string) ($expenseRow['paid_by'] ?? ''));
    $notes = trim((string) ($expenseRow['notes'] ?? $expenseRow['description'] ?? ''));
    $amount = (float) ($expenseRow['amount'] ?? 0);

    exp_reverse_payment_history($pdo, $expenseId, $paidBy !== '' ? $paidBy : null);

    $stmt = $pdo->prepare("
        INSERT INTO expense_payments
            (expense_id, payment_date, total_amount, paid_by, notes, status)
        VALUES
            (:expense_id, :payment_date, :total_amount, :paid_by, :notes, 'paid')
    ");
    $stmt->execute([
        ':expense_id' => $expenseId,
        ':payment_date' => $paymentDate,
        ':total_amount' => $amount,
        ':paid_by' => $paidBy !== '' ? $paidBy : null,
        ':notes' => $notes !== '' ? $notes : null,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO expense_payment_items
            (payment_id, payment_source_type, payment_source_account_id, amount, linked_wallet_txn_id)
        VALUES
            (:payment_id, :payment_source_type, :payment_source_account_id, :amount, :linked_wallet_txn_id)
    ");

    $firstTxnId = null;
    foreach ($allocations as $index => $allocation) {
        $allocationAmount = (float) ($allocation['amount'] ?? 0);
        $accountId = (int) ($allocation['account_id'] ?? 0);
        $accountName = trim((string) ($allocation['account_name'] ?? ''));
        $noteSuffix = count($allocations) > 1 ? ('Split ' . ($index + 1) . ': ' . $accountName . ' Rs ' . number_format($allocationAmount, 2)) : $notes;
        $walletTxnId = exp_insert_payment_txn_for_account(
            $pdo,
            $accountId,
            $paymentDate,
            $billName,
            $category,
            $allocationAmount,
            $noteSuffix !== '' ? $noteSuffix : null
        );

        if ($firstTxnId === null) {
            $firstTxnId = $walletTxnId;
        }

        $itemStmt->execute([
            ':payment_id' => $paymentId,
            ':payment_source_type' => (string) ($allocation['account_type'] ?? ''),
            ':payment_source_account_id' => $accountId,
            ':amount' => $allocationAmount,
            ':linked_wallet_txn_id' => $walletTxnId,
        ]);
    }

    if (count($allocations) === 1) {
        return [
            'payment_source_type' => (string) ($allocations[0]['account_type'] ?? ''),
            'payment_source_account_id' => (int) ($allocations[0]['account_id'] ?? 0),
            'linked_wallet_txn_id' => $firstTxnId,
        ];
    }

    return [
        'payment_source_type' => 'multiple',
        'payment_source_account_id' => null,
        'linked_wallet_txn_id' => null,
    ];
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
