<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Dealer Payments - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canEditDelete = app_can_edit_delete_records();

$networks = ['Jazz', 'Zong', 'Telenor', 'Ufone'];
$entryTypes = [
    '' => 'All',
    'advance_payment' => 'Advance Payment to Dealer',
    'load_received_against_advance' => 'Load Received Against Advance',
    'credit_load_received' => 'Credit Load Received',
    'dealer_payment' => 'Dealer Payment',
];

$dealerNames = [];
try {
    $stmt = $pdo->query("SELECT dealer_name FROM dealers WHERE status = 'active' ORDER BY dealer_name ASC");
    $dealerNames = array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['dealer_name'] ?? ''), $stmt->fetchAll())));
} catch (Throwable $e) {
    $dealerNames = [];
}
if (!$dealerNames) {
    $dealerNames = ['Khalid', 'Nouman', 'Saifullah', 'Imran'];
}

$dealer = trim((string) ($_GET['dealer'] ?? ''));
$network = trim((string) ($_GET['network'] ?? ''));
$txnType = trim((string) ($_GET['txn_type'] ?? ''));
$createdBy = (int) ($_GET['created_by'] ?? 0);
$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));

if ($dealer !== '' && !in_array($dealer, $dealerNames, true)) {
    $dealer = '';
}
if ($network !== '' && !in_array($network, $networks, true)) {
    $network = '';
}
if ($txnType !== '' && !array_key_exists($txnType, $entryTypes)) {
    $txnType = '';
}

$adminRows = [];
try {
    $adminRows = $pdo->query("SELECT id, name, role FROM admins ORDER BY role ASC, name ASC, id ASC")->fetchAll();
} catch (Throwable $e) {
    $adminRows = [];
}

