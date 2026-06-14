<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'EasyPaisa Receiving - Shop Management';

$pdo = db();

$date = date('Y-m-d');
$customerName = '';
$number = '';
$transactionId = '';
$amount = '';
$charges = '';
$remarks = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? ''));
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $number = trim((string) ($_POST['number'] ?? ''));
    $transactionId = trim((string) ($_POST['transaction_id'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $charges = trim((string) ($_POST['charges'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($number === '') {
        $error = 'Number is required.';
    } elseif ($amount === '' || !is_numeric($amount)) {
        $error = 'Amount must be a number.';
    } elseif ($charges !== '' && !is_numeric($charges)) {
        $error = 'Charges must be a number.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO easypaisa_transactions
                (date, customer_name, number, transaction_id, type, amount, charges, remarks)
            VALUES
                (:date, :customer_name, :number, :transaction_id, :type, :amount, :charges, :remarks)
        ');
        $stmt->execute([
            ':date' => $date,
            ':customer_name' => $customerName !== '' ? $customerName : null,
            ':number' => $number,
            ':transaction_id' => $transactionId !== '' ? $transactionId : null,
            ':type' => 'receiving',
            ':amount' => (float) $amount,
            ':charges' => $charges === '' ? 0.0 : (float) $charges,
            ':remarks' => $remarks !== '' ? $remarks : null,
        ]);

        flash_set('success', 'Receiving transaction added successfully.');
        app_redirect('easypaisa/index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">EasyPaisa Receiving</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('easypaisa/index.php')) ?>">Back</a>
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
                    <label class="form-label" for="customer_name">Customer Name</label>
                    <input class="form-control" type="text" id="customer_name" name="customer_name" value="<?= h($customerName) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="number">Number</label>
                    <input class="form-control" type="text" id="number" name="number" value="<?= h($number) ?>" required>
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

