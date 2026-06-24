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
    SELECT
        s.id, s.product_id, p.product_name, s.quantity, s.sale_price, s.profit, s.created_at,
        COALESCE(r.returned_qty, 0) AS returned_qty
    FROM sales s
    JOIN products p ON p.id = s.product_id
    LEFT JOIN (
        SELECT sale_id, COALESCE(SUM(quantity), 0) AS returned_qty
        FROM sales_returns
        GROUP BY sale_id
    ) r ON r.sale_id = s.id
    ORDER BY s.created_at DESC, s.id DESC
    LIMIT 50
');
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold tracking-tight">Sales Management</h1>
        <p class="text-gray-500 text-sm mb-0">Track all your product sales<?= $canViewProfit ? ' and profits' : '' ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canViewProfit): ?>
            <a class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift rounded-xl" href="<?= h(app_url('reports/index.php?module=sales')) ?>">
                <i data-lucide="bar-chart-3" class="w-4 h-4 text-gray-600"></i> Reports
            </a>
        <?php endif; ?>
        <a class="btn btn-gradient shadow-glow rounded-xl" href="<?= h(app_url('sales/add.php')) ?>">
            <i data-lucide="shopping-cart" class="w-4 h-4"></i> Add Sale
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="glass-card animate-slide-up stagger-1">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-table">
                <thead class="bg-light bg-opacity-50">
                <tr>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Date/Time</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Product</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Qty</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Returned</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Sale Price</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Total</th>
                    <?php if ($canViewProfit): ?>
                        <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Profit</th>
                    <?php endif; ?>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Return/Exchange</th>
                    <?php if ($canEditDelete): ?>
                        <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody class="border-top-0">
                <?php foreach ($rows as $r): ?>
                    <?php $total = (int) $r['quantity'] * (float) $r['sale_price']; ?>
                    <?php
                    $returnedQty = (int) ($r['returned_qty'] ?? 0);
                    $remainingQty = max(0, (int) $r['quantity'] - $returnedQty);
                    ?>
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3 font-medium text-gray-600"><?= h((string) $r['created_at']) ?></td>
                        <td class="px-4 py-3">
                            <span class="fw-bold text-gray-800"><?= h((string) $r['product_name']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-end font-bold text-primary"><?= h((string) (int) $r['quantity']) ?></td>
                        <td class="px-4 py-3 text-end <?= $returnedQty > 0 ? 'text-warning font-bold' : 'text-gray-400' ?>"><?= h((string) $returnedQty) ?></td>
                        <td class="px-4 py-3 text-end text-gray-600"><?= h(number_format((float) $r['sale_price'], 2)) ?></td>
                        <td class="px-4 py-3 text-end font-bold text-success"><?= h(number_format((float) $total, 2)) ?></td>
                        <?php if ($canViewProfit): ?>
                            <td class="px-4 py-3 text-end font-medium text-green-600"><?= h(number_format((float) $r['profit'], 2)) ?></td>
                        <?php endif; ?>
                        <td class="px-4 py-3 text-end">
                            <a class="btn btn-outline-warning btn-sm <?= $remainingQty <= 0 ? 'disabled' : '' ?>" href="<?= h(app_url('sales/return.php?id=' . (int) $r['id'])) ?>">Return</a>
                            <a class="btn btn-outline-info btn-sm <?= $remainingQty <= 0 ? 'disabled' : '' ?>" href="<?= h(app_url('sales/exchange.php?id=' . (int) $r['id'])) ?>">Exchange</a>
                        </td>
                        <?php if ($canEditDelete): ?>
                            <td class="px-4 py-3 text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('sales/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('sales/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (8 + ($canViewProfit ? 1 : 0) + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i data-lucide="shopping-cart" class="w-8 h-8 text-gray-300 mb-2"></i>
                                <p class="mb-0">No sales yet.</p>
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
