<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Udhar - Shop Management';

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

$tab = trim((string) ($_GET['tab'] ?? 'pending'));
if (!in_array($tab, ['pending', 'cleared', 'all'], true)) {
    $tab = 'pending';
}
$q = trim((string) ($_GET['q'] ?? ''));

$success = flash_get('success');
$error = flash_get('error');

$whereParts = [];
$havingParts = [];
$params = [];
if ($q !== '') {
    $whereParts[] = '(c.name LIKE :q OR c.phone LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
if ($tab === 'pending') {
    $havingParts[] = '(balance > 0)';
} elseif ($tab === 'cleared') {
    $havingParts[] = '(balance <= 0)';
}
$having = $havingParts ? ('HAVING ' . implode(' AND ', $havingParts)) : '';

$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.phone,
        c.udhar_date,
        c.notes,
        c.created_at,
        COALESCE(SUM(CASE WHEN t.txn_type = 'udhar' THEN t.amount ELSE 0 END), 0) AS udhar_total,
        COALESCE(SUM(CASE WHEN t.txn_type = 'payment' THEN t.amount ELSE 0 END), 0) AS paid_total,
        (
            COALESCE(SUM(CASE WHEN t.txn_type = 'udhar' THEN t.amount ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN t.txn_type = 'payment' THEN t.amount ELSE 0 END), 0)
        ) AS balance
    FROM udhar_customers c
    LEFT JOIN udhar_transactions t ON t.udhar_id = c.id
    {$where}
    GROUP BY c.id
    {$having}
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as $r) {
    udhar_ensure_ledger($pdo, (int) ($r['id'] ?? 0));
}

$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalAmount = 0.0;
$totalPaid = 0.0;
$totalRemaining = 0.0;
foreach ($rows as $r) {
    $amount = (float) ($r['udhar_total'] ?? 0);
    $paid = (float) ($r['paid_total'] ?? 0);
    $remaining = (float) ($r['balance'] ?? 0);
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
                    $amount = (float) $r['udhar_total'];
                    $paid = (float) $r['paid_total'];
                    $remaining = (float) ($r['balance'] ?? 0);
                    if ($remaining < 0) {
                        $remaining = 0.0;
                    }
                    $status = $remaining <= 0 ? 'cleared' : 'pending';
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
