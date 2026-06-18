<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Sales - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canViewProfit = app_can_view_profit();
$canEditDelete = app_can_edit_delete_records();

$stmt = $pdo->query('
    SELECT s.id, s.product_id, p.product_name, s.quantity, s.sale_price, s.profit, s.created_at
    FROM sales s
    JOIN products p ON p.id = s.product_id
    ORDER BY s.created_at DESC, s.id DESC
    LIMIT 50
');
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-1 text-gray-900 font-bold tracking-tight">Sales Management</h1>
        <p class="text-gray-500 text-sm mb-0">Track all your product sales<?= $canViewProfit ? ' and profits' : '' ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canViewProfit): ?>
            <a class="btn btn-secondary btn-sm" href="<?= h(app_url('reports/index.php?module=sales')) ?>">
                <i data-lucide="bar-chart-3" class="w-4 h-4"></i> Reports
            </a>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('sales/add.php')) ?>">
            <i data-lucide="shopping-cart" class="w-4 h-4"></i> Add Sale
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date/Time</th>
                    <th>Product</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Sale Price</th>
                    <th class="text-end">Total</th>
                    <?php if ($canViewProfit): ?>
                        <th class="text-end">Profit</th>
                    <?php endif; ?>
                    <?php if ($canEditDelete): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $total = (int) $r['quantity'] * (float) $r['sale_price']; ?>
                    <tr>
                        <td><?= h((string) $r['created_at']) ?></td>
                        <td><?= h((string) $r['product_name']) ?></td>
                        <td class="text-end"><?= h((string) (int) $r['quantity']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['sale_price'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $total, 2)) ?></td>
                        <?php if ($canViewProfit): ?>
                            <td class="text-end"><?= h(number_format((float) $r['profit'], 2)) ?></td>
                        <?php endif; ?>
                        <?php if ($canEditDelete): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('sales/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('sales/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (5 + ($canViewProfit ? 1 : 0) + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-4">No sales yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
