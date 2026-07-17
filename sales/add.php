<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/sales_lib.php';

$pageTitle = 'Add Sale - Shop Management';

$pdo = db();
$canViewProfit = app_can_view_profit();
$products = sales_products($pdo);
$adminId = inv_current_admin_id();

$creditCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM credit_customers WHERE status = 'active' ORDER BY name ASC, id ASC");
    $creditCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
}

$productId = (int) ($_POST['product_id'] ?? ($products[0]['id'] ?? 0));
$quantity = (string) ($_POST['quantity'] ?? '1');
$salePrice = (string) ($_POST['sale_price'] ?? '');
$creditCustomerId = (int) ($_POST['credit_customer_id'] ?? 0);
$creditUseAmount = (string) ($_POST['credit_use_amount'] ?? '');
$error = '';

$productsMap = [];
foreach ($products as $p) {
    $productsMap[(int) $p['id']] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = trim((string) ($_POST['quantity'] ?? ''));
    $salePrice = trim((string) ($_POST['sale_price'] ?? ''));
    $creditCustomerId = (int) ($_POST['credit_customer_id'] ?? 0);
    $creditUseAmount = trim((string) ($_POST['credit_use_amount'] ?? ''));

    if ($productId <= 0 || !isset($productsMap[$productId])) {
        $error = 'Please select a product.';
    } elseif ($quantity === '' || !ctype_digit($quantity) || (int) $quantity <= 0) {
        $error = 'Quantity must be a positive whole number.';
    } elseif ($salePrice === '' || !is_numeric($salePrice)) {
        $error = 'Sale price must be a number.';
    } elseif ($creditCustomerId > 0 && ($creditUseAmount === '' || !is_numeric($creditUseAmount) || (float) $creditUseAmount <= 0)) {
        $error = 'Credit used amount must be a positive number.';
    } else {
        $product = $productsMap[$productId];
        $purchase = (float) $product['purchase_price'];
        $sale = (float) $salePrice;
        $qty = (int) $quantity;
        $availableStock = (int) ($product['stock'] ?? 0);
        $profit = sales_profit_total($purchase, $sale, $qty);

        if ($qty > $availableStock) {
            $error = 'Insufficient stock. Available stock is ' . $availableStock . '.';
        } elseif ($creditCustomerId > 0) {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN txn_type='advance' THEN amount ELSE 0 END), 0) AS adv_total,
                    COALESCE(SUM(CASE WHEN txn_type='used' THEN amount ELSE 0 END), 0) AS used_total
                FROM credit_transactions
                WHERE customer_id = :id
            ");
            $stmt->execute([':id' => $creditCustomerId]);
            $row = $stmt->fetch() ?: [];
            $remaining = (float) ($row['adv_total'] ?? 0) - (float) ($row['used_total'] ?? 0);
            if ((float) $creditUseAmount > $remaining) {
                $error = 'Credit used amount exceeds remaining credit.';
            }
        }

        if ($error === '') {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO sales (product_id, quantity, sale_price, profit)
                    VALUES (:product_id, :quantity, :sale_price, :profit)
                ');
                $stmt->execute([
                    ':product_id' => $productId,
                    ':quantity' => $qty,
                    ':sale_price' => $sale,
                    ':profit' => $profit,
                ]);
                $saleId = (int) $pdo->lastInsertId();

                inv_adjust_stock(
                    $pdo,
                    $productId,
                    -1 * $qty,
                    date('Y-m-d'),
                    'sale',
                    $adminId,
                    'sale',
                    $saleId,
                    'SALE-' . $saleId,
                    'Stock reduced after sale.'
                );

                if ($creditCustomerId > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO credit_transactions (customer_id, txn_date, txn_type, amount, notes)
                        VALUES (:customer_id, :txn_date, 'used', :amount, :notes)
                    ");
                    $stmt->execute([
                        ':customer_id' => $creditCustomerId,
                        ':txn_date' => date('Y-m-d'),
                        ':amount' => (float) $creditUseAmount,
                        ':notes' => 'Used for Sale #' . $saleId,
                    ]);
                }

                $pdo->commit();
                flash_set('success', 'Sale added successfully.');
                app_redirect('sales/index.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not save sale.';
            }
        }
    }
}

