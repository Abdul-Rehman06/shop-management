<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/backup_lib.php';
require_once __DIR__ . '/../config/db.php';

$pageTitle = 'Backup Database - Shop Management';

$pdo = db();
app_require_owner_access();
$success = flash_get('success');
$error = flash_get('error');

$dir = backup_dir();

if (isset($_GET['download'])) {
    $file = basename((string) $_GET['download']);
    if (!preg_match('/\.sql$/i', $file)) {
        flash_set('error', 'Invalid file.');
        app_redirect('settings/backup.php');
    }
    $path = $dir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        flash_set('error', 'File not found.');
        app_redirect('settings/backup.php');
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    try {
        $sql = backup_generate_sql($pdo);
        $file = backup_filename();
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        file_put_contents($path, $sql);
        flash_set('success', 'Backup created: ' . $file);
        app_redirect('settings/backup.php');
    } catch (Throwable $e) {
        $error = 'Failed to create backup.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_backup'])) {
    $file = basename((string) ($_POST['file'] ?? ''));
    if ($file !== '' && preg_match('/\.sql$/i', $file)) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            @unlink($path);
            flash_set('success', 'Backup deleted.');
            app_redirect('settings/backup.php');
        }
    }
    $error = 'Invalid file.';
}

$files = [];
if (is_dir($dir)) {
    $items = scandir($dir) ?: [];
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (preg_match('/\.sql$/i', $f) && is_file($dir . DIRECTORY_SEPARATOR . $f)) {
            $files[] = $f;
        }
    }
}
rsort($files);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Backup Database</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="post">
            <button class="btn btn-gradient shadow-glow" name="create_backup" value="1">Create Backup</button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>File</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $f): ?>
                    <tr>
                        <td><?= h($f) ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/backup.php?download=' . urlencode($f))) ?>">Download</a>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="file" value="<?= h($f) ?>">
                                <button class="btn btn-outline-danger btn-sm" name="delete_backup" value="1">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$files): ?>
                    <tr>
                        <td colspan="2" class="text-center text-muted py-4">No backups yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
