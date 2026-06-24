<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/inv_lib.php';

$pageTitle = 'Inventory - Shop Management';

$pdo = db();
app_require_stock_access();
$success = flash_get('success');
$error = flash_get('error');

$rows = inv_product_rows_with_stock($pdo, 200);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Inventory</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-gradient shadow-glow btn-sm" href="<?= h(app_url('inventory/add.php')) ?>">Add Product</a>
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
                    <th>Product</th>
                    <th class="text-end">Purchase</th>
                    <th class="text-end">Sale</th>
                    <th class="text-end">Opening</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Current</th>
                    <th class="text-end">Low Limit</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $current = (int) $r['current_stock'];
                    $limit = (int) $r['low_stock_limit'];
                    $isLow = $current < $limit;
                    ?>
                    <tr>
                        <td><?= h((string) $r['product_name']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['purchase_price'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['sale_price'], 2)) ?></td>
                        <td class="text-end"><?= h((string) (int) $r['opening_stock']) ?></td>
                        <td class="text-end"><?= h((string) (int) $r['sold_qty']) ?></td>
                        <td class="text-end fw-semibold"><?= h((string) $current) ?></td>
                        <td class="text-end"><?= h((string) $limit) ?></td>
                        <td>
                            <?php if ($isLow): ?>
                                <span class="badge bg-danger">Low</span>
                            <?php else: ?>
                                <span class="badge bg-success">OK</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                            <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('inventory/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No products yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="text-muted small mt-3">
    Current Stock = Opening Stock - Sold Quantity
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
