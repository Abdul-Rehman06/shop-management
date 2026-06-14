<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pageTitle = 'Load Reports - Shop Management';

$pdo = db();
$networks = load_get_networks($pdo);

$from = (string) ($_GET['from'] ?? date('Y-m-d'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$network = trim((string) ($_GET['network'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));

$params = [
    ':from' => $from,
    ':to' => $to,
];

$where = 'WHERE date >= :from AND date <= :to';
if ($network !== '') {
    $where .= ' AND network = :network';
    $params[':network'] = $network;
}
if ($type !== '') {
    $where .= ' AND type = :type';
    $params[':type'] = $type;
}

$sql = "
    SELECT id, date, network, type, opening_balance, purchased, sold, profit, closing_balance, supplier, customer_number, remarks
    FROM load_transactions
    {$where}
    ORDER BY date ASC, id ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalPurchased = 0.0;
$totalSold = 0.0;
$totalProfit = 0.0;

foreach ($rows as $r) {
    $totalPurchased += (float) $r['purchased'];
    $totalSold += (float) $r['sold'];
    $totalProfit += (float) $r['profit'];
}

$firstOpening = $rows ? (float) $rows[0]['opening_balance'] : 0.0;
$lastClosing = $rows ? (float) $rows[count($rows) - 1]['closing_balance'] : 0.0;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Load Reports</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/index.php')) ?>">Back</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= h($from) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= h($to) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="network">Network</label>
                <select class="form-select" id="network" name="network">
                    <option value="">All</option>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $n === $network ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    <option value="opening" <?= $type === 'opening' ? 'selected' : '' ?>>Opening</option>
                    <option value="purchase" <?= $type === 'purchase' ? 'selected' : '' ?>>Purchase</option>
                    <option value="sale" <?= $type === 'sale' ? 'selected' : '' ?>>Sale</option>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Opening</div>
                <div class="h5 mb-0"><?= h(number_format($firstOpening, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Purchased</div>
                <div class="h5 mb-0"><?= h(number_format($totalPurchased, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Sold</div>
                <div class="h5 mb-0"><?= h(number_format($totalSold, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Closing</div>
                <div class="h5 mb-0"><?= h(number_format($lastClosing, 2)) ?></div>
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
                    <th>Network</th>
                    <th>Type</th>
                    <th class="text-end">Opening</th>
                    <th class="text-end">Purchased</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Closing</th>
                    <th>Party</th>
                    <th>Remarks</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['network']) ?></td>
                        <td><?= h((string) $r['type']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['opening_balance'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['purchased'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['sold'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['profit'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['closing_balance'], 2)) ?></td>
                        <td>
                            <?php
                            $party = '';
                            if ((string) $r['type'] === 'purchase') {
                                $party = (string) ($r['supplier'] ?? '');
                            } elseif ((string) $r['type'] === 'sale') {
                                $party = (string) ($r['customer_number'] ?? '');
                            }
                            ?>
                            <?= h($party) ?>
                        </td>
                        <td><?= h((string) ($r['remarks'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No data found for selected filters.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3 text-muted small">
    Total Profit: <?= h(number_format($totalProfit, 2)) ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

