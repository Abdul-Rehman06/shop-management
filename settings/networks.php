<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../load-management/load_lib.php';

$pageTitle = 'Networks - Shop Management';

$pdo = db();
app_require_owner_access();
load_ensure_schema($pdo);

$success = flash_get('success');
$error = flash_get('error');

$coreNetworks = ['Jazz', 'Zong', 'Telenor', 'Ufone'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $name = trim((string) ($_POST['network_name'] ?? ''));
        if ($name === '') {
            flash_set('error', 'Network name is required.');
            app_redirect('settings/networks.php');
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO load_networks (network_name) VALUES (:name)");
            $stmt->execute([':name' => $name]);
            flash_set('success', 'Network added.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not add network. Name must be unique.');
        }

        app_redirect('settings/networks.php');
    }

    if ($action === 'rename') {
        $id = (int) ($_POST['id'] ?? 0);
        $new = trim((string) ($_POST['network_name'] ?? ''));

        if ($id <= 0) {
            flash_set('error', 'Invalid network.');
            app_redirect('settings/networks.php');
        }
        if ($new === '') {
            flash_set('error', 'Network name is required.');
            app_redirect('settings/networks.php');
        }

        $stmt = $pdo->prepare("SELECT network_name FROM load_networks WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $old = (string) ($stmt->fetchColumn() ?: '');
        if ($old === '') {
            flash_set('error', 'Network not found.');
            app_redirect('settings/networks.php');
        }
        if (in_array($old, $coreNetworks, true)) {
            flash_set('error', 'Core networks cannot be renamed.');
            app_redirect('settings/networks.php');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE load_networks SET network_name = :new WHERE id = :id");
            $stmt->execute([':new' => $new, ':id' => $id]);

            $stmt = $pdo->prepare("UPDATE load_entries SET network = :new WHERE network = :old");
            $stmt->execute([':new' => $new, ':old' => $old]);

            $stmt = $pdo->prepare("UPDATE load_customer_transactions SET network = :new WHERE network = :old");
            $stmt->execute([':new' => $new, ':old' => $old]);

            $pdo->commit();
            flash_set('success', 'Network renamed.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Could not rename network.');
        }

        app_redirect('settings/networks.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('error', 'Invalid network.');
            app_redirect('settings/networks.php');
        }

        $stmt = $pdo->prepare("SELECT network_name FROM load_networks WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $name = (string) ($stmt->fetchColumn() ?: '');
        if ($name === '') {
            flash_set('error', 'Network not found.');
            app_redirect('settings/networks.php');
        }
        if (in_array($name, $coreNetworks, true)) {
            flash_set('error', 'Core networks cannot be deleted.');
            app_redirect('settings/networks.php');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM load_entries WHERE network = :n");
            $stmt->execute([':n' => $name]);

            $stmt = $pdo->prepare("DELETE FROM load_customer_transactions WHERE network = :n");
            $stmt->execute([':n' => $name]);

            $stmt = $pdo->prepare("DELETE FROM load_networks WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $pdo->commit();
            flash_set('success', 'Network deleted (related load records removed).');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Could not delete network.');
        }

        app_redirect('settings/networks.php');
    }
}

$stmt = $pdo->query("
    SELECT ln.id, ln.network_name,
           (SELECT COUNT(*) FROM load_entries le WHERE le.network = ln.network_name) AS load_daily_count,
           (SELECT COUNT(*) FROM load_customer_transactions lct WHERE lct.network = ln.network_name) AS load_txn_count
    FROM load_networks ln
    ORDER BY ln.network_name ASC, ln.id ASC
");
$networks = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Networks</h1>
        <div class="text-muted small">Manage networks used in Load Management</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
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
        <h2 class="h6 fw-bold mb-3">Add Network</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-8">
                <label class="form-label" for="network_name_new">Network Name</label>
                <input class="form-control" id="network_name_new" name="network_name" required>
                <div class="text-muted small mt-1">Core networks (Jazz/Zong/Telenor/Ufone) cannot be renamed or deleted.</div>
            </div>
            <div class="col-12 col-md-4">
                <button class="btn btn-gradient shadow-glow w-100">Add</button>
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
                    <th>Network</th>
                    <th class="text-end">Daily Rows</th>
                    <th class="text-end">Txn Rows</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($networks as $n): ?>
                    <?php $name = (string) ($n['network_name'] ?? ''); ?>
                    <tr>
                        <td>
                            <form method="post" class="d-flex gap-2 align-items-center">
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="id" value="<?= h((string) (int) $n['id']) ?>">
                                <input class="form-control form-control-sm" name="network_name" value="<?= h($name) ?>" <?= in_array($name, $coreNetworks, true) ? 'readonly' : '' ?> required>
                        </td>
                        <td class="text-end"><?= h((string) (int) ($n['load_daily_count'] ?? 0)) ?></td>
                        <td class="text-end"><?= h((string) (int) ($n['load_txn_count'] ?? 0)) ?></td>
                        <td class="text-end">
                                <button class="btn btn-outline-primary btn-sm" <?= in_array($name, $coreNetworks, true) ? 'disabled' : '' ?> onclick="return confirm('Rename this network? This will update load records too.')">Save</button>
                            </form>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h((string) (int) $n['id']) ?>">
                                <button class="btn btn-outline-danger btn-sm" <?= in_array($name, $coreNetworks, true) ? 'disabled' : '' ?> onclick="return confirm('Delete this network? Related load records will be removed.')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$networks): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No networks found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

