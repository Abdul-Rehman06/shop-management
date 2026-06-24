<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Udhar Details - Shop Management';

$pdo = db();

function udhar_ensure_ledger(PDO $pdo, int $udharId): void
{
    if ($udharId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM udhar_transactions WHERE udhar_id = :id');
        $stmt->execute([':id' => $udharId]);
        $cnt = (int) $stmt->fetchColumn();
        if ($cnt > 0) {
            return;
        }

        $stmt = $pdo->prepare('SELECT amount, udhar_date, notes FROM udhar_customers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $udharId]);
        $row = $stmt->fetch();
        if ($row) {
            $amount = (float) ($row['amount'] ?? 0);
            $date = (string) ($row['udhar_date'] ?? '');
            $notes = (string) ($row['notes'] ?? '');
            if ($amount > 0 && $date !== '') {
                $ins = $pdo->prepare("
                    INSERT INTO udhar_transactions (udhar_id, txn_date, txn_type, amount, notes)
                    VALUES (:udhar_id, :txn_date, 'udhar', :amount, :notes)
                ");
                $ins->execute([
                    ':udhar_id' => $udharId,
                    ':txn_date' => $date,
                    ':amount' => $amount,
                    ':notes' => $notes !== '' ? $notes : null,
                ]);
            }
        }

        try {
            $stmt = $pdo->prepare('SELECT payment_date, amount, created_at FROM udhar_payments WHERE udhar_id = :id ORDER BY payment_date ASC, id ASC');
            $stmt->execute([':id' => $udharId]);
            $payments = $stmt->fetchAll();
            if ($payments) {
                $ins = $pdo->prepare("
                    INSERT INTO udhar_transactions (udhar_id, txn_date, txn_type, amount, notes, created_at)
                    VALUES (:udhar_id, :txn_date, 'payment', :amount, NULL, :created_at)
                ");
                foreach ($payments as $p) {
                    $pDate = (string) ($p['payment_date'] ?? '');
                    $pAmount = (float) ($p['amount'] ?? 0);
                    $createdAt = (string) ($p['created_at'] ?? '');
                    if ($pDate !== '' && $pAmount > 0) {
                        $ins->execute([
                            ':udhar_id' => $udharId,
                            ':txn_date' => $pDate,
                            ':amount' => $pAmount,
                            ':created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
    }
}

function udhar_totals(PDO $pdo, int $udharId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN txn_type = 'udhar' THEN amount ELSE 0 END), 0) AS udhar_total,
            COALESCE(SUM(CASE WHEN txn_type = 'payment' THEN amount ELSE 0 END), 0) AS paid_total
        FROM udhar_transactions
        WHERE udhar_id = :id
    ");
    $stmt->execute([':id' => $udharId]);
    $row = $stmt->fetch() ?: [];
    $udharTotal = (float) ($row['udhar_total'] ?? 0);
    $paidTotal = (float) ($row['paid_total'] ?? 0);
    $balance = $udharTotal - $paidTotal;
    return [$udharTotal, $paidTotal, $balance];
}

function udhar_update_status(PDO $pdo, int $udharId): void
{
    try {
        [, , $balance] = udhar_totals($pdo, $udharId);
        $status = $balance <= 0 ? 'cleared' : 'pending';
        $stmt = $pdo->prepare('UPDATE udhar_customers SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $udharId]);
    } catch (Throwable $e) {
    }
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid udhar record.');
    app_redirect('udhar/index.php');
}

$success = flash_get('success');
$error = flash_get('error');

udhar_ensure_ledger($pdo, $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_txn') {
        $txnDate = trim((string) ($_POST['txn_date'] ?? date('Y-m-d')));
        $txnType = trim((string) ($_POST['txn_type'] ?? ''));
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($txnDate === '') {
            flash_set('error', 'Date is required.');
            app_redirect('udhar/view.php?id=' . $id);
        }
        if ($txnType !== 'udhar' && $txnType !== 'payment') {
            flash_set('error', 'Invalid transaction type.');
            app_redirect('udhar/view.php?id=' . $id);
        }
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            flash_set('error', 'Amount must be a positive number.');
            app_redirect('udhar/view.php?id=' . $id);
        }

        $stmt = $pdo->prepare("
            INSERT INTO udhar_transactions (udhar_id, txn_date, txn_type, amount, notes)
            VALUES (:udhar_id, :txn_date, :txn_type, :amount, :notes)
        ");
        $stmt->execute([
            ':udhar_id' => $id,
            ':txn_date' => $txnDate,
            ':txn_type' => $txnType,
            ':amount' => (float) $amount,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        udhar_update_status($pdo, $id);
        flash_set('success', $txnType === 'udhar' ? 'Udhar entry saved.' : 'Payment saved.');
        app_redirect('udhar/view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare("SELECT * FROM udhar_customers WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$customer = $stmt->fetch();
if (!$customer) {
    flash_set('error', 'Udhar record not found.');
    app_redirect('udhar/index.php');
}

udhar_ensure_ledger($pdo, $id);
[$udharTotal, $paidTotal, $balance] = udhar_totals($pdo, $id);
$remaining = $balance;
if ($remaining < 0) {
    $remaining = 0.0;
}
$status = $balance <= 0 ? 'cleared' : 'pending';

$stmt = $pdo->prepare("
    SELECT id, txn_date, txn_type, amount, notes, created_at
    FROM udhar_transactions
    WHERE udhar_id = :id
    ORDER BY txn_date DESC, id DESC
");
$stmt->execute([':id' => $id]);
$txns = $stmt->fetchAll();

$txnDate = date('Y-m-d');
$txnType = 'payment';
$txnAmount = '';
$txnNotes = '';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Udhar Details</h1>
        <div class="text-muted small"><?= h((string) $customer['name']) ?> <?= ($customer['phone'] ?? '') !== '' ? ('• ' . h((string) $customer['phone'])) : '' ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/index.php')) ?>">Back</a>
        <a class="btn btn-gradient shadow-glow btn-sm" href="<?= h(app_url('udhar/add.php')) ?>">Add Udhar</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Udhar</div>
                <div class="h5 mb-0"><?= h(number_format($udharTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Paid</div>
                <div class="h5 mb-0"><?= h(number_format($paidTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Remaining</div>
                <div class="h5 mb-0"><?= h(number_format($remaining, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Status</div>
                <div class="h5 mb-0">
                    <?php if ($status === 'cleared'): ?>
                        <span class="badge text-bg-success">Cleared</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning">Pending</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-md-3">
                <div class="text-muted small">Udhar Date</div>
                <div class="fw-semibold"><?= h((string) $customer['udhar_date']) ?></div>
            </div>
            <div class="col-12 col-md-9">
                <div class="text-muted small">Notes</div>
                <div class="fw-semibold"><?= h((string) ($customer['notes'] ?? '')) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 fw-bold mb-3">Add Transaction</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_txn">
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_date">Date</label>
                <input class="form-control" type="date" id="txn_date" name="txn_date" value="<?= h($txnDate) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_type">Type</label>
                <select class="form-select" id="txn_type" name="txn_type" required>
                    <option value="udhar" <?= $txnType === 'udhar' ? 'selected' : '' ?>>+ Udhar</option>
                    <option value="payment" <?= $txnType === 'payment' ? 'selected' : '' ?>>- Payment</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($txnAmount) ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($txnNotes) ?>">
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-gradient shadow-glow w-100">Save</button>
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
                    <?php if (app_can_edit_delete_records()): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($txns as $t): ?>
                    <tr>
                        <td><?= h((string) $t['txn_date']) ?></td>
                        <td>
                            <?php if (($t['txn_type'] ?? '') === 'udhar'): ?>
                                <span class="badge text-bg-warning">+ Udhar</span>
                            <?php else: ?>
                                <span class="badge text-bg-success">- Payment</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= h(number_format((float) $t['amount'], 2)) ?></td>
                        <td><?= h((string) ($t['notes'] ?? '')) ?></td>
                        <td><?= h((string) $t['created_at']) ?></td>
                        <?php if (app_can_edit_delete_records()): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/edit_txn.php?id=' . (int) $t['id'] . '&udhar_id=' . $id)) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('udhar/delete_txn.php?id=' . (int) $t['id'] . '&udhar_id=' . $id)) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$txns): ?>
                    <tr>
                        <td colspan="<?= h((string) (5 + (app_can_edit_delete_records() ? 1 : 0))) ?>" class="text-center text-muted py-4">No transactions yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
