<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/report_lib.php';

$pageTitle = 'Reports - Shop Management';

$pdo = db();
$filters = report_filters_from_request();
$modules = report_modules();
$networks = report_load_networks($pdo);

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

$baseQueryString = http_build_query($query);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Reports</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('reports/export.php?format=csv&' . $baseQueryString)) ?>">CSV</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('reports/export.php?format=xls&' . $baseQueryString)) ?>">Excel</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('reports/export.php?format=pdf&' . $baseQueryString)) ?>">PDF</a>
    </div>
</div>

<?php if ($topCards): ?>
    <div class="row g-3 mb-3">
        <?php foreach ($topCards as $c): ?>
            <div class="col-12 col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small"><?= h((string) ($c['label'] ?? '')) ?></div>
                        <div class="h5 mb-0">Rs <?= h((string) ($c['value'] ?? '0.00')) ?></div>
                        <div class="text-muted small mt-1"><?= h((string) ($filters['from'] ?? '')) ?> to <?= h((string) ($filters['to'] ?? '')) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label" for="range">Range</label>
                <select class="form-select" id="range" name="range">
                    <option value="today" <?= ($filters['range'] ?? 'today') === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="7days" <?= ($filters['range'] ?? '') === '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="month" <?= ($filters['range'] ?? '') === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="custom" <?= ($filters['range'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="module">Report</label>
                <select class="form-select" id="module" name="module">
                    <?php foreach ($modules as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $filters['module'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= h($filters['from']) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= h($filters['to']) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-primary w-100">Filter</button>
            </div>

            <?php if ($filters['module'] === 'load'): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="network">Network</label>
                    <select class="form-select" id="network" name="network">
                        <option value="">All</option>
                        <?php foreach ($networks as $n): ?>
                            <option value="<?= h($n) ?>" <?= $filters['network'] === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif (in_array($filters['module'], ['easypaisa', 'jazzcash', 'bank'], true)): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="type">Transaction Type</label>
                    <select class="form-select" id="type" name="type">
                        <option value="">All</option>
                        <option value="receiving" <?= $filters['type'] === 'receiving' ? 'selected' : '' ?>>Receiving</option>
                        <option value="sending" <?= $filters['type'] === 'sending' ? 'selected' : '' ?>>Sending</option>
                    </select>
                </div>
            <?php elseif ($filters['module'] === 'expenses'): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="type">Category</label>
                    <select class="form-select" id="type" name="type">
                        <option value="">All</option>
                        <?php foreach (['Rent','Bills','Salary','Maintenance','Other'] as $c): ?>
                            <option value="<?= h($c) ?>" <?= $filters['type'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($filters['module'] === 'all'): ?>
    <?php foreach ($modules as $key => $label): ?>
        <?php if ($key === 'all') continue; ?>
        <?php $d = $allReports[$key] ?? ['headers' => [], 'rows' => [], 'summary' => []]; ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
                    <div class="fw-semibold"><?= h($label) ?></div>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('reports/index.php?module=' . urlencode($key) . '&range=' . urlencode((string) ($filters['range'] ?? 'today')) . '&from=' . urlencode($filters['from']) . '&to=' . urlencode($filters['to']))) ?>">Open</a>
                </div>

                <?php if (!empty($d['summary'])): ?>
                    <div class="row g-2 mb-2">
                        <?php foreach ($d['summary'] as $k => $v): ?>
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-2">
                                    <div class="text-muted small"><?= h((string) $k) ?></div>
                                    <div class="fw-semibold"><?= h((string) $v) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
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
                                <td colspan="<?= h((string) max(1, count((array) ($d['headers'] ?? [])))) ?>" class="text-center text-muted py-3">No data found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <?php if (!empty($data['summary'])): ?>
        <div class="row g-3 mb-3">
            <?php foreach ($data['summary'] as $k => $v): ?>
                <div class="col-12 col-md-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="text-muted small"><?= h((string) $k) ?></div>
                            <div class="h6 mb-0"><?= h((string) $v) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
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
                            <td colspan="<?= h((string) count($data['headers'])) ?>" class="text-center text-muted py-4">No data found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
