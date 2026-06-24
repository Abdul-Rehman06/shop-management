<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/sales_lib.php';

$pageTitle = 'Exchange - Shop Management';

$pdo = db();

$saleId = (int) ($_GET['id'] ?? 0);
if ($saleId <= 0) {
    flash_set('error', 'Invalid sale.');
    app_redirect('sales/index.php');
}

$sale = sales_find($pdo, $saleId);
if (!$sale) {
    flash_set('error', 'Sale not found.');
    app_redirect('sales/index.php');
}

$products = sales_products($pdo);
$product = sales_product_by_id($pdo, (int) $sale['product_id']);
$returnedQty = sales_returned_qty($pdo, $saleId);
$remainingQty = max(0, (int) $sale['quantity'] - $returnedQty);

$exchangeDate = date('Y-m-d');
$returnQty = (string) min(1, $remainingQty);
$newProductId = (int) ($_POST['new_product_id'] ?? ($products[0]['id'] ?? 0));
$newSalePrice = (string) ($_POST['new_sale_price'] ?? '');
$notes = (string) ($_POST['notes'] ?? '');
$error = '';

$productsMap = [];
foreach ($products as $p) {
    $productsMap[(int) $p['id']] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exchangeDate = trim((string) ($_POST['exchange_date'] ?? date('Y-m-d')));
    $returnQty = trim((string) ($_POST['return_quantity'] ?? ''));
    $newProductId = (int) ($_POST['new_product_id'] ?? 0);
    $newSalePrice = trim((string) ($_POST['new_sale_price'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($remainingQty <= 0) {
        $error = 'This sale is already fully returned.';
    } elseif ($exchangeDate === '') {
        $error = 'Exchange date is required.';
    } elseif ($returnQty === '' || !ctype_digit($returnQty) || (int) $returnQty <= 0) {
        $error = 'Return quantity must be a positive whole number.';
    } elseif ((int) $returnQty > $remainingQty) {
        $error = 'Return quantity cannot be greater than remaining quantity.';
    } elseif ($newProductId <= 0 || !isset($productsMap[$newProductId])) {
        $error = 'Please select exchange product.';
    } elseif ($newSalePrice === '' || !is_numeric($newSalePrice)) {
        $error = 'New sale price must be a number.';
    } else {
        $qtyInt = (int) $returnQty;
        $newProduct = $productsMap[$newProductId];
        $purchase = (float) $newProduct['purchase_price'];
        $salePrice = (float) $newSalePrice;
        $profit = sales_profit_total($purchase, $salePrice, $qtyInt);
        $profitAdj = sales_profit_adjustment_for_return((float) $sale['profit'], (int) $sale['quantity'], $qtyInt);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO sales_returns (sale_id, quantity, return_date, reason, notes, profit_adjustment)
                VALUES (:sale_id, :quantity, :return_date, 'exchange', :notes, :profit_adjustment)
            ");
            $stmt->execute([
                ':sale_id' => $saleId,
                ':quantity' => $qtyInt,
                ':return_date' => $exchangeDate,
                ':notes' => $notes !== '' ? $notes : null,
                ':profit_adjustment' => $profitAdj,
            ]);
            $returnId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO sales (product_id, quantity, sale_price, profit)
                VALUES (:product_id, :quantity, :sale_price, :profit)
            ");
            $stmt->execute([
                ':product_id' => $newProductId,
                ':quantity' => $qtyInt,
                ':sale_price' => $salePrice,
                ':profit' => $profit,
            ]);
            $newSaleId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO sales_exchanges (return_id, new_sale_id, exchange_date, notes)
                VALUES (:return_id, :new_sale_id, :exchange_date, :notes)
            ");
            $stmt->execute([
                ':return_id' => $returnId,
                ':new_sale_id' => $newSaleId,
                ':exchange_date' => $exchangeDate,
                ':notes' => $notes !== '' ? $notes : null,
            ]);

            $pdo->commit();
            flash_set('success', 'Exchange saved.');
            app_redirect('sales/index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not save exchange.';
        }
    }
}

$selected = $productsMap[$newProductId] ?? null;
if ($newSalePrice === '' && $selected) {
    $def = (float) ($selected['sale_price'] ?? 0);
    if ($def > 0) {
        $newSalePrice = number_format($def, 2, '.', '');
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Exchange</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('sales/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-4"><span class="text-muted">Returned Item:</span> <?= h((string) ($product['product_name'] ?? '')) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Sold Qty:</span> <?= h((string) (int) $sale['quantity']) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Remaining:</span> <?= h((string) $remainingQty) ?></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label" for="exchange_date">Exchange Date</label>
                <input class="form-control" type="date" id="exchange_date" name="exchange_date" value="<?= h($exchangeDate) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="return_quantity">Return Quantity</label>
                <input class="form-control" type="number" min="1" step="1" id="return_quantity" name="return_quantity" value="<?= h($returnQty) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="new_product_id">Exchange Product</label>
                <select class="form-select" id="new_product_id" name="new_product_id" required>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $newProductId ? 'selected' : '' ?>>
                            <?= h((string) $p['product_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="new_sale_price">New Price</label>
                <input class="form-control" type="number" step="0.01" id="new_sale_price" name="new_sale_price" value="<?= h($newSalePrice) ?>" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="notes">Notes (optional)</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Save Exchange</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

