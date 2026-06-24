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

$date = (string) $txn['txn_date'];
$type = (string) $txn['txn_type'];
$amount = (string) $txn['amount'];
$notes = (string) ($txn['notes'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['txn_date'] ?? ''));
    $type = trim((string) ($_POST['txn_type'] ?? 'udhar'));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!in_array($type, ['udhar', 'payment'], true)) {
        $type = 'udhar';
    }

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $error = 'Amount must be a positive number.';
    } else {
        $before = $txn;
        $stmt = $pdo->prepare("
            UPDATE udhar_transactions
            SET txn_date = :txn_date,
                txn_type = :txn_type,
                amount = :amount,
                notes = :notes
            WHERE id = :id
        ");
        $stmt->execute([
            ':txn_date' => $date,
            ':txn_type' => $type,
            ':amount' => (float) $amount,
            ':notes' => $notes !== '' ? $notes : null,
            ':id' => $id,
        ]);

        $stmt = $pdo->prepare("SELECT * FROM udhar_transactions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $after = $stmt->fetch() ?: null;

        app_audit_log('udhar_transactions', $id, 'edit', is_array($before) ? $before : null, is_array($after) ? $after : null);

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

        flash_set('success', 'Transaction updated.');
        app_redirect('udhar/view.php?id=' . $udharId);
    }
}

$pageTitle = 'Edit Udhar Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Udhar Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/view.php?id=' . $udharId)) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_date">Date</label>
                <input class="form-control" type="date" id="txn_date" name="txn_date" value="<?= h($date) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_type">Type</label>
                <select class="form-select" id="txn_type" name="txn_type">
                    <option value="udhar" <?= $type === 'udhar' ? 'selected' : '' ?>>+ Udhar</option>
                    <option value="payment" <?= $type === 'payment' ? 'selected' : '' ?>>- Payment</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

