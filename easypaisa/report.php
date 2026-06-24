<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/ep_lib.php';

$pageTitle = 'EasyPaisa Reports - Shop Management';

$pdo = db();
$accounts = wallet_accounts($pdo, 'easypaisa');

$from = (string) ($_GET['from'] ?? date('Y-m-d'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$type = trim((string) ($_GET['type'] ?? ''));
$accountId = (int) ($_GET['account_id'] ?? 0);

$validAccount = false;
foreach ($accounts as $a) {
    if ((int) $a['id'] === $accountId) {
        $validAccount = true;
        break;
    }
}
if (!$validAccount) {
    $accountId = 0;
}

$params = [
    ':from' => $from,
    ':to' => $to,
    ':account_type' => 'easypaisa',
];

$where = 'WHERE wt.date >= :from AND wt.date <= :to AND a.account_type = :account_type';
if ($accountId > 0) {
    $where .= ' AND wt.account_id = :account_id';
    $params[':account_id'] = $accountId;
}
if ($type !== '') {
    $where .= ' AND type = :type';
    $params[':type'] = $type;
}

$stmt = $pdo->prepare("
    SELECT wt.id, wt.date, wt.customer_name, wt.number, wt.transaction_id, wt.type, wt.amount, wt.charges, wt.remarks
    FROM wallet_transactions wt
    JOIN accounts a ON a.id = wt.account_id
    {$where}
    ORDER BY wt.date ASC, wt.id ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN wt.type='receiving' THEN wt.amount ELSE 0 END), 0) AS total_receiving,
        COALESCE(SUM(CASE WHEN wt.type='sending' THEN wt.amount ELSE 0 END), 0) AS total_sending,
        COALESCE(SUM(wt.charges), 0) AS total_charges
    FROM wallet_transactions wt
    JOIN accounts a ON a.id = wt.account_id
    {$where}
");
$stmt->execute($params);
$t = $stmt->fetch() ?: [];
$totals = [
    'receiving' => (float) ($t['total_receiving'] ?? 0),
    'sending' => (float) ($t['total_sending'] ?? 0),
    'commission' => (float) ($t['total_charges'] ?? 0),
];
$closing = $totals['receiving'] - $totals['sending'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">EasyPaisa Reports</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('easypaisa/index.php')) ?>">Back</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="account_id">Account</label>
                <select class="form-select" id="account_id" name="account_id">
                    <option value="0">All Accounts</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= h((string) (int) $a['id']) ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>>
                            <?= h((string) $a['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= h($from) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= h($to) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    <option value="receiving" <?= $type === 'receiving' ? 'selected' : '' ?>>Receiving</option>
                    <option value="sending" <?= $type === 'sending' ? 'selected' : '' ?>>Sending</option>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Receiving</div>
                <div class="h5 mb-0"><?= h(number_format($totals['receiving'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Sending</div>
                <div class="h5 mb-0"><?= h(number_format($totals['sending'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Closing Balance</div>
                <div class="h5 mb-0"><?= h(number_format($closing, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Commission Earned</div>
                <div class="h5 mb-0"><?= h(number_format($totals['commission'], 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Customer</th>
                    <th>Number</th>
                    <th>Transaction ID</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Charges</th>
                    <th>Remarks</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $amountStyle = '';
                    if (($r['type'] ?? '') === 'receiving') {
                        $amountStyle = 'color:#16a34a;font-weight:600;';
                    } elseif (($r['type'] ?? '') === 'sending') {
                        $amountStyle = 'color:#dc2626;font-weight:600;';
                    }
                    ?>
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['type']) ?></td>
                        <td><?= h((string) ($r['customer_name'] ?? '')) ?></td>
                        <td><?= h((string) ($r['number'] ?? '')) ?></td>
                        <td><?= h((string) ($r['transaction_id'] ?? '')) ?></td>
                        <td class="text-end" style="<?= h($amountStyle) ?>"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['charges'], 2)) ?></td>
                        <td><?= h((string) ($r['remarks'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No data found for selected filters.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
