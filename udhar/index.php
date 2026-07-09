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

$tab = trim((string) ($_GET['tab'] ?? 'all'));
if (!in_array($tab, ['pending', 'cleared', 'all'], true)) {
    $tab = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$success = flash_get('success');
$error = flash_get('error');

$whereParts = [];
$havingParts = [];
$params = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $whereParts[] = '(c.name LIKE :q ESCAPE \'\\\\\' OR c.phone LIKE :q ESCAPE \'\\\\\')';
    $params[':q'] = $like;
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
    ORDER BY
        CASE WHEN :has_query = 1 THEN c.name ELSE '' END ASC,
        c.created_at DESC,
        c.id DESC
    LIMIT 150
");
$params[':has_query'] = $q !== '' ? 1 : 0;
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

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Udhar Management</h1>
        <div class="text-gray-500 text-sm">Manage customer udhar and payments</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-gradient shadow-glow d-inline-flex align-items-center gap-2 shadow-sm" href="<?= h(app_url('udhar/add.php')) ?>">
            <i class="bi bi-plus-lg"></i> Add Udhar
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="glass-card mb-4 animate-slide-up stagger-1">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4">
            <div class="d-flex gap-2">
                <a class="btn <?= $tab === 'pending' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift' ?> px-4 rounded-xl" href="<?= h(app_url('udhar/index.php?tab=pending')) ?>">Pending</a>
                <a class="btn <?= $tab === 'cleared' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift' ?> px-4 rounded-xl" href="<?= h(app_url('udhar/index.php?tab=cleared')) ?>">Cleared</a>
                <a class="btn <?= $tab === 'all' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift' ?> px-4 rounded-xl" href="<?= h(app_url('udhar/index.php?tab=all')) ?>">All</a>
            </div>
            <form method="get" class="d-flex gap-2 align-items-center bg-light p-1 rounded-pill">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <div class="input-group input-group-sm border-0">
                    <span class="input-group-text bg-transparent border-0 text-muted ps-3">
                        <i class="bi bi-search"></i>
                    </span>
                    <input class="form-control border-0 bg-transparent shadow-none" name="q" value="<?= h($q) ?>" placeholder="Search name or phone">
                </div>
                <button class="btn btn-gradient shadow-glow rounded-pill btn-sm px-3">Search</button>
                <?php if ($q !== ''): ?>
                    <a class="btn btn-light rounded-pill btn-sm px-3 text-muted" href="<?= h(app_url('udhar/index.php?tab=' . urlencode($tab))) ?>"><i class="bi bi-x"></i></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="row g-4">
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-4 border-start border-primary border-4 h-100 transition-all hover-lift">
                    <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Total Udhar</div>
                    <div class="h4 mb-0 font-bold text-primary"><?= h(number_format($totalAmount, 2)) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-4 border-start border-success border-4 h-100 transition-all hover-lift">
                    <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Total Paid</div>
                    <div class="h4 mb-0 font-bold text-success"><?= h(number_format($totalPaid, 2)) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-4 border-start border-warning border-4 h-100 transition-all hover-lift">
                    <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Total Remaining</div>
                    <div class="h4 mb-0 font-bold text-warning"><?= h(number_format($totalRemaining, 2)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="glass-card animate-slide-up stagger-2">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-table">
                <thead class="bg-light bg-opacity-50">
                <tr>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Customer</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Date</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Total</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Paid</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Remaining</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Status</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Action</th>
                </tr>
                </thead>
                <tbody class="border-top-0">
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
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar-circle bg-primary bg-opacity-10 text-primary fw-bold">
                                    <?= h(strtoupper(substr((string) $r['name'], 0, 1))) ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-gray-800"><?= h((string) $r['name']) ?></div>
                                    <div class="text-muted small"><?= h((string) ($r['phone'] ?? 'No Phone')) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600"><?= h((string) $r['udhar_date']) ?></td>
                        <td class="px-4 py-3 text-end font-medium"><?= h(number_format($amount, 2)) ?></td>
                        <td class="px-4 py-3 text-end font-medium text-success"><?= h(number_format($paid, 2)) ?></td>
                        <td class="px-4 py-3 text-end fw-bold <?= $remaining > 0 ? 'text-warning' : 'text-gray-500' ?>"><?= h(number_format($remaining, 2)) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($status === 'cleared'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill border border-success border-opacity-25"><i class="bi bi-check-circle me-1"></i> Cleared</span>
                            <?php else: ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2 rounded-pill border border-warning border-opacity-25"><i class="bi bi-hourglass-split me-1"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('udhar/view.php?id=' . (int) $r['id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i class="bi bi-inbox fs-1 text-gray-300 mb-2"></i>
                                <p class="mb-0">No udhar records found.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
