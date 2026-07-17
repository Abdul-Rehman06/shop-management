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

$saleRow = sales_find($pdo, $id);
if (!$saleRow) {
    flash_set('error', 'Sale not found.');
    app_redirect('sales/index.php');
}

$products = sales_products($pdo);
$adminId = inv_current_admin_id();
$productsMap = [];
foreach ($products as $p) {
    $productsMap[(int) $p['id']] = $p;
}

$returnedQty = sales_returned_qty($pdo, $id);

$productId = (int) $saleRow['product_id'];
$quantity = (string) $saleRow['quantity'];
$salePrice = (string) $saleRow['sale_price'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = trim((string) ($_POST['quantity'] ?? ''));
    $salePrice = trim((string) ($_POST['sale_price'] ?? ''));

    if ($returnedQty > 0) {
        $error = 'Sale with return or exchange history cannot be edited.';
    } elseif ($productId <= 0 || !isset($productsMap[$productId])) {
        $error = 'Please select a product.';
    } elseif ($quantity === '' || !ctype_digit($quantity) || (int) $quantity <= 0) {
        $error = 'Quantity must be a positive whole number.';
    } elseif ($salePrice === '' || !is_numeric($salePrice)) {
        $error = 'Sale price must be a number.';
    } else {
        $product = $productsMap[$productId];
        $purchase = (float) $product['purchase_price'];
        $sale = (float) $salePrice;
        $qty = (int) $quantity;
        $oldProductId = (int) $saleRow['product_id'];
        $oldQty = (int) $saleRow['quantity'];
        $availableStock = (int) ($product['stock'] ?? 0);
        if ($productId === $oldProductId) {
            $availableStock += $oldQty;
        }
        $profit = sales_profit_total($purchase, $sale, $qty);

        if ($qty > $availableStock) {
            $error = 'Insufficient stock. Available stock is ' . $availableStock . '.';
        } else {
            $pdo->beginTransaction();
            try {
                if ($productId === $oldProductId) {
                    $deltaQty = $oldQty - $qty;
                    if ($deltaQty !== 0) {
                        inv_adjust_stock(
                            $pdo,
                            $productId,
                            $deltaQty,
                            date('Y-m-d'),
                            $deltaQty > 0 ? 'manual_adjustment' : 'sale',
                            $adminId,
                            'sale',
                            $id,
                            'SALE-' . $id,
                            'Stock updated after editing sale.'
                        );
                    }
                } else {
                    inv_adjust_stock(
                        $pdo,
                        $oldProductId,
                        $oldQty,
                        date('Y-m-d'),
                        'manual_adjustment',
                        $adminId,
                        'sale',
                        $id,
                        'SALE-' . $id,
                        'Previous product stock restored after editing sale.'
                    );
                    inv_adjust_stock(
                        $pdo,
                        $productId,
                        -1 * $qty,
                        date('Y-m-d'),
                        'sale',
                        $adminId,
                        'sale',
                        $id,
                        'SALE-' . $id,
                        'New product stock reduced after editing sale.'
                    );
                }

                $stmt = $pdo->prepare('
                    UPDATE sales
                    SET product_id = :product_id,
                        quantity = :quantity,
                        sale_price = :sale_price,
                        profit = :profit
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':product_id' => $productId,
                    ':quantity' => $qty,
                    ':sale_price' => $sale,
                    ':profit' => $profit,
                    ':id' => $id,
                ]);

                $pdo->commit();
                flash_set('success', 'Sale updated successfully.');
                app_redirect('sales/index.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not update sale.';
            }
        }
    }
}

$selected = $productsMap[$productId] ?? null;
$purchasePrice = $selected ? (float) $selected['purchase_price'] : 0.0;
$availableStock = $selected ? (int) ($selected['stock'] ?? 0) : 0;
if ($selected && (int) ($selected['id'] ?? 0) === (int) $saleRow['product_id']) {
    $availableStock += (int) $saleRow['quantity'];
}

$pageTitle = 'Edit Sale - Shop Management';
$extraHead = '<script>window.__products=' . json_encode($products, JSON_UNESCAPED_SLASHES) . ';</script>';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Sale</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('sales/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="product_id">Product</label>
                    <select class="form-select" id="product_id" name="product_id" required>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= h((string) (int) $p['id']) ?>" <?= (int) $p['id'] === $productId ? 'selected' : '' ?>>
                                <?= h((string) $p['product_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label" for="purchase_price">Purchase Price</label>
                    <input class="form-control" type="number" step="0.01" id="purchase_price" value="<?= h(number_format($purchasePrice, 2, '.', '')) ?>" disabled>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label" for="available_stock">Available Stock</label>
                    <input class="form-control" type="number" id="available_stock" value="<?= h((string) $availableStock) ?>" disabled>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label" for="quantity">Quantity</label>
                    <input class="form-control" type="number" step="1" min="1" id="quantity" name="quantity" value="<?= h($quantity) ?>" required>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label" for="sale_price">Sale Price</label>
                    <input class="form-control" type="number" step="0.01" id="sale_price" name="sale_price" value="<?= h($salePrice) ?>" required>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label" for="profit_total">Profit (Total)</label>
                    <input class="form-control" type="number" step="0.01" id="profit_total" value="0.00" disabled>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-gradient shadow-glow">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    const products = Array.isArray(window.__products) ? window.__products : [];
    const productSelect = document.getElementById('product_id');
    const purchaseInput = document.getElementById('purchase_price');
    const stockInput = document.getElementById('available_stock');
    const qtyInput = document.getElementById('quantity');
    const saleInput = document.getElementById('sale_price');
    const profitInput = document.getElementById('profit_total');
    const originalProductId = <?= (int) $saleRow['product_id'] ?>;
    const originalQty = <?= (int) $saleRow['quantity'] ?>;

    function selectedProduct() {
        const id = parseInt(productSelect.value, 10);
        return products.find(p => parseInt(p.id, 10) === id) || null;
    }

    function recalc() {
        const p = selectedProduct();
        const purchase = p ? parseFloat(p.purchase_price) : 0;
        let stock = p ? (parseInt(p.stock, 10) || 0) : 0;
        if (p && parseInt(p.id, 10) === originalProductId) {
            stock += originalQty;
        }
        const qty = parseInt(qtyInput.value || '0', 10) || 0;
        const sale = parseFloat(saleInput.value || '0') || 0;
        purchaseInput.value = purchase.toFixed(2);
        if (stockInput) {
            stockInput.value = String(stock);
        }
        if (qty > stock) {
            qtyInput.setCustomValidity(`Only ${stock} items available in stock.`);
        } else {
            qtyInput.setCustomValidity('');
        }
        const profit = (sale - purchase) * qty;
        profitInput.value = profit.toFixed(2);
    }

    productSelect.addEventListener('change', recalc);
    qtyInput.addEventListener('input', recalc);
    saleInput.addEventListener('input', recalc);
    recalc();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
