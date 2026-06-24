<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pdo = db();
app_require_owner_access();

$id = (int) ($_GET['id'] ?? 0);
$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($id <= 0 || $customerId <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('credit/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM credit_transactions WHERE id = :id AND customer_id = :customer_id LIMIT 1");
$stmt->execute([':id' => $id, ':customer_id' => $customerId]);
$txn = $stmt->fetch();
if (!$txn) {
    flash_set('error', 'Transaction not found.');
    app_redirect('credit/view.php?id=' . $customerId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $before = $txn;
    $stmt = $pdo->prepare("DELETE FROM credit_transactions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    app_audit_log('credit_transactions', $id, 'delete', is_array($before) ? $before : null, null);

    flash_set('success', 'Transaction deleted.');
    app_redirect('credit/view.php?id=' . $customerId);
}

$pageTitle = 'Delete Credit Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Credit Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('credit/view.php?id=' . $customerId)) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this entry?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-3"><span class="text-muted">Date:</span> <?= h((string) $txn['txn_date']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Type:</span> <?= h((string) $txn['txn_type']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Amount:</span> <?= h(number_format((float) $txn['amount'], 2)) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Notes:</span> <?= h((string) ($txn['notes'] ?? '')) ?></div>
        </div>
        <form method="post" class="mt-3">
            <button class="btn btn-danger">Yes, Delete</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

