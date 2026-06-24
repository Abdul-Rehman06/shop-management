<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pageTitle = 'Load Transactions - Shop Management';

$pdo = db();
load_ensure_schema($pdo);

$success = flash_get('success');
$errorFlash = flash_get('error');
$canViewProfit = app_can_view_profit();
$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);

$networks = load_get_networks($pdo);

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

$from = trim((string) ($_GET['from'] ?? date('Y-m-d')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
$networkFilter = trim((string) ($_GET['network'] ?? ''));
if ($networkFilter !== '' && !in_array($networkFilter, $networks, true)) {
    $networkFilter = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

$txnDate = trim((string) ($_POST['txn_date'] ?? date('Y-m-d')));
$txnNetwork = trim((string) ($_POST['network'] ?? ($networks[0] ?? 'Jazz')));
$customerId = (int) ($_POST['customer_id'] ?? 0);
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
$amount = trim((string) ($_POST['amount'] ?? ''));
$profit = trim((string) ($_POST['profit'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'add_txn'));

    if ($action === 'save_customer') {
        $saveName = trim((string) ($_POST['save_customer_name'] ?? ''));
        $savePhone = trim((string) ($_POST['save_customer_phone'] ?? ''));
        if ($saveName === '' || $savePhone === '') {
            $formError = 'Customer name and phone are required.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (name, phone)
                    VALUES (:name, :phone)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name)
                ");
                $stmt->execute([':name' => $saveName, ':phone' => $savePhone]);
                flash_set('success', 'Customer saved.');
                app_redirect('load-management/transactions.php?from=' . urlencode($from) . '&to=' . urlencode($to) . ($networkFilter !== '' ? ('&network=' . urlencode($networkFilter)) : '') . ($q !== '' ? ('&q=' . urlencode($q)) : ''));
            } catch (Throwable $e) {
                $formError = 'Could not save customer.';
            }
        }
    }

    if ($action !== 'save_customer') {
    $txnDate = trim((string) ($_POST['txn_date'] ?? ''));
    $txnNetwork = trim((string) ($_POST['network'] ?? ''));
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $profit = trim((string) ($_POST['profit'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($txnDate === '') {
        $formError = 'Date is required.';
    } elseif ($txnNetwork === '' || !in_array($txnNetwork, $networks, true)) {
        $formError = 'Network is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $formError = 'Amount must be a positive number.';
    } elseif ($canViewProfit && $profit !== '' && !is_numeric($profit)) {
        $formError = 'Profit must be a number.';
    } else {
        if ($customerId > 0 && ($customerName === '' || $customerPhone === '')) {
            foreach ($savedCustomers as $c) {
                if ((int) ($c['id'] ?? 0) === $customerId) {
                    $customerName = (string) ($c['name'] ?? '');
                    $customerPhone = (string) ($c['phone'] ?? '');
                    break;
                }
            }
        }

        if ($customerPhone !== '' && $customerName !== '') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (name, phone)
                    VALUES (:name, :phone)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name)
                ");
                $stmt->execute([':name' => $customerName, ':phone' => $customerPhone]);
                if ($customerId <= 0) {
                    $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = :phone LIMIT 1");
                    $stmt->execute([':phone' => $customerPhone]);
                    $customerId = (int) ($stmt->fetchColumn() ?: 0);
                }
            } catch (Throwable $e) {
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO load_customer_transactions
                (txn_date, network, customer_id, customer_name, customer_phone, amount, profit, notes, created_by)
            VALUES
                (:txn_date, :network, :customer_id, :customer_name, :customer_phone, :amount, :profit, :notes, :created_by)
        ");
        $stmt->execute([
            ':txn_date' => $txnDate,
            ':network' => $txnNetwork,
            ':customer_id' => $customerId > 0 ? $customerId : null,
            ':customer_name' => $customerName !== '' ? $customerName : null,
            ':customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            ':amount' => (float) $amount,
            ':profit' => $canViewProfit ? ($profit === '' ? 0.0 : (float) $profit) : 0.0,
            ':notes' => $notes !== '' ? $notes : null,
            ':created_by' => $adminId > 0 ? $adminId : null,
        ]);

        flash_set('success', 'Load transaction saved.');
        app_redirect('load-management/transactions.php?from=' . urlencode($from) . '&to=' . urlencode($to) . ($networkFilter !== '' ? ('&network=' . urlencode($networkFilter)) : ''));
    }
    }
}

$whereParts = ['t.txn_date >= :from', 't.txn_date <= :to'];
$params = [':from' => $from, ':to' => $to];
if ($networkFilter !== '') {
    $whereParts[] = 't.network = :network';
    $params[':network'] = $networkFilter;
}
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $whereParts[] = "(t.customer_name LIKE :q1 ESCAPE '\\\\' OR t.customer_phone LIKE :q2 ESCAPE '\\\\' OR t.notes LIKE :q3 ESCAPE '\\\\')";
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$stmt = $pdo->prepare("
    SELECT t.*
    FROM load_customer_transactions t
    {$where}
    ORDER BY t.txn_date DESC, t.id DESC
    LIMIT 300
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(amount), 0) AS total_amount,
        COALESCE(SUM(profit), 0) AS total_profit
    FROM load_customer_transactions t
    {$where}
");
$stmt->execute($params);
$sumRow = $stmt->fetch() ?: [];
$totalAmount = (float) ($sumRow['total_amount'] ?? 0);
$totalProfit = (float) ($sumRow['total_profit'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Load Transactions</h1>
        <div class="text-muted small">Customer-wise load record (does not change daily totals)</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/index.php')) ?>">Back to Load Management</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errorFlash !== ''): ?>
    <div class="alert alert-danger"><?= h($errorFlash) ?></div>
<?php endif; ?>

<?php if ($formError !== ''): ?>
    <div class="alert alert-danger"><?= h($formError) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 fw-bold mb-3">Add Load Transaction</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_txn">
            <div class="col-12 col-md-2">
                <label class="form-label">Date</label>
                <input class="form-control" type="date" name="txn_date" value="<?= h($txnDate) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Network</label>
                <select class="form-select" name="network" required>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $txnNetwork === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Saved Customer</label>
                <select class="form-select" id="saved_customer_select" name="customer_id">
                    <option value="0">-- Select --</option>
                    <?php foreach ($savedCustomers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-name="<?= h((string) $c['name']) ?>" data-phone="<?= h((string) $c['phone']) ?>" <?= (int) $c['id'] === $customerId ? 'selected' : '' ?>>
                            <?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100" id="btn_show_add_customer">Add New</button>
                    <a class="btn btn-outline-secondary btn-sm w-100" href="<?= h(app_url('settings/customers.php')) ?>">All</a>
                </div>
                <?php if (!$savedCustomers): ?>
                    <div class="text-muted small mt-1">No saved customers yet.</div>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Customer Name</label>
                <input class="form-control" type="text" name="customer_name" id="customer_name" value="<?= h($customerName) ?>" placeholder="Ali">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Customer Phone</label>
                <input class="form-control" type="text" name="customer_phone" id="customer_phone" value="<?= h($customerPhone) ?>" placeholder="03xx...">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Amount</label>
                <input class="form-control" type="number" step="0.01" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <?php if ($canViewProfit): ?>
                <div class="col-12 col-md-2">
                    <label class="form-label">Profit</label>
                    <input class="form-control" type="number" step="0.01" name="profit" value="<?= h($profit) ?>">
                </div>
            <?php endif; ?>
            <div class="col-12 col-md-6">
                <label class="form-label">Notes</label>
                <input class="form-control" type="text" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-primary w-100">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3 d-none" id="add_customer_panel">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div class="fw-semibold">Add Customer</div>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_hide_add_customer">Close</button>
        </div>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="save_customer">
            <div class="col-12 col-md-5">
                <label class="form-label">Customer Name</label>
                <input class="form-control" type="text" name="save_customer_name" id="save_customer_name" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Phone</label>
                <input class="form-control" type="text" name="save_customer_phone" id="save_customer_phone" required>
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-primary w-100">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label">From</label>
                <input class="form-control" type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">To</label>
                <input class="form-control" type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Network</label>
                <select class="form-select" name="network">
                    <option value="">All</option>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $networkFilter === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Search</label>
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Name / phone / notes">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-outline-primary w-100">Filter</button>
                <a class="btn btn-outline-secondary w-100" href="<?= h(app_url('load-management/transactions.php')) ?>">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Load (Range)</div>
                <div class="h5 mb-0"><?= h(number_format($totalAmount, 2)) ?></div>
            </div>
        </div>
    </div>
    <?php if ($canViewProfit): ?>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Profit (Range)</div>
                    <div class="h5 mb-0"><?= h(number_format($totalProfit, 2)) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Network</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th class="text-end">Amount</th>
                    <?php if ($canViewProfit): ?>
                        <th class="text-end">Profit</th>
                    <?php endif; ?>
                    <th>Notes</th>
                    <th>Created At</th>
                    <?php if (app_can_edit_delete_records()): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['txn_date']) ?></td>
                        <td><?= h((string) $r['network']) ?></td>
                        <td><?= h((string) ($r['customer_name'] ?? '')) ?></td>
                        <td><?= h((string) ($r['customer_phone'] ?? '')) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <?php if ($canViewProfit): ?>
                            <td class="text-end"><?= h(number_format((float) $r['profit'], 2)) ?></td>
                        <?php endif; ?>
                        <td><?= h((string) ($r['notes'] ?? '')) ?></td>
                        <td><?= h((string) $r['created_at']) ?></td>
                        <?php if (app_can_edit_delete_records()): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/edit_customer_txn.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('load-management/delete_customer_txn.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (8 + ($canViewProfit ? 1 : 0) + (app_can_edit_delete_records() ? 1 : 0))) ?>" class="text-center text-muted py-4">No transactions found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    (function () {
        const sel = document.getElementById('saved_customer_select');
        if (!sel) return;
        const nameInput = document.getElementById('customer_name');
        const phoneInput = document.getElementById('customer_phone');
        const btnShow = document.getElementById('btn_show_add_customer');
        const btnHide = document.getElementById('btn_hide_add_customer');
        const panel = document.getElementById('add_customer_panel');
        const saveName = document.getElementById('save_customer_name');
        const savePhone = document.getElementById('save_customer_phone');

        sel.addEventListener('change', () => {
            const opt = sel.options[sel.selectedIndex];
            const n = opt ? (opt.getAttribute('data-name') || '') : '';
            const p = opt ? (opt.getAttribute('data-phone') || '') : '';
            if (nameInput && n) nameInput.value = n;
            if (phoneInput && p) phoneInput.value = p;
        });

        if (btnShow && panel) {
            btnShow.addEventListener('click', () => {
                panel.classList.remove('d-none');
                if (saveName && nameInput && nameInput.value) saveName.value = nameInput.value;
                if (savePhone && phoneInput && phoneInput.value) savePhone.value = phoneInput.value;
            });
        }
        if (btnHide && panel) {
            btnHide.addEventListener('click', () => {
                panel.classList.add('d-none');
            });
        }
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
