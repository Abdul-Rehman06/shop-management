<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pdo = db();
app_require_owner_access();

$id = (int) ($_GET['id'] ?? 0);
$udharId = (int) ($_GET['udhar_id'] ?? 0);
if ($id <= 0 || $udharId <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('udhar/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM udhar_transactions WHERE id = :id AND udhar_id = :udhar_id LIMIT 1");
$stmt->execute([':id' => $id, ':udhar_id' => $udharId]);
$txn = $stmt->fetch();
if (!$txn) {
    flash_set('error', 'Transaction not found.');
    app_redirect('udhar/view.php?id=' . $udharId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $before = $txn;

    $stmt = $pdo->prepare("DELETE FROM udhar_transactions WHERE id = :id AND udhar_id = :udhar_id");
    $stmt->execute([':id' => $id, ':udhar_id' => $udharId]);

    app_audit_log('udhar_transactions', $id, 'delete', is_array($before) ? $before : null, null);

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN txn_type = 'udhar' THEN amount ELSE 0 END), 0) AS udhar_total,
                COALESCE(SUM(CASE WHEN txn_type = 'payment' THEN amount ELSE 0 END), 0) AS paid_total
            FROM udhar_transactions
            WHERE udhar_id = :udhar_id
        ");
        $stmt->execute([':udhar_id' => $udharId]);
        $totals = $stmt->fetch() ?: [];
        $balance = (float) ($totals['udhar_total'] ?? 0) - (float) ($totals['paid_total'] ?? 0);
        $status = $balance <= 0 ? 'cleared' : 'pending';
        $upd = $pdo->prepare('UPDATE udhar_customers SET status = :status WHERE id = :id');
        $upd->execute([':status' => $status, ':id' => $udharId]);
    } catch (Throwable $e) {
    }

    flash_set('success', 'Transaction deleted.');
    app_redirect('udhar/view.php?id=' . $udharId);
}

$pageTitle = 'Delete Udhar Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Udhar Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/view.php?id=' . $udharId)) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this transaction?
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-3">
                <div class="text-muted small">Date</div>
                <div class="fw-semibold"><?= h((string) $txn['txn_date']) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="text-muted small">Type</div>
                <div class="fw-semibold"><?= h((string) $txn['txn_type']) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="text-muted small">Amount</div>
                <div class="fw-semibold"><?= h(number_format((float) $txn['amount'], 2)) ?></div>
            </div>
            <div class="col-12 col-md-3">
                <div class="text-muted small">Notes</div>
                <div class="fw-semibold"><?= h((string) ($txn['notes'] ?? '')) ?></div>
            </div>
        </div>

        <form method="post" class="d-flex gap-2">
            <button class="btn btn-danger">Yes, Delete</button>
            <a class="btn btn-outline-secondary" href="<?= h(app_url('udhar/view.php?id=' . $udharId)) ?>">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

