<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pdo = db();
app_require_owner_access();
load_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('load-management/transactions.php');
}

$stmt = $pdo->prepare("SELECT * FROM load_customer_transactions WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('load-management/transactions.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $before = $row;
    $stmt = $pdo->prepare("DELETE FROM load_customer_transactions WHERE id = :id");
    $stmt->execute([':id' => $id]);

    app_audit_log('load_customer_transactions', $id, 'delete', is_array($before) ? $before : null, null);

    flash_set('success', 'Transaction deleted.');
    app_redirect('load-management/transactions.php');
}

$pageTitle = 'Delete Load Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Load Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/transactions.php')) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this transaction?
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-3">
                <div class="text-muted small">Date</div>
                <div class="fw-semibold"><?= h((string) $row['txn_date']) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="text-muted small">Network</div>
                <div class="fw-semibold"><?= h((string) $row['network']) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="text-muted small">Customer</div>
                <div class="fw-semibold"><?= h((string) ($row['customer_name'] ?? '')) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="text-muted small">Phone</div>
                <div class="fw-semibold"><?= h((string) ($row['customer_phone'] ?? '')) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="text-muted small">Amount</div>
                <div class="fw-semibold"><?= h(number_format((float) $row['amount'], 2)) ?></div>
            </div>
            <div class="col-12">
                <div class="text-muted small">Notes</div>
                <div class="fw-semibold"><?= h((string) ($row['notes'] ?? '')) ?></div>
            </div>
        </div>

        <form method="post" class="d-flex gap-2">
            <button class="btn btn-danger">Yes, Delete</button>
            <a class="btn btn-outline-secondary" href="<?= h(app_url('load-management/transactions.php')) ?>">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

