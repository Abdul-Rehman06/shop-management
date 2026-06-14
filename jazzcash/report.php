<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/jc_lib.php';

$pageTitle = 'JazzCash Reports - Shop Management';

$pdo = db();

$from = (string) ($_GET['from'] ?? date('Y-m-d'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$type = trim((string) ($_GET['type'] ?? ''));

$params = [
    ':from' => $from,
    ':to' => $to,
];

$where = 'WHERE date >= :from AND date <= :to';
if ($type !== '') {
    $where .= ' AND type = :type';
    $params[':type'] = $type;
}

$stmt = $pdo->prepare("
    SELECT id, date, customer_name, number, transaction_id, type, amount, charges, remarks
    FROM jazzcash_transactions
    {$where}
    ORDER BY date ASC, id ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totals = jc_totals($pdo, $from, $to);
if ($type === 'receiving') {
    $totals['sending'] = 0.0;
} elseif ($type === 'sending') {
    $totals['receiving'] = 0.0;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">JazzCash Reports</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('jazzcash/index.php')) ?>">Back</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
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
                <button class="btn btn-primary">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Receiving</div>
                <div class="h5 mb-0"><?= h(number_format($totals['receiving'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Sending</div>
                <div class="h5 mb-0"><?= h(number_format($totals['sending'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
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
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['type']) ?></td>
                        <td><?= h((string) ($r['customer_name'] ?? '')) ?></td>
                        <td><?= h((string) $r['number']) ?></td>
                        <td><?= h((string) ($r['transaction_id'] ?? '')) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['amount'], 2)) ?></td>
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

