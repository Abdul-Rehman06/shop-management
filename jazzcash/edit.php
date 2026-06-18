<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/jc_lib.php';

$pdo = db();
app_require_edit_delete_access();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('jazzcash/index.php');
}

$row = jc_find($pdo, $id);
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('jazzcash/index.php');
}

$accounts = wallet_accounts($pdo, 'jazzcash');
$accountId = (int) ($row['account_id'] ?? 0);

$type = (string) $row['type'];
$date = (string) $row['date'];
$customerName = (string) ($row['customer_name'] ?? '');
$number = (string) ($row['number'] ?? '');
$transactionId = (string) ($row['transaction_id'] ?? '');
$amount = (string) $row['amount'];
$charges = (string) $row['charges'];
$remarks = (string) ($row['remarks'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $date = trim((string) ($_POST['date'] ?? ''));
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $number = trim((string) ($_POST['number'] ?? ''));
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
    } elseif ($type !== 'opening' && $number === '') {
        $error = 'Number is required.';
    } elseif ($amount === '' || !is_numeric($amount)) {
        $error = 'Amount must be a number.';
    } elseif ($charges !== '' && !is_numeric($charges)) {
        $error = 'Charges must be a number.';
    } else {
        $stmt = $pdo->prepare('
            UPDATE wallet_transactions
            SET account_id = :account_id,
                date = :date,
                customer_name = :customer_name,
                number = :number,
                transaction_id = :transaction_id,
                amount = :amount,
                charges = :charges,
                remarks = :remarks
            WHERE id = :id
        ');
        $stmt->execute([
            ':account_id' => $accountId,
            ':date' => $date,
            ':customer_name' => $customerName !== '' ? $customerName : null,
            ':number' => $number !== '' ? $number : null,
            ':transaction_id' => $transactionId !== '' ? $transactionId : null,
            ':amount' => (float) $amount,
            ':charges' => $charges === '' ? 0.0 : (float) $charges,
            ':remarks' => $remarks !== '' ? $remarks : null,
            ':id' => $id,
        ]);

        flash_set('success', 'Transaction updated successfully.');
        app_redirect('jazzcash/index.php?account_id=' . $accountId);
    }
}

$pageTitle = 'Edit JazzCash Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit JazzCash Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('jazzcash/index.php?account_id=' . $accountId)) ?>">Back</a>
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
                    <label class="form-label" for="date">Date</label>
                    <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Type</label>
                    <input class="form-control" value="<?= h($type) ?>" disabled>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="customer_name">Customer Name</label>
                    <input class="form-control" type="text" id="customer_name" name="customer_name" value="<?= h($customerName) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="number">Number</label>
                    <input class="form-control" type="text" id="number" name="number" value="<?= h($number) ?>" <?= $type === 'opening' ? '' : 'required' ?>>
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
                <button class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
