<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/inv_lib.php';

$pageTitle = 'Inventory - Shop Management';

$pdo = db();
app_require_stock_access();
$success = flash_get('success');
$error = flash_get('error');

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];
$rows = inv_product_rows($pdo, $filters, 200);
$summary = inv_inventory_summary($pdo);
$analytics = inv_inventory_analytics($pdo);
$categories = inv_product_categories_in_use($pdo);
$recentPurchases = inv_recent_purchases($pdo, 8);
$recentDamages = inv_recent_damages($pdo, 8);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Inventory</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-gradient shadow-glow btn-sm" href="<?= h(app_url('inventory/add.php')) ?>">Add Product</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/purchases.php')) ?>">Purchases</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/damages.php')) ?>">Damage</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/history.php')) ?>">History</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('reports/index.php?module=inventory')) ?>">Reports</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Total Products</div>
                <div class="h3 mb-1"><?= h((string) $summary['total_products']) ?></div>
                <div class="small text-muted">Stock Units: <?= h((string) $summary['total_stock_units']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Inventory Purchase Value</div>
                <div class="h3 mb-1">Rs <?= h(number_format((float) $summary['purchase_value'], 2)) ?></div>
                <div class="small text-muted">Current stock x purchase price</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Expected Selling Value</div>
                <div class="h3 mb-1">Rs <?= h(number_format((float) $summary['selling_value'], 2)) ?></div>
                <div class="small text-muted">Current stock x sale price</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Expected Gross Profit</div>
                <div class="h3 mb-1">Rs <?= h(number_format((float) $summary['expected_profit'], 2)) ?></div>
                <div class="small text-muted">Selling value - purchase value</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Today's Purchases</div>
                <div class="h5 mb-1"><?= h((string) $summary['today_purchases_qty']) ?> units</div>
                <div class="small text-muted">Rs <?= h(number_format((float) $summary['today_purchases_amount'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Today's Sales</div>
                <div class="h5 mb-1"><?= h((string) $summary['today_sales_qty']) ?> units</div>
                <div class="small text-muted">Rs <?= h(number_format((float) $summary['today_sales_amount'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Monthly Purchases</div>
                <div class="h5 mb-1"><?= h((string) $summary['month_purchases_qty']) ?> units</div>
                <div class="small text-muted">Rs <?= h(number_format((float) $summary['month_purchases_amount'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Monthly Sales</div>
                <div class="h5 mb-1"><?= h((string) $summary['month_sales_qty']) ?> units</div>
                <div class="small text-muted">Rs <?= h(number_format((float) $summary['month_sales_amount'], 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="alert alert-warning mb-0">
            Low Stock Products: <strong><?= h((string) $summary['low_stock_count']) ?></strong>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="alert alert-danger mb-0">
            Out of Stock Products: <strong><?= h((string) $summary['out_of_stock_count']) ?></strong>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="Product, category, brand, SKU or barcode">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $categoryOption): ?>
                        <option value="<?= h($categoryOption) ?>" <?= $filters['category'] === $categoryOption ? 'selected' : '' ?>><?= h($categoryOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All Status</option>
                    <option value="in_stock" <?= $filters['status'] === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low_stock" <?= $filters['status'] === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $filters['status'] === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button class="btn btn-gradient w-100">Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Brand</th>
                    <th>SKU</th>
                    <th class="text-end">Purchase</th>
                    <th class="text-end">Sale</th>
                    <th class="text-end">Current</th>
                    <th class="text-end">Stock Value</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $current = (int) ($r['current_stock'] ?? 0);
                    $limit = (int) ($r['low_stock_limit'] ?? 0);
                    $status = inv_stock_status($current, $limit);
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= h((string) $r['product_name']) ?></div>
                            <div class="text-muted small"><?= h((string) ($r['barcode'] ?? '')) ?></div>
                        </td>
                        <td><?= h((string) ($r['category'] ?? '')) ?></td>
                        <td><?= h((string) ($r['brand'] ?? '')) ?></td>
                        <td><?= h((string) ($r['sku'] ?? '')) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['purchase_price'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['sale_price'], 2)) ?></td>
                        <td class="text-end fw-semibold"><?= h((string) $current) ?></td>
                        <td class="text-end"><?= h(number_format((float) ($r['stock_value'] ?? 0), 2)) ?></td>
                        <td>
                            <span class="badge <?= h(inv_stock_status_badge_class($status)) ?>"><?= h(inv_stock_status_label($status)) ?></span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('inventory/history.php?product_id=' . (int) $r['id'])) ?>">History</a>
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('inventory/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                            <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('inventory/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No products found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 fw-semibold">Analytics</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="small text-muted mb-2">Best Selling Products</div>
                        <?php foreach (($analytics['best_selling'] ?? []) as $item): ?>
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <span><?= h((string) ($item['product_name'] ?? '')) ?></span>
                                <strong><?= h((string) (int) ($item['net_sold_qty'] ?? 0)) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="small text-muted mb-2">Slow Moving Products</div>
                        <?php foreach (($analytics['slow_moving'] ?? []) as $item): ?>
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <span><?= h((string) ($item['product_name'] ?? '')) ?></span>
                                <strong><?= h((string) (int) ($item['net_sold_qty'] ?? 0)) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="small text-muted mb-2">Highest Profit Products</div>
                        <?php foreach (($analytics['highest_profit'] ?? []) as $item): ?>
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <span><?= h((string) ($item['product_name'] ?? '')) ?></span>
                                <strong>Rs <?= h(number_format((float) ($item['total_profit'] ?? 0), 2)) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="small text-muted mb-2">Most Sold Categories</div>
                        <?php foreach (($analytics['top_categories'] ?? []) as $item): ?>
                            <div class="d-flex justify-content-between border-bottom py-2">
                                <span><?= h((string) ($item['category'] ?? '')) ?></span>
                                <strong><?= h((string) (int) ($item['sold_qty'] ?? 0)) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 fw-semibold">Monthly Sales Trend</div>
            <div class="card-body">
                <?php foreach (($analytics['monthly_trend'] ?? []) as $item): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <span><?= h((string) ($item['label'] ?? '')) ?></span>
                        <strong>Rs <?= h(number_format((float) ($item['sales_value'] ?? 0), 2)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 fw-semibold">Recent Purchases</div>
            <div class="card-body">
                <?php foreach ($recentPurchases as $purchase): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <div>
                            <div class="fw-semibold"><?= h((string) ($purchase['product_name'] ?? '')) ?></div>
                            <div class="small text-muted"><?= h((string) ($purchase['supplier_name'] ?? '')) ?><?= ($purchase['invoice_number'] ?? '') !== '' ? (' • ' . h((string) $purchase['invoice_number'])) : '' ?></div>
                        </div>
                        <div class="text-end">
                            <div><?= h((string) (int) ($purchase['quantity'] ?? 0)) ?> pcs</div>
                            <div class="small text-muted">Rs <?= h(number_format((float) ($purchase['total_amount'] ?? 0), 2)) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentPurchases): ?>
                    <div class="text-muted">No purchases yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 fw-semibold">Recent Damages</div>
            <div class="card-body">
                <?php foreach ($recentDamages as $damage): ?>
                    <div class="d-flex justify-content-between border-bottom py-2">
                        <div>
                            <div class="fw-semibold"><?= h((string) ($damage['product_name'] ?? '')) ?></div>
                            <div class="small text-muted"><?= h((string) ($damage['reason'] ?? '')) ?></div>
                        </div>
                        <div class="text-end">
                            <div><?= h((string) (int) ($damage['quantity'] ?? 0)) ?> pcs</div>
                            <div class="small text-muted"><?= h((string) ($damage['damage_date'] ?? '')) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentDamages): ?>
                    <div class="text-muted">No damage entries yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
