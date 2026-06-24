<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Credit - Shop Management';

$pdo = db();

$tab = trim((string) ($_GET['tab'] ?? 'active'));
if (!in_array($tab, ['active', 'inactive', 'all'], true)) {
    $tab = 'active';
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
        c.id, c.name, c.phone, c.status, c.created_at,
        COALESCE(SUM(CASE WHEN t.txn_type = 'advance' THEN t.amount ELSE 0 END), 0) AS total_advance,
        COALESCE(SUM(CASE WHEN t.txn_type = 'used' THEN t.amount ELSE 0 END), 0) AS total_used
    FROM credit_customers c
    LEFT JOIN credit_transactions t ON t.customer_id = c.id
    {$where}
    GROUP BY c.id
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT 300
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalAdvance = 0.0;
$totalUsed = 0.0;
foreach ($rows as $r) {
    $totalAdvance += (float) ($r['total_advance'] ?? 0);
    $totalUsed += (float) ($r['total_used'] ?? 0);
}
$totalRemaining = $totalAdvance - $totalUsed;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Credit / Advance Balance</h1>
        <div class="text-muted small">Advance received and used (separate from Udhar)</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('credit/add.php')) ?>">Add Customer Credit</a>
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
                <a class="btn btn-outline-secondary <?= $tab === 'active' ? 'active' : '' ?>" href="<?= h(app_url('credit/index.php?tab=active')) ?>">Active</a>
                <a class="btn btn-outline-secondary <?= $tab === 'inactive' ? 'active' : '' ?>" href="<?= h(app_url('credit/index.php?tab=inactive')) ?>">Inactive</a>
                <a class="btn btn-outline-secondary <?= $tab === 'all' ? 'active' : '' ?>" href="<?= h(app_url('credit/index.php?tab=all')) ?>">All</a>
            </div>
            <form method="get" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search name or phone">
                <button class="btn btn-outline-primary">Search</button>
                <a class="btn btn-outline-secondary" href="<?= h(app_url('credit/index.php?tab=' . urlencode($tab))) ?>">Clear</a>
            </form>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Total Credit (Advance)</div>
                        <div class="h5 mb-0"><?= h(number_format($totalAdvance, 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Used Credit</div>
                        <div class="h5 mb-0"><?= h(number_format($totalUsed, 2)) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-muted small">Remaining Credit</div>
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
                    <th class="text-end">Advance</th>
                    <th class="text-end">Used</th>
                    <th class="text-end">Remaining</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $adv = (float) ($r['total_advance'] ?? 0);
                    $used = (float) ($r['total_used'] ?? 0);
                    $rem = $adv - $used;
                    $status = (string) ($r['status'] ?? 'active');
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= h((string) $r['name']) ?></div>
                            <div class="text-muted small"><?= h((string) ($r['phone'] ?? '')) ?></div>
                        </td>
                        <td class="text-end"><?= h(number_format($adv, 2)) ?></td>
                        <td class="text-end"><?= h(number_format($used, 2)) ?></td>
                        <td class="text-end fw-semibold"><?= h(number_format($rem, 2)) ?></td>
                        <td>
                            <?php if ($status === 'inactive'): ?>
                                <span class="badge text-bg-secondary">Inactive</span>
                            <?php else: ?>
                                <span class="badge text-bg-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('credit/view.php?id=' . (int) $r['id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No customers found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

