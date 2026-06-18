<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pdo = db();
app_require_edit_delete_access();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid deposit.');
    app_redirect('bank-deposits/index.php');
}

$stmt = $pdo->prepare("
    SELECT id, bank_wallet_transaction_id, cash_wallet_transaction_id, deposit_date, amount
    FROM bank_deposits
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    flash_set('error', 'Deposit not found.');
    app_redirect('bank-deposits/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->beginTransaction();
    try {
        $bankTxnId = (int) ($row['bank_wallet_transaction_id'] ?? 0);
        $cashTxnId = (int) ($row['cash_wallet_transaction_id'] ?? 0);
        if ($bankTxnId > 0) {
            $pdo->prepare("DELETE FROM wallet_transactions WHERE id = :id")->execute([':id' => $bankTxnId]);
        }
        if ($cashTxnId > 0) {
            $pdo->prepare("DELETE FROM wallet_transactions WHERE id = :id")->execute([':id' => $cashTxnId]);
        }

        $pdo->prepare("DELETE FROM bank_deposits WHERE id = :id")->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash_set('error', 'Could not delete deposit.');
        app_redirect('bank-deposits/index.php');
    }

    flash_set('success', 'Deposit deleted.');
    app_redirect('bank-deposits/index.php');
}

$pageTitle = 'Delete Bank Deposit - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Deposit</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bank-deposits/index.php')) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this deposit?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-4"><span class="text-muted">Date:</span> <?= h((string) $row['deposit_date']) ?></div>
            <div class="col-12 col-md-4"><span class="text-muted">Amount:</span> <?= h(number_format((float) $row['amount'], 2)) ?></div>
        </div>
        <form method="post" class="mt-3">
            <button class="btn btn-danger">Yes, Delete</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

