<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Bank Transfer - Money Sent';

$pdo = db();
$accounts = wallet_accounts($pdo, 'bank');

$date = date('Y-m-d');
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
                (account_id, date, transaction_id, type, amount, charges, remarks)
            VALUES
                (:account_id, :date, :transaction_id, :type, :amount, :charges, :remarks)
        ');
        $stmt->execute([
            ':account_id' => $accountId,
            ':date' => $date,
            ':transaction_id' => $transactionId !== '' ? $transactionId : null,
            ':type' => 'sending',
            ':amount' => (float) $amount,
            ':charges' => $charges === '' ? 0.0 : (float) $charges,
            ':remarks' => $remarks !== '' ? $remarks : null,
        ]);

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
                    <label class="form-label" for="date">Date</label>
                    <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
