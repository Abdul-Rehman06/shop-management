<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/report_lib.php';

$pageTitle = 'Reports - Shop Management';

$pdo = db();
$filters = report_filters_from_request();
$modules = report_modules();
$networks = report_load_networks($pdo);
$dealerNames = [];
try {
    $stmt = $pdo->query("SELECT dealer_name FROM dealers WHERE status = 'active' ORDER BY dealer_name ASC");
    $dealerNames = array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['dealer_name'] ?? ''), $stmt->fetchAll())));
} catch (Throwable $e) {
    $dealerNames = [];
}
$adminRows = [];
try {
    $adminRows = $pdo->query("SELECT id, name, role FROM admins ORDER BY role ASC, name ASC, id ASC")->fetchAll();
} catch (Throwable $e) {
    $adminRows = [];
}
$dealerTxnTypes = [
    '' => 'All',
    'advance_payment' => 'Advance Payment to Dealer',
    'load_received_against_advance' => 'Load Received Against Advance',
    'credit_load_received' => 'Credit Load Received',
    'dealer_payment' => 'Dealer Payment',
];
$billCompanies = bill_fetch_companies($pdo);
$inventoryCategories = inv_product_categories_in_use($pdo);

$canViewProfit = app_can_view_profit();
if (!$canViewProfit && in_array($filters['module'], ['sales', 'load', 'load_txn'], true)) {
    flash_set('error', 'Access denied.');
    app_redirect('reports/index.php?module=expenses');
}

if (!$canViewProfit) {
    unset($modules['sales'], $modules['load'], $modules['load_txn']);
    if (!isset($modules[$filters['module']])) {
        $filters['module'] = array_key_first($modules) ?: 'expenses';
    }
}

$allReports = [];
$data = ['headers' => [], 'rows' => [], 'summary' => []];
if ($filters['module'] === 'all') {
    $allKeys = array_keys($modules);
    $allKeys = array_values(array_filter($allKeys, static fn (string $k): bool => $k !== 'all'));
    foreach ($allKeys as $k) {
        $f = $filters;
        $f['module'] = $k;
        try {
            $allReports[$k] = report_fetch($pdo, $f);
        } catch (Throwable $e) {
            $allReports[$k] = ['headers' => [], 'rows' => [], 'summary' => ['Error' => 'Could not load']];
        }
    }
} else {
    $data = report_fetch($pdo, $filters);
}

$toNumber = static function ($value): float {
    if (is_numeric($value)) {
        return (float) $value;
    }
    $s = trim((string) $value);
    if ($s === '') {
        return 0.0;
    }
    $s = preg_replace('/[^0-9.\-]+/', '', $s) ?: '0';
    return (float) $s;
};

