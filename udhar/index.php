<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Udhar - Shop Management';

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

$pdo->exec("
    UPDATE udhar_customers c
    LEFT JOIN (
        SELECT udhar_id, COALESCE(SUM(amount), 0) AS paid_total
        FROM udhar_payments
        GROUP BY udhar_id
    ) p ON p.udhar_id = c.id
    SET c.status = CASE
        WHEN (c.amount - COALESCE(p.paid_total, 0)) <= 0 THEN 'cleared'
        ELSE 'pending'
    END
");

$tab = trim((string) ($_GET['tab'] ?? 'pending'));
if (!in_array($tab, ['pending', 'cleared', 'all'], true)) {
    $tab = 'pending';
}
$q = trim((string) ($_GET['q'] ?? ''));

$success = flash_get('success');
$error = flash_get('error');

$whereParts = [];
$params = [];
if ($tab !== 'all') {
    $whereParts[] = 'c.status = :status';
    $params[':status'] = $tab;
}
if ($q !== '') {
    $whereParts[] = '(c.name LIKE :q OR c.phone LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$stmt = $pdo->prepare("
    SELECT
        c.id, c.name, c.phone, c.amount, c.udhar_date, c.notes, c.status, c.created_at,
        COALESCE(SUM(p.amount), 0) AS paid_total
    FROM udhar_customers c
    LEFT JOIN udhar_payments p ON p.udhar_id = c.id
    {$where}
    GROUP BY c.id
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalAmount = 0.0;
$totalPaid = 0.0;
$totalRemaining = 0.0;
foreach ($rows as $r) {
    $amount = (float) ($r['amount'] ?? 0);
    $paid = (float) ($r['paid_total'] ?? 0);
    $remaining = $amount - $paid;
    if ($remaining < 0) {
        $remaining = 0.0;
    }
    $totalAmount += $amount;
    $totalPaid += $paid;
    $totalRemaining += $remaining;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Udhar Management</h1>
        <div class="text-muted small">Manage customer udhar and payments</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('udhar/add.php')) ?>">Add Udhar</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
            <div class="btn-group" role="group">
                <a class="btn btn-outline-secondary <?= $tab === 'pending' ? 'active' : '' ?>" href="<?= h(app_url('udhar/index.php?tab=pending')) ?>">Pending</a>
                <a class="btn btn-outline-secondary <?= $tab === 'cleared' ? 'active' : '' ?>" href="<?= h(app_url('udhar/index.php?tab=cleared')) ?>">Cleared</a>
                <a class="btn btn-outline-secondary <?= $tab === 'all' ? 'active' : '' ?>" href="<?= h(app_url('udhar/index.php?tab=all')) ?>">All</a>
            </div>
            <form method="get" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search name or phone">
                <button class="btn btn-outline-primary">Search</button>
                <a class="btn btn-outline-secondary" href="<?= h(app_url('udhar/index.php?tab=' . urlencode($tab))) ?>">Clear</a>
            </form>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Udhar</div>
                        <div class="h5 mb-0"><?= h(number_format($totalAmount, 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Paid</div>
                        <div class="h5 mb-0"><?= h(number_format($totalPaid, 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Remaining</div>
                        <div class="h5 mb-0"><?= h(number_format($totalRemaining, 2)) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Customer</th>
                    <th>Date</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Remaining</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $amount = (float) $r['amount'];
                    $paid = (float) $r['paid_total'];
                    $remaining = $amount - $paid;
                    if ($remaining < 0) {
                        $remaining = 0.0;
                    }
                    $status = (string) ($r['status'] ?? 'pending');
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= h((string) $r['name']) ?></div>
                            <div class="text-muted small"><?= h((string) ($r['phone'] ?? '')) ?></div>
                        </td>
                        <td><?= h((string) $r['udhar_date']) ?></td>
                        <td class="text-end"><?= h(number_format($amount, 2)) ?></td>
                        <td class="text-end"><?= h(number_format($paid, 2)) ?></td>
                        <td class="text-end fw-semibold"><?= h(number_format($remaining, 2)) ?></td>
                        <td>
                            <?php if ($status === 'cleared'): ?>
                                <span class="badge text-bg-success">Cleared</span>
                            <?php else: ?>
                                <span class="badge text-bg-warning">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('udhar/view.php?id=' . (int) $r['id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No udhar records found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

