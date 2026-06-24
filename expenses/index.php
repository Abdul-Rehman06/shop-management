<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pageTitle = 'Expenses - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canEditDelete = app_can_edit_delete_records();

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

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Expenses</h1>
        <div class="text-gray-500 text-sm">Manage shop expenses and daily outgoing costs</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift rounded-xl" href="<?= h(app_url('expenses/report.php')) ?>">
            <i class="bi bi-file-earmark-bar-graph"></i> Reports
        </a>
        <a class="btn btn-gradient shadow-glow rounded-xl" href="<?= h(app_url('expenses/add.php')) ?>">
            <i class="bi bi-plus-lg"></i> Add Expense
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4 animate-slide-up stagger-1">
    <div class="col-12 col-md-6">
        <div class="p-4 bg-light rounded-4 border-start border-danger border-4 h-100 transition-all hover-lift">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="text-muted small fw-bold text-uppercase tracking-wider">Today Expense</div>
                <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-circle">
                    <i class="bi bi-calendar-day"></i>
                </div>
            </div>
            <div class="h3 mb-0 font-bold text-danger"><?= h(number_format($todayTotal, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="p-4 bg-light rounded-4 border-start border-warning border-4 h-100 transition-all hover-lift">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="text-muted small fw-bold text-uppercase tracking-wider">Monthly Expense</div>
                <div class="bg-warning bg-opacity-10 text-warning p-2 rounded-circle">
                    <i class="bi bi-calendar-month"></i>
                </div>
            </div>
            <div class="h3 mb-0 font-bold text-warning"><?= h(number_format($monthlyTotal, 2)) ?></div>
        </div>
    </div>
</div>

<div class="glass-card animate-slide-up stagger-2">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-table">
                <thead class="bg-light bg-opacity-50">
                <tr>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Date</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Category</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Amount</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Description</th>
                    <?php if ($canEditDelete): ?>
                        <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody class="border-top-0">
                <?php foreach ($rows as $r): ?>
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3 font-medium text-gray-600"><?= h((string) $r['date']) ?></td>
                        <td class="px-4 py-3">
                            <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1 rounded-pill border border-secondary border-opacity-25">
                                <?= h((string) $r['category']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-end font-bold text-danger"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= h((string) ($r['description'] ?? '')) ?></td>
                        <?php if ($canEditDelete): ?>
                            <td class="px-4 py-3 text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('expenses/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (4 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i class="bi bi-receipt fs-1 text-gray-300 mb-2"></i>
                                <p class="mb-0">No expenses yet.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