$topCards = [];
if ($filters['module'] === 'all') {
    $get = static function (array $allReports, string $moduleKey, string $field, callable $toNumber): ?float {
        $summary = $allReports[$moduleKey]['summary'] ?? null;
        if (!is_array($summary)) {
            return null;
        }
        $needle = strtolower(trim($field));
        foreach ($summary as $k => $v) {
            if (strtolower(trim((string) $k)) === $needle) {
                if ($v === null || $v === '') {
                    return null;
                }
                return $toNumber($v);
            }
        }
        return null;
    };

    $salesTotal = $get($allReports, 'sales', 'Total Sales', $toNumber);
    if ($salesTotal !== null) {
        $topCards[] = ['label' => 'Sales', 'value' => number_format($salesTotal, 2)];
    }
    $salesProfit = $get($allReports, 'sales', 'Total Profit', $toNumber);
    if ($salesProfit !== null) {
        $topCards[] = ['label' => 'Profit', 'value' => number_format($salesProfit, 2)];
    }
    $inventoryValue = $get($allReports, 'inventory', 'Current Purchase Value', $toNumber);
    if ($inventoryValue !== null) {
        $topCards[] = ['label' => 'Inventory Value', 'value' => number_format($inventoryValue, 2)];
    }
    $inventoryProfit = $get($allReports, 'inventory', 'Expected Profit', $toNumber);
    if ($inventoryProfit !== null) {
        $topCards[] = ['label' => 'Expected Inv. Profit', 'value' => number_format($inventoryProfit, 2)];
    }
    $expTotal = $get($allReports, 'expenses', 'Total', $toNumber);
    if ($expTotal !== null) {
        $topCards[] = ['label' => 'Expenses', 'value' => number_format($expTotal, 2)];
    }

    $loadTxnTotal = $get($allReports, 'load_txn', 'Total Amount', $toNumber);
    if ($loadTxnTotal !== null) {
        $topCards[] = ['label' => 'Load Transactions', 'value' => number_format($loadTxnTotal, 2)];
    }
    $loadSold = $get($allReports, 'load', 'Sold', $toNumber);
    if ($loadSold !== null) {
        $topCards[] = ['label' => 'Load Sold (Daily)', 'value' => number_format($loadSold, 2)];
    }
    $walletNet = $get($allReports, 'wallet', 'Net', $toNumber);
    if ($walletNet !== null) {
        $topCards[] = ['label' => 'Wallet Net', 'value' => number_format($walletNet, 2)];
    }
    $dealerPaid = $get($allReports, 'dealer_payments', 'Total Payments', $toNumber);
    if ($dealerPaid !== null) {
        $topCards[] = ['label' => 'Dealer Payments', 'value' => number_format($dealerPaid, 2)];
    }
    $billPending = $get($allReports, 'bill_payments', 'Pending Bills Amount', $toNumber);
    if ($billPending !== null) {
        $topCards[] = ['label' => 'Pending Bills', 'value' => number_format($billPending, 2)];
    }
    $billCommission = $get($allReports, 'bill_payments', 'Bill Commission', $toNumber);
    if ($billCommission !== null) {
        $topCards[] = ['label' => 'Bill Commission', 'value' => number_format($billCommission, 2)];
    }

    $udharPlus = $get($allReports, 'udhar', 'Udhar (+)', $toNumber);
    if ($udharPlus !== null) {
        $topCards[] = ['label' => 'Udhar (+)', 'value' => number_format($udharPlus, 2)];
    }
    $udharMinus = $get($allReports, 'udhar', 'Payment (-)', $toNumber);
    if ($udharMinus !== null) {
        $topCards[] = ['label' => 'Udhar Payment (-)', 'value' => number_format($udharMinus, 2)];
    }
    $udharBal = $get($allReports, 'udhar', 'Balance', $toNumber);
    if ($udharBal !== null) {
        $topCards[] = ['label' => 'Udhar Balance', 'value' => number_format($udharBal, 2)];
    }
    $creditRem = $get($allReports, 'credit', 'Remaining', $toNumber);
    if ($creditRem !== null) {
        $topCards[] = ['label' => 'Credit Remaining', 'value' => number_format($creditRem, 2)];
    }
} else {
    foreach (($data['summary'] ?? []) as $k => $v) {
        $topCards[] = ['label' => (string) $k, 'value' => (string) $v];
    }
}

$query = [
    'module' => $filters['module'],
    'from' => $filters['from'],
    'to' => $filters['to'],
];
if (($filters['range'] ?? '') !== '') {
    $query['range'] = (string) $filters['range'];
}
if ($filters['network'] !== '') {
    $query['network'] = $filters['network'];
}
if ($filters['type'] !== '') {
    $query['type'] = $filters['type'];
}
if (($filters['dealer'] ?? '') !== '') {
    $query['dealer'] = (string) $filters['dealer'];
}
if (($filters['txn_type'] ?? '') !== '') {
    $query['txn_type'] = (string) $filters['txn_type'];
}
if ((int) ($filters['created_by'] ?? 0) > 0) {
    $query['created_by'] = (int) $filters['created_by'];
}
if (($filters['company'] ?? '') !== '') {
    $query['company'] = (string) ($filters['company'] ?? '');
}
if (($filters['status'] ?? '') !== '') {
    $query['status'] = (string) ($filters['status'] ?? '');
}
if (($filters['q'] ?? '') !== '') {
    $query['q'] = (string) ($filters['q'] ?? '');
}
if (($filters['category'] ?? '') !== '') {
    $query['category'] = (string) ($filters['category'] ?? '');
}
if (($filters['stock_status'] ?? '') !== '') {
    $query['stock_status'] = (string) ($filters['stock_status'] ?? '');
}

