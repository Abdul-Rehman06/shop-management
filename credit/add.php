<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Credit Customer - Shop Management';

$pdo = db();

$name = '';
$phone = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if ($name === '') {
        $error = 'Customer name is required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO credit_customers (name, phone, status)
            VALUES (:name, :phone, 'active')
        ");
        $stmt->execute([
            ':name' => $name,
            ':phone' => $phone !== '' ? $phone : null,
        ]);

        $id = (int) $pdo->lastInsertId();
        flash_set('success', 'Customer added.');
        app_redirect('credit/view.php?id=' . $id);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Credit Customer</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('credit/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label" for="name">Customer Name</label>
                <input class="form-control" type="text" id="name" name="name" value="<?= h($name) ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control" type="text" id="phone" name="phone" value="<?= h($phone) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

