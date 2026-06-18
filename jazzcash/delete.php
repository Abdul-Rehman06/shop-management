<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/jc_lib.php';

$pdo = db();
app_require_edit_delete_access();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('jazzcash/index.php');
}

$row = jc_find($pdo, $id);
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('jazzcash/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('DELETE FROM wallet_transactions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    flash_set('success', 'Transaction deleted successfully.');
    app_redirect('jazzcash/index.php?account_id=' . (int) ($row['account_id'] ?? 0));
}

$pageTitle = 'Delete JazzCash Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete JazzCash Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('jazzcash/index.php?account_id=' . (int) ($row['account_id'] ?? 0))) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this transaction?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-3"><span class="text-muted">Date:</span> <?= h((string) $row['date']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Type:</span> <?= h((string) $row['type']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Number:</span> <?= h((string) $row['number']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Amount:</span> <?= h(number_format((float) $row['amount'], 2)) ?></div>
        </div>
    </div>
</div>

<form method="post">
    <button class="btn btn-danger">Yes, delete</button>
    <a class="btn btn-outline-secondary" href="<?= h(app_url('jazzcash/index.php')) ?>">Cancel</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
