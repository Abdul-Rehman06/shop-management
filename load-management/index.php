<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pageTitle = 'Load Management - Shop Management';

$pdo = db();
load_ensure_schema($pdo);
$success = flash_get('success');
$error = flash_get('error');

$networks = load_get_networks($pdo);
$date = (string) ($_GET['date'] ?? date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? date('Y-m-d')));
    $entries = $_POST['entries'] ?? [];

    if ($date === '') {
        flash_set('error', 'Date is required.');
        app_redirect('load-management/index.php');
    }

    foreach ($networks as $network) {
        $row = is_array($entries) ? ($entries[$network] ?? null) : null;
        if (!is_array($row)) {
            $row = [];
        }

        $opening = (float) ($row['opening'] ?? 0);
        $purchased = (float) ($row['purchased'] ?? 0);
        $sold = (float) ($row['sold'] ?? 0);
        $profit = (float) ($row['profit'] ?? 0);

        load_upsert_entry($pdo, $date, (string) $network, $opening, $purchased, $sold, $profit);
    }

    flash_set('success', 'Daily load totals saved.');
    app_redirect('load-management/index.php?date=' . urlencode($date));
}

$entriesByNetwork = [];
$stmt = $pdo->prepare("
    SELECT network, opening_balance, purchased_balance, sold_balance, profit, closing_balance
    FROM load_entries
    WHERE date = :date
");
$stmt->execute([':date' => $date]);
foreach ($stmt->fetchAll() as $r) {
    $entriesByNetwork[(string) $r['network']] = $r;
}

$totals = [
    'opening' => 0.0,
    'purchased' => 0.0,
    'sold' => 0.0,
    'profit' => 0.0,
    'closing' => 0.0,
];
foreach ($networks as $n) {
    $e = $entriesByNetwork[$n] ?? null;
    $opening = (float) ($e['opening_balance'] ?? 0);
    $purchased = (float) ($e['purchased_balance'] ?? 0);
    $sold = (float) ($e['sold_balance'] ?? 0);
    $profit = (float) ($e['profit'] ?? 0);
    $closing = $opening + $purchased - $sold;
    $totals['opening'] += $opening;
    $totals['purchased'] += $purchased;
    $totals['sold'] += $sold;
    $totals['profit'] += $profit;
    $totals['closing'] += $closing;
}

$history = $pdo->query("
    SELECT date,
        COALESCE(SUM(opening_balance), 0) AS opening_total,
        COALESCE(SUM(purchased_balance), 0) AS purchased_total,
        COALESCE(SUM(sold_balance), 0) AS sold_total,
        COALESCE(SUM(profit), 0) AS profit_total,
        COALESCE(SUM(closing_balance), 0) AS closing_total
    FROM load_entries
    GROUP BY date
    ORDER BY date DESC
    LIMIT 30
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-1 text-gray-900 font-bold tracking-tight">Load Management</h1>
        <p class="text-gray-500 text-sm mb-0">Daily totals per network (no customer-wise entries)</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-secondary btn-sm" href="<?= h(app_url('load-management/report.php')) ?>">
            <i data-lucide="bar-chart-3" class="w-4 h-4"></i> Reports
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="date">Date</label>
                <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-outline-primary">Open</button>
            </div>
        </form>

        <div class="row g-3">
            <div class="col-12 col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Opening</div>
                        <div class="h5 mb-0"><?= h(number_format($totals['opening'], 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Purchased</div>
                        <div class="h5 mb-0"><?= h(number_format($totals['purchased'], 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Sold</div>
                        <div class="h5 mb-0"><?= h(number_format($totals['sold'], 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Profit</div>
                        <div class="h5 mb-0"><?= h(number_format($totals['profit'], 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Closing (Auto)</div>
                        <div class="h5 mb-0"><?= h(number_format($totals['closing'], 2)) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="post" id="dailyLoadForm">
            <input type="hidden" name="date" value="<?= h($date) ?>">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Network</th>
                        <th class="text-end">Opening Balance</th>
                        <th class="text-end">Purchased Balance</th>
                        <th class="text-end">Sold Balance</th>
                        <th class="text-end">Profit (manual)</th>
                        <th class="text-end">Closing (auto)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($networks as $n): ?>
                        <?php
                        $e = $entriesByNetwork[$n] ?? [];
                        $opening = (float) ($e['opening_balance'] ?? 0);
                        $purchased = (float) ($e['purchased_balance'] ?? 0);
                        $sold = (float) ($e['sold_balance'] ?? 0);
                        $profit = (float) ($e['profit'] ?? 0);
                        $closing = $opening + $purchased - $sold;
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= h($n) ?></td>
                            <td class="text-end">
                                <input class="form-control form-control-sm text-end js-opening" type="number" step="0.01" name="entries[<?= h($n) ?>][opening]" value="<?= h((string) $opening) ?>">
                            </td>
                            <td class="text-end">
                                <input class="form-control form-control-sm text-end js-purchased" type="number" step="0.01" name="entries[<?= h($n) ?>][purchased]" value="<?= h((string) $purchased) ?>">
                            </td>
                            <td class="text-end">
                                <input class="form-control form-control-sm text-end js-sold" type="number" step="0.01" name="entries[<?= h($n) ?>][sold]" value="<?= h((string) $sold) ?>">
                            </td>
                            <td class="text-end">
                                <input class="form-control form-control-sm text-end" type="number" step="0.01" name="entries[<?= h($n) ?>][profit]" value="<?= h((string) $profit) ?>">
                            </td>
                            <td class="text-end">
                                <input class="form-control form-control-sm text-end js-closing" type="text" value="<?= h(number_format($closing, 2, '.', '')) ?>" readonly>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary">Save Daily Totals</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th class="text-end">Opening</th>
                    <th class="text-end">Purchased</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Closing</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $hrow): ?>
                    <tr>
                        <td>
                            <a href="<?= h(app_url('load-management/index.php?date=' . (string) $hrow['date'])) ?>">
                                <?= h((string) $hrow['date']) ?>
                            </a>
                        </td>
                        <td class="text-end"><?= h(number_format((float) $hrow['opening_total'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $hrow['purchased_total'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $hrow['sold_total'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $hrow['profit_total'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $hrow['closing_total'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No records yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function recalcRow(tr) {
        const opening = parseFloat(tr.querySelector('.js-opening')?.value || '0') || 0;
        const purchased = parseFloat(tr.querySelector('.js-purchased')?.value || '0') || 0;
        const sold = parseFloat(tr.querySelector('.js-sold')?.value || '0') || 0;
        const closing = opening + purchased - sold;
        const closingEl = tr.querySelector('.js-closing');
        if (closingEl) closingEl.value = closing.toFixed(2);
    }

    document.querySelectorAll('#dailyLoadForm tbody tr').forEach(tr => {
        tr.querySelectorAll('input').forEach(inp => {
            inp.addEventListener('input', () => recalcRow(tr));
        });
        recalcRow(tr);
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
