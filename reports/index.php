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
if (!$canViewProfit && in_array($filters['module'], ['sales', 'load'], true)) {
    flash_set('error', 'Access denied.');
    app_redirect('reports/index.php?module=expenses');
}

if (!$canViewProfit) {
    unset($modules['sales'], $modules['load']);
    if (!isset($modules[$filters['module']])) {
        $filters['module'] = array_key_first($modules) ?: 'expenses';
    }
}

$data = report_fetch($pdo, $filters);

$query = [
    'module' => $filters['module'],
    'from' => $filters['from'],
    'to' => $filters['to'],
];
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

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
