<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Mobile Accounts - Shop Management';
$pdo = db();
$canEditDelete = app_can_edit_delete_records();

$wallet_get_opening_txn = function (PDO $pdo, int $accountId, string $date): ?array {
    $stmt = $pdo->prepare("
        SELECT id, date, amount
        FROM wallet_transactions
        WHERE account_id = ? AND date = ? AND type = 'opening'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$accountId, $date]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
};

$wallet_compute_auto_opening = function (PDO $pdo, int $accountId, string $date): float {
    $stmt = $pdo->prepare("
        SELECT date, amount
        FROM wallet_transactions
        WHERE account_id = ? AND type = 'opening' AND date < ?
        ORDER BY date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$accountId, $date]);
    $baseline = $stmt->fetch();

    $baselineDate = is_array($baseline) ? (string) ($baseline['date'] ?? '') : '';
    $baselineAmount = is_array($baseline) ? (float) ($baseline['amount'] ?? 0) : 0.0;

    if ($baselineDate !== '') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN type = 'receiving' THEN amount
                    WHEN type = 'sending' THEN -amount
                    ELSE 0
                END
            ), 0)
            FROM wallet_transactions
            WHERE account_id = ?
              AND date >= ?
              AND date < ?
              AND type IN ('receiving', 'sending')
        ");
        $stmt->execute([$accountId, $baselineDate, $date]);
        $net = (float) $stmt->fetchColumn();
        return $baselineAmount + $net;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN type = 'receiving' THEN amount
                WHEN type = 'sending' THEN -amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions
        WHERE account_id = ?
          AND date < ?
          AND type IN ('receiving', 'sending')
    ");
    $stmt->execute([$accountId, $date]);
    $net = (float) $stmt->fetchColumn();
    return $net;
};

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $date = $_POST['date'] ?? date('Y-m-d');
    $type = $_POST['type'] ?? 'easypaisa';
    $accountFilter = (int) ($_POST['account_id'] ?? ($_POST['account_id_filter'] ?? 0));
    $redirectUrl = "mobile-accounts/index.php?date={$date}&type={$type}&account_id={$accountFilter}";
    $returnUrl = (string) ($_POST['return_url'] ?? $redirectUrl);

    if ($action === 'save_opening') {
        $balances = $_POST['balances'] ?? [];
        foreach ($balances as $accId => $amount) {
            $accId = (int) $accId;
            $raw = trim((string) $amount);

            $stmt = $pdo->prepare("SELECT id FROM wallet_transactions WHERE account_id=? AND date=? AND type='opening' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$accId, $date]);
            $exists = (int) ($stmt->fetchColumn() ?: 0);

            if ($raw === '') {
                if ($exists > 0) {
                    $pdo->prepare("DELETE FROM wallet_transactions WHERE id=?")->execute([$exists]);
                }
                continue;
            }

            $amt = (float) $raw;
            if ($exists > 0) {
                $pdo->prepare("UPDATE wallet_transactions SET amount=? WHERE id=?")->execute([$amt, $exists]);
            } else {
                $pdo->prepare("INSERT INTO wallet_transactions (account_id, date, type, amount) VALUES (?, ?, 'opening', ?)")->execute([$accId, $date, $amt]);
            }
        }
        flash_set('success', 'Opening balances saved successfully.');
        
    } elseif ($action === 'save_customer') {
        $custName = trim((string) ($_POST['customer_name'] ?? ''));
        $custPhone = trim((string) ($_POST['customer_phone'] ?? ''));
        if ($custName === '' || $custPhone === '') {
            flash_set('error', 'Customer name and phone are required.');
            app_redirect($returnUrl);
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO customers (name, phone)
                VALUES (:name, :phone)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name)
            ");
            $stmt->execute([':name' => $custName, ':phone' => $custPhone]);
            flash_set('success', 'Customer saved.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not save customer.');
        }
        app_redirect($returnUrl);

    } elseif ($action === 'add_entry') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $entryType = $_POST['entry_type'] ?? 'receiving';
        $amount = (float)($_POST['amount'] ?? 0);
        $charges = (float)($_POST['charges'] ?? 0);
        $customerName = trim($_POST['customer_name'] ?? '');
        $number = trim($_POST['number'] ?? '');
        $transactionId = trim($_POST['transaction_id'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($accountId > 0 && $amount > 0 && in_array($entryType, ['receiving', 'sending'])) {
            $openingRow = $wallet_get_opening_txn($pdo, $accountId, $date);
            if (!$openingRow) {
                $autoOpening = $wallet_compute_auto_opening($pdo, $accountId, $date);
                $stmt = $pdo->prepare("INSERT INTO wallet_transactions (account_id, date, type, amount) VALUES (?, ?, 'opening', ?)");
                $stmt->execute([$accountId, $date, $autoOpening]);
            }
            $stmt = $pdo->prepare("INSERT INTO wallet_transactions (account_id, date, type, amount, charges, customer_name, number, transaction_id, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $accountId, $date, $entryType, $amount, $charges,
                $customerName !== '' ? $customerName : null,
                $number !== '' ? $number : null,
                $transactionId !== '' ? $transactionId : null,
                $remarks !== '' ? $remarks : null
            ]);

            if ($number !== '' && $customerName !== '') {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO customers (name, phone)
                        VALUES (:name, :phone)
                        ON DUPLICATE KEY UPDATE
                            name = VALUES(name)
                    ");
                    $stmt->execute([':name' => $customerName, ':phone' => $number]);
                } catch (Throwable $e) {
                }
            }
            flash_set('success', 'Transaction added successfully.');
        } else {
            flash_set('error', 'Invalid account or amount.');
        }
    } elseif ($action === 'update_txn') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect($returnUrl);
        }

        $txnId = (int) ($_POST['txn_id'] ?? 0);
        $txnDate = trim((string) ($_POST['txn_date'] ?? ''));
        $txnAccountId = (int) ($_POST['txn_account_id'] ?? 0);
        $txnType = trim((string) ($_POST['txn_type'] ?? ''));
        $txnAmount = trim((string) ($_POST['txn_amount'] ?? ''));
        $txnCharges = trim((string) ($_POST['txn_charges'] ?? '0'));
        $txnCustomer = trim((string) ($_POST['txn_customer_name'] ?? ''));
        $txnNumber = trim((string) ($_POST['txn_number'] ?? ''));
        $txnTransactionId = trim((string) ($_POST['txn_transaction_id'] ?? ''));
        $txnRemarks = trim((string) ($_POST['txn_remarks'] ?? ''));

        if ($txnId <= 0) {
            flash_set('error', 'Invalid transaction.');
            app_redirect($returnUrl);
        }
        if ($txnDate === '') {
            flash_set('error', 'Date is required.');
            app_redirect($returnUrl);
        }
        if ($txnAccountId <= 0) {
            flash_set('error', 'Account is required.');
            app_redirect($returnUrl);
        }
        if (!in_array($txnType, ['opening', 'receiving', 'sending'], true)) {
            flash_set('error', 'Invalid type.');
            app_redirect($returnUrl);
        }
        if ($txnAmount === '' || !is_numeric($txnAmount) || (float) $txnAmount <= 0) {
            flash_set('error', 'Amount must be a positive number.');
            app_redirect($returnUrl);
        }
        if ($txnCharges !== '' && !is_numeric($txnCharges)) {
            flash_set('error', 'Commission must be a number.');
            app_redirect($returnUrl);
        }

        $stmt = $pdo->prepare('SELECT * FROM wallet_transactions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $txnId]);
        $before = $stmt->fetch() ?: null;
        if (!$before) {
            flash_set('error', 'Transaction not found.');
            app_redirect($returnUrl);
        }

        $stmt = $pdo->prepare("
            UPDATE wallet_transactions
            SET account_id = :account_id,
                date = :date,
                type = :type,
                amount = :amount,
                charges = :charges,
                customer_name = :customer_name,
                number = :number,
                transaction_id = :transaction_id,
                remarks = :remarks
            WHERE id = :id
        ");
        $stmt->execute([
            ':account_id' => $txnAccountId,
            ':date' => $txnDate,
            ':type' => $txnType,
            ':amount' => (float) $txnAmount,
            ':charges' => $txnCharges === '' ? 0.0 : (float) $txnCharges,
            ':customer_name' => $txnCustomer !== '' ? $txnCustomer : null,
            ':number' => $txnNumber !== '' ? $txnNumber : null,
            ':transaction_id' => $txnTransactionId !== '' ? $txnTransactionId : null,
            ':remarks' => $txnRemarks !== '' ? $txnRemarks : null,
            ':id' => $txnId,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM wallet_transactions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $txnId]);
        $after = $stmt->fetch() ?: null;

        app_audit_log('wallet_transactions', $txnId, 'edit', is_array($before) ? $before : null, is_array($after) ? $after : null);

        if ($txnNumber !== '' && $txnCustomer !== '') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (name, phone)
                    VALUES (:name, :phone)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name)
                ");
                $stmt->execute([':name' => $txnCustomer, ':phone' => $txnNumber]);
            } catch (Throwable $e) {
            }
        }

        flash_set('success', 'Transaction updated.');
        app_redirect($returnUrl);
    } elseif ($action === 'delete_txn') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect($returnUrl);
        }

        $txnId = (int) ($_POST['txn_id'] ?? 0);
        if ($txnId <= 0) {
            flash_set('error', 'Invalid transaction.');
            app_redirect($returnUrl);
        }

        $stmt = $pdo->prepare('SELECT * FROM wallet_transactions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $txnId]);
        $before = $stmt->fetch() ?: null;
        if (!$before) {
            flash_set('error', 'Transaction not found.');
            app_redirect($returnUrl);
        }

        $stmt = $pdo->prepare('DELETE FROM wallet_transactions WHERE id = :id');
        $stmt->execute([':id' => $txnId]);

        app_audit_log('wallet_transactions', $txnId, 'delete', is_array($before) ? $before : null, null);

        flash_set('success', 'Transaction deleted.');
        app_redirect($returnUrl);
    }
    
    app_redirect($redirectUrl);
}

