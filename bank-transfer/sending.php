<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Bank Transfer - Money Sent';

$pdo = db();
$accounts = wallet_accounts($pdo, 'bank');

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

$date = date('Y-m-d');
$customerName = '';
$customerPhone = '';
$transactionId = '';
$amount = '';
$charges = '';
$remarks = '';
$error = '';
$accountId = (int) ($_GET['account_id'] ?? ($_POST['account_id'] ?? ($accounts[0]['id'] ?? 0)));

$validAccount = false;
foreach ($accounts as $a) {
    if ((int) $a['id'] === $accountId) {
        $validAccount = true;
        break;
    }
}
if (!$validAccount) {
    $accountId = (int) ($accounts[0]['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $date = trim((string) ($_POST['date'] ?? ''));
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $charges = trim((string) ($_POST['charges'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    $validAccount = false;
    foreach ($accounts as $a) {
        if ((int) $a['id'] === $accountId) {
            $validAccount = true;
            break;
        }
    }

    if (!$validAccount) {
        $error = 'Please select a valid account.';
    } elseif ($date === '') {
        $error = 'Date is required.';
    } elseif ($amount === '' || !is_numeric($amount)) {
        $error = 'Amount must be a number.';
    } elseif ($charges !== '' && !is_numeric($charges)) {
        $error = 'Charges must be a number.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO wallet_transactions
                (account_id, date, customer_name, number, transaction_id, type, amount, charges, remarks)
            VALUES
                (:account_id, :date, :customer_name, :number, :transaction_id, :type, :amount, :charges, :remarks)
        ');
        $stmt->execute([
            ':account_id' => $accountId,
            ':date' => $date,
            ':customer_name' => $customerName !== '' ? $customerName : null,
            ':number' => $customerPhone !== '' ? $customerPhone : null,
            ':transaction_id' => $transactionId !== '' ? $transactionId : null,
            ':type' => 'sending',
            ':amount' => (float) $amount,
            ':charges' => $charges === '' ? 0.0 : (float) $charges,
            ':remarks' => $remarks !== '' ? $remarks : null,
        ]);

        if ($customerName !== '' && $customerPhone !== '') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (name, phone)
                    VALUES (:name, :phone)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name)
                ");
                $stmt->execute([':name' => $customerName, ':phone' => $customerPhone]);
            } catch (Throwable $e) {
            }
        }

        flash_set('success', 'Bank sending transaction added successfully.');
        app_redirect('bank-transfer/index.php?account_id=' . $accountId);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Money Sent</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bank-transfer/index.php?account_id=' . $accountId)) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="account_id">Account</label>
                    <select class="form-select" id="account_id" name="account_id" required>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= h((string) (int) $a['id']) ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>>
                                <?= h((string) $a['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="saved_customer_select">Saved Customer</label>
                    <select class="form-select" id="saved_customer_select">
                        <option value="">-- Select --</option>
                        <?php foreach ($savedCustomers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" data-name="<?= h((string) $c['name']) ?>" data-phone="<?= h((string) $c['phone']) ?>">
                                <?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-2">
                        <a class="btn btn-outline-secondary btn-sm w-100" href="<?= h(app_url('settings/customers.php')) ?>">Add Customer</a>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="date">Date</label>
                    <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="customer_name">Customer Name</label>
                    <input class="form-control" type="text" id="customer_name" name="customer_name" value="<?= h($customerName) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="customer_phone">Customer Phone</label>
                    <input class="form-control" type="text" id="customer_phone" name="customer_phone" value="<?= h($customerPhone) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="transaction_id">Transaction ID</label>
                    <input class="form-control" type="text" id="transaction_id" name="transaction_id" value="<?= h($transactionId) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="charges">Charges</label>
                    <input class="form-control" type="number" step="0.01" id="charges" name="charges" value="<?= h($charges) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="remarks">Remarks</label>
                    <input class="form-control" type="text" id="remarks" name="remarks" value="<?= h($remarks) ?>">
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const sel = document.getElementById('saved_customer_select');
        const nameInput = document.getElementById('customer_name');
        const phoneInput = document.getElementById('customer_phone');
        if (!sel || !nameInput || !phoneInput) return;
        sel.addEventListener('change', () => {
            const opt = sel.options[sel.selectedIndex];
            const n = opt ? (opt.getAttribute('data-name') || '') : '';
            const p = opt ? (opt.getAttribute('data-phone') || '') : '';
            if (n) nameInput.value = n;
            if (p) phoneInput.value = p;
        });
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
