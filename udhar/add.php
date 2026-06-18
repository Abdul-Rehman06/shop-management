<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Udhar - Shop Management';

$pdo = db();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS udhar_customers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(30) NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        udhar_date DATE NOT NULL,
        notes VARCHAR(255) NULL,
        status ENUM('pending','cleared') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_udhar_status (status),
        KEY idx_udhar_date (udhar_date),
        KEY idx_udhar_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS udhar_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        udhar_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_date DATE NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_udhar_payments_udhar_id (udhar_id),
        KEY idx_udhar_payments_date (payment_date),
        CONSTRAINT fk_udhar_payments_udhar_id FOREIGN KEY (udhar_id) REFERENCES udhar_customers (id) ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$name = '';
$phone = '';
$amount = '';
$udharDate = date('Y-m-d');
$notes = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $udharDate = trim((string) ($_POST['udhar_date'] ?? date('Y-m-d')));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($name === '') {
        $error = 'Customer name is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $error = 'Udhar amount must be a positive number.';
    } elseif ($udharDate === '') {
        $error = 'Date is required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO udhar_customers (name, phone, amount, udhar_date, notes, status)
            VALUES (:name, :phone, :amount, :udhar_date, :notes, 'pending')
        ");
        $stmt->execute([
            ':name' => $name,
            ':phone' => $phone !== '' ? $phone : null,
            ':amount' => (float) $amount,
            ':udhar_date' => $udharDate,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $id = (int) $pdo->lastInsertId();
        flash_set('success', 'Udhar added.');
        app_redirect('udhar/view.php?id=' . $id);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Udhar</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="name">Customer Name</label>
                    <input class="form-control" type="text" id="name" name="name" value="<?= h($name) ?>" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="phone">Phone</label>
                    <input class="form-control" type="text" id="phone" name="phone" value="<?= h($phone) ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="udhar_date">Date</label>
                    <input class="form-control" type="date" id="udhar_date" name="udhar_date" value="<?= h($udharDate) ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="amount">Udhar Amount</label>
                    <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes (optional)</label>
                    <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

