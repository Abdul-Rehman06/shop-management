<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/bank_lib.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('bank-transfer/index.php');
}

$row = bank_find($pdo, $id);
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('bank-transfer/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('DELETE FROM wallet_transactions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    flash_set('success', 'Transaction deleted successfully.');
    app_redirect('bank-transfer/index.php?account_id=' . (int) ($row['account_id'] ?? 0));
}

$pageTitle = 'Delete Bank Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Bank Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bank-transfer/index.php?account_id=' . (int) ($row['account_id'] ?? 0))) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this transaction?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-3"><span class="text-muted">Date:</span> <?= h((string) $row['date']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Type:</span> <?= h((string) $row['type']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Bank:</span> <?= h((string) ($row['account_name'] ?? '')) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Amount:</span> <?= h(number_format((float) $row['amount'], 2)) ?></div>
        </div>
    </div>
</div>

<form method="post">
    <button class="btn btn-danger">Yes, delete</button>
    <a class="btn btn-outline-secondary" href="<?= h(app_url('bank-transfer/index.php?account_id=' . (int) ($row['account_id'] ?? 0))) ?>">Cancel</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