$selected = $productsMap[$productId] ?? null;
$purchasePrice = $selected ? (float) $selected['purchase_price'] : 0.0;
$stockAvailable = $selected ? (int) ($selected['stock'] ?? 0) : 0;
$defaultSale = $selected ? (float) $selected['sale_price'] : 0.0;
if ($salePrice === '' && $defaultSale > 0) {
    $salePrice = (string) $defaultSale;
}

$productsUi = $products;
if (!$canViewProfit) {
    $productsUi = array_map(static function (array $p): array {
        unset($p['purchase_price']);
        return $p;
    }, $products);
}
$extraHead = '<script>window.__products=' . json_encode($productsUi, JSON_UNESCAPED_SLASHES) . ';</script>';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Sale</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('sales/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$products): ?>
    <div class="alert alert-warning">
        Please add products first in Inventory.
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post" id="saleForm">
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

                    <?php if ($canViewProfit): ?>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="purchase_price">Purchase Price</label>
                            <input class="form-control" type="number" step="0.01" id="purchase_price" value="<?= h(number_format($purchasePrice, 2, '.', '')) ?>" disabled>
                        </div>
                    <?php endif; ?>

                    <div class="col-12 col-md-3">
                        <label class="form-label" for="available_stock">Available Stock</label>
                        <input class="form-control" type="number" id="available_stock" value="<?= h((string) $stockAvailable) ?>" disabled>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label" for="quantity">Quantity</label>
                        <input class="form-control" type="number" step="1" min="1" id="quantity" name="quantity" value="<?= h($quantity) ?>" required>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label" for="sale_price">Sale Price</label>
                        <input class="form-control" type="number" step="0.01" id="sale_price" name="sale_price" value="<?= h($salePrice) ?>" required>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="credit_customer_id">Use Customer Credit (Optional)</label>
                        <select class="form-select" id="credit_customer_id" name="credit_customer_id">
                            <option value="0">-- No Credit --</option>
                            <?php foreach ($creditCustomers as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $creditCustomerId ? 'selected' : '' ?>>
                                    <?= h((string) $c['name']) ?><?= ($c['phone'] ?? '') !== '' ? (' • ' . h((string) $c['phone'])) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="credit_use_amount">Credit Used Amount</label>
                        <input class="form-control" type="number" step="0.01" id="credit_use_amount" name="credit_use_amount" value="<?= h($creditUseAmount) ?>" placeholder="0.00">
                    </div>

                    <?php if ($canViewProfit): ?>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="profit_total">Profit (Total)</label>
                            <input class="form-control" type="number" step="0.01" id="profit_total" value="0.00" disabled>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-3">
                    <button class="btn btn-gradient shadow-glow">Save</button>
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

        function selectedProduct() {
            const id = parseInt(productSelect.value, 10);
            return products.find(p => parseInt(p.id, 10) === id) || null;
        }

        function recalc() {
            const p = selectedProduct();
            const purchase = p && typeof p.purchase_price !== 'undefined' ? (parseFloat(p.purchase_price) || 0) : 0;
            const stock = p && typeof p.stock !== 'undefined' ? (parseInt(p.stock, 10) || 0) : 0;
            const qty = parseInt(qtyInput.value || '0', 10) || 0;
            const sale = parseFloat(saleInput.value || '0') || 0;
            if (purchaseInput) {
                purchaseInput.value = purchase.toFixed(2);
            }
            if (stockInput) {
                stockInput.value = String(stock);
            }
            if (qty > stock) {
                qtyInput.setCustomValidity(`Only ${stock} items available in stock.`);
            } else {
                qtyInput.setCustomValidity('');
            }
            if (profitInput) {
                const profit = (sale - purchase) * qty;
                profitInput.value = profit.toFixed(2);
            }
        }

        productSelect.addEventListener('change', () => {
            const p = selectedProduct();
            if (p) {
                const defaultSale = parseFloat(p.sale_price) || 0;
                if (!saleInput.value || parseFloat(saleInput.value) === 0) {
                    saleInput.value = defaultSale ? defaultSale.toFixed(2) : '';
                }
            }
            recalc();
        });
        qtyInput.addEventListener('input', recalc);
        saleInput.addEventListener('input', recalc);
        recalc();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
