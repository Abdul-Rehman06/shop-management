<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/backup_lib.php';

$pageTitle = 'Restore Database - Shop Management';

$pdo = db();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['sql_file']) || !is_array($_FILES['sql_file'])) {
        $error = 'Please choose a SQL file.';
    } else {
        $file = $_FILES['sql_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Upload failed.';
        } else {
            $tmp = (string) $file['tmp_name'];
            $size = (int) $file['size'];
            $name = (string) $file['name'];
            if ($size <= 0 || $size > 25 * 1024 * 1024) {
                $error = 'SQL file must be less than 25MB.';
            } elseif (!preg_match('/\.sql$/i', $name)) {
                $error = 'Only .sql files are allowed.';
            } else {
                $sql = file_get_contents($tmp);
                if ($sql === false || trim($sql) === '') {
                    $error = 'SQL file is empty.';
                } else {
                    try {
                        $statements = restore_split_sql($sql);
                        $pdo->beginTransaction();
                        foreach ($statements as $stmtSql) {
                            $pdo->exec($stmtSql);
                        }
                        $pdo->commit();
                        $success = 'Database restored successfully.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'Restore failed. Please upload a valid SQL backup.';
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Restore Database</h1>
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
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label" for="sql_file">SQL File</label>
                <input class="form-control" type="file" id="sql_file" name="sql_file" accept=".sql" required>
                <div class="text-muted small mt-1">Max 25MB. Recommended: use backups created from Settings → Backup.</div>
            </div>
            <button class="btn btn-danger">Restore</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

