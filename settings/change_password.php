<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Change Password - Shop Management';

$pdo = db();
$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirm password do not match.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $stmt = $pdo->prepare('SELECT id, password FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $adminId]);
        $row = $stmt->fetch();
        if (!$row) {
            $error = 'Admin not found.';
        } else {
            $stored = (string) $row['password'];
            $info = password_get_info($stored);
            $ok = false;
            if (($info['algo'] ?? 0) !== 0) {
                $ok = password_verify($current, $stored);
            } else {
                $ok = hash_equals($stored, $current);
            }

            if (!$ok) {
                $error = 'Current password is incorrect.';
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $upd = $pdo->prepare('UPDATE admins SET password = :password WHERE id = :id');
                $upd->execute([':password' => $hash, ':id' => $adminId]);
                $success = 'Password updated successfully.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Change Password</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="current_password">Current Password</label>
                    <input class="form-control" type="password" id="current_password" name="current_password" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="new_password">New Password</label>
                    <input class="form-control" type="password" id="new_password" name="new_password" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" required>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-gradient shadow-glow">Update Password</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

