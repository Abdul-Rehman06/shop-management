<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/ep_lib.php';

$pageTitle = 'EasyPaisa - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canEditDelete = app_can_edit_delete_records();
$isOwner = app_is_owner();

$accounts = wallet_accounts($pdo, 'easypaisa');
$accountId = (int) ($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
$q = trim((string) ($_GET['q'] ?? ''));
if ($accountId > 0) {
    $ok = false;
    foreach ($accounts as $a) {
        if ((int) $a['id'] === $accountId) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $accountId = (int) ($accounts[0]['id'] ?? 0);
    }
}

$totals = $accountId > 0 ? ep_totals($pdo, $accountId) : ['opening' => 0.0, 'receiving' => 0.0, 'sending' => 0.0, 'closing' => 0.0, 'commission' => 0.0];
$searchTotals = null;
if ($accountId > 0 && $q !== '') {
    $rows = wallet_search_transactions($pdo, $accountId, $q, 200);
    $searchTotals = wallet_search_totals($pdo, $accountId, $q);
} else {
    $rows = $accountId > 0 ? wallet_recent_transactions($pdo, $accountId, 50) : [];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <h1 class="h4 mb-0">EasyPaisa</h1>
        <form method="get" class="d-flex align-items-center gap-2">
            <label class="text-muted small" for="account_id">Account</label>
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <select class="form-select form-select-sm" id="account_id" name="account_id" onchange="this.form.submit()" <?= $accounts ? '' : 'disabled' ?>>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= h((string) (int) $a['id']) ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>>
                        <?= h((string) $a['account_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($isOwner): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/accounts.php?type=easypaisa')) ?>">Manage Accounts</a>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-secondary btn-sm" href="<?= h(app_url('easypaisa/report.php')) ?>">Reports</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('easypaisa/opening.php?account_id=' . $accountId)) ?>">Set Opening</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('easypaisa/receiving.php?account_id=' . $accountId)) ?>">Add Receiving</a>
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('easypaisa/sending.php?account_id=' . $accountId)) ?>">Add Sending</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($accounts): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="account_id" value="<?= h((string) $accountId) ?>">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="q">Search</label>
                    <input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="Search name, number or tx id">
                </div>
                <div class="col-12 col-md-auto d-flex gap-2">
                    <button class="btn btn-outline-primary">Search</button>
                    <a class="btn btn-outline-secondary" href="<?= h(app_url('easypaisa/index.php?account_id=' . $accountId)) ?>">Clear</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($q !== '' && is_array($searchTotals)): ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Found</div>
                    <div class="h5 mb-0"><?= h((string) (int) ($searchTotals['count'] ?? 0)) ?> transactions</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Receiving</div>
                    <div class="h5 mb-0"><?= h(number_format((float) ($searchTotals['receiving'] ?? 0), 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Sending</div>
                    <div class="h5 mb-0"><?= h(number_format((float) ($searchTotals['sending'] ?? 0), 2)) ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$accounts): ?>
    <div class="alert alert-warning">No EasyPaisa accounts found. Add accounts first.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Opening Balance</div>
                    <div class="h5 mb-0"><?= h(number_format((float) $totals['opening'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Receiving</div>
                    <div class="h5 mb-0"><?= h(number_format((float) $totals['receiving'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Sending</div>
                    <div class="h5 mb-0"><?= h(number_format((float) $totals['sending'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Closing Balance</div>
                    <div class="h5 mb-0"><?= h(number_format((float) $totals['closing'], 2)) ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Customer</th>
                    <th>Number</th>
                    <th>Transaction ID</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Charges</th>
                    <th>Remarks</th>
                    <?php if ($canEditDelete): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $amountStyle = '';
                    if (($r['type'] ?? '') === 'receiving') {
                        $amountStyle = 'color:#16a34a;font-weight:600;';
                    } elseif (($r['type'] ?? '') === 'sending') {
                        $amountStyle = 'color:#dc2626;font-weight:600;';
                    }
                    ?>
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['type']) ?></td>
                        <td><?= h((string) ($r['customer_name'] ?? '')) ?></td>
                        <td><?= h((string) ($r['number'] ?? '')) ?></td>
                        <td><?= h((string) ($r['transaction_id'] ?? '')) ?></td>
                        <td class="text-end" style="<?= h($amountStyle) ?>"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['charges'], 2)) ?></td>
                        <td><?= h((string) ($r['remarks'] ?? '')) ?></td>
                        <?php if ($canEditDelete): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('easypaisa/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('easypaisa/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (8 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-4">No transactions yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
