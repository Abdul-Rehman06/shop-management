<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/bank_lib.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('bank-transfer/index.php');
}

$row = bank_find($pdo, $id);
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('bank-transfer/index.php');
}

$type = (string) $row['type'];
$date = (string) $row['date'];
$bankName = (string) $row['bank_name'];
$accountNumber = (string) $row['account_number'];
$transactionId = (string) ($row['transaction_id'] ?? '');
$amount = (string) $row['amount'];
$charges = (string) $row['charges'];
$remarks = (string) ($row['remarks'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? ''));
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
    $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
    $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $charges = trim((string) ($_POST['charges'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($bankName === '') {
        $error = 'Bank name is required.';
    } elseif ($accountNumber === '') {
        $error = 'Account number is required.';
    } elseif ($amount === '' || !is_numeric($amount)) {
        $error = 'Amount must be a number.';
    } elseif ($charges !== '' && !is_numeric($charges)) {
        $error = 'Charges must be a number.';
    } else {
        $stmt = $pdo->prepare('
            UPDATE bank_transactions
            SET date = :date,
                bank_name = :bank_name,
                account_number = :account_number,
                transaction_id = :transaction_id,
                amount = :amount,
                charges = :charges,
                remarks = :remarks
            WHERE id = :id
        ');
        $stmt->execute([
            ':date' => $date,
            ':bank_name' => $bankName,
            ':account_number' => $accountNumber,
            ':transaction_id' => $transactionId !== '' ? $transactionId : null,
            ':amount' => (float) $amount,
            ':charges' => $charges === '' ? 0.0 : (float) $charges,
            ':remarks' => $remarks !== '' ? $remarks : null,
            ':id' => $id,
        ]);

        flash_set('success', 'Transaction updated successfully.');
        app_redirect('bank-transfer/index.php');
    }
}

$pageTitle = 'Edit Bank Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Bank Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bank-transfer/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="date">Date</label>
                    <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Type</label>
                    <input class="form-control" value="<?= h($type) ?>" disabled>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="bank_name">Bank Name</label>
                    <input class="form-control" type="text" id="bank_name" name="bank_name" value="<?= h($bankName) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="account_number">Account Number</label>
                    <input class="form-control" type="text" id="account_number" name="account_number" value="<?= h($accountNumber) ?>" required>
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