// Data Fetching & Setup
$currentDate = $_GET['date'] ?? date('Y-m-d');
$currentType = $_GET['type'] ?? 'easypaisa';
$currentAccountId = (int)($_GET['account_id'] ?? 0);
$tab = trim((string) ($_GET['tab'] ?? 'daily'));
if (!in_array($tab, ['daily', 'search'], true)) {
    $tab = 'daily';
}

$allAccounts = $pdo->query("SELECT * FROM accounts WHERE status='active' ORDER BY account_type, account_name")->fetchAll();
$accountsByType = ['easypaisa' => [], 'jazzcash' => [], 'cash' => [], 'bank' => []];
foreach ($allAccounts as $a) {
    if (array_key_exists($a['account_type'], $accountsByType)) {
        $accountsByType[$a['account_type']][] = $a;
    }
}

$counts = [
    'easypaisa' => count($accountsByType['easypaisa']),
    'jazzcash' => count($accountsByType['jazzcash']),
    'cash' => count($accountsByType['cash']),
    'bank' => count($accountsByType['bank'])
];

if (!array_key_exists($currentType, $accountsByType)) {
    $currentType = 'easypaisa';
}

$selectedAccounts = $currentAccountId > 0 
    ? array_filter($accountsByType[$currentType], fn($a) => (int)$a['id'] === $currentAccountId)
    : $accountsByType[$currentType];

$accIds = array_column($selectedAccounts, 'id');

// Helper to get daily stats for a specific account and date
function get_daily_stats(PDO $pdo, int $accountId, string $date): array {
    $stmt = $pdo->prepare("
        SELECT amount
        FROM wallet_transactions
        WHERE account_id = ? AND date = ? AND type = 'opening'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$accountId, $date]);
    $manualOpening = $stmt->fetchColumn();

    if ($manualOpening !== false && $manualOpening !== null) {
        $opening = (float) $manualOpening;
    } else {
        $stmt = $pdo->prepare("
            SELECT date, amount
            FROM wallet_transactions
            WHERE account_id = ? AND type = 'opening' AND date < ?
            ORDER BY date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$accountId, $date]);
        $baseline = $stmt->fetch();

        $baselineDate = is_array($baseline) ? (string) ($baseline['date'] ?? '') : '';
        $baselineAmount = is_array($baseline) ? (float) ($baseline['amount'] ?? 0) : 0.0;

        if ($baselineDate !== '') {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(
                    CASE
                        WHEN type = 'receiving' THEN amount
                        WHEN type = 'sending' THEN -amount
                        ELSE 0
                    END
                ), 0)
                FROM wallet_transactions
                WHERE account_id = ?
                  AND date >= ?
                  AND date < ?
                  AND type IN ('receiving', 'sending')
            ");
            $stmt->execute([$accountId, $baselineDate, $date]);
            $net = (float) $stmt->fetchColumn();
            $opening = $baselineAmount + $net;
        } else {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(
                    CASE
                        WHEN type = 'receiving' THEN amount
                        WHEN type = 'sending' THEN -amount
                        ELSE 0
                    END
                ), 0)
                FROM wallet_transactions
                WHERE account_id = ?
                  AND date < ?
                  AND type IN ('receiving', 'sending')
            ");
            $stmt->execute([$accountId, $date]);
            $opening = (float) $stmt->fetchColumn();
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'receiving' THEN amount ELSE 0 END), 0) AS received,
            COALESCE(SUM(CASE WHEN type = 'sending' THEN amount ELSE 0 END), 0) AS sent
        FROM wallet_transactions
        WHERE account_id = ?
          AND date = ?
          AND type IN ('receiving', 'sending')
    ");
    $stmt->execute([$accountId, $date]);
    $row = $stmt->fetch() ?: [];

    $received = (float) ($row['received'] ?? 0);
    $sent = (float) ($row['sent'] ?? 0);

    return [
        'opening' => $opening,
        'received' => $received,
        'sent' => $sent,
        'closing' => $opening + $received - $sent,
    ];
}

// Calculate combined totals for selected accounts
$totalOpening = 0.0; $totalReceived = 0.0; $totalSent = 0.0; $totalClosing = 0.0;
foreach ($selectedAccounts as $a) {
    $st = get_daily_stats($pdo, (int)$a['id'], $currentDate);
    $totalOpening += $st['opening'];
    $totalReceived += $st['received'];
    $totalSent += $st['sent'];
    $totalClosing += $st['closing'];
}

