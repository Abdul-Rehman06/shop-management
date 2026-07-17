<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/inv_lib.php';

$pageTitle = 'Inventory History - Shop Management';

$pdo = db();
app_require_stock_access();

$products = inv_product_rows($pdo, [], 500);
$transactionLabels = inv_transaction_type_labels();
$filters = [
    'from' => inv_normalize_date((string) ($_GET['from'] ?? ''), date('Y-m-01')),
    'to' => inv_normalize_date((string) ($_GET['to'] ?? ''), date('Y-m-d')),
    'product_id' => (int) ($_GET['product_id'] ?? 0),
    'type' => trim((string) ($_GET['type'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$rows = inv_movement_rows($pdo, $filters, 400);

$selectedProduct = $filters['product_id'] > 0 ? inv_find($pdo, $filters['product_id']) : null;
$totalIn = 0;
$totalOut = 0;
foreach ($rows as $row) {
    $type = (string) ($row['transaction_type'] ?? '');
    $qty = (int) ($row['quantity'] ?? 0);
    if (in_array($type, ['opening_stock', 'purchase', 'customer_return'], true)) {
        $totalIn += $qty;
    } else {
        $totalOut += $qty;
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Stock Movement Log</h1>
        <?php if ($selectedProduct): ?>
            <div class="text-muted small"><?= h((string) ($selectedProduct['product_name'] ?? '')) ?> • <?= h((string) ($selectedProduct['sku'] ?? '')) ?></div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/index.php')) ?>">Back</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('reports/index.php?module=inventory')) ?>">Reports</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= h($filters['from']) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= h($filters['to']) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="product_id">Product</label>
                <select class="form-select" id="product_id" name="product_id">
                    <option value="0">All Products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" <?= (int) $filters['product_id'] === (int) $product['id'] ? 'selected' : '' ?>>
                            <?= h((string) $product['product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All Types</option>
                    <?php foreach ($transactionLabels as $typeKey => $typeLabel): ?>
                        <option value="<?= h($typeKey) ?>" <?= $filters['type'] === $typeKey ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="Reference or remarks">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient">Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="alert alert-success mb-0">Total Stock In: <strong><?= h((string) $totalIn) ?></strong></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="alert alert-danger mb-0">Total Stock Out: <strong><?= h((string) $totalOut) ?></strong></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="alert alert-info mb-0">Net Change: <strong><?= h((string) ($totalIn - $totalOut)) ?></strong></div>
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
                    <th>Transaction Type</th>
                    <th class="text-end">Quantity</th>
                    <th class="text-end">Previous Stock</th>
                    <th class="text-end">Updated Stock</th>
                    <th>Reference</th>
                    <th>Remarks</th>
                    <th>User</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h((string) ($row['movement_date'] ?? '')) ?></td>
                        <td><?= h((string) ($row['product_name'] ?? '')) ?></td>
                        <td><?= h($transactionLabels[(string) ($row['transaction_type'] ?? '')] ?? ucfirst(str_replace('_', ' ', (string) ($row['transaction_type'] ?? '')))) ?></td>
                        <td class="text-end"><?= h((string) (int) ($row['quantity'] ?? 0)) ?></td>
                        <td class="text-end"><?= h((string) (int) ($row['previous_stock'] ?? 0)) ?></td>
                        <td class="text-end"><?= h((string) (int) ($row['new_stock'] ?? 0)) ?></td>
                        <td><?= h((string) ($row['reference_no'] ?? '')) ?></td>
                        <td><?= h((string) ($row['remarks'] ?? '')) ?></td>
                        <td><?= h((string) ($row['created_by_name'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No stock movement found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