$whereParts = ['payment_date >= :from', 'payment_date <= :to'];
$params = [':from' => $from, ':to' => $to];
if ($dealer !== '') {
    $whereParts[] = 'dealer_name = :dealer';
    $params[':dealer'] = $dealer;
}
if ($network !== '') {
    $whereParts[] = 'network = :network';
    $params[':network'] = $network;
}
if ($txnType !== '') {
    $whereParts[] = 'entry_type = :entry_type';
    $params[':entry_type'] = $txnType;
}
if ($createdBy > 0) {
    $whereParts[] = 'created_by = :created_by';
    $params[':created_by'] = $createdBy;
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$stmt = $pdo->prepare("
    SELECT dp.id, dp.dealer_name, dp.network, dp.payment_date, dp.amount, dp.notes, dp.entry_type, dp.description, dp.created_by, dp.created_at,
           a.name AS created_by_name,
           acc.account_name AS payment_source_name,
           acc.account_type AS payment_source_type
    FROM dealer_payments dp
    LEFT JOIN admins a ON a.id = dp.created_by
    LEFT JOIN accounts acc ON acc.id = dp.payment_source_account_id
    {$where}
    ORDER BY dp.payment_date DESC, dp.id DESC
    LIMIT 300
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$displayRows = $rows;
$showRunningBalance = false;
if ($dealer !== '') {
    $stmt = $pdo->prepare("
        SELECT dp.id, dp.dealer_name, dp.network, dp.payment_date, dp.amount, dp.notes, dp.entry_type, dp.description, dp.created_by, dp.created_at,
               a.name AS created_by_name,
               acc.account_name AS payment_source_name,
               acc.account_type AS payment_source_type
        FROM dealer_payments dp
        LEFT JOIN admins a ON a.id = dp.created_by
        LEFT JOIN accounts acc ON acc.id = dp.payment_source_account_id
        {$where}
        ORDER BY dp.payment_date ASC, dp.id ASC
        LIMIT 500
    ");
    $stmt->execute($params);
    $displayRows = $stmt->fetchAll();
    $showRunningBalance = true;
}

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN entry_type = 'advance_payment' THEN amount ELSE 0 END), 0) AS advance_total,
        COALESCE(SUM(CASE WHEN entry_type = 'dealer_payment' THEN amount ELSE 0 END), 0) AS payment_total,
        COALESCE(SUM(CASE WHEN entry_type = 'load_received_against_advance' THEN amount ELSE 0 END), 0) AS load_received_total,
        COALESCE(SUM(CASE WHEN entry_type = 'credit_load_received' THEN amount ELSE 0 END), 0) AS credit_total
    FROM dealer_payments
    {$where}
");
$stmt->execute($params);
$sum = $stmt->fetch() ?: [];
$rangeAdvanceTotal = (float) ($sum['advance_total'] ?? 0);
$rangePaymentTotal = (float) ($sum['payment_total'] ?? 0);
$rangeLoadReceivedTotal = (float) ($sum['load_received_total'] ?? 0);
$rangeCreditTotal = (float) ($sum['credit_total'] ?? 0);
$rangeCashOutTotal = $rangeAdvanceTotal + $rangePaymentTotal;
$rangeBalanceNet = $rangeCashOutTotal - $rangeLoadReceivedTotal - $rangeCreditTotal;

$paymentSourceTotals = [];
foreach ($rows as $r) {
    $type = trim((string) ($r['entry_type'] ?? ''));
    if (!in_array($type, ['advance_payment', 'dealer_payment'], true)) {
        continue;
    }
    $sourceName = trim((string) ($r['payment_source_name'] ?? ''));
    $sourceType = trim((string) ($r['payment_source_type'] ?? ''));
    $key = $sourceName !== '' ? $sourceName : 'Not linked';
    if (!isset($paymentSourceTotals[$key])) {
        $paymentSourceTotals[$key] = [
            'label' => $sourceName !== '' ? $sourceName . ($sourceType !== '' ? ' (' . $sourceType . ')' : '') : 'Not linked',
            'amount' => 0.0,
        ];
    }
    $paymentSourceTotals[$key]['amount'] += (float) ($r['amount'] ?? 0);
}

$stmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM dealer_payments
    WHERE payment_date = CURDATE()
      AND entry_type IN ('advance_payment', 'dealer_payment')
");
$todayCashOutTotal = (float) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM dealer_payments
    WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND payment_date <= LAST_DAY(CURDATE())
      AND entry_type IN ('advance_payment', 'dealer_payment')
");
$monthCashOutTotal = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        dealer_name,
        COALESCE(SUM(CASE WHEN entry_type = 'advance_payment' THEN amount ELSE 0 END), 0) AS advance_total,
        COALESCE(SUM(CASE WHEN entry_type = 'dealer_payment' THEN amount ELSE 0 END), 0) AS payment_total,
        COALESCE(SUM(CASE WHEN entry_type = 'load_received_against_advance' THEN amount ELSE 0 END), 0) AS load_received_total,
        COALESCE(SUM(CASE WHEN entry_type = 'credit_load_received' THEN amount ELSE 0 END), 0) AS credit_total
    FROM dealer_payments
    WHERE payment_date >= :from AND payment_date <= :to
    GROUP BY dealer_name
    ORDER BY dealer_name ASC, MIN(network) ASC
");
$stmt->execute([':from' => $from, ':to' => $to]);
$totalsByDealer = [];
foreach ($stmt->fetchAll() as $r) {
    $adv = (float) ($r['advance_total'] ?? 0);
    $pay = (float) ($r['payment_total'] ?? 0);
    $lr = (float) ($r['load_received_total'] ?? 0);
    $cr = (float) ($r['credit_total'] ?? 0);
    $totalsByDealer[(string) $r['dealer_name']] = [
        'advance' => $adv,
        'payment' => $pay,
        'cash_out' => $adv + $pay,
        'load_received' => $lr,
        'credit' => $cr,
        'balance' => ($adv + $pay) - $lr - $cr,
    ];
}

$stmt = $pdo->prepare("
    SELECT
        network,
        COALESCE(SUM(CASE WHEN entry_type IN ('advance_payment','dealer_payment') THEN amount ELSE 0 END), 0) AS cash_out_total,
        COALESCE(SUM(CASE WHEN entry_type = 'load_received_against_advance' THEN amount ELSE 0 END), 0) AS load_received_total,
        COALESCE(SUM(CASE WHEN entry_type = 'credit_load_received' THEN amount ELSE 0 END), 0) AS credit_total
    FROM dealer_payments
    WHERE payment_date >= :from AND payment_date <= :to
    GROUP BY network
    ORDER BY network ASC
");
$stmt->execute([':from' => $from, ':to' => $to]);
$totalsByNetwork = [];
foreach ($stmt->fetchAll() as $r) {
    $totalsByNetwork[(string) $r['network']] = [
        'cash_out' => (float) ($r['cash_out_total'] ?? 0),
        'load_received' => (float) ($r['load_received_total'] ?? 0),
        'credit' => (float) ($r['credit_total'] ?? 0),
    ];
}

$loadPurchasesByNetwork = [];
try {
    $stmt = $pdo->prepare("
        SELECT network, COALESCE(SUM(purchased_balance), 0) AS total
        FROM load_entries
        WHERE date >= :from AND date <= :to
        GROUP BY network
    ");
    $stmt->execute([':from' => $from, ':to' => $to]);
    foreach ($stmt->fetchAll() as $r) {
        $loadPurchasesByNetwork[(string) $r['network']] = (float) $r['total'];
    }
} catch (Throwable $e) {
}

$remainingPayableByNetwork = [];
$remainingPayableTotal = 0.0;
foreach ($networks as $n) {
    $purchased = (float) ($loadPurchasesByNetwork[$n] ?? 0);
    $paid = (float) (($totalsByNetwork[$n]['cash_out'] ?? 0));
    $rem = $purchased - $paid;
    $remainingPayableByNetwork[$n] = $rem;
    $remainingPayableTotal += $rem;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold tracking-tight">Dealer Ledger</h1>
        <p class="text-gray-500 text-sm mb-0">Advance, credit, load received and payments with live balance</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-gradient shadow-glow rounded-xl" href="<?= h(app_url('dealer-payments/add.php')) ?>">
            <i data-lucide="plus" class="w-4 h-4"></i> Add Entry
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="glass-card mb-4 animate-slide-up stagger-1">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-bold text-gray-600 uppercase tracking-wider">Dealer</label>
                <select class="form-select bg-light border-0 shadow-sm rounded-xl" name="dealer">
                    <option value="">All</option>
                    <?php foreach ($dealerNames as $d): ?>
                        <option value="<?= h($d) ?>" <?= $dealer === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-bold text-gray-600 uppercase tracking-wider">Network</label>
                <select class="form-select bg-light border-0 shadow-sm rounded-xl" name="network">
                    <option value="">All</option>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $network === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-bold text-gray-600 uppercase tracking-wider">Transaction Type</label>
                <select class="form-select bg-light border-0 shadow-sm rounded-xl" name="txn_type">
                    <?php foreach ($entryTypes as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= $txnType === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-gray-600 uppercase tracking-wider">From</label>
                <input class="form-control bg-light border-0 shadow-sm rounded-xl" type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-gray-600 uppercase tracking-wider">To</label>
                <input class="form-control bg-light border-0 shadow-sm rounded-xl" type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-bold text-gray-600 uppercase tracking-wider">User</label>
                <select class="form-select bg-light border-0 shadow-sm rounded-xl" name="created_by">
                    <option value="0">All</option>
                    <?php foreach ($adminRows as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $createdBy ? 'selected' : '' ?>>
                            <?= h((string) ($a['name'] ?? '')) ?><?= ((string) ($a['role'] ?? '') !== '' ? (' • ' . (string) $a['role']) : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-gradient w-100 shadow-sm rounded-xl">Filter</button>
                <a class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift w-100 rounded-xl" href="<?= h(app_url('dealer-payments/index.php')) ?>">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-4 mb-4 animate-slide-up stagger-2">
    <div class="col-12 col-md-3">
        <div class="p-4 bg-light rounded-4 border-start border-primary border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-bold text-uppercase tracking-wider mb-2">Cash Out (Range)</div>
            <div class="h4 mb-0 font-bold text-primary"><?= h(number_format($rangeCashOutTotal, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-4 bg-light rounded-4 border-start border-success border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-bold text-uppercase tracking-wider mb-2">Cash Out (Today)</div>
            <div class="h4 mb-0 font-bold text-success"><?= h(number_format($todayCashOutTotal, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-4 bg-light rounded-4 border-start border-info border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-bold text-uppercase tracking-wider mb-2">Cash Out (This Month)</div>
            <div class="h4 mb-0 font-bold text-info"><?= h(number_format($monthCashOutTotal, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-4 bg-light rounded-4 border-start border-warning border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-bold text-uppercase tracking-wider mb-2">Remaining Payable</div>
            <div class="h4 mb-0 font-bold text-warning"><?= h(number_format($remainingPayableTotal, 2)) ?></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4 animate-slide-up stagger-3">
    <div class="col-12 col-lg-6">
        <div class="glass-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-4">
                    <div class="bg-primary bg-opacity-10 p-2 rounded-3 text-primary">
                        <i data-lucide="users" class="w-5 h-5"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-gray-800">Dealer Balance (Range)</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 custom-table border-0">
                        <thead class="bg-light bg-opacity-50">
                        <tr>
                            <th class="border-0 px-3 py-2 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Dealer</th>
                            <th class="border-0 px-3 py-2 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Balance</th>
                        </tr>
                        </thead>
                        <tbody class="border-top-0">
                        <?php foreach ($dealerNames as $d): ?>
                            <tr class="transition-all hover-bg-light">
                                <td class="px-3 py-2 border-0 border-bottom text-gray-700 fw-medium">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-circle bg-gray-100 text-gray-600 fw-bold flex items-center justify-center flex-shrink-0" style="width:28px;height:28px;font-size:11px;">
                                            <?= h(strtoupper(substr($d, 0, 1))) ?>
                                        </div>
                                        <span class="text-truncate"><?= h($d) ?></span>
                                    </div>
                                </td>
                                <?php
                                    $bal = (float) (($totalsByDealer[$d]['balance'] ?? 0));
                                ?>
                                <td class="px-3 py-2 border-0 border-bottom text-end font-bold <?= $bal >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= h(number_format($bal, 2)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="glass-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-4">
                    <div class="bg-brand-50 p-2 rounded-3 text-brand-600">
                        <i data-lucide="smartphone" class="w-5 h-5"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-gray-800">Network-wise Payments / Payable <span class="text-muted fw-normal fs-6">(Range)</span></h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 custom-table border-0">
                        <thead class="bg-light bg-opacity-50">
                        <tr>
                            <th class="border-0 px-3 py-2 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Network</th>
                            <th class="border-0 px-3 py-2 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Paid</th>
                            <th class="border-0 px-3 py-2 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Payable</th>
                        </tr>
                        </thead>
                        <tbody class="border-top-0">
                        <?php foreach ($networks as $n): ?>
                            <tr class="transition-all hover-bg-light">
                                <td class="px-3 py-2 border-0 border-bottom text-gray-700 fw-medium">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-brand-500"></span>
                                        <?= h($n) ?>
                                    </div>
                                </td>
                                <td class="px-3 py-2 border-0 border-bottom text-end font-bold text-success"><?= h(number_format((float) (($totalsByNetwork[$n]['cash_out'] ?? 0)), 2)) ?></td>
                                <td class="px-3 py-2 border-0 border-bottom text-end font-bold text-warning"><?= h(number_format((float) ($remainingPayableByNetwork[$n] ?? 0), 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="glass-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-4">
                    <div class="bg-success bg-opacity-10 p-2 rounded-3 text-success">
                        <i data-lucide="landmark" class="w-5 h-5"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-gray-800">Payment Source Summary <span class="text-muted fw-normal fs-6">(Filtered Range)</span></h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 custom-table border-0">
                        <thead class="bg-light bg-opacity-50">
                        <tr>
                            <th class="border-0 px-3 py-2 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Source</th>
                            <th class="border-0 px-3 py-2 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Amount</th>
                        </tr>
                        </thead>
                        <tbody class="border-top-0">
                        <?php foreach ($paymentSourceTotals as $source): ?>
                            <tr class="transition-all hover-bg-light">
                                <td class="px-3 py-2 border-0 border-bottom text-gray-700 fw-medium"><?= h((string) ($source['label'] ?? '')) ?></td>
                                <td class="px-3 py-2 border-0 border-bottom text-end fw-bold text-success"><?= h(number_format((float) ($source['amount'] ?? 0), 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentSourceTotals): ?>
                            <tr>
                                <td colspan="2" class="px-3 py-4 text-center text-muted">No payment-source deductions in this range.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="glass-card animate-slide-up stagger-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-table">
                <thead class="bg-light bg-opacity-50">
                <tr>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Date</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Dealer</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Network</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Type</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Description</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Advance</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Credit</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Load</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Payment</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Payment Source</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Balance</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Created By</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Remarks</th>
                    <?php if ($canEditDelete): ?>
                        <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody class="border-top-0">
                <?php $running = 0.0; ?>
                <?php foreach ($displayRows as $r): ?>
                    <?php
                        $type = trim((string) ($r['entry_type'] ?? ''));
                        if ($type === '') {
                            $type = 'dealer_payment';
                        }
                        $amt = (float) ($r['amount'] ?? 0);
                        $advAmt = $type === 'advance_payment' ? $amt : 0.0;
                        $creditAmt = $type === 'credit_load_received' ? $amt : 0.0;
                        $loadAmt = $type === 'load_received_against_advance' ? $amt : 0.0;
                        $payAmt = $type === 'dealer_payment' ? $amt : 0.0;
                        if ($showRunningBalance) {
                            $running += ($advAmt + $payAmt) - ($loadAmt + $creditAmt);
                        }
                    ?>
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3 font-medium text-gray-600"><?= h((string) $r['payment_date']) ?></td>
                        <td class="px-4 py-3 fw-bold text-gray-800"><?= h((string) $r['dealer_name']) ?></td>
                        <td class="px-4 py-3">
                            <span class="badge bg-brand-50 text-brand-600 px-2 py-1 rounded border border-brand-100">
                                <?= h((string) $r['network']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-700 fw-semibold"><?= h((string) ($entryTypes[$type] ?? $type)) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= h((string) ($r['description'] ?? '')) ?></td>
                        <td class="px-4 py-3 text-end fw-semibold text-success"><?= $advAmt > 0 ? h(number_format($advAmt, 2)) : '' ?></td>
                        <td class="px-4 py-3 text-end fw-semibold text-danger"><?= $creditAmt > 0 ? h(number_format($creditAmt, 2)) : '' ?></td>
                        <td class="px-4 py-3 text-end fw-semibold text-gray-700"><?= $loadAmt > 0 ? h(number_format($loadAmt, 2)) : '' ?></td>
                        <td class="px-4 py-3 text-end fw-semibold text-primary"><?= $payAmt > 0 ? h(number_format($payAmt, 2)) : '' ?></td>
                        <td class="px-4 py-3 text-gray-600">
                            <?php if ((string) ($r['payment_source_name'] ?? '') !== ''): ?>
                                <?= h((string) $r['payment_source_name']) ?>
                                <?php if ((string) ($r['payment_source_type'] ?? '') !== ''): ?>
                                    <span class="text-muted small">(<?= h((string) $r['payment_source_type']) ?>)</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-end fw-bold <?= $running >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $showRunningBalance ? h(number_format($running, 2)) : '' ?>
                        </td>
                        <td class="px-4 py-3 text-gray-600"><?= h((string) ($r['created_by_name'] ?? '')) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= h((string) ($r['notes'] ?? '')) ?></td>
                        <?php if ($canEditDelete): ?>
                            <td class="px-4 py-3 text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('dealer-payments/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('dealer-payments/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$displayRows): ?>
                    <tr>
                        <td colspan="<?= h((string) (13 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i data-lucide="users" class="w-8 h-8 text-gray-300 mb-2"></i>
                                <p class="mb-0">No dealer entries found.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
