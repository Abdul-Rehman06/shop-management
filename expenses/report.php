<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pageTitle = 'Expense Reports - Shop Management';

$pdo = db();
$categories = exp_categories();

$preset = trim((string) ($_GET['preset'] ?? ''));

$today = date('Y-m-d');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');

if ($preset === 'today') {
    $from = $today;
    $to = $today;
} elseif ($preset === 'month') {
    $from = date('Y-m-01');
    $to = date('Y-m-t');
}

if ($from === '') {
    $from = $today;
}
if ($to === '') {
    $to = $today;
}

$category = trim((string) ($_GET['category'] ?? ''));
if ($category !== '' && !in_array($category, $categories, true)) {
    $category = '';
}

$params = [
    ':from' => $from,
    ':to' => $to,
];

$where = 'WHERE date >= :from AND date <= :to';
if ($category !== '') {
    $where .= ' AND category = :category';
    $params[':category'] = $category;
}

$stmt = $pdo->prepare("
    SELECT id, date, category, amount, description
    FROM expenses
    {$where}
    ORDER BY date ASC, id ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = exp_total($pdo, $from, $to, $category);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Expense Reports</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/index.php')) ?>">Back</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('expenses/report.php?preset=today')) ?>">Daily</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('expenses/report.php?preset=month')) ?>">Monthly</a>
    </div>
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
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= h($c) ?>" <?= $c === $category ? 'selected' : '' ?>><?= h($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-gradient shadow-glow w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Expense</div>
                <div class="h5 mb-0"><?= h(number_format($total, 2)) ?></div>
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
                    <th>Category</th>
                    <th class="text-end">Amount</th>
                    <th>Description</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['category']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td><?= h((string) ($r['description'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No data found for selected filters.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

