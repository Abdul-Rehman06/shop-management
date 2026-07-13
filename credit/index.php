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
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $whereParts[] = '(c.name LIKE :q_name ESCAPE \'\\\\\' OR c.phone LIKE :q_phone ESCAPE \'\\\\\')';
    $params[':q_name'] = $like;
    $params[':q_phone'] = $like;
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
    ORDER BY
        CASE WHEN :has_query = 1 THEN c.name ELSE '' END ASC,
        c.created_at DESC,
        c.id DESC
    LIMIT 150
");
$params[':has_query'] = $q !== '' ? 1 : 0;
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

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Credit / Advance Balance</h1>
        <div class="text-gray-500 text-sm">Advance received and used (separate from Udhar)</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-gradient shadow-glow d-inline-flex align-items-center gap-2 shadow-sm" href="<?= h(app_url('credit/add.php')) ?>">
            <i class="bi bi-plus-lg"></i> Add Customer Credit
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
                <a class="btn <?= $tab === 'active' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift' ?> px-4 rounded-xl" href="<?= h(app_url('credit/index.php?tab=active')) ?>">Active</a>
                <a class="btn <?= $tab === 'inactive' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift' ?> px-4 rounded-xl" href="<?= h(app_url('credit/index.php?tab=inactive')) ?>">Inactive</a>
                <a class="btn <?= $tab === 'all' ? 'btn-gradient shadow-glow' : 'btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift' ?> px-4 rounded-xl" href="<?= h(app_url('credit/index.php?tab=all')) ?>">All</a>
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
                    <a class="btn btn-light rounded-pill btn-sm px-3 text-muted" href="<?= h(app_url('credit/index.php?tab=' . urlencode($tab))) ?>"><i class="bi bi-x"></i></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="row g-4">
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-4 border-start border-primary border-4 h-100 transition-all hover-lift">
                    <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Total Credit (Advance)</div>
                    <div class="h4 mb-0 font-bold text-primary"><?= h(number_format($totalAdvance, 2)) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-4 border-start border-warning border-4 h-100 transition-all hover-lift">
                    <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Used Credit</div>
                    <div class="h4 mb-0 font-bold text-warning"><?= h(number_format($totalUsed, 2)) ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="p-3 bg-light rounded-4 border-start border-success border-4 h-100 transition-all hover-lift">
                    <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Remaining Credit</div>
                    <div class="h4 mb-0 font-bold text-success"><?= h(number_format($totalRemaining, 2)) ?></div>
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
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Advance</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Used</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Remaining</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Status</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Action</th>
                </tr>
                </thead>
                <tbody class="border-top-0">
                <?php foreach ($rows as $r): ?>
                    <?php
                    $adv = (float) ($r['total_advance'] ?? 0);
                    $used = (float) ($r['total_used'] ?? 0);
                    $rem = $adv - $used;
                    $status = (string) ($r['status'] ?? 'active');
                    ?>
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar-circle bg-success bg-opacity-10 text-success fw-bold">
                                    <?= h(strtoupper(substr((string) $r['name'], 0, 1))) ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-gray-800"><?= h((string) $r['name']) ?></div>
                                    <div class="text-muted small"><?= h((string) ($r['phone'] ?? 'No Phone')) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-end font-medium text-primary"><?= h(number_format($adv, 2)) ?></td>
                        <td class="px-4 py-3 text-end font-medium text-warning"><?= h(number_format($used, 2)) ?></td>
                        <td class="px-4 py-3 text-end fw-bold <?= $rem > 0 ? 'text-success' : 'text-gray-500' ?>"><?= h(number_format($rem, 2)) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($status === 'inactive'): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2 rounded-pill border border-secondary border-opacity-25"><i class="bi bi-x-circle me-1"></i> Inactive</span>
                            <?php else: ?>
                                <span class="badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill border border-success border-opacity-25"><i class="bi bi-check-circle me-1"></i> Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('credit/view.php?id=' . (int) $r['id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i class="bi bi-inbox fs-1 text-gray-300 mb-2"></i>
                                <p class="mb-0">No customers found.</p>
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

