<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/inv_lib.php';

$pageTitle = 'Inventory Purchases - Shop Management';

$pdo = db();
app_require_stock_access();

$products = inv_product_rows($pdo, [], 500);
$adminId = inv_current_admin_id();
$success = flash_get('success');
$error = flash_get('error');

$purchaseDate = date('Y-m-d');
$supplierName = '';
$invoiceNumber = '';
$productId = (int) ($_POST['product_id'] ?? ($products[0]['id'] ?? 0));
$quantity = (string) ($_POST['quantity'] ?? '1');
$purchasePrice = (string) ($_POST['purchase_price'] ?? '');
$notes = (string) ($_POST['notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purchaseDate = inv_normalize_date((string) ($_POST['purchase_date'] ?? ''), date('Y-m-d'));
    $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
    $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = trim((string) ($_POST['quantity'] ?? ''));
    $purchasePrice = trim((string) ($_POST['purchase_price'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    try {
        if ($productId <= 0) {
            throw new RuntimeException('Please select a product.');
        }
        if ($quantity === '' || !ctype_digit($quantity) || (int) $quantity <= 0) {
            throw new RuntimeException('Quantity must be a positive whole number.');
        }
        if ($purchasePrice === '' || !is_numeric($purchasePrice) || (float) $purchasePrice < 0) {
            throw new RuntimeException('Purchase price must be a valid number.');
        }

        inv_add_purchase($pdo, [
            'purchase_date' => $purchaseDate,
            'supplier_name' => $supplierName,
            'invoice_number' => $invoiceNumber,
            'product_id' => $productId,
            'quantity' => (int) $quantity,
            'purchase_price' => (float) $purchasePrice,
            'notes' => $notes,
        ], $adminId);

        flash_set('success', 'Purchase saved successfully.');
        app_redirect('inventory/purchases.php');
    } catch (Throwable $e) {
        $error = $e->getMessage() !== '' ? $e->getMessage() : 'Could not save purchase.';
    }
}

$filters = [
    'from' => inv_normalize_date((string) ($_GET['from'] ?? ''), date('Y-m-01')),
    'to' => inv_normalize_date((string) ($_GET['to'] ?? ''), date('Y-m-d')),
    'product_id' => (int) ($_GET['product_id'] ?? 0),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$rows = inv_purchase_rows($pdo, $filters, 200);

$totalQty = 0;
$totalAmount = 0.0;
foreach ($rows as $row) {
    $totalQty += (int) ($row['quantity'] ?? 0);
    $totalAmount += (float) ($row['total_amount'] ?? 0);
}

$productsMap = [];
foreach ($products as $product) {
    $productsMap[(int) $product['id']] = $product;
}
$selected = $productsMap[$productId] ?? null;
if ($purchasePrice === '' && $selected) {
    $purchasePrice = number_format((float) ($selected['purchase_price'] ?? 0), 2, '.', '');
}

$extraHead = '<script>window.__inventoryProducts=' . json_encode(array_values($productsMap), JSON_UNESCAPED_SLASHES) . ';</script>';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Purchase Stock Entry</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/index.php')) ?>">Back</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/history.php')) ?>">History</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="post" id="purchaseForm" class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label" for="purchase_date">Purchase Date</label>
                <input class="form-control" type="date" id="purchase_date" name="purchase_date" value="<?= h($purchaseDate) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="supplier_name">Supplier</label>
                <input class="form-control" type="text" id="supplier_name" name="supplier_name" value="<?= h($supplierName) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="invoice_number">Invoice Number</label>
                <input class="form-control" type="text" id="invoice_number" name="invoice_number" value="<?= h($invoiceNumber) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="product_id">Product</label>
                <select class="form-select" id="product_id" name="product_id" required>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= (int) $product['id'] === $productId ? 'selected' : '' ?>>
                            <?= h((string) $product['product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="quantity">Quantity</label>
                <input class="form-control" type="number" step="1" min="1" id="quantity" name="quantity" value="<?= h($quantity) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="purchase_price">Purchase Price</label>
                <input class="form-control" type="number" step="0.01" min="0" id="purchase_price" name="purchase_price" value="<?= h($purchasePrice) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="total_amount">Total Amount</label>
                <input class="form-control" type="number" step="0.01" id="total_amount" value="0.00" disabled>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="current_stock">Current Stock</label>
                <input class="form-control" type="number" id="current_stock" value="<?= h((string) (int) ($selected['current_stock'] ?? $selected['stock'] ?? 0)) ?>" disabled>
            </div>
            <div class="col-12">
                <label class="form-label" for="notes">Notes (Optional)</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Save Purchase</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= h($filters['from']) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= h($filters['to']) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="filter_product_id">Product</label>
                <select class="form-select" id="filter_product_id" name="product_id">
                    <option value="0">All Products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= (int) $filters['product_id'] === (int) $product['id'] ? 'selected' : '' ?>>
                            <?= h((string) $product['product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="Supplier or invoice">
            </div>
            <div class="col-12">
                <button class="btn btn-outline-secondary">Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-6">
        <div class="alert alert-info mb-0">Purchased Quantity: <strong><?= h((string) $totalQty) ?></strong></div>
    </div>
    <div class="col-12 col-md-6">
        <div class="alert alert-primary mb-0">Purchase Value: <strong>Rs <?= h(number_format($totalAmount, 2)) ?></strong></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Product</th>
                    <th>Supplier</th>
                    <th>Invoice</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Purchase Price</th>
                    <th class="text-end">Total</th>
                    <th>User</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h((string) ($row['purchase_date'] ?? '')) ?></td>
                        <td><?= h((string) ($row['product_name'] ?? '')) ?></td>
                        <td><?= h((string) ($row['supplier_name'] ?? '')) ?></td>
                        <td><?= h((string) ($row['invoice_number'] ?? '')) ?></td>
                        <td class="text-end"><?= h((string) (int) ($row['quantity'] ?? 0)) ?></td>
                        <td class="text-end"><?= h(number_format((float) ($row['purchase_price'] ?? 0), 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) ($row['total_amount'] ?? 0), 2)) ?></td>
                        <td><?= h((string) ($row['created_by_name'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No purchase entries found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const inventoryProducts = Array.isArray(window.__inventoryProducts) ? window.__inventoryProducts : [];
    const productSelect = document.getElementById('product_id');
    const quantityInput = document.getElementById('quantity');
    const purchaseInput = document.getElementById('purchase_price');
    const totalInput = document.getElementById('total_amount');
    const stockInput = document.getElementById('current_stock');

    function selectedProduct() {
        const id = parseInt(productSelect.value || '0', 10);
        return inventoryProducts.find((item) => parseInt(item.id, 10) === id) || null;
    }

    function recalcPurchase() {
        const product = selectedProduct();
        const qty = parseInt(quantityInput.value || '0', 10) || 0;
        const price = parseFloat(purchaseInput.value || '0') || 0;
        if (stockInput) {
            stockInput.value = String(product ? (parseInt(product.current_stock || product.stock || 0, 10) || 0) : 0);
        }
        if (product && (!purchaseInput.value || parseFloat(purchaseInput.value) === 0)) {
            const defaultPrice = parseFloat(product.purchase_price || '0') || 0;
            purchaseInput.value = defaultPrice.toFixed(2);
        }
        totalInput.value = (qty * price).toFixed(2);
    }

    productSelect.addEventListener('change', recalcPurchase);
    quantityInput.addEventListener('input', recalcPurchase);
    purchaseInput.addEventListener('input', recalcPurchase);
    recalcPurchase();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
