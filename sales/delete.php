<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/sales_lib.php';

$pdo = db();
app_require_edit_delete_access();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid sale.');
    app_redirect('sales/index.php');
}

$row = sales_find($pdo, $id);
if (!$row) {
    flash_set('error', 'Sale not found.');
    app_redirect('sales/index.php');
}

$product = sales_product_by_id($pdo, (int) $row['product_id']);
$returnedQty = sales_returned_qty($pdo, $id);
$remainingQty = max(0, (int) $row['quantity'] - $returnedQty);
$adminId = inv_current_admin_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        if ($remainingQty > 0) {
            inv_adjust_stock(
                $pdo,
                (int) $row['product_id'],
                $remainingQty,
                date('Y-m-d'),
                'manual_adjustment',
                $adminId,
                'sale',
                $id,
                'SALE-' . $id,
                'Stock restored after deleting sale.'
            );
        }

        $stmt = $pdo->prepare('DELETE FROM sales WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $pdo->commit();
        flash_set('success', 'Sale deleted successfully.');
        app_redirect('sales/index.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'Could not delete sale.');
        app_redirect('sales/index.php');
    }
}

$pageTitle = 'Delete Sale - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Sale</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('sales/index.php')) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this sale?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-4"><span class="text-muted">Product:</span> <?= h((string) ($product['product_name'] ?? '')) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Qty:</span> <?= h((string) (int) $row['quantity']) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Returned:</span> <?= h((string) $returnedQty) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Sale Price:</span> <?= h(number_format((float) $row['sale_price'], 2)) ?></div>
        </div>
    </div>
</div>

<form method="post">
    <button class="btn btn-danger">Yes, delete</button>
    <a class="btn btn-outline-secondary" href="<?= h(app_url('sales/index.php')) ?>">Cancel</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