// Fetch existing opening balances for the form
$openingBalances = [];
if (!empty($accIds)) {
    $in = str_repeat('?,', count($accIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT account_id, amount FROM wallet_transactions WHERE date = ? AND type = 'opening' AND account_id IN ($in)");
    $params = array_merge([$currentDate], $accIds);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $openingBalances[(int)$row['account_id']] = (float)$row['amount'];
    }
}

foreach ($accIds as $aid) {
    $aid = (int) $aid;
    if (!array_key_exists($aid, $openingBalances)) {
        $openingBalances[$aid] = $wallet_compute_auto_opening($pdo, $aid, $currentDate);
    }
}

// Fetch transactions for the table
$transactions = [];
if (!empty($accIds)) {
    $in = str_repeat('?,', count($accIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT wt.*, a.account_name, a.account_type 
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE wt.date = ? AND wt.account_id IN ($in) AND wt.type != 'opening'
        ORDER BY wt.created_at ASC, wt.id ASC
    ");
    $params = array_merge([$currentDate], $accIds);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
}

$totalCommission = 0.0;
if (!empty($accIds)) {
    $in = str_repeat('?,', count($accIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(charges), 0)
        FROM wallet_transactions
        WHERE date = ? AND account_id IN ($in) AND type != 'opening'
    ");
    $params = array_merge([$currentDate], $accIds);
    $stmt->execute($params);
    $totalCommission = (float) $stmt->fetchColumn();
}

$export = (int) ($_GET['export'] ?? 0) === 1;
$exportFormat = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if (!in_array($exportFormat, ['csv', 'xls', 'pdf'], true)) {
    $exportFormat = 'csv';
}

if ($export) {
    $suffix = $exportFormat === 'xls' ? 'xls' : ($exportFormat === 'pdf' ? 'pdf' : 'csv');
    $filename = 'mobile-accounts-' . $currentDate . '-' . $currentType . ($currentAccountId > 0 ? ('-acc' . $currentAccountId) : '-all') . '.' . $suffix;

    $summaryPairs = [
        ['Date', $currentDate],
        ['Type', $currentType],
        ['Account Filter', $currentAccountId > 0 ? (string) $currentAccountId : 'All'],
        ['Opening', number_format($totalOpening, 2)],
        ['Received', number_format($totalReceived, 2)],
        ['Sent', number_format($totalSent, 2)],
        ['Commission', number_format($totalCommission, 2)],
        ['Closing', number_format($totalClosing, 2)],
    ];

    $headers = ['Created At', 'Account', 'Type', 'Amount', 'Commission', 'Customer', 'Number', 'Transaction ID', 'Note'];
    $rows = [];
    foreach ($transactions as $t) {
        $rows[] = [
            (string) ($t['created_at'] ?? ''),
            (string) ($t['account_name'] ?? ''),
            (string) ($t['type'] ?? ''),
            number_format((float) ($t['amount'] ?? 0), 2),
            number_format((float) ($t['charges'] ?? 0), 2),
            (string) ($t['customer_name'] ?? ''),
            (string) ($t['number'] ?? ''),
            (string) ($t['transaction_id'] ?? ''),
            (string) ($t['remarks'] ?? ''),
        ];
    }

    if ($exportFormat === 'pdf') {
        $title = 'Mobile Accounts (' . $currentDate . ') - ' . strtoupper($currentType) . ' - ' . ($currentAccountId > 0 ? ('Account ' . $currentAccountId) : 'All Accounts');
        
        $summaryHtml = '<table style="width: 100%; margin-bottom: 20px; font-size: 12px; font-family: sans-serif; border-bottom: 1px solid #ccc; padding-bottom: 10px;"><tr>';
        $summaryHtml .= '<td style="width: 20%;"><b>Opening:</b> ' . number_format($totalOpening, 2) . '</td>';
        $summaryHtml .= '<td style="width: 20%;"><b>Received:</b> ' . number_format($totalReceived, 2) . '</td>';
        $summaryHtml .= '<td style="width: 20%;"><b>Sent:</b> ' . number_format($totalSent, 2) . '</td>';
        $summaryHtml .= '<td style="width: 20%;"><b>Commission:</b> ' . number_format($totalCommission, 2) . '</td>';
        $summaryHtml .= '<td style="width: 20%;"><b>Closing:</b> ' . number_format($totalClosing, 2) . '</td>';
        $summaryHtml .= '</tr></table>';

        $tableHtml = '<table style="width: 100%; border-collapse: collapse; font-family: sans-serif; font-size: 11px;">';
        $tableHtml .= '<thead><tr style="background-color: #f3f4f6;">';
        foreach ($headers as $h) {
            $tableHtml .= '<th style="border: 1px solid #e5e7eb; padding: 8px; text-align: left;">' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $tableHtml .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $tableHtml .= '<tr>';
            foreach ($r as $i => $cell) {
                $align = in_array($i, [3, 4]) ? 'right' : 'left';
                $tableHtml .= '<td style="border: 1px solid #e5e7eb; padding: 8px; text-align: ' . $align . ';">' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $tableHtml .= '</tr>';
        }
        $tableHtml .= '</tbody></table>';

        $html = '<h2 style="font-family: sans-serif; color: #1f2937; margin-bottom: 15px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
        $html .= $summaryHtml;
        $html .= $tableHtml;

        // Use Dompdf or mPDF if available, otherwise fallback to simple print view
        if (class_exists('Mpdf\Mpdf')) {
            $mpdf = new \Mpdf\Mpdf(['orientation' => 'L']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($filename, 'D');
        } else {
            // Very simple fallback: trigger a print dialog on a styled HTML page
            echo '<!DOCTYPE html><html><head><title>' . $title . '</title></head><body onload="window.print()">' . $html . '</body></html>';
        }
        exit;
    }

    if ($exportFormat === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo '<table border="1">';
        echo '<tbody>';
        foreach ($summaryPairs as $pair) {
            echo '<tr><td><b>' . htmlspecialchars((string) $pair[0], ENT_QUOTES, 'UTF-8') . '</b></td><td>' . htmlspecialchars((string) $pair[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        echo '<tr><td colspan="' . count($headers) . '"></td></tr>';
        echo '</tbody>';
        echo '<thead><tr>';
        foreach ($headers as $h) {
            echo '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $cell) {
                echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        exit;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($summaryPairs as $pair) {
        fputcsv($out, $pair);
    }
    fputcsv($out, []);
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

$typeConfig = [
    'easypaisa' => ['label' => 'EasyPaisa', 'icon' => 'wallet', 'color' => 'emerald'],
    'jazzcash'  => ['label' => 'JazzCash', 'icon' => 'circle-dollar-sign', 'color' => 'rose'],
    'cash'      => ['label' => 'Cash', 'icon' => 'coins', 'color' => 'amber'],
    'bank'      => ['label' => 'Bank Transfer', 'icon' => 'building-2', 'color' => 'indigo'],
];

$searchQ = trim((string) ($_GET['q'] ?? ''));
$searchFrom = (string) ($_GET['from'] ?? $currentDate);
$searchTo = (string) ($_GET['to'] ?? $currentDate);
$searchAccountType = trim((string) ($_GET['account_type'] ?? ''));
if ($searchAccountType !== '' && !array_key_exists($searchAccountType, $typeConfig)) {
    $searchAccountType = '';
}
$searchAccountId = (int) ($_GET['search_account_id'] ?? 0);
$validSearchAccountId = false;
if ($searchAccountId > 0) {
    foreach ($allAccounts as $a) {
        if ((int) ($a['id'] ?? 0) === $searchAccountId) {
            if ($searchAccountType === '' || (string) ($a['account_type'] ?? '') === $searchAccountType) {
                $validSearchAccountId = true;
            }
            break;
        }
    }
}
if (!$validSearchAccountId) {
    $searchAccountId = 0;
}

$accountsForSearch = [];
foreach ($allAccounts as $a) {
    if ($searchAccountType === '' || (string) ($a['account_type'] ?? '') === $searchAccountType) {
        $accountsForSearch[] = $a;
    }
}

$searchRows = [];
$searchTotals = ['count' => 0, 'receiving' => 0.0, 'sending' => 0.0, 'commission' => 0.0, 'net' => 0.0];
if ($tab === 'search') {
    $whereParts = [
        'wt.date >= :from',
        'wt.date <= :to',
        "wt.type IN ('receiving','sending')",
    ];
    $params = [
        ':from' => $searchFrom,
        ':to' => $searchTo,
    ];
    if ($searchAccountType !== '') {
        $whereParts[] = 'a.account_type = :account_type';
        $params[':account_type'] = $searchAccountType;
    }
    if ($searchAccountId > 0) {
        $whereParts[] = 'wt.account_id = :account_id';
        $params[':account_id'] = $searchAccountId;
    }
    if ($searchQ !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchQ) . '%';
        $whereParts[] = "(wt.customer_name LIKE :q_name ESCAPE '\\\\' OR wt.number LIKE :q_number ESCAPE '\\\\' OR wt.transaction_id LIKE :q_tx ESCAPE '\\\\')";
        $params[':q_name'] = $like;
        $params[':q_number'] = $like;
        $params[':q_tx'] = $like;
    }
    $where = 'WHERE ' . implode(' AND ', $whereParts);

    $stmt = $pdo->prepare("
        SELECT
            wt.id, wt.date, wt.created_at, wt.type, wt.amount, wt.charges, wt.customer_name, wt.number, wt.transaction_id, wt.remarks,
            a.account_name, a.account_type
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        {$where}
        ORDER BY wt.date DESC, wt.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $searchRows = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_rows,
            COALESCE(SUM(CASE WHEN wt.type='receiving' THEN wt.amount ELSE 0 END), 0) AS total_receiving,
            COALESCE(SUM(CASE WHEN wt.type='sending' THEN wt.amount ELSE 0 END), 0) AS total_sending,
            COALESCE(SUM(wt.charges), 0) AS total_commission
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        {$where}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    $searchTotals['count'] = (int) ($row['total_rows'] ?? 0);
    $searchTotals['receiving'] = (float) ($row['total_receiving'] ?? 0);
    $searchTotals['sending'] = (float) ($row['total_sending'] ?? 0);
    $searchTotals['commission'] = (float) ($row['total_commission'] ?? 0);
    $searchTotals['net'] = $searchTotals['receiving'] - $searchTotals['sending'];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$success = flash_get('success');
$error = flash_get('error');
$isOwner = app_is_owner();

$editTxnId = (int) ($_GET['edit_txn_id'] ?? 0);
$editTxn = null;
$returnParams = $_GET;
unset($returnParams['edit_txn_id']);
$returnQuery = http_build_query($returnParams);
$returnUrl = 'mobile-accounts/index.php' . ($returnQuery !== '' ? ('?' . $returnQuery) : '');
if ($editTxnId > 0 && $canEditDelete) {
    $stmt = $pdo->prepare("
        SELECT wt.*, a.account_name, a.account_type
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE wt.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $editTxnId]);
    $editTxn = $stmt->fetch() ?: null;
}
?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8 animate-slide-up stagger-1">
    <div class="flex items-center gap-3">
        <div class="h-10 w-2 bg-gradient-premium rounded-full"></div>
        <div>
            <h1 class="text-3xl font-bold text-gray-900 tracking-tight m-0">Mobile Accounts</h1>
            <p class="text-sm text-gray-500 mt-1">Manage all your digital wallets in one place</p>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a class="btn <?= $tab === 'daily' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover:bg-gray-50' ?> rounded-xl" href="?tab=daily&date=<?= h($currentDate) ?>&type=<?= h($currentType) ?>&account_id=<?= (int) $currentAccountId ?>">Daily Entry</a>
        <a class="btn <?= $tab === 'search' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover:bg-gray-50' ?> rounded-xl" href="?tab=search&from=<?= h($currentDate) ?>&to=<?= h($currentDate) ?>">Search</a>
        <?php if ($isOwner): ?>
            <a class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm hover:bg-gray-50 rounded-xl" href="<?= h(app_url('settings/accounts.php?type=' . $currentType)) ?>">
                <i data-lucide="settings" class="w-4 h-4"></i> Manage
            </a>
            <a class="btn btn-outline-primary bg-brand-50 border-0 shadow-sm hover:bg-brand-100 rounded-xl" href="<?= h(app_url('settings/accounts.php?add=1&type=' . $currentType)) ?>">
                <i data-lucide="plus" class="w-4 h-4"></i> Add Account
            </a>
        <?php endif; ?>
        <?php if ($tab === 'daily'): ?>
            <div class="dropdown inline-block">
                <button class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm hover:bg-gray-50 rounded-xl dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i data-lucide="download" class="w-4 h-4"></i> Export
                </button>
                <ul class="dropdown-menu border-0 shadow-sm rounded-xl overflow-hidden">
                    <li><a class="dropdown-item py-2" href="<?= h(app_url('mobile-accounts/index.php?export=1&format=csv&tab=daily&date=' . urlencode($currentDate) . '&type=' . urlencode($currentType) . '&account_id=' . (int) $currentAccountId)) ?>"><i data-lucide="file-text" class="w-4 h-4 inline-block mr-2 text-gray-500"></i> CSV</a></li>
                    <li><a class="dropdown-item py-2" href="<?= h(app_url('mobile-accounts/index.php?export=1&format=xls&tab=daily&date=' . urlencode($currentDate) . '&type=' . urlencode($currentType) . '&account_id=' . (int) $currentAccountId)) ?>"><i data-lucide="file-spreadsheet" class="w-4 h-4 inline-block mr-2 text-green-600"></i> Excel</a></li>
                    <li><a class="dropdown-item py-2 text-danger" href="<?= h(app_url('mobile-accounts/index.php?export=1&format=pdf&tab=daily&date=' . urlencode($currentDate) . '&type=' . urlencode($currentType) . '&account_id=' . (int) $currentAccountId)) ?>"><i data-lucide="file" class="w-4 h-4 inline-block mr-2"></i> PDF</a></li>
                </ul>
            </div>
        <?php endif; ?>
        <form method="get" class="flex items-center gap-2 bg-white/80 px-3 py-1.5 rounded-xl border border-gray-100 shadow-sm backdrop-blur-sm">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <input type="hidden" name="type" value="<?= h($currentType) ?>">
            <input type="hidden" name="account_id" value="<?= h((string)$currentAccountId) ?>">
            <label for="header_date" class="text-gray-500 text-xs font-bold uppercase tracking-wider mb-0">Date</label>
            <input type="date" id="header_date" name="date" value="<?= h($currentDate) ?>" class="form-control form-control-sm border-0 bg-transparent font-bold text-gray-900 shadow-none p-0 w-auto cursor-pointer focus:ring-0" onchange="this.form.submit()">
        </form>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-2xl mb-6 animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-2xl mb-6 animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($editTxn && $canEditDelete): ?>
    <div class="glass-card rounded-3xl p-6 mb-8 animate-slide-up relative overflow-hidden">
        <div class="absolute top-0 left-0 w-full h-1 bg-gradient-premium"></div>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900 m-0">Edit Transaction</h2>
            <a class="btn btn-outline-secondary bg-gray-50 border-0 shadow-sm rounded-xl" href="<?= h(app_url($returnUrl)) ?>">Cancel</a>
        </div>
        <form method="post" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 items-end">
            <input type="hidden" name="action" value="update_txn">
            <input type="hidden" name="txn_id" value="<?= (int) $editTxn['id'] ?>">
            <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
            <input type="hidden" name="date" value="<?= h((string) $currentDate) ?>">
            <input type="hidden" name="type" value="<?= h((string) $currentType) ?>">
            <input type="hidden" name="account_id_filter" value="<?= h((string) $currentAccountId) ?>">

            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Date</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="date" name="txn_date" value="<?= h((string) $editTxn['date']) ?>" required>
            </div>
            <div class="md:col-span-2">
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Account</label>
                <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" name="txn_account_id" required>
                    <?php foreach ($allAccounts as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === (int) $editTxn['account_id'] ? 'selected' : '' ?>>
                            <?= h((string) $a['account_name']) ?> (<?= h((string) $a['account_type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Type</label>
                <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" name="txn_type" required>
                    <option value="opening" <?= (string) $editTxn['type'] === 'opening' ? 'selected' : '' ?>>Opening</option>
                    <option value="receiving" <?= (string) $editTxn['type'] === 'receiving' ? 'selected' : '' ?>>Receiving</option>
                    <option value="sending" <?= (string) $editTxn['type'] === 'sending' ? 'selected' : '' ?>>Sending</option>
                </select>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Amount</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="number" step="0.01" name="txn_amount" value="<?= h((string) $editTxn['amount']) ?>" required>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Commission</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="number" step="0.01" name="txn_charges" value="<?= h((string) ($editTxn['charges'] ?? 0)) ?>">
            </div>
            <div class="md:col-span-2">
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Customer Name</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="text" name="txn_customer_name" value="<?= h((string) ($editTxn['customer_name'] ?? '')) ?>">
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Number</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="text" name="txn_number" value="<?= h((string) ($editTxn['number'] ?? '')) ?>">
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Transaction ID</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="text" name="txn_transaction_id" value="<?= h((string) ($editTxn['transaction_id'] ?? '')) ?>">
            </div>
            <div class="md:col-span-2">
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Note</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="text" name="txn_remarks" value="<?= h((string) ($editTxn['remarks'] ?? '')) ?>">
            </div>
            <div class="md:col-span-4 mt-2">
                <button class="btn btn-gradient rounded-xl px-8 shadow-md hover:shadow-lg w-full md:w-auto">Save Changes</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'search'): ?>
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h6 fw-bold text-gray-800 mb-0">Search Transactions</h2>
            <div class="text-muted small">Search by customer name, number or transaction id</div>
        </div>
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="search">
            <div class="col-12 col-md-4">
                <label class="form-label">Search</label>
                <input class="form-control" name="q" value="<?= h($searchQ) ?>" placeholder="Search name, number or tx id">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">From</label>
                <input class="form-control" type="date" name="from" value="<?= h($searchFrom) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">To</label>
                <input class="form-control" type="date" name="to" value="<?= h($searchTo) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Account Type</label>
                <select class="form-select" name="account_type">
                    <option value="">All</option>
                    <?php foreach ($typeConfig as $k => $conf): ?>
                        <option value="<?= h($k) ?>" <?= $searchAccountType === $k ? 'selected' : '' ?>><?= h($conf['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Account</label>
                <select class="form-select" name="search_account_id">
                    <option value="0">All</option>
                    <?php foreach ($accountsForSearch as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $searchAccountId ? 'selected' : '' ?>>
                            <?= h((string) $a['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-gradient shadow-glow">Search</button>
                <a class="btn btn-outline-secondary" href="?tab=search&from=<?= h($currentDate) ?>&to=<?= h($currentDate) ?>">Clear</a>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Found</div>
                    <div class="h5 mb-0"><?= h((string) $searchTotals['count']) ?> transactions</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Receiving</div>
                    <div class="h5 mb-0" style="color:#16a34a;font-weight:600;">Rs <?= h(number_format((float) $searchTotals['receiving'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Sending</div>
                    <div class="h5 mb-0" style="color:#dc2626;font-weight:600;">Rs <?= h(number_format((float) $searchTotals['sending'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Commission</div>
                    <div class="h5 mb-0">Rs <?= h(number_format((float) $searchTotals['commission'], 2)) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm">
        <div class="p-4 border-b border-gray-100">
            <div class="fw-semibold text-gray-800">Transactions</div>
            <div class="text-muted small">Showing up to 500 records</div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Commission</th>
                    <th>Customer</th>
                    <th>Number</th>
                    <th>Transaction ID</th>
                    <th>Note</th>
                    <?php if ($canEditDelete): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($searchRows as $r): ?>
                    <?php
                    $amountStyle = '';
                    if (($r['type'] ?? '') === 'receiving') {
                        $amountStyle = 'color:#16a34a;font-weight:600;';
                    } elseif (($r['type'] ?? '') === 'sending') {
                        $amountStyle = 'color:#dc2626;font-weight:600;';
                    }
                    ?>
                    <tr>
                        <td><?= h((string) ($r['date'] ?? '')) ?></td>
                        <td><?= h((string) ($r['account_name'] ?? '')) ?></td>
                        <td><?= h((string) ($r['type'] ?? '')) ?></td>
                        <td class="text-end" style="<?= h($amountStyle) ?>"><?= h(number_format((float) ($r['amount'] ?? 0), 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) ($r['charges'] ?? 0), 2)) ?></td>
                        <td><?= h((string) ($r['customer_name'] ?? '')) ?></td>
                        <td><?= h((string) ($r['number'] ?? '')) ?></td>
                        <td><?= h((string) ($r['transaction_id'] ?? '')) ?></td>
                        <td><?= h((string) ($r['remarks'] ?? '')) ?></td>
                        <?php if ($canEditDelete): ?>
                            <?php
                            $editParams = $_GET;
                            $editParams['edit_txn_id'] = (int) ($r['id'] ?? 0);
                            $editQuery = http_build_query($editParams);
                            $editUrl = 'mobile-accounts/index.php' . ($editQuery !== '' ? ('?' . $editQuery) : '');
                            ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url($editUrl)) ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this transaction?');">
                                    <input type="hidden" name="action" value="delete_txn">
                                    <input type="hidden" name="txn_id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                    <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
                                    <input type="hidden" name="date" value="<?= h((string) $currentDate) ?>">
                                    <input type="hidden" name="type" value="<?= h((string) $currentType) ?>">
                                    <input type="hidden" name="account_id_filter" value="<?= h((string) $currentAccountId) ?>">
                                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$searchRows): ?>
                    <tr>
                        <td colspan="<?= h((string) (9 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-4">No transactions found for your filters.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
    <?php return; ?>
<?php endif; ?>

<!-- Main Section: Select Account & Totals -->
<div class="glass-card rounded-3xl p-6 md:p-8 mb-8 animate-slide-up stagger-2 relative overflow-hidden">
    <div class="absolute right-0 top-0 w-96 h-96 bg-brand-50 rounded-full blur-[80px] -z-10 translate-x-1/2 -translate-y-1/2"></div>
    <div class="row g-6">
        <!-- Tabs Side -->
        <div class="col-12 col-xl-7">
            <h2 class="text-sm font-bold text-gray-500 uppercase tracking-widest mb-4">Select Account</h2>
            
            <!-- Main Types -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <?php foreach (['easypaisa', 'jazzcash', 'cash', 'bank'] as $t): 
                    $conf = $typeConfig[$t];
                    $isActive = $currentType === $t;
                    $cName = $conf['color'];
                    $baseClass = "flex flex-col items-center justify-center p-4 rounded-2xl border transition-all duration-300 no-underline shadow-sm gap-2 relative overflow-hidden group";
                    $activeClass = "bg-{$cName}-50 border-{$cName}-300 ring-2 ring-{$cName}-500/20 shadow-md";
                    $inactiveClass = "bg-white border-gray-100 hover:bg-gray-50 hover:border-gray-200 hover:-translate-y-1 hover:shadow-md";
                ?>
                    <a href="?tab=<?= h($tab) ?>&date=<?= h($currentDate) ?>&type=<?= $t ?>&account_id=0" class="<?= $baseClass ?> <?= $isActive ? $activeClass : $inactiveClass ?>">
                        <?php if ($isActive): ?>
                            <div class="absolute inset-0 bg-gradient-to-br from-<?= $cName ?>-500/5 to-transparent pointer-events-none"></div>
                        <?php endif; ?>
                        <div class="w-12 h-12 rounded-full flex items-center justify-center <?= $isActive ? "bg-{$cName}-100 text-{$cName}-600 shadow-inner" : "bg-gray-100 text-gray-500 group-hover:bg-{$cName}-50 group-hover:text-{$cName}-500 transition-colors" ?>">
                            <i data-lucide="<?= h($conf['icon']) ?>" class="w-6 h-6"></i>
                        </div>
                        <div class="font-bold text-sm text-center <?= $isActive ? "text-{$cName}-700" : "text-gray-700" ?>">
                            <?= $conf['label'] ?>
                        </div>
                        <?php if ($counts[$t] > 0): ?>
                            <span class="absolute top-2 right-2 flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full text-[10px] font-bold <?= $isActive ? "bg-{$cName}-500 text-white shadow-sm" : "bg-gray-200 text-gray-600" ?>">
                                <?= $counts[$t] ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Sub Accounts -->
            <?php if (!empty($accountsByType[$currentType])): ?>
                <div class="flex flex-wrap gap-2 p-4 bg-gray-50/50 rounded-2xl border border-gray-100">
                    <?php 
                    $cName = $typeConfig[$currentType]['color'];
                    $baseClass = "inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold border transition-all duration-300 no-underline";
                    $activeClass = "bg-white border-{$cName}-200 text-{$cName}-700 shadow-sm ring-1 ring-{$cName}-500/10";
                    $inactiveClass = "bg-transparent border-transparent text-gray-500 hover:bg-white hover:border-gray-200 hover:shadow-sm";
                    ?>
                    <a href="?tab=<?= h($tab) ?>&date=<?= h($currentDate) ?>&type=<?= h($currentType) ?>&account_id=0" class="<?= $baseClass ?> <?= $currentAccountId === 0 ? $activeClass : $inactiveClass ?>">
                        All <?= $typeConfig[$currentType]['label'] ?>
                    </a>
                    <?php foreach ($accountsByType[$currentType] as $a): 
                        $isActive = $currentAccountId === (int)$a['id'];
                    ?>
                        <a href="?tab=<?= h($tab) ?>&date=<?= h($currentDate) ?>&type=<?= h($currentType) ?>&account_id=<?= $a['id'] ?>" class="<?= $baseClass ?> <?= $isActive ? $activeClass : $inactiveClass ?>">
                            <span class="w-2 h-2 rounded-full <?= $isActive ? "bg-{$cName}-500 shadow-[0_0_8px_rgba(var(--color-{$cName}-500),0.6)]" : "bg-gray-300" ?>"></span>
                            <?= h($a['account_name']) ?>
                            <span class="text-gray-400 font-medium ml-1"><?= h((string)$a['account_number']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-4 bg-gray-50 rounded-2xl border border-gray-100 text-center text-sm text-gray-500 flex items-center justify-center gap-2">
                    <i data-lucide="inbox" class="w-4 h-4"></i> No accounts added for this type.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Totals Side -->
        <div class="col-12 col-xl-5">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white/60 p-4 rounded-2xl border border-white shadow-sm hover:shadow-md transition-shadow group">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Opening (Today)</div>
                    <div class="text-xl font-extrabold text-gray-900">Rs. <?= number_format($totalOpening, 2) ?></div>
                </div>
                <div class="bg-emerald-50/50 p-4 rounded-2xl border border-emerald-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="text-xs font-bold text-emerald-700 uppercase tracking-wider mb-2">Total Received</div>
                    <div class="text-xl font-extrabold text-emerald-600">+Rs. <?= number_format($totalReceived, 2) ?></div>
                </div>
                <div class="bg-rose-50/50 p-4 rounded-2xl border border-rose-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="text-xs font-bold text-rose-700 uppercase tracking-wider mb-2">Total Sent</div>
                    <div class="text-xl font-extrabold text-rose-600">-Rs. <?= number_format($totalSent, 2) ?></div>
                </div>
                <div class="bg-white/60 p-4 rounded-2xl border border-white shadow-sm hover:shadow-md transition-shadow group">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Commission</div>
                    <div class="text-xl font-extrabold text-gray-900">Rs. <?= number_format($totalCommission, 2) ?></div>
                </div>
                <div class="col-span-2 bg-gradient-to-r from-brand-50 to-blue-50 p-5 rounded-2xl border border-brand-100 shadow-sm relative overflow-hidden group">
                    <div class="absolute right-0 top-0 w-32 h-32 bg-white/40 rounded-full blur-[20px] -z-10 translate-x-1/2 -translate-y-1/2"></div>
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-bold text-brand-700 uppercase tracking-wider mb-2">Closing Balance</div>
                            <div class="text-3xl font-extrabold text-brand-600 tracking-tight">Rs. <?= number_format($totalClosing, 2) ?></div>
                        </div>
                        <div class="p-3 bg-white/60 rounded-xl text-brand-500 shadow-sm">
                            <i data-lucide="calculator" class="w-6 h-6"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Opening Balance Form -->
<?php if (!empty($accountsByType[$currentType])): ?>
<div class="glass-card rounded-3xl mb-8 animate-slide-up stagger-3 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-brand-400"></div>
    <div class="p-6 border-b border-gray-100 bg-white/40 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="p-2 bg-brand-50 text-brand-600 rounded-lg"><i data-lucide="log-in" class="w-5 h-5"></i></div>
            <div>
                <h3 class="text-lg font-bold text-gray-900 m-0">Opening Balance</h3>
                <p class="text-xs text-gray-500 mt-1">Set once per day. Closing balance is auto-calculated.</p>
            </div>
        </div>
    </div>
    <div class="p-6">
        <form method="post" action="">
            <input type="hidden" name="action" value="save_opening">
            <input type="hidden" name="type" value="<?= h($currentType) ?>">
            <input type="hidden" name="account_id" value="<?= h((string)$currentAccountId) ?>">
            
            <div class="flex flex-wrap gap-4 items-end">
                <div class="w-full sm:w-48">
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Select Date</label>
                    <input type="date" name="date" value="<?= h($currentDate) ?>" class="form-control bg-gray-50 border-0 shadow-sm rounded-xl">
                </div>
                
                <?php foreach ($accountsByType[$currentType] as $a): 
                    if ($currentAccountId > 0 && (int)$a['id'] !== $currentAccountId) continue;
                    $val = isset($openingBalances[$a['id']]) ? $openingBalances[$a['id']] : '';
                ?>
                <div class="flex-1 min-w-[200px]">
                    <label class="form-label text-xs uppercase tracking-wider text-<?= $typeConfig[$currentType]['color'] ?>-600 font-bold mb-1"><?= h($a['account_name']) ?></label>
                    <input type="number" step="0.01" name="balances[<?= $a['id'] ?>]" value="<?= h((string)$val) ?>" class="form-control bg-white border border-gray-200 shadow-sm rounded-xl" placeholder="0.00">
                </div>
                <?php endforeach; ?>
                
                <div class="w-full sm:w-auto">
                    <button type="submit" class="btn btn-gradient rounded-xl px-6 shadow-md w-full sm:w-auto flex justify-center"><i data-lucide="check" class="w-4 h-4 mr-2"></i> Save Balances</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Add Entry Form -->
<div class="glass-card rounded-3xl mb-8 animate-slide-up stagger-4 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-emerald-400"></div>
    <div class="p-6 border-b border-gray-100 bg-white/40 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="p-2 bg-emerald-50 text-emerald-600 rounded-lg"><i data-lucide="plus-circle" class="w-5 h-5"></i></div>
            <div>
                <h3 class="text-lg font-bold text-gray-900 m-0">Add New Entry</h3>
                <p class="text-xs text-gray-500 mt-1">Record receiving or sending transactions</p>
            </div>
        </div>
    </div>
    <div class="p-6">
        <form method="post" action="" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-5">
            <input type="hidden" name="action" value="add_entry">
            <input type="hidden" name="type" value="<?= h($currentType) ?>">
            <input type="hidden" name="account_id_filter" value="<?= h((string)$currentAccountId) ?>">
            
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Date</label>
                <input type="date" name="date" value="<?= h($currentDate) ?>" class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" required>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Account</label>
                <select name="account_id" class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" required>
                    <?php foreach ($accountsByType[$currentType] as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $currentAccountId === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['account_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Type</label>
                <select name="entry_type" class="form-select bg-emerald-50 border-0 shadow-sm rounded-xl text-emerald-700 font-bold" required>
                    <option value="receiving">Receiving (Money In)</option>
                    <option value="sending">Sending (Money Out)</option>
                </select>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Amount (Rs)</label>
                <input type="number" step="0.01" name="amount" class="form-control bg-white border border-gray-200 shadow-sm rounded-xl text-lg font-bold text-gray-900" required placeholder="0.00">
            </div>
            
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Commission (Rs)</label>
                <input type="number" step="0.01" name="charges" class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" placeholder="0.00">
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Saved Customer</label>
                <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="saved_customer_select">
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($savedCustomers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-name="<?= h((string) $c['name']) ?>" data-phone="<?= h((string) $c['phone']) ?>">
                            <?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2 flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-lg flex-1 text-xs" id="btn_show_add_customer">Add New</button>
                    <a class="btn btn-outline-secondary btn-sm rounded-lg flex-1 text-xs" href="<?= h(app_url('settings/customers.php')) ?>">View All</a>
                </div>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Customer Name</label>
                <input type="text" name="customer_name" class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" placeholder="Ali Khan">
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Phone Number <span class="text-danger">*</span></label>
                <input type="text" name="number" class="form-control bg-gray-50 border-0 shadow-sm rounded-xl font-medium tracking-wide" placeholder="03xx-xxxxxxx">
            </div>
            
            <div class="md:col-span-2">
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Transaction ID</label>
                <input type="text" name="transaction_id" class="form-control bg-gray-50 border-0 shadow-sm rounded-xl font-mono text-sm" placeholder="TXN123456789">
            </div>
            <div class="md:col-span-2">
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Note (optional)</label>
                <input type="text" name="remarks" class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" placeholder="Any additional details...">
            </div>
            
            <div class="md:col-span-4 mt-2">
                <button type="submit" class="btn btn-gradient rounded-xl px-8 py-3 shadow-md hover:shadow-lg w-full md:w-auto text-base font-bold"><i data-lucide="plus" class="w-5 h-5 mr-2"></i> Save Entry</button>
            </div>
        </form>
    </div>
</div>

<div class="glass-card rounded-3xl p-6 mb-8 animate-slide-up shadow-sm hidden relative overflow-hidden" id="add_customer_panel">
    <div class="absolute top-0 left-0 w-1 h-full bg-blue-400"></div>
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <div class="p-2 bg-blue-50 text-blue-600 rounded-lg"><i data-lucide="user-plus" class="w-5 h-5"></i></div>
            <h3 class="text-lg font-bold text-gray-900 m-0">Add Customer</h3>
        </div>
        <button type="button" class="btn btn-outline-secondary bg-gray-50 border-0 shadow-sm rounded-xl" id="btn_hide_add_customer">Close</button>
    </div>
    <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
        <input type="hidden" name="action" value="save_customer">
        <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <input type="hidden" name="date" value="<?= h((string) $currentDate) ?>">
        <input type="hidden" name="type" value="<?= h((string) $currentType) ?>">
        <input type="hidden" name="account_id_filter" value="<?= h((string) $currentAccountId) ?>">
        
        <div>
            <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Customer Name</label>
            <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="text" name="customer_name" id="new_customer_name" required>
        </div>
        <div>
            <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1">Phone</label>
            <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="text" name="customer_phone" id="new_customer_phone" required>
        </div>
        <div>
            <button class="btn btn-gradient rounded-xl px-6 shadow-md hover:shadow-lg w-full">Save Customer</button>
        </div>
    </form>
</div>

<script>
    (function () {
        const sel = document.getElementById('saved_customer_select');
        const btnShow = document.getElementById('btn_show_add_customer');
        const btnHide = document.getElementById('btn_hide_add_customer');
        const panel = document.getElementById('add_customer_panel');
        const newName = document.getElementById('new_customer_name');
        const newPhone = document.getElementById('new_customer_phone');

        if (!sel) return;
        const form = sel.closest('form');
        if (!form) return;
        const nameInput = form.querySelector('input[name="customer_name"]');
        const phoneInput = form.querySelector('input[name="number"]');

        sel.addEventListener('change', () => {
            const opt = sel.options[sel.selectedIndex];
            const n = opt ? (opt.getAttribute('data-name') || '') : '';
            const p = opt ? (opt.getAttribute('data-phone') || '') : '';
            if (nameInput && n) nameInput.value = n;
            if (phoneInput && p) phoneInput.value = p;
        });

        if (btnShow && panel) {
            btnShow.addEventListener('click', () => {
                panel.classList.remove('d-none');
                if (newName && nameInput && nameInput.value) newName.value = nameInput.value;
                if (newPhone && phoneInput && phoneInput.value) newPhone.value = phoneInput.value;
            });
        }
        if (btnHide && panel) {
            btnHide.addEventListener('click', () => {
                panel.classList.add('d-none');
            });
        }
    })();
</script>

<!-- Bottom Section: Table & Summary -->
<div class="row g-4">
    <!-- Table -->
    <div class="col-12 col-lg-9">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm h-100 flex flex-col">
            <div class="p-4 border-b border-gray-100">
                <h3 class="h6 fw-bold text-gray-800 mb-0">All Transactions <span class="text-muted fw-normal fs-6">(Today)</span></h3>
            </div>
            <div class="flex-1 overflow-auto">
                <table class="table table-borderless table-hover align-middle mb-0 text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="py-3 px-4 font-semibold">#</th>
                            <th class="py-3 px-4 font-semibold">Date</th>
                            <th class="py-3 px-4 font-semibold">Account</th>
                            <th class="py-3 px-4 font-semibold">Type</th>
                            <th class="py-3 px-4 font-semibold text-end">Amount (Rs)</th>
                            <th class="py-3 px-4 font-semibold text-end">Commission</th>
                            <th class="py-3 px-4 font-semibold">Customer</th>
                            <th class="py-3 px-4 font-semibold">Transaction ID</th>
                            <th class="py-3 px-4 font-semibold">Note</th>
                            <?php if ($canEditDelete): ?>
                                <th class="py-3 px-4 font-semibold text-end">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php $idx = 1; foreach ($transactions as $t): 
                            $isRec = $t['type'] === 'receiving';
                            $cName = $typeConfig[$t['account_type']]['color'] ?? 'gray';
                        ?>
                        <tr>
                            <td class="py-3 px-4 text-gray-500"><?= $idx++ ?></td>
                            <td class="py-3 px-4 text-gray-700"><?= date('m/d/Y h:i A', strtotime($t['created_at'])) ?></td>
                            <td class="py-3 px-4 fw-medium text-<?= $cName ?>-600"><?= h($t['account_name']) ?></td>
                            <td class="py-3 px-4">
                                <?php if ($isRec): ?>
                                    <span class="d-inline-block px-2 py-1 rounded-pill bg-emerald-50 text-emerald-600 border border-emerald-100 text-xs fw-medium">Receiving</span>
                                <?php else: ?>
                                    <span class="d-inline-block px-2 py-1 rounded-pill bg-rose-50 text-rose-600 border border-rose-100 text-xs fw-medium">Sending</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-end fw-bold <?= $isRec ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $isRec ? '+' : '-' ?><?= number_format((float)$t['amount'], 2) ?>
                            </td>
                            <td class="py-3 px-4 text-end fw-bold text-emerald-600">
                                <?= number_format((float) ($t['charges'] ?? 0), 2) ?>
                            </td>
                            <td class="py-3 px-4 text-gray-700"><?= h((string)$t['customer_name']) ?></td>
                            <td class="py-3 px-4 text-gray-700"><?= h((string)$t['transaction_id']) ?></td>
                            <td class="py-3 px-4 text-gray-500"><?= h((string)$t['remarks']) ?: '-' ?></td>
                            <?php if ($canEditDelete): ?>
                                <?php
                                $editParams = $_GET;
                                $editParams['edit_txn_id'] = (int) ($t['id'] ?? 0);
                                $editQuery = http_build_query($editParams);
                                $editUrl = 'mobile-accounts/index.php' . ($editQuery !== '' ? ('?' . $editQuery) : '');
                                ?>
                                <td class="py-3 px-4 text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url($editUrl)) ?>">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this transaction?');">
                                        <input type="hidden" name="action" value="delete_txn">
                                        <input type="hidden" name="txn_id" value="<?= (int) ($t['id'] ?? 0) ?>">
                                        <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
                                        <input type="hidden" name="date" value="<?= h((string) $currentDate) ?>">
                                        <input type="hidden" name="type" value="<?= h((string) $currentType) ?>">
                                        <input type="hidden" name="account_id_filter" value="<?= h((string) $currentAccountId) ?>">
                                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="<?= h((string) (9 + ($canEditDelete ? 1 : 0))) ?>" class="py-5 text-center text-gray-500">No transactions found for this date.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 bg-brand-50/50 border-t border-brand-100 rounded-b-2xl text-[11px] text-brand-600 flex items-center gap-2">
                <i data-lucide="info" class="w-3 h-3"></i> Note: Records from selected accounts are shown in one list for easy tracking and reporting.
            </div>
        </div>
    </div>
    
    <!-- Summary Sidebar -->
    <div class="col-12 col-lg-3">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 h-100 flex flex-col">
            <h3 class="h6 fw-bold text-gray-800 mb-4">Summary <span class="text-muted fw-normal fs-6">(Auto)</span></h3>
            
            <div class="space-y-4 flex-1">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-sm text-gray-500 fw-medium">Opening Balance (Total)</span>
                    <span class="text-sm text-gray-900 fw-bold">Rs. <?= number_format($totalOpening, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-sm text-gray-500 fw-medium">Total Received</span>
                    <span class="text-sm text-emerald-600 fw-bold">+Rs. <?= number_format($totalReceived, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-sm text-gray-500 fw-medium">Total Sent</span>
                    <span class="text-sm text-rose-600 fw-bold">-Rs. <?= number_format($totalSent, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-sm text-gray-500 fw-medium">Commission</span>
                    <span class="text-sm text-emerald-600 fw-bold">Rs. <?= number_format($totalCommission, 2) ?></span>
                </div>
                
                <hr class="border-gray-100 my-4">
                
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-sm text-gray-900 fw-bold">Closing Balance (Auto)</span>
                    <span class="fs-5 text-brand-600 fw-bold">Rs. <?= number_format($totalClosing, 2) ?></span>
                </div>
                <div class="text-[10px] text-gray-400 d-flex align-items-center gap-1"><i data-lucide="refresh-cw" class="w-3 h-3"></i> Auto calculated</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
