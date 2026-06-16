<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Mobile Accounts - Shop Management';
$pdo = db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $date = $_POST['date'] ?? date('Y-m-d');
    $type = $_POST['type'] ?? 'easypaisa';
    $accountFilter = (int) ($_POST['account_id'] ?? ($_POST['account_id_filter'] ?? 0));
    $redirectUrl = "mobile-accounts/index.php?date={$date}&type={$type}&account_id={$accountFilter}";

    if ($action === 'save_opening') {
        $balances = $_POST['balances'] ?? [];
        foreach ($balances as $accId => $amount) {
            if (trim((string)$amount) !== '') {
                $amt = (float)$amount;
                $accId = (int)$accId;
                
                $stmt = $pdo->prepare("SELECT id FROM wallet_transactions WHERE account_id=? AND date=? AND type='opening'");
                $stmt->execute([$accId, $date]);
                $exists = $stmt->fetchColumn();
                
                if ($exists) {
                    $pdo->prepare("UPDATE wallet_transactions SET amount=? WHERE id=?")->execute([$amt, $exists]);
                } else {
                    $pdo->prepare("INSERT INTO wallet_transactions (account_id, date, type, amount) VALUES (?, ?, 'opening', ?)")->execute([$accId, $date, $amt]);
                }
            }
        }
        flash_set('success', 'Opening balances saved successfully.');
        
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
            $stmt = $pdo->prepare("INSERT INTO wallet_transactions (account_id, date, type, amount, charges, customer_name, number, transaction_id, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $accountId, $date, $entryType, $amount, $charges,
                $customerName !== '' ? $customerName : null,
                $number !== '' ? $number : null,
                $transactionId !== '' ? $transactionId : null,
                $remarks !== '' ? $remarks : null
            ]);
            flash_set('success', 'Transaction added successfully.');
        } else {
            flash_set('error', 'Invalid account or amount.');
        }
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
        SELECT 
            COALESCE(SUM(CASE 
                WHEN date < ? AND type IN ('opening', 'receiving') THEN amount
                WHEN date < ? AND type = 'sending' THEN -amount
                WHEN date = ? AND type = 'opening' THEN amount
                ELSE 0 
            END), 0) AS opening,
            COALESCE(SUM(CASE WHEN date = ? AND type = 'receiving' THEN amount ELSE 0 END), 0) AS received,
            COALESCE(SUM(CASE WHEN date = ? AND type = 'sending' THEN amount ELSE 0 END), 0) AS sent
        FROM wallet_transactions 
        WHERE account_id = ?
    ");
    $stmt->execute([$date, $date, $date, $date, $date, $accountId]);
    $row = $stmt->fetch();
    
    $opening = (float)($row['opening'] ?? 0);
    $received = (float)($row['received'] ?? 0);
    $sent = (float)($row['sent'] ?? 0);
    
    return [
        'opening' => $opening,
        'received' => $received,
        'sent' => $sent,
        'closing' => $opening + $received - $sent
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
?>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h4 fw-bold mb-0 text-gray-800 d-flex align-items-center gap-2">
            <i data-lucide="wallet-cards" class="w-6 h-6 text-gray-500"></i> Mobile Accounts
        </h1>
        <div class="text-muted small mt-1">All transactions from both accounts are shown in one record</div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary btn-sm <?= $tab === 'daily' ? 'active' : '' ?>" href="?tab=daily&date=<?= h($currentDate) ?>&type=<?= h($currentType) ?>&account_id=<?= (int) $currentAccountId ?>">Daily Entry</a>
        <a class="btn btn-outline-secondary btn-sm <?= $tab === 'search' ? 'active' : '' ?>" href="?tab=search&from=<?= h($currentDate) ?>&to=<?= h($currentDate) ?>">Search</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/accounts.php?type=' . $currentType)) ?>">Manage Accounts</a>
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/accounts.php?add=1&type=' . $currentType)) ?>">Add Account</a>
        <?php if ($tab === 'daily'): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('mobile-accounts/index.php?export=1&format=csv&tab=daily&date=' . urlencode($currentDate) . '&type=' . urlencode($currentType) . '&account_id=' . (int) $currentAccountId)) ?>">Export CSV</a>
            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('mobile-accounts/index.php?export=1&format=xls&tab=daily&date=' . urlencode($currentDate) . '&type=' . urlencode($currentType) . '&account_id=' . (int) $currentAccountId)) ?>">Export Excel</a>
            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('mobile-accounts/index.php?export=1&format=pdf&tab=daily&date=' . urlencode($currentDate) . '&type=' . urlencode($currentType) . '&account_id=' . (int) $currentAccountId)) ?>">Export PDF</a>
        <?php endif; ?>
        <form method="get" class="d-flex align-items-center gap-2 bg-white px-3 py-2 rounded-lg border border-gray-200 shadow-sm">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <input type="hidden" name="type" value="<?= h($currentType) ?>">
            <input type="hidden" name="account_id" value="<?= h((string)$currentAccountId) ?>">
            <label for="header_date" class="text-gray-500 small mb-0 fw-medium">Date</label>
            <input type="date" id="header_date" name="date" value="<?= h($currentDate) ?>" class="form-control form-control-sm border-0 bg-transparent fw-bold text-gray-800 shadow-none p-0 w-auto" onchange="this.form.submit()">
        </form>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-xl mb-4"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-xl mb-4"><?= h($error) ?></div>
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
                <button class="btn btn-primary">Search</button>
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
                    </tr>
                <?php endforeach; ?>
                <?php if (!$searchRows): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No transactions found for your filters.</td>
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
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 p-md-5 mb-4">
    <div class="row g-4">
        <!-- Tabs Side -->
        <div class="col-12 col-xl-7">
            <h2 class="h6 fw-bold text-gray-800 mb-3">Select Account</h2>
            
            <!-- Main Types -->
            <div class="d-flex flex-wrap gap-2 mb-4">
                <?php foreach (['easypaisa', 'jazzcash', 'cash', 'bank'] as $t): 
                    $conf = $typeConfig[$t];
                    $isActive = $currentType === $t;
                    $cName = $conf['color'];
                    $baseClass = "d-inline-flex align-items-center gap-2 px-4 py-2 rounded-pill text-sm fw-medium border transition-colors text-decoration-none";
                    $activeClass = "bg-{$cName}-50 border-{$cName}-200 text-{$cName}-700";
                    $inactiveClass = "bg-white border-gray-200 text-gray-600 hover:bg-gray-50";
                ?>
                    <a href="?tab=<?= h($tab) ?>&date=<?= h($currentDate) ?>&type=<?= $t ?>&account_id=0" class="<?= $baseClass ?> <?= $isActive ? $activeClass : $inactiveClass ?>">
                        <span class="w-2 h-2 rounded-circle <?= $isActive ? "bg-{$cName}-500" : "bg-gray-300" ?>"></span>
                        <?= $conf['label'] ?> <?= $counts[$t] > 0 ? "({$counts[$t]})" : '' ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Sub Accounts -->
            <?php if (!empty($accountsByType[$currentType])): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php 
                    $cName = $typeConfig[$currentType]['color'];
                    $baseClass = "d-inline-flex align-items-center gap-2 px-3 py-1.5 rounded-pill text-xs fw-medium border transition-colors text-decoration-none";
                    $activeClass = "bg-{$cName}-50 border-{$cName}-200 text-{$cName}-700";
                    $inactiveClass = "bg-white border-gray-100 text-gray-500 hover:bg-gray-50";
                    ?>
                    <a href="?tab=<?= h($tab) ?>&date=<?= h($currentDate) ?>&type=<?= h($currentType) ?>&account_id=0" class="<?= $baseClass ?> <?= $currentAccountId === 0 ? $activeClass : $inactiveClass ?>">
                        All <?= $typeConfig[$currentType]['label'] ?>
                    </a>
                    <?php foreach ($accountsByType[$currentType] as $a): 
                        $isActive = $currentAccountId === (int)$a['id'];
                    ?>
                        <a href="?tab=<?= h($tab) ?>&date=<?= h($currentDate) ?>&type=<?= h($currentType) ?>&account_id=<?= $a['id'] ?>" class="<?= $baseClass ?> <?= $isActive ? $activeClass : $inactiveClass ?>">
                            <span class="w-1.5 h-1.5 rounded-circle <?= $isActive ? "bg-{$cName}-500" : "bg-gray-300" ?>"></span>
                            <?= h($a['account_name']) ?>
                            <span class="text-muted fw-normal ms-1"><?= h((string)$a['account_number']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-muted small">No accounts added for this type.</div>
            <?php endif; ?>
        </div>
        
        <!-- Totals Side -->
        <div class="col-12 col-xl-5">
            <div class="row g-3">
                <div class="col-6 col-sm-3">
                    <div class="text-xs fw-semibold text-gray-500 mb-1">Opening Balance (Today)</div>
                    <div class="fs-5 fw-bold text-gray-900">Rs. <?= number_format($totalOpening, 2) ?></div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="text-xs fw-semibold text-gray-500 mb-1">Total Received</div>
                    <div class="fs-5 fw-bold text-emerald-600">Rs. <?= number_format($totalReceived, 2) ?></div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="text-xs fw-semibold text-gray-500 mb-1">Total Sent</div>
                    <div class="fs-5 fw-bold text-rose-600">Rs. <?= number_format($totalSent, 2) ?></div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="text-xs fw-semibold text-gray-500 mb-1">Commission</div>
                    <div class="fs-5 fw-bold text-emerald-600">Rs. <?= number_format($totalCommission, 2) ?></div>
                </div>
                <div class="col-6 col-sm-3 border-start ps-3">
                    <div class="text-xs fw-semibold text-gray-500 mb-1">Closing Balance (Auto)</div>
                    <div class="fs-4 fw-bold text-brand-600">Rs. <?= number_format($totalClosing, 2) ?></div>
                    <div class="text-[10px] text-gray-400 mt-1 d-flex align-items-center gap-1"><i data-lucide="refresh-cw" class="w-3 h-3"></i> Auto calculated</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Opening Balance Form -->
<?php if (!empty($accountsByType[$currentType])): ?>
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 mb-4">
    <h3 class="h6 fw-bold text-gray-800 flex items-center gap-2 mb-3">
        <i data-lucide="plus" class="w-4 h-4 text-brand-500"></i> Opening Balance <span class="text-muted fw-normal fs-6">(Set once per day)</span>
    </h3>
    <form method="post" action="">
        <input type="hidden" name="action" value="save_opening">
        <input type="hidden" name="type" value="<?= h($currentType) ?>">
        <input type="hidden" name="account_id" value="<?= h((string)$currentAccountId) ?>">
        
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Select Date</label>
                <input type="date" name="date" value="<?= h($currentDate) ?>" class="form-control form-control-sm bg-gray-50 border-gray-200 rounded-lg">
            </div>
            
            <?php foreach ($accountsByType[$currentType] as $a): 
                // If specific account selected, only show that one, else show all in type
                if ($currentAccountId > 0 && (int)$a['id'] !== $currentAccountId) continue;
                $val = isset($openingBalances[$a['id']]) ? $openingBalances[$a['id']] : '';
            ?>
            <div class="col-12 col-md-auto flex-grow-1">
                <label class="form-label text-xs fw-semibold text-<?= $typeConfig[$currentType]['color'] ?>-600 mb-1"><?= h($a['account_name']) ?></label>
                <input type="number" step="0.01" name="balances[<?= $a['id'] ?>]" value="<?= h((string)$val) ?>" class="form-control form-control-sm border-gray-200 rounded-lg" placeholder="0.00">
            </div>
            <?php endforeach; ?>
            
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm rounded-lg px-4 fw-medium bg-brand-600 border-brand-600 hover:bg-brand-700 w-100"><i data-lucide="check" class="w-4 h-4 d-inline-block me-1"></i> Save Opening Balance</button>
            </div>
        </div>
        <div class="text-[11px] text-gray-400 mt-2">Opening balance is saved per account per day. Closing balance will be calculated automatically at the end of the day.</div>
    </form>
</div>
<?php endif; ?>

<!-- Add Entry Form -->
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 mb-4">
    <h3 class="h6 fw-bold text-gray-800 flex items-center gap-2 mb-3">
        <i data-lucide="plus" class="w-4 h-4 text-brand-500"></i> Add Entry <span class="text-muted fw-normal fs-6">(All accounts record in one list)</span>
    </h3>
    <form method="post" action="">
        <input type="hidden" name="action" value="add_entry">
        <input type="hidden" name="type" value="<?= h($currentType) ?>">
        <input type="hidden" name="account_id_filter" value="<?= h((string)$currentAccountId) ?>">
        
        <div class="row g-3">
            <div class="col-12 col-md-2">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Date</label>
                <input type="date" name="date" value="<?= h($currentDate) ?>" class="form-control form-control-sm border-gray-200 rounded-lg" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Account</label>
                <select name="account_id" class="form-select form-select-sm border-gray-200 rounded-lg" required>
                    <?php foreach ($accountsByType[$currentType] as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $currentAccountId === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['account_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Type</label>
                <select name="entry_type" class="form-select form-select-sm border-gray-200 rounded-lg bg-gray-50" required>
                    <option value="receiving">Receiving (Money In)</option>
                    <option value="sending">Sending (Money Out)</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Amount (Rs)</label>
                <input type="number" step="0.01" name="amount" class="form-control form-control-sm border-gray-200 rounded-lg" required placeholder="500">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Commission (Rs)</label>
                <input type="number" step="0.01" name="charges" class="form-control form-control-sm border-gray-200 rounded-lg" placeholder="0.00">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Customer Name</label>
                <input type="text" name="customer_name" class="form-control form-control-sm border-gray-200 rounded-lg" placeholder="Ali Khan">
            </div>
            
            <div class="col-12 col-md-3">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Apna Number <span class="text-danger">*</span></label>
                <input type="text" name="number" class="form-control form-control-sm border-gray-200 rounded-lg" placeholder="03xx-xxxxxxx">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Transaction ID</label>
                <input type="text" name="transaction_id" class="form-control form-control-sm border-gray-200 rounded-lg" placeholder="TXN12345">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label text-xs fw-semibold text-gray-500 mb-1">Note (optional)</label>
                <input type="text" name="remarks" class="form-control form-control-sm border-gray-200 rounded-lg" placeholder="Any info...">
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm rounded-lg px-4 fw-medium bg-brand-600 border-brand-600 hover:bg-brand-700 w-100"><i data-lucide="plus" class="w-4 h-4 d-inline-block me-1"></i> Save Entry</button>
            </div>
        </div>
    </form>
</div>

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
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9" class="py-5 text-center text-gray-500">No transactions found for this date.</td>
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