$baseQueryString = http_build_query($query);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8 animate-slide-up stagger-1">
    <div class="flex items-center gap-3">
        <div class="h-10 w-2 bg-gradient-premium rounded-full"></div>
        <div>
            <h1 class="text-3xl font-bold text-gray-900 tracking-tight m-0">Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Detailed analytics and exports</p>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary bg-white/60 hover:bg-gray-50 border-0 shadow-sm" href="<?= h(app_url('reports/export.php?format=csv&' . $baseQueryString)) ?>">
            <i data-lucide="file-text" class="w-4 h-4 text-gray-500"></i> CSV
        </a>
        <a class="btn btn-outline-secondary bg-white/60 hover:bg-green-50 border-0 shadow-sm text-green-700 hover:text-green-800" href="<?= h(app_url('reports/export.php?format=xls&' . $baseQueryString)) ?>">
            <i data-lucide="file-spreadsheet" class="w-4 h-4 text-green-600"></i> Excel
        </a>
        <a class="btn btn-outline-danger bg-white/60 hover:bg-red-50 border-0 shadow-sm" href="<?= h(app_url('reports/export.php?format=pdf&' . $baseQueryString)) ?>">
            <i data-lucide="file" class="w-4 h-4"></i> PDF
        </a>
    </div>
</div>

