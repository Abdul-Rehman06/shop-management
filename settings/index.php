<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Settings - Shop Management';
$isOwner = app_is_owner();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold tracking-tight">Settings</h1>
        <p class="text-gray-500 text-sm mb-0">System configuration and management</p>
    </div>
</div>

<div class="row g-4 animate-slide-up stagger-1">
    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-primary border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3">
                        <i data-lucide="users" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Users</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Create staff users and manage roles</div>
                <a class="btn btn-light text-primary hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/users.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-success border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-success bg-opacity-10 text-success p-2 rounded-3">
                        <i data-lucide="user-check" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Customers</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Save customer once and reuse</div>
                <a class="btn btn-light text-success hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/customers.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-warning border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-warning bg-opacity-10 text-warning p-2 rounded-3">
                        <i data-lucide="shield" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Login History</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Security log and login notifications</div>
                <a class="btn btn-light text-warning hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/login_history.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-danger border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-3">
                        <i data-lucide="file-text" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Audit Logs</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Edit/Delete history tracking</div>
                <a class="btn btn-light text-danger hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/audit_logs.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-info border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-info bg-opacity-10 text-info p-2 rounded-3">
                        <i data-lucide="credit-card" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Accounts</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Manage EasyPaisa/JazzCash/Bank accounts</div>
                <a class="btn btn-light text-info hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/accounts.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-primary border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3">
                        <i data-lucide="truck" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Dealers</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Manage dealer list and networks</div>
                <a class="btn btn-light text-primary hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/dealers.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-success border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-success bg-opacity-10 text-success p-2 rounded-3">
                        <i data-lucide="wifi" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Networks</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Manage load networks</div>
                <a class="btn btn-light text-success hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/networks.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12 col-md-6 col-lg-4">
        <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-dark border-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="bg-dark bg-opacity-10 text-dark p-2 rounded-3">
                    <i data-lucide="key" class="w-5 h-5"></i>
                </div>
                <div class="fw-bold text-gray-800 fs-5">Change Password</div>
            </div>
            <div class="text-gray-500 text-sm mb-4">Update admin password</div>
            <a class="btn btn-light text-dark hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/change_password.php')) ?>">
                Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </a>
        </div>
    </div>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-info border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-info bg-opacity-10 text-info p-2 rounded-3">
                        <i data-lucide="store" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Shop Settings</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Company name, address, logo</div>
                <a class="btn btn-light text-info hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/shop.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-primary border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3">
                        <i data-lucide="database" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Backup Database</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Create and download SQL backup</div>
                <a class="btn btn-light text-primary hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/backup.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card h-100 p-4 transition-all hover-lift hover-bg-light border-start border-danger border-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-3">
                        <i data-lucide="refresh-cw" class="w-5 h-5"></i>
                    </div>
                    <div class="fw-bold text-gray-800 fs-5">Restore Database</div>
                </div>
                <div class="text-gray-500 text-sm mb-4">Upload and restore SQL backup</div>
                <a class="btn btn-light text-danger hover-lift shadow-sm rounded-xl px-4 d-inline-flex align-items-center gap-2" href="<?= h(app_url('settings/restore.php')) ?>">
                    Open <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
