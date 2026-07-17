<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/inv_lib.php';

$pageTitle = 'Inventory Damage - Shop Management';

$pdo = db();
app_require_stock_access();

$products = inv_product_rows($pdo, [], 500);
$adminId = inv_current_admin_id();
$success = flash_get('success');
$error = flash_get('error');

$damageDate = date('Y-m-d');
$productId = (int) ($_POST['product_id'] ?? ($products[0]['id'] ?? 0));
$quantity = (string) ($_POST['quantity'] ?? '1');
$reason = (string) ($_POST['reason'] ?? '');
$notes = (string) ($_POST['notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $damageDate = inv_normalize_date((string) ($_POST['damage_date'] ?? ''), date('Y-m-d'));
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = trim((string) ($_POST['quantity'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    try {
        if ($productId <= 0) {
            throw new RuntimeException('Please select a product.');
        }
        if ($quantity === '' || !ctype_digit($quantity) || (int) $quantity <= 0) {
            throw new RuntimeException('Quantity must be a positive whole number.');
        }
        if ($reason === '') {
            throw new RuntimeException('Reason is required.');
        }

        inv_add_damage($pdo, [
            'product_id' => $productId,
            'quantity' => (int) $quantity,
            'damage_date' => $damageDate,
            'reason' => $reason,
            'notes' => $notes,
        ], $adminId);

        flash_set('success', 'Damage entry saved successfully.');
        app_redirect('inventory/damages.php');
    } catch (Throwable $e) {
        $error = $e->getMessage() !== '' ? $e->getMessage() : 'Could not save damage entry.';
    }
}

$filters = [
    'from' => inv_normalize_date((string) ($_GET['from'] ?? ''), date('Y-m-01')),
    'to' => inv_normalize_date((string) ($_GET['to'] ?? ''), date('Y-m-d')),
    'product_id' => (int) ($_GET['product_id'] ?? 0),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$rows = inv_damage_rows($pdo, $filters, 200);

$totalQty = 0;
foreach ($rows as $row) {
    $totalQty += (int) ($row['quantity'] ?? 0);
}

$productsMap = [];
foreach ($products as $product) {
    $productsMap[(int) $product['id']] = $product;
}
$selected = $productsMap[$productId] ?? null;

$extraHead = '<script>window.__inventoryProducts=' . json_encode(array_values($productsMap), JSON_UNESCAPED_SLASHES) . ';</script>';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Damage Management</h1>
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
        <form method="post" class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label" for="damage_date">Date</label>
                <input class="form-control" type="date" id="damage_date" name="damage_date" value="<?= h($damageDate) ?>" required>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label" for="product_id">Product</label>
                <select class="form-select" id="product_id" name="product_id" required>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= (int) $product['id'] === $productId ? 'selected' : '' ?>>
                            <?= h((string) $product['product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="current_stock">Current Stock</label>
                <input class="form-control" type="number" id="current_stock" value="<?= h((string) (int) ($selected['current_stock'] ?? $selected['stock'] ?? 0)) ?>" disabled>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="quantity">Quantity</label>
                <input class="form-control" type="number" step="1" min="1" id="quantity" name="quantity" value="<?= h($quantity) ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="reason">Reason</label>
                <input class="form-control" type="text" id="reason" name="reason" value="<?= h($reason) ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Save Damage Entry</button>
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
                <input class="form-control" type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="Reason or notes">
            </div>
            <div class="col-12">
                <button class="btn btn-outline-secondary">Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-warning">Damaged Quantity in Selected Range: <strong><?= h((string) $totalQty) ?></strong></div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Product</th>
                    <th class="text-end">Qty</th>
                    <th>Reason</th>
                    <th>Notes</th>
                    <th>User</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h((string) ($row['damage_date'] ?? '')) ?></td>
                        <td><?= h((string) ($row['product_name'] ?? '')) ?></td>
                        <td class="text-end"><?= h((string) (int) ($row['quantity'] ?? 0)) ?></td>
                        <td><?= h((string) ($row['reason'] ?? '')) ?></td>
                        <td><?= h((string) ($row['notes'] ?? '')) ?></td>
                        <td><?= h((string) ($row['created_by_name'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No damage entries found.</td>
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
    const stockInput = document.getElementById('current_stock');

    function selectedProduct() {
        const id = parseInt(productSelect.value || '0', 10);
        return inventoryProducts.find((item) => parseInt(item.id, 10) === id) || null;
    }

    function recalcDamageStock() {
        const product = selectedProduct();
        const stock = product ? (parseInt(product.current_stock || product.stock || 0, 10) || 0) : 0;
        const qty = parseInt(quantityInput.value || '0', 10) || 0;
        stockInput.value = String(stock);
        if (qty > stock) {
            quantityInput.setCustomValidity(`Only ${stock} items available in stock.`);
        } else {
            quantityInput.setCustomValidity('');
        }
    }

    productSelect.addEventListener('change', recalcDamageStock);
    quantityInput.addEventListener('input', recalcDamageStock);
    recalcDamageStock();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
