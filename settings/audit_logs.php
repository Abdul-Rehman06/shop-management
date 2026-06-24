<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

app_require_owner_access();

$pageTitle = 'Audit Logs - Shop Management';

$pdo = db();

$entity = trim((string) ($_GET['entity'] ?? ''));
$action = trim((string) ($_GET['action'] ?? ''));

$allowedActions = ['', 'edit', 'delete'];
if (!in_array($action, $allowedActions, true)) {
    $action = '';
}

$whereParts = ['1=1'];
$params = [];
if ($entity !== '') {
    $whereParts[] = 'l.entity_type = :entity';
    $params[':entity'] = $entity;
}
if ($action !== '') {
    $whereParts[] = 'l.action = :action';
    $params[':action'] = $action;
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$stmt = $pdo->prepare("
    SELECT
        l.id, l.entity_type, l.entity_id, l.action, l.ip_address, l.user_agent, l.created_at,
        a.name AS admin_name, a.email AS admin_email
    FROM audit_logs l
    LEFT JOIN admins a ON a.id = l.admin_id
    {$where}
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$types = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC");
    $types = array_map(static fn ($x): string => (string) $x, $stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Audit Logs</h1>
        <div class="text-muted small">Edit/Delete history for transactions and records</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">Entity</label>
                <select class="form-select" name="entity">
                    <option value="">All</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= h($t) ?>" <?= $entity === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Action</label>
                <select class="form-select" name="action">
                    <option value="">All</option>
                    <option value="edit" <?= $action === 'edit' ? 'selected' : '' ?>>Edit</option>
                    <option value="delete" <?= $action === 'delete' ? 'selected' : '' ?>>Delete</option>
                </select>
            </div>
            <div class="col-12 col-md-5 d-flex gap-2">
                <button class="btn btn-outline-primary w-100">Filter</button>
                <a class="btn btn-outline-secondary w-100" href="<?= h(app_url('settings/audit_logs.php')) ?>">Clear</a>
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
                    <th>Time</th>
                    <th>Admin</th>
                    <th>Entity</th>
                    <th class="text-end">Entity ID</th>
                    <th>Action</th>
                    <th>IP</th>
                    <th>Device/Browser</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['created_at']) ?></td>
                        <td><?= h((string) ($r['admin_name'] ?? '')) ?></td>
                        <td><?= h((string) $r['entity_type']) ?></td>
                        <td class="text-end"><?= h((string) $r['entity_id']) ?></td>
                        <td><?= h((string) $r['action']) ?></td>
                        <td><?= h((string) ($r['ip_address'] ?? '')) ?></td>
                        <td style="max-width: 420px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= h((string) ($r['user_agent'] ?? '')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No audit logs yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

