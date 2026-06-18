<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pdo = db();
load_ensure_schema($pdo);
flash_set('success', 'Load Management has been updated to Daily Totals. Use the main Load Management page.');
app_redirect('load-management/index.php');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('load-management/index.php');
}

$row = load_find_transaction($pdo, $id);
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('load-management/index.php');
}

$network = (string) $row['network'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('DELETE FROM load_transactions WHERE id = :id');
    $stmt->execute([':id' => $id]);

    load_recalculate_network($pdo, $network);
    flash_set('success', 'Transaction deleted successfully.');
    app_redirect('load-management/index.php');
}

$pageTitle = 'Delete Transaction - Load Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/index.php')) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this transaction?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-3"><span class="text-muted">Date:</span> <?= h((string) $row['date']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Network:</span> <?= h((string) $row['network']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Type:</span> <?= h((string) $row['type']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Closing:</span> <?= h(number_format((float) $row['closing_balance'], 2)) ?></div>
        </div>
    </div>
</div>

<form method="post">
    <button class="btn btn-danger">Yes, delete</button>
    <a class="btn btn-outline-secondary" href="<?= h(app_url('load-management/index.php')) ?>">Cancel</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