<?php if ($topCards): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 mb-8 animate-slide-up stagger-2">
        <?php foreach ($topCards as $c): ?>
            <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
                <div class="absolute -right-6 -top-6 w-24 h-24 bg-brand-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0 opacity-50"></div>
                <div class="relative z-10">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-2"><?= h((string) ($c['label'] ?? '')) ?></div>
                    <div class="text-2xl font-extrabold text-gray-900 tracking-tight">Rs <?= h((string) ($c['value'] ?? '0.00')) ?></div>
                    <div class="text-xs font-medium text-gray-400 mt-2 bg-gray-50/50 px-2 py-1 rounded-lg inline-block"><?= h((string) ($filters['from'] ?? '')) ?> to <?= h((string) ($filters['to'] ?? '')) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="glass-card rounded-3xl mb-8 animate-slide-up stagger-3 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-full h-1 bg-gradient-premium"></div>
    <div class="p-6">
        <form method="get" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4 items-end">
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="range">Range</label>
                <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="range" name="range" onchange="this.form.submit()">
                    <option value="today" <?= ($filters['range'] ?? 'today') === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="7days" <?= ($filters['range'] ?? '') === '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="month" <?= ($filters['range'] ?? '') === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="custom" <?= ($filters['range'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                </select>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="module">Report</label>
                <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="module" name="module">
                    <?php foreach ($modules as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $filters['module'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="from">From</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="date" id="from" name="from" value="<?= h($filters['from']) ?>" required>
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="to">To</label>
                <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" type="date" id="to" name="to" value="<?= h($filters['to']) ?>" required>
            </div>
            <div>
                <button class="btn btn-gradient w-full rounded-xl py-2.5 shadow-md">Apply Filters</button>
            </div>

            <?php if (in_array($filters['module'], ['dealer_ledger', 'dealer_payments'], true)): ?>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="dealer">Dealer</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="dealer" name="dealer">
                        <option value="">All</option>
                        <?php foreach ($dealerNames as $d): ?>
                            <option value="<?= h($d) ?>" <?= (string) ($filters['dealer'] ?? '') === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="txn_type">Transaction Type</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="txn_type" name="txn_type">
                        <?php foreach ($dealerTxnTypes as $k => $label): ?>
                            <option value="<?= h($k) ?>" <?= (string) ($filters['txn_type'] ?? '') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="created_by">User</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="created_by" name="created_by">
                        <option value="0">All</option>
                        <?php foreach ($adminRows as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= (int) ($filters['created_by'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                                <?= h((string) ($a['name'] ?? '')) ?><?= ((string) ($a['role'] ?? '') !== '' ? (' • ' . (string) $a['role']) : '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($filters['module'] === 'load'): ?>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="network">Network</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="network" name="network">
                        <option value="">All</option>
                        <?php foreach ($networks as $n): ?>
                            <option value="<?= h($n) ?>" <?= $filters['network'] === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif (in_array($filters['module'], ['easypaisa', 'jazzcash', 'bank'], true)): ?>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="type">Transaction Type</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="type" name="type">
                        <option value="">All</option>
                        <option value="receiving" <?= $filters['type'] === 'receiving' ? 'selected' : '' ?>>Receiving</option>
                        <option value="sending" <?= $filters['type'] === 'sending' ? 'selected' : '' ?>>Sending</option>
                    </select>
                </div>
            <?php elseif ($filters['module'] === 'expenses'): ?>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="type">Category</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="type" name="type">
                        <option value="">All</option>
                        <?php foreach (['Rent','Bills','Salary','Maintenance','Other'] as $c): ?>
                            <option value="<?= h($c) ?>" <?= $filters['type'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($filters['module'] === 'bill_payments'): ?>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="company">Company</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="company" name="company">
                        <option value="">All</option>
                        <?php foreach ($billCompanies as $companyName): ?>
                            <option value="<?= h($companyName) ?>" <?= (string) ($filters['company'] ?? '') === $companyName ? 'selected' : '' ?>><?= h($companyName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="status">Status</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="status" name="status">
                        <option value="">All</option>
                        <option value="pending" <?= (string) ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="paid" <?= (string) ($filters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="q">Search</label>
                    <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" id="q" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" placeholder="Customer or Bill ID">
                </div>
            <?php elseif ($filters['module'] === 'inventory'): ?>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="category">Category</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="category" name="category">
                        <option value="">All</option>
                        <?php foreach ($inventoryCategories as $categoryName): ?>
                            <option value="<?= h($categoryName) ?>" <?= (string) ($filters['category'] ?? '') === $categoryName ? 'selected' : '' ?>><?= h($categoryName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="stock_status">Status</label>
                    <select class="form-select bg-gray-50 border-0 shadow-sm rounded-xl" id="stock_status" name="stock_status">
                        <option value="">All</option>
                        <option value="in_stock" <?= (string) ($filters['stock_status'] ?? '') === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                        <option value="low_stock" <?= (string) ($filters['stock_status'] ?? '') === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="out_of_stock" <?= (string) ($filters['stock_status'] ?? '') === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1" for="q">Search</label>
                    <input class="form-control bg-gray-50 border-0 shadow-sm rounded-xl" id="q" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" placeholder="Product, SKU or brand">
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($filters['module'] === 'all'): ?>
    <div class="grid grid-cols-1 gap-6 animate-slide-up stagger-4">
        <?php foreach ($modules as $key => $label): ?>
            <?php if ($key === 'all') continue; ?>
            <?php $d = $allReports[$key] ?? ['headers' => [], 'rows' => [], 'summary' => []]; ?>
            <div class="glass-card rounded-3xl overflow-hidden group">
                <div class="p-5 bg-white/50 border-b border-gray-100 flex flex-wrap gap-4 items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-brand-50 text-brand-600 rounded-lg group-hover:scale-110 transition-transform"><i data-lucide="folder-open" class="w-5 h-5"></i></div>
                        <div class="text-lg font-bold text-gray-900"><?= h($label) ?></div>
                    </div>
                    <a class="btn btn-outline-primary btn-sm rounded-xl bg-white hover:bg-brand-50" href="<?= h(app_url('reports/index.php?module=' . urlencode($key) . '&range=' . urlencode((string) ($filters['range'] ?? 'today')) . '&from=' . urlencode($filters['from']) . '&to=' . urlencode($filters['to']))) ?>">
                        Open Details <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </a>
                </div>

                <?php if (!empty($d['summary'])): ?>
                    <div class="p-5 bg-gray-50/30 border-b border-gray-100">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <?php foreach ($d['summary'] as $k => $v): ?>
                                <div class="bg-white rounded-2xl p-4 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                                    <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1"><?= h((string) $k) ?></div>
                                    <div class="text-lg font-extrabold text-gray-900"><?= h((string) $v) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <?php foreach (($d['headers'] ?? []) as $hcol): ?>
                                <th><?= h((string) $hcol) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice(($d['rows'] ?? []), 0, 20) as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= h((string) $cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($d['rows'])): ?>
                            <tr>
                                <td colspan="<?= h((string) max(1, count((array) ($d['headers'] ?? [])))) ?>" class="text-center text-gray-400 py-8 font-medium">No data found in this period.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?php if (!empty($data['summary'])): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6 mb-8 animate-slide-up stagger-4">
            <?php foreach ($data['summary'] as $k => $v): ?>
                <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
                    <div class="absolute -right-4 -top-4 w-20 h-20 bg-brand-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0 opacity-50"></div>
                    <div class="relative z-10">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-2"><?= h((string) $k) ?></div>
                        <div class="text-2xl font-extrabold text-gray-900"><?= h((string) $v) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="glass-card rounded-3xl overflow-hidden animate-slide-up stagger-5 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <?php foreach ($data['headers'] as $hcol): ?>
                        <th><?= h((string) $hcol) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($data['rows'] as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?= h((string) $cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$data['rows']): ?>
                    <tr>
                        <td colspan="<?= h((string) count($data['headers'])) ?>" class="text-center text-gray-400 py-10 font-medium">
                            <div class="flex flex-col items-center justify-center gap-2">
                                <i data-lucide="search-x" class="w-8 h-8 text-gray-300"></i>
                                No data found for the selected criteria.
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
