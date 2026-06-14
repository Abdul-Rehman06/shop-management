<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid expense.');
    app_redirect('expenses/index.php');
}

$row = exp_find($pdo, $id);
if (!$row) {
    flash_set('error', 'Expense not found.');
    app_redirect('expenses/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = :id');
    $stmt->execute([':id' => $id]);
    flash_set('success', 'Expense deleted successfully.');
    app_redirect('expenses/index.php');
}

$pageTitle = 'Delete Expense - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Delete Expense</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/index.php')) ?>">Back</a>
</div>

<div class="alert alert-warning">
    Are you sure you want to delete this expense?
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-12 col-md-3"><span class="text-muted">Date:</span> <?= h((string) $row['date']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Category:</span> <?= h((string) $row['category']) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Amount:</span> <?= h(number_format((float) $row['amount'], 2)) ?></div>
            <div class="col-12 col-md-3"><span class="text-muted">Description:</span> <?= h((string) ($row['description'] ?? '')) ?></div>
        </div>
    </div>
</div>

<form method="post">
    <button class="btn btn-danger">Yes, delete</button>
    <a class="btn btn-outline-secondary" href="<?= h(app_url('expenses/index.php')) ?>">Cancel</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

