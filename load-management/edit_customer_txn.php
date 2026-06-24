<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pdo = db();
app_require_owner_access();
load_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('load-management/transactions.php');
}

$stmt = $pdo->prepare("SELECT * FROM load_customer_transactions WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('load-management/transactions.php');
}

$networks = load_get_networks($pdo);

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

$date = (string) $row['txn_date'];
$network = (string) $row['network'];
$customerId = (int) ($row['customer_id'] ?? 0);
$customerName = (string) ($row['customer_name'] ?? '');
$customerPhone = (string) ($row['customer_phone'] ?? '');
$amount = (string) $row['amount'];
$profit = (string) ($row['profit'] ?? '');
$notes = (string) ($row['notes'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['txn_date'] ?? ''));
    $network = trim((string) ($_POST['network'] ?? ''));
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $profit = trim((string) ($_POST['profit'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($network === '' || !in_array($network, $networks, true)) {
        $error = 'Network is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $error = 'Amount must be a positive number.';
    } elseif ($profit !== '' && !is_numeric($profit)) {
        $error = 'Profit must be a number.';
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

        $before = $row;
        $stmt = $pdo->prepare("
            UPDATE load_customer_transactions
            SET txn_date = :txn_date,
                network = :network,
                customer_id = :customer_id,
                customer_name = :customer_name,
                customer_phone = :customer_phone,
                amount = :amount,
                profit = :profit,
                notes = :notes
            WHERE id = :id
        ");
        $stmt->execute([
            ':txn_date' => $date,
            ':network' => $network,
            ':customer_id' => $customerId > 0 ? $customerId : null,
            ':customer_name' => $customerName !== '' ? $customerName : null,
            ':customer_phone' => $customerPhone !== '' ? $customerPhone : null,
            ':amount' => (float) $amount,
            ':profit' => $profit === '' ? 0.0 : (float) $profit,
            ':notes' => $notes !== '' ? $notes : null,
            ':id' => $id,
        ]);

        $stmt = $pdo->prepare("SELECT * FROM load_customer_transactions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $after = $stmt->fetch() ?: null;

        app_audit_log('load_customer_transactions', $id, 'edit', is_array($before) ? $before : null, is_array($after) ? $after : null);

        flash_set('success', 'Transaction updated.');
        app_redirect('load-management/transactions.php');
    }
}

$pageTitle = 'Edit Load Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Load Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/transactions.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label">Date</label>
                <input class="form-control" type="date" name="txn_date" value="<?= h($date) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Network</label>
                <select class="form-select" name="network" required>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $network === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label">Saved Customer</label>
                <select class="form-select" id="saved_customer_select" name="customer_id">
                    <option value="0">-- Select --</option>
                    <?php foreach ($savedCustomers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-name="<?= h((string) $c['name']) ?>" data-phone="<?= h((string) $c['phone']) ?>" <?= (int) $c['id'] === $customerId ? 'selected' : '' ?>>
                            <?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Customer Name</label>
                <input class="form-control" type="text" name="customer_name" id="customer_name" value="<?= h($customerName) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Customer Phone</label>
                <input class="form-control" type="text" name="customer_phone" id="customer_phone" value="<?= h($customerPhone) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Amount</label>
                <input class="form-control" type="number" step="0.01" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Profit</label>
                <input class="form-control" type="number" step="0.01" name="profit" value="<?= h($profit) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <input class="form-control" type="text" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const sel = document.getElementById('saved_customer_select');
        if (!sel) return;
        const nameInput = document.getElementById('customer_name');
        const phoneInput = document.getElementById('customer_phone');
        sel.addEventListener('change', () => {
            const opt = sel.options[sel.selectedIndex];
            const n = opt ? (opt.getAttribute('data-name') || '') : '';
            const p = opt ? (opt.getAttribute('data-phone') || '') : '';
            if (nameInput && n) nameInput.value = n;
            if (phoneInput && p) phoneInput.value = p;
        });
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

