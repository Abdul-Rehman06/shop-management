<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pageTitle = 'Add Expense - Shop Management';

$pdo = db();
$categories = exp_categories($pdo);
$methodLabels = exp_method_labels();
$methodAccounts = exp_accounts_by_method($pdo);
$admin = app_current_admin();
$adminName = trim((string) ($admin['name'] ?? ''));

$date = date('Y-m-d');
$billName = '';
$category = $categories[0] ?? 'Other';
$amount = '';
$paymentStatus = 'paid';
$paymentSourceType = 'cash';
$paymentSourceAccountId = 0;
$paymentDate = $date;
$paidBy = $adminName;
$notes = '';
$description = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? ''));
    $billName = trim((string) ($_POST['bill_name'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $paymentStatus = trim((string) ($_POST['payment_status'] ?? 'paid'));
    $paymentSourceType = trim((string) ($_POST['payment_source_type'] ?? 'cash'));
    $paymentSourceAccountId = (int) ($_POST['payment_source_account_id'] ?? 0);
    $paymentDate = trim((string) ($_POST['payment_date'] ?? $date));
    $paidBy = trim((string) ($_POST['paid_by'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($billName === '') {
        $error = 'Bill name is required.';
    } elseif ($category === '') {
        $error = 'Category is required.';
    } elseif (!in_array($category, $categories, true)) {
        $error = 'Invalid category.';
    } elseif ($amount === '' || !is_numeric($amount)) {
        $error = 'Amount must be a number.';
    } elseif (!in_array($paymentStatus, ['paid', 'unpaid'], true)) {
        $error = 'Invalid payment status.';
    } elseif ($paymentStatus === 'paid' && !array_key_exists($paymentSourceType, $methodLabels)) {
        $error = 'Invalid payment source.';
    } elseif ($paymentStatus === 'paid' && $paymentSourceType !== 'cash' && $paymentSourceType !== 'other' && $paymentSourceAccountId <= 0) {
        $error = 'Please select payment account.';
    } elseif ($paymentStatus === 'paid' && ($paymentDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) !== 1)) {
        $error = 'Payment date is required.';
    } else {
        $expenseDate = $date;
        $expenseAmount = (float) $amount;
        $effectivePaymentDate = $paymentStatus === 'paid' ? $paymentDate : null;
        $effectivePaymentSourceType = $paymentStatus === 'paid' ? $paymentSourceType : null;
        $effectivePaymentSourceAccountId = $paymentStatus === 'paid' ? ($paymentSourceType === 'cash' ? exp_account_id_for_method($pdo, 'cash') : ($paymentSourceAccountId > 0 ? $paymentSourceAccountId : null)) : null;

        $pdo->beginTransaction();
        try {
            $linkedWalletTxnId = exp_sync_payment_txn($pdo, null, [
                'payment_status' => $paymentStatus,
                'payment_source_type' => $effectivePaymentSourceType,
                'payment_source_account_id' => $effectivePaymentSourceAccountId,
                'payment_date' => $effectivePaymentDate ?? $expenseDate,
                'bill_name' => $billName,
                'category' => $category,
                'amount' => $expenseAmount,
                'notes' => $notes,
                'description' => $description,
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO expenses
                    (date, bill_name, category, amount, payment_status, payment_source_type, payment_source_account_id, payment_date, paid_by, notes, description, linked_wallet_txn_id)
                VALUES
                    (:date, :bill_name, :category, :amount, :payment_status, :payment_source_type, :payment_source_account_id, :payment_date, :paid_by, :notes, :description, :linked_wallet_txn_id)
            ");
            $stmt->execute([
                ':date' => $expenseDate,
                ':bill_name' => $billName,
                ':category' => $category,
                ':amount' => $expenseAmount,
                ':payment_status' => $paymentStatus,
                ':payment_source_type' => $effectivePaymentSourceType,
                ':payment_source_account_id' => $effectivePaymentSourceAccountId,
                ':payment_date' => $effectivePaymentDate,
                ':paid_by' => $paymentStatus === 'paid' ? ($paidBy !== '' ? $paidBy : null) : null,
                ':notes' => $notes !== '' ? $notes : null,
                ':description' => $description !== '' ? $description : null,
                ':linked_wallet_txn_id' => $linkedWalletTxnId,
            ]);

            $pdo->commit();
            flash_set('success', 'Expense bill added successfully.');
            app_redirect('expenses/index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not save expense bill.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Expense</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="date">Date</label>
                    <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="bill_name">Bill Name</label>
                    <input class="form-control" type="text" id="bill_name" name="bill_name" value="<?= h($billName) ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category" name="category" required>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= h($c) ?>" <?= $c === $category ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="payment_status">Payment Status</label>
                    <select class="form-select" id="payment_status" name="payment_status" required>
                        <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    </select>
                </div>
                <div class="col-12 col-md-4 expense-paid-fields">
                    <label class="form-label" for="payment_source_type">Paid From</label>
                    <select class="form-select exp-method-toggle" id="payment_source_type" name="payment_source_type" data-account-target="payment_source_account_wrap">
                        <?php foreach ($methodLabels as $methodKey => $methodLabel): ?>
                            <option value="<?= h($methodKey) ?>" <?= $paymentSourceType === $methodKey ? 'selected' : '' ?>><?= h($methodLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 expense-paid-fields exp-method-account" id="payment_source_account_wrap">
                    <label class="form-label" for="payment_source_account_id">Payment Account</label>
                    <select class="form-select" id="payment_source_account_id" name="payment_source_account_id">
                        <option value="0">Select Account</option>
                        <?php foreach ($methodAccounts as $methodKey => $accounts): ?>
                            <optgroup label="<?= h($methodLabels[$methodKey] ?? ucfirst($methodKey)) ?>">
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) ($account['id'] ?? 0) ?>" data-method="<?= h($methodKey) ?>" <?= $paymentSourceAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= h((string) ($account['account_name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 expense-paid-fields">
                    <label class="form-label" for="payment_date">Payment Date</label>
                    <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($paymentDate) ?>">
                </div>
                <div class="col-12 col-md-4 expense-paid-fields">
                    <label class="form-label" for="paid_by">Paid By</label>
                    <input class="form-control" type="text" id="paid_by" name="paid_by" value="<?= h($paidBy) ?>">
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" type="text" id="description" name="description" value="<?= h($description) ?>">
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-gradient shadow-glow">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    const expenseStatus = document.getElementById('payment_status');
    const methodToggle = document.getElementById('payment_source_type');
    const accountWrap = document.getElementById('payment_source_account_wrap');
    const accountSelect = document.getElementById('payment_source_account_id');
    const paidFields = Array.from(document.querySelectorAll('.expense-paid-fields'));

    function syncExpensePaidFields() {
        const isPaid = expenseStatus && expenseStatus.value === 'paid';
        paidFields.forEach((field) => {
            field.style.display = isPaid ? '' : 'none';
        });
        if (!isPaid && accountSelect) {
            accountSelect.value = '0';
        }
        syncExpenseAccountOptions();
    }

    function syncExpenseAccountOptions() {
        if (!methodToggle || !accountSelect || !accountWrap) {
            return;
        }
        const method = methodToggle.value || 'cash';
        let visibleCount = 0;
        Array.from(accountSelect.options).forEach((option) => {
            if (!option.dataset.method) {
                option.hidden = false;
                return;
            }
            const visible = option.dataset.method === method;
            option.hidden = !visible;
            if (visible) {
                visibleCount++;
            }
        });
        accountWrap.style.display = method === 'cash' || method === 'other' || visibleCount === 0 ? 'none' : '';
        if ((method === 'cash' || method === 'other') && accountSelect) {
            accountSelect.value = '0';
        }
    }

    if (expenseStatus) {
        expenseStatus.addEventListener('change', syncExpensePaidFields);
    }
    if (methodToggle) {
        methodToggle.addEventListener('change', syncExpenseAccountOptions);
    }
    syncExpensePaidFields();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

