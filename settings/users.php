<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Users - Shop Management';

$pdo = db();
app_require_owner_access();

$success = flash_get('success');
$error = flash_get('error');

$roles = [
    'owner' => 'Owner/Author',
    'staff' => 'Handler/Staff',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'staff'));
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '') {
            flash_set('error', 'Name is required.');
            app_redirect('settings/users.php');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Valid email is required.');
            app_redirect('settings/users.php');
        }
        if (!array_key_exists($role, $roles)) {
            $role = 'staff';
        }
        if (trim($password) === '' || strlen($password) < 6) {
            flash_set('error', 'Password must be at least 6 characters.');
            app_redirect('settings/users.php');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO admins (name, email, role, password)
                VALUES (:name, :email, :role, :password)
            ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':password' => $hash,
            ]);
            flash_set('success', 'User created.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not create user. Email must be unique.');
        }

        app_redirect('settings/users.php');
    }

    if ($action === 'role') {
        $id = (int) ($_POST['id'] ?? 0);
        $role = trim((string) ($_POST['role'] ?? 'staff'));
        if ($id <= 0) {
            flash_set('error', 'Invalid user.');
            app_redirect('settings/users.php');
        }
        if (!array_key_exists($role, $roles)) {
            $role = 'staff';
        }
        $me = app_current_admin();
        if ($me && (int) ($me['id'] ?? 0) === $id && $role !== 'owner') {
            flash_set('error', 'You cannot change your own role to Staff.');
            app_redirect('settings/users.php');
        }

        $stmt = $pdo->prepare("UPDATE admins SET role = :role WHERE id = :id");
        $stmt->execute([':role' => $role, ':id' => $id]);
        flash_set('success', 'Role updated.');
        app_redirect('settings/users.php');
    }

    if ($action === 'password') {
        $id = (int) ($_POST['id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if ($id <= 0) {
            flash_set('error', 'Invalid user.');
            app_redirect('settings/users.php');
        }
        if (trim($password) === '' || strlen($password) < 6) {
            flash_set('error', 'Password must be at least 6 characters.');
            app_redirect('settings/users.php');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE admins SET password = :password WHERE id = :id");
        $stmt->execute([':password' => $hash, ':id' => $id]);
        flash_set('success', 'Password updated.');
        app_redirect('settings/users.php');
    }
}

$stmt = $pdo->query("SELECT id, name, email, role, created_at FROM admins ORDER BY created_at DESC, id DESC");
$users = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Users</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= h((string) $u['name']) ?></div>
                                    <div class="text-muted small"><?= h((string) $u['email']) ?></div>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= ((string) ($u['role'] ?? 'owner')) === 'staff' ? 'secondary' : 'success' ?>">
                                        <?= h((string) ($roles[(string) ($u['role'] ?? 'owner')] ?? (string) ($u['role'] ?? 'owner'))) ?>
                                    </span>
                                </td>
                                <td><?= h((string) $u['created_at']) ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline-flex gap-2 align-items-center">
                                        <input type="hidden" name="action" value="role">
                                        <input type="hidden" name="id" value="<?= h((string) (int) $u['id']) ?>">
                                        <select class="form-select form-select-sm" name="role" onchange="this.form.submit()">
                                            <?php foreach ($roles as $rk => $rl): ?>
                                                <option value="<?= h($rk) ?>" <?= $rk === (string) ($u['role'] ?? 'owner') ? 'selected' : '' ?>><?= h($rl) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="4">
                                    <form method="post" class="row g-2 align-items-end">
                                        <input type="hidden" name="action" value="password">
                                        <input type="hidden" name="id" value="<?= h((string) (int) $u['id']) ?>">
                                        <div class="col-12 col-md-6">
                                            <label class="form-label mb-1">Reset Password</label>
                                            <input class="form-control form-control-sm" type="text" name="password" placeholder="New password (min 6)">
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <button class="btn btn-outline-primary btn-sm w-100" onclick="return confirm('Reset password for this user?')">Update</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No users found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">Create Staff / Owner</h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="create">
                    <div class="col-12">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" name="name" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" type="email" id="email" name="email" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="role">Role</label>
                        <select class="form-select" id="role" name="role">
                            <?php foreach ($roles as $rk => $rl): ?>
                                <option value="<?= h($rk) ?>" <?= $rk === 'staff' ? 'selected' : '' ?>><?= h($rl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" type="text" id="password" name="password" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

