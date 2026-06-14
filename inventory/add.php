<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Product - Shop Management';

$pdo = db();

$productName = '';
$purchasePrice = '';
$salePrice = '';
$openingStock = '';
$lowStockLimit = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productName = trim((string) ($_POST['product_name'] ?? ''));
    $purchasePrice = trim((string) ($_POST['purchase_price'] ?? ''));
    $salePrice = trim((string) ($_POST['sale_price'] ?? ''));
    $openingStock = trim((string) ($_POST['stock'] ?? ''));
    $lowStockLimit = trim((string) ($_POST['low_stock_limit'] ?? ''));

    if ($productName === '') {
        $error = 'Product name is required.';
    } elseif ($purchasePrice === '' || !is_numeric($purchasePrice)) {
        $error = 'Purchase price must be a number.';
    } elseif ($salePrice === '' || !is_numeric($salePrice)) {
        $error = 'Sale price must be a number.';
    } elseif ($openingStock === '' || !is_numeric($openingStock)) {
        $error = 'Opening stock must be a number.';
    } elseif ($lowStockLimit === '' || !is_numeric($lowStockLimit)) {
        $error = 'Low stock limit must be a number.';
    } else {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO products (product_name, purchase_price, sale_price, stock, low_stock_limit)
                VALUES (:product_name, :purchase_price, :sale_price, :stock, :low_stock_limit)
            ');
            $stmt->execute([
                ':product_name' => $productName,
                ':purchase_price' => (float) $purchasePrice,
                ':sale_price' => (float) $salePrice,
                ':stock' => (int) $openingStock,
                ':low_stock_limit' => (int) $lowStockLimit,
            ]);

            flash_set('success', 'Product added successfully.');
            app_redirect('inventory/index.php');
        } catch (Throwable $e) {
            $error = 'Product name already exists.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Product</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="product_name">Product Name</label>
                    <input class="form-control" type="text" id="product_name" name="product_name" value="<?= h($productName) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="purchase_price">Purchase Price</label>
                    <input class="form-control" type="number" step="0.01" id="purchase_price" name="purchase_price" value="<?= h($purchasePrice) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="sale_price">Sale Price</label>
                    <input class="form-control" type="number" step="0.01" id="sale_price" name="sale_price" value="<?= h($salePrice) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="stock">Opening Stock</label>
                    <input class="form-control" type="number" step="1" id="stock" name="stock" value="<?= h($openingStock) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="low_stock_limit">Low Stock Limit</label>
                    <input class="form-control" type="number" step="1" id="low_stock_limit" name="low_stock_limit" value="<?= h($lowStockLimit) ?>" required>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

