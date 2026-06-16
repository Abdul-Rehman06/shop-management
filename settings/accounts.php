<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Accounts - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');

$types = [
    'easypaisa' => 'EasyPaisa',
    'jazzcash' => 'JazzCash',
    'bank' => 'Bank',
    'cash' => 'Cash',
];

$filterType = trim((string) ($_GET['type'] ?? ''));
if ($filterType !== '' && !array_key_exists($filterType, $types)) {
    $filterType = '';
}

$editId = (int) ($_GET['edit'] ?? 0);
$isAdd = (int) ($_GET['add'] ?? 0) === 1;
$isEdit = $editId > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('error', 'Invalid account.');
            app_redirect('settings/accounts.php' . ($filterType !== '' ? ('?type=' . urlencode($filterType)) : ''));
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM accounts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            flash_set('success', 'Account deleted.');
        } catch (Throwable $e) {
            flash_set('error', 'Cannot delete this account because it has transactions. Set status to Inactive instead.');
        }

        app_redirect('settings/accounts.php' . ($filterType !== '' ? ('?type=' . urlencode($filterType)) : ''));
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $accountName = trim((string) ($_POST['account_name'] ?? ''));
        $accountType = trim((string) ($_POST['account_type'] ?? ''));
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));

        if ($accountName === '') {
            flash_set('error', 'Account name is required.');
            app_redirect('settings/accounts.php' . ($filterType !== '' ? ('?type=' . urlencode($filterType)) : ''));
        }
        if (!array_key_exists($accountType, $types)) {
            flash_set('error', 'Invalid account type.');
            app_redirect('settings/accounts.php' . ($filterType !== '' ? ('?type=' . urlencode($filterType)) : ''));
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $accountNumberValue = $accountNumber !== '' ? $accountNumber : null;

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE accounts
                    SET account_name = :account_name,
                        account_type = :account_type,
                        account_number = :account_number,
                        status = :status
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':account_name' => $accountName,
                    ':account_type' => $accountType,
                    ':account_number' => $accountNumberValue,
                    ':status' => $status,
                    ':id' => $id,
                ]);
                flash_set('success', 'Account updated.');
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO accounts (account_name, account_type, account_number, status)
                    VALUES (:account_name, :account_type, :account_number, :status)
                ");
                $stmt->execute([
                    ':account_name' => $accountName,
                    ':account_type' => $accountType,
                    ':account_number' => $accountNumberValue,
                    ':status' => $status,
                ]);
                flash_set('success', 'Account added.');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not save account. Make sure the account name is unique.');
        }

        app_redirect('settings/accounts.php' . ($filterType !== '' ? ('?type=' . urlencode($filterType)) : ''));
    }
}

$editAccount = null;
if ($isEdit) {
    $editAccount = wallet_account($pdo, $editId);
    if (!$editAccount) {
        $isEdit = false;
        $editId = 0;
    }
}

$params = [];
$where = '';
if ($filterType !== '') {
    $where = 'WHERE account_type = :type';
    $params[':type'] = $filterType;
}

$stmt = $pdo->prepare("
    SELECT id, account_name, account_type, account_number, status, created_at
    FROM accounts
    {$where}
    ORDER BY account_type ASC, account_name ASC
");
$stmt->execute($params);
$accounts = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Accounts</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('settings/accounts.php?add=1' . ($filterType !== '' ? ('&type=' . urlencode($filterType)) : ''))) ?>">Add Account</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-12 col-md-8">
                        <label class="form-label" for="type">Filter Type</label>
                        <select class="form-select" id="type" name="type">
                            <option value="">All</option>
                            <?php foreach ($types as $k => $label): ?>
                                <option value="<?= h($k) ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button class="btn btn-outline-primary w-100">Apply</button>
                        <a class="btn btn-outline-secondary w-100" href="<?= h(app_url('settings/accounts.php')) ?>">Reset</a>
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
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $a): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= h((string) $a['account_name']) ?></div>
                                    <div class="text-muted small"><?= h((string) ($a['account_number'] ?? '')) ?></div>
                                </td>
                                <td><?= h((string) ($types[(string) $a['account_type']] ?? (string) $a['account_type'])) ?></td>
                                <td>
                                    <?php if (($a['status'] ?? '') === 'active'): ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/accounts.php?edit=' . (int) $a['id'] . ($filterType !== '' ? ('&type=' . urlencode($filterType)) : ''))) ?>">Edit</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= h((string) (int) $a['id']) ?>">
                                        <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this account?')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$accounts): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No accounts found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <?php if ($isAdd || $isEdit): ?>
            <?php
            $formId = $isEdit ? (int) ($editAccount['id'] ?? 0) : 0;
            $formName = $isEdit ? (string) ($editAccount['account_name'] ?? '') : '';
            $formType = $isEdit ? (string) ($editAccount['account_type'] ?? '') : ($filterType !== '' ? $filterType : 'easypaisa');
            $formNumber = $isEdit ? (string) ($editAccount['account_number'] ?? '') : '';
            $formStatus = $isEdit ? (string) ($editAccount['status'] ?? 'active') : 'active';
            ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="fw-semibold"><?= $isEdit ? 'Edit Account' : 'Add Account' ?></div>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/accounts.php' . ($filterType !== '' ? ('?type=' . urlencode($filterType)) : ''))) ?>">Close</a>
                    </div>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= h((string) $formId) ?>">

                        <div class="col-12">
                            <label class="form-label" for="account_name">Account Name</label>
                            <input class="form-control" id="account_name" name="account_name" value="<?= h($formName) ?>" required>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="account_type">Account Type</label>
                            <select class="form-select" id="account_type" name="account_type" required>
                                <?php foreach ($types as $k => $label): ?>
                                    <option value="<?= h($k) ?>" <?= $formType === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" <?= $formStatus === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $formStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="account_number">Account Number (optional)</label>
                            <input class="form-control" id="account_number" name="account_number" value="<?= h($formNumber) ?>">
                        </div>

                        <div class="col-12">
                            <button class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Account' ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted">Select an account to edit, or click “Add Account”.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

