<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Udhar - Shop Management';

$pdo = db();

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
        $pdo->beginTransaction();
        try {
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
        $stmt = $pdo->prepare("
            INSERT INTO udhar_transactions (udhar_id, txn_date, txn_type, amount, notes)
            VALUES (:udhar_id, :txn_date, 'udhar', :amount, :notes)
        ");
        $stmt->execute([
            ':udhar_id' => $id,
            ':txn_date' => $udharDate,
            ':amount' => (float) $amount,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $pdo->commit();
        flash_set('success', 'Udhar added.');
        app_redirect('udhar/view.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not save udhar.';
        }
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
