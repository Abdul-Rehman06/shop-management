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

$productName = (string) $row['product_name'];
$category = (string) ($row['category'] ?? 'Others');
$brand = (string) ($row['brand'] ?? '');
$sku = (string) ($row['sku'] ?? '');
$barcode = (string) ($row['barcode'] ?? '');
$purchasePrice = (string) $row['purchase_price'];
$salePrice = (string) $row['sale_price'];
$currentStock = (string) ($row['current_stock'] ?? $row['stock']);
$lowStockLimit = (string) $row['low_stock_limit'];
$unit = (string) ($row['unit'] ?? 'Piece');
$adjustmentDate = date('Y-m-d');
$adjustmentNotes = '';
$error = '';
$categories = inv_categories();
$adminId = inv_current_admin_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productName = trim((string) ($_POST['product_name'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? 'Others'));
    $brand = trim((string) ($_POST['brand'] ?? ''));
    $sku = trim((string) ($_POST['sku'] ?? ''));
    $barcode = trim((string) ($_POST['barcode'] ?? ''));
    $purchasePrice = trim((string) ($_POST['purchase_price'] ?? ''));
    $salePrice = trim((string) ($_POST['sale_price'] ?? ''));
    $currentStock = trim((string) ($_POST['current_stock'] ?? ''));
    $lowStockLimit = trim((string) ($_POST['low_stock_limit'] ?? ''));
    $unit = trim((string) ($_POST['unit'] ?? 'Piece'));
    $adjustmentDate = trim((string) ($_POST['adjustment_date'] ?? date('Y-m-d')));
    $adjustmentNotes = trim((string) ($_POST['adjustment_notes'] ?? ''));

    if ($productName === '') {
        $error = 'Product name is required.';
    } elseif ($category === '') {
        $error = 'Category is required.';
    } elseif ($purchasePrice === '' || !is_numeric($purchasePrice)) {
        $error = 'Purchase price must be a number.';
    } elseif ($salePrice === '' || !is_numeric($salePrice)) {
        $error = 'Sale price must be a number.';
    } elseif ($currentStock === '' || !is_numeric($currentStock)) {
        $error = 'Current stock must be a number.';
    } elseif ($lowStockLimit === '' || !is_numeric($lowStockLimit)) {
        $error = 'Low stock limit must be a number.';
    } else {
        try {
            inv_update_product($pdo, $id, [
                'product_name' => $productName,
                'category' => $category,
                'brand' => $brand,
                'sku' => $sku,
                'barcode' => $barcode,
                'purchase_price' => (float) $purchasePrice,
                'sale_price' => (float) $salePrice,
                'current_stock' => (int) $currentStock,
                'low_stock_limit' => (int) $lowStockLimit,
                'unit' => $unit,
                'adjustment_date' => $adjustmentDate,
                'adjustment_notes' => $adjustmentNotes,
            ], $adminId);
            flash_set('success', 'Product updated successfully.');
            app_redirect('inventory/index.php');
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'sku') !== false) {
                $error = 'SKU already exists.';
            } elseif (stripos($e->getMessage(), 'product_name') !== false) {
                $error = 'Product name already exists.';
            } else {
                $error = 'Could not update product.';
            }
        }
    }
}

$pageTitle = 'Edit Product - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Product</h1>
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
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <?php foreach ($categories as $categoryOption): ?>
                            <option value="<?= h($categoryOption) ?>" <?= $category === $categoryOption ? 'selected' : '' ?>><?= h($categoryOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="brand">Brand (Optional)</label>
                    <input class="form-control" type="text" id="brand" name="brand" value="<?= h($brand) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="sku">SKU</label>
                    <input class="form-control" type="text" id="sku" name="sku" value="<?= h($sku) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="barcode">Barcode (Optional)</label>
                    <input class="form-control" type="text" id="barcode" name="barcode" value="<?= h($barcode) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="unit">Unit</label>
                    <input class="form-control" type="text" id="unit" name="unit" value="<?= h($unit) ?>" required>
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
                    <label class="form-label" for="current_stock">Current Stock</label>
                    <input class="form-control" type="number" step="1" min="0" id="current_stock" name="current_stock" value="<?= h($currentStock) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="low_stock_limit">Low Stock Limit</label>
                    <input class="form-control" type="number" step="1" id="low_stock_limit" name="low_stock_limit" value="<?= h($lowStockLimit) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="adjustment_date">Adjustment Date</label>
                    <input class="form-control" type="date" id="adjustment_date" name="adjustment_date" value="<?= h($adjustmentDate) ?>" required>
                </div>
                <div class="col-12 col-md-9">
                    <label class="form-label" for="adjustment_notes">Adjustment Notes (Optional)</label>
                    <input class="form-control" type="text" id="adjustment_notes" name="adjustment_notes" value="<?= h($adjustmentNotes) ?>" placeholder="Used only when current stock is changed">
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-gradient shadow-glow">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
