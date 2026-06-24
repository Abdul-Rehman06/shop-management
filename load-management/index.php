<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pageTitle = 'Load Management - Shop Management';

$pdo = db();
$canViewProfit = app_can_view_profit();
$canEditDelete = app_can_edit_delete_records();
load_ensure_schema($pdo);
$success = flash_get('success');
$error = flash_get('error');

$networks = load_get_networks($pdo);
$date = (string) ($_GET['date'] ?? date('Y-m-d'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));
    if ($action === 'delete_day') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('load-management/index.php');
        }
        $deleteDate = trim((string) ($_POST['delete_date'] ?? ''));
        if ($deleteDate === '') {
            flash_set('error', 'Date is required.');
            app_redirect('load-management/index.php');
        }

        $stmt = $pdo->prepare("
            SELECT id, network, date, opening_balance, purchased_balance, sold_balance, profit, closing_balance, created_at, updated_at
            FROM load_entries
            WHERE date = :date
            ORDER BY network ASC, id ASC
        ");
        $stmt->execute([':date' => $deleteDate]);
        $beforeRows = $stmt->fetchAll();

        $stmt = $pdo->prepare("DELETE FROM load_entries WHERE date = :date");
        $stmt->execute([':date' => $deleteDate]);

        $entityId = (int) str_replace('-', '', $deleteDate);
        app_audit_log('load_entries_day', $entityId, 'delete', ['date' => $deleteDate, 'rows' => $beforeRows], null);

        flash_set('success', 'Load day deleted.');
        app_redirect('load-management/index.php');
    }

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
        $closing = (float) ($row['closing'] ?? 0);
        $sold = $opening + $purchased - $closing;
        $profit = (float) ($row['profit'] ?? 0);
        if (!$canViewProfit) {
            $existing = load_entry($pdo, $date, (string) $network);
            $profit = (float) ($existing['profit'] ?? 0);
        }

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
    $closing = (float) ($e['closing_balance'] ?? ($opening + $purchased - $sold));
    $totals['opening'] += $opening;
    $totals['purchased'] += $purchased;
    $totals['sold'] += $sold;
    if ($canViewProfit) {
        $totals['profit'] += $profit;
    }
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

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8 animate-slide-up stagger-1">
    <div class="flex items-center gap-3">
        <div class="h-10 w-2 bg-gradient-premium rounded-full"></div>
        <div>
            <h1 class="text-3xl font-bold text-gray-900 tracking-tight m-0">Load Management</h1>
            <p class="text-sm text-gray-500 mt-1">Daily totals per network</p>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <a class="btn btn-outline-primary bg-white/60 hover:bg-brand-50 border-0 shadow-sm" href="<?= h(app_url('load-management/transactions.php')) ?>">
            <i data-lucide="list" class="w-4 h-4 text-brand-600"></i> Load Transactions
        </a>
        <?php if ($canViewProfit): ?>
            <a class="btn btn-outline-secondary bg-white/60 hover:bg-gray-50 border-0 shadow-sm" href="<?= h(app_url('load-management/report.php')) ?>">
                <i data-lucide="bar-chart-3" class="w-4 h-4 text-gray-600"></i> Reports
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success mb-6 animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger mb-6 animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="glass-card rounded-3xl mb-8 animate-slide-up stagger-2 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-full h-1 bg-gradient-premium"></div>
    <div class="p-6 border-b border-gray-100 bg-white/40">
        <form method="get" class="flex flex-col sm:flex-row gap-4 items-end">
            <div class="w-full sm:w-64">
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="date">Date</label>
                <input class="form-control bg-white shadow-sm rounded-xl border-gray-200" type="date" id="date" name="date" value="<?= h($date) ?>" required>
            </div>
            <div>
                <button class="btn btn-gradient rounded-xl px-6 shadow-md">Open Date</button>
            </div>
        </form>
    </div>

    <div class="p-6">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-gray-50/50 rounded-2xl p-4 border border-gray-100 hover:shadow-md transition-shadow group">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Opening</div>
                <div class="text-xl font-extrabold text-gray-900"><?= h(number_format($totals['opening'], 2)) ?></div>
            </div>
            <div class="bg-gray-50/50 rounded-2xl p-4 border border-gray-100 hover:shadow-md transition-shadow group">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Purchased</div>
                <div class="text-xl font-extrabold text-gray-900"><?= h(number_format($totals['purchased'], 2)) ?></div>
            </div>
            <div class="bg-gray-50/50 rounded-2xl p-4 border border-gray-100 hover:shadow-md transition-shadow group">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Sold</div>
                <div class="text-xl font-extrabold text-brand-600"><?= h(number_format($totals['sold'], 2)) ?></div>
            </div>
            <?php if ($canViewProfit): ?>
                <div class="bg-green-50/50 rounded-2xl p-4 border border-green-100 hover:shadow-md transition-shadow group">
                    <div class="text-xs font-bold text-green-600 uppercase tracking-wider mb-1">Total Profit</div>
                    <div class="text-xl font-extrabold text-green-700"><?= h(number_format($totals['profit'], 2)) ?></div>
                </div>
            <?php endif; ?>
            <div class="bg-blue-50/50 rounded-2xl p-4 border border-blue-100 hover:shadow-md transition-shadow group">
                <div class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1">Total Closing</div>
                <div class="text-xl font-extrabold text-blue-700"><?= h(number_format($totals['closing'], 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="glass-card rounded-3xl mb-8 animate-slide-up stagger-3 overflow-hidden shadow-sm">
    <div class="p-5 bg-white/50 border-b border-gray-100 flex items-center gap-3">
        <div class="p-2 bg-brand-50 text-brand-600 rounded-lg"><i data-lucide="edit-3" class="w-5 h-5"></i></div>
        <div class="text-lg font-bold text-gray-900">Daily Totals Entry</div>
    </div>
    <div class="p-0">
        <form method="post" id="dailyLoadForm">
            <input type="hidden" name="date" value="<?= h($date) ?>">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th class="px-6 py-4">Network</th>
                        <th class="text-end px-6 py-4">Opening Balance</th>
                        <th class="text-end px-6 py-4">Purchased Balance</th>
                        <th class="text-end px-6 py-4">Sold (auto)</th>
                        <?php if ($canViewProfit): ?>
                            <th class="text-end px-6 py-4">Profit (manual)</th>
                        <?php endif; ?>
                        <th class="text-end px-6 py-4">Closing</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($networks as $n): ?>
                        <?php
                        $e = $entriesByNetwork[$n] ?? [];
                        $opening = (float) ($e['opening_balance'] ?? 0);
                        $purchased = (float) ($e['purchased_balance'] ?? 0);
                        $closing = (float) ($e['closing_balance'] ?? 0);
                        $sold = $opening + $purchased - $closing;
                        $profit = (float) ($e['profit'] ?? 0);
                        ?>
                        <tr class="group hover:bg-gray-50/50 transition-colors">
                            <td class="fw-semibold px-6 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-brand-500"></span>
                                    <?= h($n) ?>
                                </div>
                            </td>
                            <td class="text-end px-6 py-3">
                                <input class="form-control form-control-sm text-end js-opening bg-white/80 focus:bg-white rounded-lg shadow-sm border-gray-200" type="number" step="0.01" name="entries[<?= h($n) ?>][opening]" value="<?= h((string) $opening) ?>">
                            </td>
                            <td class="text-end px-6 py-3">
                                <input class="form-control form-control-sm text-end js-purchased bg-white/80 focus:bg-white rounded-lg shadow-sm border-gray-200" type="number" step="0.01" name="entries[<?= h($n) ?>][purchased]" value="<?= h((string) $purchased) ?>">
                            </td>
                            <td class="text-end px-6 py-3">
                                <input class="form-control form-control-sm text-end js-sold bg-gray-100 text-gray-600 font-bold rounded-lg border-transparent" type="text" value="<?= h(number_format($sold, 2, '.', '')) ?>" readonly tabindex="-1">
                            </td>
                            <?php if ($canViewProfit): ?>
                                <td class="text-end px-6 py-3">
                                    <input class="form-control form-control-sm text-end bg-white/80 focus:bg-white rounded-lg shadow-sm border-gray-200" type="number" step="0.01" name="entries[<?= h($n) ?>][profit]" value="<?= h((string) $profit) ?>">
                                </td>
                            <?php endif; ?>
                            <td class="text-end px-6 py-3">
                                <input class="form-control form-control-sm text-end js-closing bg-white/80 focus:bg-white rounded-lg shadow-sm border-gray-200" type="number" step="0.01" name="entries[<?= h($n) ?>][closing]" value="<?= h(number_format($closing, 2, '.', '')) ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-6 bg-gray-50/50 border-t border-gray-100 text-right">
                <button class="btn btn-gradient rounded-xl px-8 shadow-md hover:shadow-lg">
                    <i data-lucide="save" class="w-4 h-4 mr-2"></i> Save Daily Totals
                </button>
            </div>
        </form>
    </div>
</div>

<div class="flex items-center gap-3 mb-6 animate-slide-up stagger-4">
    <div class="h-8 w-1.5 bg-gradient-premium rounded-full"></div>
    <h2 class="text-xl font-bold text-gray-900 m-0">Recent History</h2>
</div>

<div class="glass-card rounded-3xl overflow-hidden animate-slide-up stagger-4 shadow-sm mb-8">
    <div class="table-responsive p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="px-6 py-4">Date</th>
                <th class="text-end px-6 py-4">Opening</th>
                <th class="text-end px-6 py-4">Purchased</th>
                <th class="text-end px-6 py-4">Sold</th>
                <?php if ($canViewProfit): ?>
                    <th class="text-end px-6 py-4">Profit</th>
                <?php endif; ?>
                <th class="text-end px-6 py-4">Closing</th>
                <?php if ($canEditDelete): ?>
                    <th class="text-end px-6 py-4">Actions</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $hrow): ?>
                <tr class="group transition-colors hover:bg-gray-50/50">
                    <td class="px-6 py-3">
                        <a class="font-bold text-brand-600 hover:text-brand-700 flex items-center gap-2" href="<?= h(app_url('load-management/index.php?date=' . (string) $hrow['date'])) ?>">
                            <i data-lucide="calendar" class="w-4 h-4"></i> <?= h((string) $hrow['date']) ?>
                        </a>
                    </td>
                    <td class="text-end px-6 py-3 font-medium text-gray-600"><?= h(number_format((float) $hrow['opening_total'], 2)) ?></td>
                    <td class="text-end px-6 py-3 font-medium text-gray-600"><?= h(number_format((float) $hrow['purchased_total'], 2)) ?></td>
                    <td class="text-end px-6 py-3 font-bold text-gray-900"><?= h(number_format((float) $hrow['sold_total'], 2)) ?></td>
                    <?php if ($canViewProfit): ?>
                        <td class="text-end px-6 py-3 font-bold text-green-600"><?= h(number_format((float) $hrow['profit_total'], 2)) ?></td>
                    <?php endif; ?>
                    <td class="text-end px-6 py-3 font-bold text-blue-600"><?= h(number_format((float) $hrow['closing_total'], 2)) ?></td>
                    <?php if ($canEditDelete): ?>
                        <td class="text-end px-6 py-3">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/index.php?date=' . (string) $hrow['date'])) ?>">Edit</a>
                            <form method="post" class="d-inline m-0">
                                <input type="hidden" name="action" value="delete_day">
                                <input type="hidden" name="delete_date" value="<?= h((string) $hrow['date']) ?>">
                                <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete all load entries for <?= h((string) $hrow['date']) ?>?')">Delete</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?>
                <tr>
                    <td colspan="<?= h((string) (5 + ($canViewProfit ? 1 : 0) + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-gray-400 py-10 font-medium">
                        <div class="flex flex-col items-center justify-center gap-2">
                            <i data-lucide="inbox" class="w-8 h-8 text-gray-300"></i>
                            No load records found.
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function recalcRow(tr) {
        const opening = parseFloat(tr.querySelector('.js-opening')?.value || '0') || 0;
        const purchased = parseFloat(tr.querySelector('.js-purchased')?.value || '0') || 0;
        const closing = parseFloat(tr.querySelector('.js-closing')?.value || '0') || 0;
        const sold = opening + purchased - closing;
        const closingEl = tr.querySelector('.js-closing');
        const soldEl = tr.querySelector('.js-sold');
        if (soldEl) soldEl.value = sold.toFixed(2);
    }

    document.querySelectorAll('#dailyLoadForm tbody tr').forEach(tr => {
        tr.querySelectorAll('input').forEach(inp => {
            inp.addEventListener('input', () => recalcRow(tr));
        });
        recalcRow(tr);
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
