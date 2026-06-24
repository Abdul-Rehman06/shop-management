<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Credit Details - Shop Management';

$pdo = db();
$isOwner = app_is_owner();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid customer.');
    app_redirect('credit/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM credit_customers WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$customer = $stmt->fetch();
if (!$customer) {
    flash_set('error', 'Customer not found.');
    app_redirect('credit/index.php');
}

$success = flash_get('success');
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_txn') {
        $date = trim((string) ($_POST['txn_date'] ?? date('Y-m-d')));
        $type = trim((string) ($_POST['txn_type'] ?? 'advance'));
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!in_array($type, ['advance', 'used'], true)) {
            $type = 'advance';
        }

        if ($date === '') {
            flash_set('error', 'Date is required.');
            app_redirect('credit/view.php?id=' . $id);
        }
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            flash_set('error', 'Amount must be a positive number.');
            app_redirect('credit/view.php?id=' . $id);
        }

        $stmt = $pdo->prepare("
            INSERT INTO credit_transactions (customer_id, txn_date, txn_type, amount, notes)
            VALUES (:customer_id, :txn_date, :txn_type, :amount, :notes)
        ");
        $stmt->execute([
            ':customer_id' => $id,
            ':txn_date' => $date,
            ':txn_type' => $type,
            ':amount' => (float) $amount,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        flash_set('success', 'Credit entry saved.');
        app_redirect('credit/view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN txn_type = 'advance' THEN amount ELSE 0 END), 0) AS total_advance,
        COALESCE(SUM(CASE WHEN txn_type = 'used' THEN amount ELSE 0 END), 0) AS total_used
    FROM credit_transactions
    WHERE customer_id = :id
");
$stmt->execute([':id' => $id]);
$tot = $stmt->fetch() ?: ['total_advance' => 0, 'total_used' => 0];
$totalAdvance = (float) ($tot['total_advance'] ?? 0);
$totalUsed = (float) ($tot['total_used'] ?? 0);
$remaining = $totalAdvance - $totalUsed;

$stmt = $pdo->prepare("
    SELECT id, txn_date, txn_type, amount, notes, created_at
    FROM credit_transactions
    WHERE customer_id = :id
    ORDER BY txn_date DESC, id DESC
    LIMIT 300
");
$stmt->execute([':id' => $id]);
$txns = $stmt->fetchAll();

$txnDate = date('Y-m-d');
$txnType = 'advance';
$txnAmount = '';
$txnNotes = '';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Credit Details</h1>
        <div class="text-muted small"><?= h((string) $customer['name']) ?> <?= ($customer['phone'] ?? '') !== '' ? ('• ' . h((string) $customer['phone'])) : '' ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('credit/index.php')) ?>">Back</a>
        <a class="btn btn-gradient shadow-glow btn-sm" href="<?= h(app_url('credit/add.php')) ?>">Add Customer</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Advance Received</div>
                <div class="h5 mb-0"><?= h(number_format($totalAdvance, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Amount Used</div>
                <div class="h5 mb-0"><?= h(number_format($totalUsed, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Remaining Credit</div>
                <div class="h5 mb-0"><?= h(number_format($remaining, 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 fw-bold mb-3">Add Credit Entry</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_txn">
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_date">Date</label>
                <input class="form-control" type="date" id="txn_date" name="txn_date" value="<?= h($txnDate) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_type">Type</label>
                <select class="form-select" id="txn_type" name="txn_type">
                    <option value="advance" <?= $txnType === 'advance' ? 'selected' : '' ?>>+ Advance Received</option>
                    <option value="used" <?= $txnType === 'used' ? 'selected' : '' ?>>- Credit Used</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($txnAmount) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($txnNotes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th>Notes</th>
                    <th>Created At</th>
                    <?php if ($isOwner): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($txns as $t): ?>
                    <?php $type = (string) $t['txn_type']; ?>
                    <tr>
                        <td><?= h((string) $t['txn_date']) ?></td>
                        <td>
                            <?php if ($type === 'advance'): ?>
                                <span class="badge text-bg-success">+ Advance</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">- Used</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?= h(number_format((float) $t['amount'], 2)) ?></td>
                        <td><?= h((string) ($t['notes'] ?? '')) ?></td>
                        <td><?= h((string) $t['created_at']) ?></td>
                        <?php if ($isOwner): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('credit/edit_txn.php?id=' . (int) $t['id'] . '&customer_id=' . $id)) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('credit/delete_txn.php?id=' . (int) $t['id'] . '&customer_id=' . $id)) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$txns): ?>
                    <tr>
                        <td colspan="<?= h((string) (5 + ($isOwner ? 1 : 0))) ?>" class="text-center text-muted py-4">No credit history yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

