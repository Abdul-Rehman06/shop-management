<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Settings - Shop Management';
$isOwner = app_is_owner();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Settings</h1>
</div>

<div class="row g-3">
    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="fw-semibold mb-1">Users</div>
                    <div class="text-muted small mb-3">Create staff users and manage roles</div>
                    <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/users.php')) ?>">Open</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="fw-semibold mb-1">Accounts</div>
                    <div class="text-muted small mb-3">Add, edit, delete EasyPaisa/JazzCash/Bank accounts</div>
                    <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/accounts.php')) ?>">Open</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12 col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="fw-semibold mb-1">Change Password</div>
                <div class="text-muted small mb-3">Update admin password</div>
                <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/change_password.php')) ?>">Open</a>
            </div>
        </div>
    </div>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="fw-semibold mb-1">Shop Settings</div>
                    <div class="text-muted small mb-3">Company name, address, logo</div>
                    <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/shop.php')) ?>">Open</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="fw-semibold mb-1">Backup Database</div>
                    <div class="text-muted small mb-3">Create and download SQL backup</div>
                    <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/backup.php')) ?>">Open</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="fw-semibold mb-1">Restore Database</div>
                    <div class="text-muted small mb-3">Upload and restore SQL backup</div>
                    <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/restore.php')) ?>">Open</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
