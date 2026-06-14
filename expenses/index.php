<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pageTitle = 'Expenses - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$todayTotal = exp_total($pdo, $today, $today);
$monthlyTotal = exp_total($pdo, $monthStart, $monthEnd);

$stmt = $pdo->query('
    SELECT id, date, category, amount, description
    FROM expenses
    ORDER BY date DESC, id DESC
    LIMIT 50
');
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Expenses</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-secondary btn-sm" href="<?= h(app_url('expenses/report.php')) ?>">Reports</a>
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('expenses/add.php')) ?>">Add Expense</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Today Expense</div>
                <div class="h5 mb-0"><?= h(number_format($todayTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Monthly Expense</div>
                <div class="h5 mb-0"><?= h(number_format($monthlyTotal, 2)) ?></div>
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
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['category']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td><?= h((string) ($r['description'] ?? '')) ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                            <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('expenses/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No expenses yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

