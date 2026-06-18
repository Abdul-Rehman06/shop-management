<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/inv_lib.php';

$pdo = db();
app_require_stock_access();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid product.');
    app_redirect('inventory/index.php');
}

$row = inv_find($pdo, $id);
if (!$row) {
    flash_set('error', 'Product not found.');
    app_redirect('inventory/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        flash_set('success', 'Product deleted successfully.');
        app_redirect('inventory/index.php');
    } catch (Throwable $e) {
        flash_set('error', 'Cannot delete product because it has sales records.');
        app_redirect('inventory/index.php');
    }
}

$pageTitle = 'Delete Product - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Product</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/index.php')) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this product?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-4"><span class="text-muted">Product:</span> <?= h((string) $row['product_name']) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Purchase:</span> <?= h(number_format((float) $row['purchase_price'], 2)) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Sale:</span> <?= h(number_format((float) $row['sale_price'], 2)) ?></div>
        </div>
    </div>
</div>

<form method="post">
    <button class="btn btn-danger">Yes, delete</button>
    <a class="btn btn-outline-secondary" href="<?= h(app_url('inventory/index.php')) ?>">Cancel</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
