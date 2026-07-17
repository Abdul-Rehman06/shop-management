<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pageTitle = 'Add Expense - Shop Management';

$pdo = db();
$categories = exp_categories($pdo);
$accountOptions = exp_account_options_by_type($pdo);
$admin = app_current_admin();
$adminName = trim((string) ($admin['name'] ?? ''));
$defaultCashAccountId = (int) (($accountOptions['cash'][0]['id'] ?? 0));

$date = date('Y-m-d');
$billName = '';
$category = $categories[0] ?? 'Other';
$amount = '';
$paymentStatus = 'paid';
$paymentDate = $date;
$paidBy = $adminName;
$notes = '';
$description = '';
$paymentAccountIds = [$defaultCashAccountId > 0 ? $defaultCashAccountId : 0];
$paymentSplitAmounts = [''];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? ''));
    $billName = trim((string) ($_POST['bill_name'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $paymentStatus = trim((string) ($_POST['payment_status'] ?? 'paid'));
    $paymentDate = trim((string) ($_POST['payment_date'] ?? $date));
    $paidBy = trim((string) ($_POST['paid_by'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $paymentAccountIds = array_map('intval', $_POST['payment_account_id'] ?? []);
    $paymentSplitAmounts = array_map(static fn ($value): string => trim((string) $value), $_POST['payment_split_amount'] ?? []);

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
    } elseif ($paymentStatus === 'paid' && ($paymentDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) !== 1)) {
        $error = 'Payment date is required.';
    } else {
        $expenseDate = $date;
        $expenseAmount = (float) $amount;
        $effectivePaymentDate = $paymentStatus === 'paid' ? $paymentDate : null;
        $allocations = [];
        if ($paymentStatus === 'paid') {
            try {
                $allocations = exp_normalize_payment_allocations($pdo, $paymentAccountIds, $paymentSplitAmounts, $expenseAmount);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error === '') {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO expenses
                        (date, bill_name, category, amount, payment_status, payment_source_type, payment_source_account_id, payment_date, paid_by, notes, description, linked_wallet_txn_id)
                    VALUES
                        (:date, :bill_name, :category, :amount, :payment_status, NULL, NULL, :payment_date, :paid_by, :notes, :description, NULL)
                ");
                $stmt->execute([
                    ':date' => $expenseDate,
                    ':bill_name' => $billName,
                    ':category' => $category,
                    ':amount' => $expenseAmount,
                    ':payment_status' => $paymentStatus,
                    ':payment_date' => $effectivePaymentDate,
                    ':paid_by' => $paymentStatus === 'paid' ? ($paidBy !== '' ? $paidBy : null) : null,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':description' => $description !== '' ? $description : null,
                ]);
                $expenseId = (int) $pdo->lastInsertId();

                $snapshot = [
                    'payment_source_type' => null,
                    'payment_source_account_id' => null,
                    'linked_wallet_txn_id' => null,
                ];
                if ($paymentStatus === 'paid') {
                    $snapshot = exp_apply_payment_history($pdo, $expenseId, [
                        'bill_name' => $billName,
                        'category' => $category,
                        'amount' => $expenseAmount,
                        'payment_date' => $effectivePaymentDate ?? $expenseDate,
                        'date' => $expenseDate,
                        'paid_by' => $paidBy,
                        'notes' => $notes,
                        'description' => $description,
                    ], $allocations);
                }

                $stmt = $pdo->prepare("
                    UPDATE expenses
                    SET payment_source_type = :payment_source_type,
                        payment_source_account_id = :payment_source_account_id,
                        linked_wallet_txn_id = :linked_wallet_txn_id
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':payment_source_type' => $snapshot['payment_source_type'],
                    ':payment_source_account_id' => $snapshot['payment_source_account_id'],
                    ':linked_wallet_txn_id' => $snapshot['linked_wallet_txn_id'],
                    ':id' => $expenseId,
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
                    <label class="form-label" for="payment_date">Payment Date</label>
                    <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($paymentDate) ?>">
                </div>
                <div class="col-12 col-md-4 expense-paid-fields">
                    <label class="form-label" for="paid_by">Paid By</label>
                    <input class="form-control" type="text" id="paid_by" name="paid_by" value="<?= h($paidBy) ?>">
                </div>
                <div class="col-12 expense-paid-fields">
                    <label class="form-label">Paid From Account(s)</label>
                    <div id="payment_split_rows" class="d-flex flex-column gap-2">
                        <?php foreach ($paymentAccountIds as $index => $accountId): ?>
                            <div class="row g-2 align-items-end expense-split-row">
                                <div class="col-12 col-md-7">
                                    <label class="form-label small text-muted"><?= $index === 0 ? 'Account' : 'Additional Account' ?></label>
                                    <select class="form-select expense-split-account" name="payment_account_id[]">
                                        <option value="0">Select Account</option>
                                        <?php foreach ($accountOptions as $accountType => $accounts): ?>
                                            <?php if (!$accounts) { continue; } ?>
                                            <optgroup label="<?= h(exp_payment_source_display($accountType)) ?>">
                                                <?php foreach ($accounts as $account): ?>
                                                    <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $accountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>>
                                                        <?= h(exp_grouped_account_label($account)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-8 col-md-4">
                                    <label class="form-label small text-muted">Amount</label>
                                    <input class="form-control expense-split-amount" type="number" step="0.01" name="payment_split_amount[]" value="<?= h((string) ($paymentSplitAmounts[$index] ?? '')) ?>" placeholder="0.00">
                                </div>
                                <div class="col-4 col-md-1">
                                    <button type="button" class="btn btn-outline-danger w-100 expense-remove-split">&times;</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="add_split_row">Add Another Account</button>
                        <div class="small text-muted align-self-center" id="split_total_hint">Total split must match bill amount.</div>
                    </div>
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
    const paidFields = Array.from(document.querySelectorAll('.expense-paid-fields'));
    const splitRows = document.getElementById('payment_split_rows');
    const addSplitRowBtn = document.getElementById('add_split_row');
    const amountInput = document.getElementById('amount');
    const splitTotalHint = document.getElementById('split_total_hint');

    function syncExpensePaidFields() {
        const isPaid = expenseStatus && expenseStatus.value === 'paid';
        paidFields.forEach((field) => {
            field.style.display = isPaid ? '' : 'none';
        });
        syncSplitHint();
    }

    function bindSplitRow(row) {
        if (!row) {
            return;
        }
        const removeBtn = row.querySelector('.expense-remove-split');
        const amountField = row.querySelector('.expense-split-amount');
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                if (!splitRows) {
                    return;
                }
                const rows = splitRows.querySelectorAll('.expense-split-row');
                if (rows.length <= 1) {
                    const accountSelect = row.querySelector('.expense-split-account');
                    if (accountSelect) {
                        accountSelect.value = '0';
                    }
                    if (amountField) {
                        amountField.value = '';
                    }
                } else {
                    row.remove();
                }
                syncSplitHint();
            });
        }
        if (amountField) {
            amountField.addEventListener('input', syncSplitHint);
        }
    }

    function syncSplitHint() {
        if (!splitRows || !splitTotalHint) {
            return;
        }
        const enteredTotal = Array.from(splitRows.querySelectorAll('.expense-split-amount')).reduce((sum, input) => {
            return sum + (parseFloat(input.value || '0') || 0);
        }, 0);
        const billAmount = parseFloat(amountInput && amountInput.value ? amountInput.value : '0') || 0;
        splitTotalHint.textContent = 'Split total: Rs ' + enteredTotal.toFixed(2) + ' / Bill amount: Rs ' + billAmount.toFixed(2);
    }

    if (expenseStatus) {
        expenseStatus.addEventListener('change', syncExpensePaidFields);
    }
    if (addSplitRowBtn && splitRows) {
        addSplitRowBtn.addEventListener('click', () => {
            const firstRow = splitRows.querySelector('.expense-split-row');
            if (!firstRow) {
                return;
            }
            const newRow = firstRow.cloneNode(true);
            const accountSelect = newRow.querySelector('.expense-split-account');
            const amountField = newRow.querySelector('.expense-split-amount');
            if (accountSelect) {
                accountSelect.value = '0';
            }
            if (amountField) {
                amountField.value = '';
            }
            splitRows.appendChild(newRow);
            bindSplitRow(newRow);
            syncSplitHint();
        });
    }
    if (amountInput) {
        amountInput.addEventListener('input', syncSplitHint);
    }
    Array.from(document.querySelectorAll('.expense-split-row')).forEach(bindSplitRow);
    syncExpensePaidFields();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

