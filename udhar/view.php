<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Udhar Details - Shop Management';

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

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid udhar record.');
    app_redirect('udhar/index.php');
}

$success = flash_get('success');
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_payment') {
        $paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
        $payAmount = trim((string) ($_POST['amount'] ?? ''));

        if ($paymentDate === '') {
            flash_set('error', 'Payment date is required.');
            app_redirect('udhar/view.php?id=' . $id);
        }
        if ($payAmount === '' || !is_numeric($payAmount) || (float) $payAmount <= 0) {
            flash_set('error', 'Payment amount must be a positive number.');
            app_redirect('udhar/view.php?id=' . $id);
        }

        $stmt = $pdo->prepare("
            INSERT INTO udhar_payments (udhar_id, amount, payment_date)
            VALUES (:udhar_id, :amount, :payment_date)
        ");
        $stmt->execute([
            ':udhar_id' => $id,
            ':amount' => (float) $payAmount,
            ':payment_date' => $paymentDate,
        ]);

        $pdo->exec("
            UPDATE udhar_customers c
            LEFT JOIN (
                SELECT udhar_id, COALESCE(SUM(amount), 0) AS paid_total
                FROM udhar_payments
                WHERE udhar_id = " . (int) $id . "
                GROUP BY udhar_id
            ) p ON p.udhar_id = c.id
            SET c.status = CASE
                WHEN (c.amount - COALESCE(p.paid_total, 0)) <= 0 THEN 'cleared'
                ELSE 'pending'
            END
            WHERE c.id = " . (int) $id . "
        ");

        flash_set('success', 'Payment saved.');
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

$stmt = $pdo->prepare("
    SELECT id, amount, payment_date, created_at
    FROM udhar_payments
    WHERE udhar_id = :id
    ORDER BY payment_date DESC, id DESC
");
$stmt->execute([':id' => $id]);
$payments = $stmt->fetchAll();

$paidTotal = 0.0;
foreach ($payments as $p) {
    $paidTotal += (float) ($p['amount'] ?? 0);
}
$total = (float) ($customer['amount'] ?? 0);
$remaining = $total - $paidTotal;
if ($remaining < 0) {
    $remaining = 0.0;
}

$payDate = date('Y-m-d');
$payAmount = '';

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
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('udhar/add.php')) ?>">Add Udhar</a>
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
                <div class="h5 mb-0"><?= h(number_format($total, 2)) ?></div>
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
                    <?php if (($customer['status'] ?? '') === 'cleared'): ?>
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
        <h2 class="h6 fw-bold mb-3">Add Payment</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_payment">
            <div class="col-12 col-md-3">
                <label class="form-label" for="payment_date">Date</label>
                <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($payDate) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($payAmount) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-primary w-100">Save Payment</button>
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
                    <th class="text-end">Amount</th>
                    <th>Created At</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= h((string) $p['payment_date']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $p['amount'], 2)) ?></td>
                        <td><?= h((string) $p['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">No payments yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

