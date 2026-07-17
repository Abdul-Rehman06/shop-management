<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/sales_lib.php';

$pageTitle = 'Return Sale - Shop Management';

$pdo = db();
$adminId = inv_current_admin_id();

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

$product = sales_product_by_id($pdo, (int) $sale['product_id']);
$returnedQty = sales_returned_qty($pdo, $saleId);
$remainingQty = max(0, (int) $sale['quantity'] - $returnedQty);

$returnDate = date('Y-m-d');
$qty = (string) min(1, $remainingQty);
$notes = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnDate = trim((string) ($_POST['return_date'] ?? date('Y-m-d')));
    $qty = trim((string) ($_POST['quantity'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($remainingQty <= 0) {
        $error = 'This sale is already fully returned.';
    } elseif ($returnDate === '') {
        $error = 'Return date is required.';
    } elseif ($qty === '' || !ctype_digit($qty) || (int) $qty <= 0) {
        $error = 'Quantity must be a positive whole number.';
    } elseif ((int) $qty > $remainingQty) {
        $error = 'Return quantity cannot be greater than remaining quantity.';
    } else {
        $qtyInt = (int) $qty;
        $profitAdj = sales_profit_adjustment_for_return((float) $sale['profit'], (int) $sale['quantity'], $qtyInt);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO sales_returns (sale_id, quantity, return_date, reason, notes, profit_adjustment)
                VALUES (:sale_id, :quantity, :return_date, 'return', :notes, :profit_adjustment)
            ");
            $stmt->execute([
                ':sale_id' => $saleId,
                ':quantity' => $qtyInt,
                ':return_date' => $returnDate,
                ':notes' => $notes !== '' ? $notes : null,
                ':profit_adjustment' => $profitAdj,
            ]);
            $returnId = (int) $pdo->lastInsertId();

            inv_adjust_stock(
                $pdo,
                (int) $sale['product_id'],
                $qtyInt,
                $returnDate,
                'customer_return',
                $adminId,
                'sale_return',
                $returnId,
                'RET-' . $returnId,
                $notes !== '' ? $notes : 'Stock increased after customer return.'
            );

            $pdo->commit();
            flash_set('success', 'Return saved.');
            app_redirect('sales/index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not save return.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Return</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('sales/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-4"><span class="text-muted">Product:</span> <?= h((string) ($product['product_name'] ?? '')) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Sold Qty:</span> <?= h((string) (int) $sale['quantity']) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Remaining:</span> <?= h((string) $remainingQty) ?></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="return_date">Return Date</label>
                <input class="form-control" type="date" id="return_date" name="return_date" value="<?= h($returnDate) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="quantity">Return Quantity</label>
                <input class="form-control" type="number" min="1" step="1" id="quantity" name="quantity" value="<?= h($qty) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="notes">Notes (optional)</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Save Return</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

