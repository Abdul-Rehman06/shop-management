<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pdo = db();
app_require_edit_delete_access();
$categories = exp_categories();

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

$date = (string) $row['date'];
$category = (string) $row['category'];
$amount = (string) $row['amount'];
$description = (string) ($row['description'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($category === '') {
        $error = 'Category is required.';
    } elseif (!in_array($category, $categories, true)) {
        $error = 'Invalid category.';
    } elseif ($amount === '' || !is_numeric($amount)) {
        $error = 'Amount must be a number.';
    } else {
        $stmt = $pdo->prepare('
            UPDATE expenses
            SET date = :date,
                category = :category,
                amount = :amount,
                description = :description
            WHERE id = :id
        ');
        $stmt->execute([
            ':date' => $date,
            ':category' => $category,
            ':amount' => (float) $amount,
            ':description' => $description !== '' ? $description : null,
            ':id' => $id,
        ]);

        flash_set('success', 'Expense updated successfully.');
        app_redirect('expenses/index.php');
    }
}

$pageTitle = 'Edit Expense - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Expense</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="date">Date</label>
                    <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= h($c) ?>" <?= $c === $category ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" type="text" id="description" name="description" value="<?= h($description) ?>">
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
