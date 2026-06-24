<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Dealers - Shop Management';

$pdo = db();
app_require_owner_access();

$success = flash_get('success');
$error = flash_get('error');

$networks = ['Jazz', 'Zong', 'Telenor', 'Ufone'];
$statuses = ['active' => 'Active', 'inactive' => 'Inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $name = trim((string) ($_POST['dealer_name'] ?? ''));
        $network = trim((string) ($_POST['network'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));

        if ($name === '') {
            flash_set('error', 'Dealer name is required.');
            app_redirect('settings/dealers.php');
        }
        if (!in_array($network, $networks, true)) {
            flash_set('error', 'Please select a valid network.');
            app_redirect('settings/dealers.php');
        }
        if (!array_key_exists($status, $statuses)) {
            $status = 'active';
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO dealers (dealer_name, network, status)
                VALUES (:dealer_name, :network, :status)
            ");
            $stmt->execute([':dealer_name' => $name, ':network' => $network, ':status' => $status]);
            flash_set('success', 'Dealer added.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not add dealer. Dealer name must be unique.');
        }

        app_redirect('settings/dealers.php');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['dealer_name'] ?? ''));
        $network = trim((string) ($_POST['network'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));

        if ($id <= 0) {
            flash_set('error', 'Invalid dealer.');
            app_redirect('settings/dealers.php');
        }
        if ($name === '') {
            flash_set('error', 'Dealer name is required.');
            app_redirect('settings/dealers.php');
        }
        if (!in_array($network, $networks, true)) {
            flash_set('error', 'Please select a valid network.');
            app_redirect('settings/dealers.php');
        }
        if (!array_key_exists($status, $statuses)) {
            $status = 'active';
        }

        $stmt = $pdo->prepare("SELECT * FROM dealers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
        if (!$before) {
            flash_set('error', 'Dealer not found.');
            app_redirect('settings/dealers.php');
        }

        $oldName = (string) ($before['dealer_name'] ?? '');

        try {
            $stmt = $pdo->prepare("
                UPDATE dealers
                SET dealer_name = :dealer_name,
                    network = :network,
                    status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                ':dealer_name' => $name,
                ':network' => $network,
                ':status' => $status,
                ':id' => $id,
            ]);

            if ($oldName !== '' && $oldName !== $name) {
                $stmt = $pdo->prepare("
                    UPDATE dealer_payments
                    SET dealer_name = :new_name
                    WHERE dealer_name = :old_name
                ");
                $stmt->execute([':new_name' => $name, ':old_name' => $oldName]);
            }

            $stmt = $pdo->prepare("SELECT * FROM dealers WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $after = $stmt->fetch() ?: null;
            app_audit_log('dealers', $id, 'edit', is_array($before) ? $before : null, is_array($after) ? $after : null);

            flash_set('success', 'Dealer updated.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not update dealer. Dealer name must be unique.');
        }

        app_redirect('settings/dealers.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('error', 'Invalid dealer.');
            app_redirect('settings/dealers.php');
        }

        $stmt = $pdo->prepare("SELECT * FROM dealers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
        if (!$before) {
            flash_set('error', 'Dealer not found.');
            app_redirect('settings/dealers.php');
        }

        $stmt = $pdo->prepare("DELETE FROM dealers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        app_audit_log('dealers', $id, 'delete', is_array($before) ? $before : null, null);

        flash_set('success', 'Dealer deleted.');
        app_redirect('settings/dealers.php');
    }
}

$stmt = $pdo->query("SELECT id, dealer_name, network, status, updated_at FROM dealers ORDER BY dealer_name ASC, id ASC");
$dealers = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Dealers</h1>
        <div class="text-muted small">Edit dealer list used in Dealer Payments</div>
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
        <h2 class="h6 fw-bold mb-3">Add Dealer</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-5">
                <label class="form-label" for="dealer_name_new">Dealer Name</label>
                <input class="form-control" id="dealer_name_new" name="dealer_name" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="network_new">Network</label>
                <select class="form-select" id="network_new" name="network" required>
                    <option value="">Select</option>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>"><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="status_new">Status</label>
                <select class="form-select" id="status_new" name="status">
                    <?php foreach ($statuses as $sk => $sl): ?>
                        <option value="<?= h($sk) ?>" <?= $sk === 'active' ? 'selected' : '' ?>><?= h($sl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
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
                    <th>Dealer</th>
                    <th>Network</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($dealers as $d): ?>
                    <tr>
                        <td>
                            <form method="post" class="d-flex gap-2 align-items-center">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= h((string) (int) $d['id']) ?>">
                                <input class="form-control form-control-sm" name="dealer_name" value="<?= h((string) $d['dealer_name']) ?>" required>
                        </td>
                        <td>
                                <select class="form-select form-select-sm" name="network" required>
                                    <?php foreach ($networks as $n): ?>
                                        <option value="<?= h($n) ?>" <?= (string) $d['network'] === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td>
                                <select class="form-select form-select-sm" name="status">
                                    <?php foreach ($statuses as $sk => $sl): ?>
                                        <option value="<?= h($sk) ?>" <?= (string) $d['status'] === $sk ? 'selected' : '' ?>><?= h($sl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td><?= h((string) $d['updated_at']) ?></td>
                        <td class="text-end">
                                <button class="btn btn-outline-primary btn-sm" onclick="return confirm('Update this dealer?')">Save</button>
                            </form>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h((string) (int) $d['id']) ?>">
                                <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this dealer? (Payment history will remain)')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dealers): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No dealers found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

