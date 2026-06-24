<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

app_require_owner_access();

$pageTitle = 'Login History - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');

$stmt = $pdo->query("
    SELECT l.id, l.logged_in_at, l.ip_address, l.user_agent, a.name, a.email, a.role
    FROM admin_login_logs l
    JOIN admins a ON a.id = l.admin_id
    ORDER BY l.logged_in_at DESC, l.id DESC
    LIMIT 200
");
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Login History</h1>
        <div class="text-muted small">Security log of all admin sign-ins</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date & Time</th>
                    <th>Admin</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>IP Address</th>
                    <th>Device/Browser</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['logged_in_at']) ?></td>
                        <td><?= h((string) $r['name']) ?></td>
                        <td><?= h((string) $r['email']) ?></td>
                        <td><?= h((string) ($r['role'] ?? '')) ?></td>
                        <td><?= h((string) ($r['ip_address'] ?? '')) ?></td>
                        <td style="max-width: 420px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= h((string) ($r['user_agent'] ?? '')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No login records yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

