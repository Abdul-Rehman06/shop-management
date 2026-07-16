<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pdo = db();
app_require_owner_access();

function udhar_edit_method_labels(): array
{
    return [
        'cash' => 'Cash',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'bank' => 'Bank Account',
        'other' => 'Other',
    ];
}

function udhar_edit_method_accounts(PDO $pdo): array
{
    return [
        'jazzcash' => wallet_accounts($pdo, 'jazzcash', true),
        'easypaisa' => wallet_accounts($pdo, 'easypaisa', true),
        'bank' => wallet_accounts($pdo, 'bank', true),
    ];
}

function udhar_edit_account_id(PDO $pdo, string $method, int $selectedAccountId = 0): ?int
{
    if ($method === 'other') {
        return null;
    }
    if ($method === 'cash') {
        return bill_cash_account_id($pdo);
    }
    if (!in_array($method, ['jazzcash', 'easypaisa', 'bank'], true)) {
        return null;
    }
    if ($selectedAccountId > 0) {
        $account = wallet_account($pdo, $selectedAccountId);
        if ($account && (string) ($account['account_type'] ?? '') === $method) {
            return $selectedAccountId;
        }
    }
    $accounts = wallet_accounts($pdo, $method, true);
    return isset($accounts[0]['id']) ? (int) ($accounts[0]['id'] ?? 0) : null;
}

$id = (int) ($_GET['id'] ?? 0);
$udharId = (int) ($_GET['udhar_id'] ?? 0);
if ($id <= 0 || $udharId <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('udhar/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM udhar_transactions WHERE id = :id AND udhar_id = :udhar_id LIMIT 1");
$stmt->execute([':id' => $id, ':udhar_id' => $udharId]);
$txn = $stmt->fetch();
if (!$txn) {
    flash_set('error', 'Transaction not found.');
    app_redirect('udhar/view.php?id=' . $udharId);
}

$date = (string) $txn['txn_date'];
$type = (string) $txn['txn_type'];
$amount = (string) $txn['amount'];
$paymentMethod = trim((string) ($txn['payment_method'] ?? 'cash'));
$receivedAccountId = (int) ($txn['received_account_id'] ?? 0);
$notes = (string) ($txn['notes'] ?? '');
$error = '';
$methodAccounts = udhar_edit_method_accounts($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['txn_date'] ?? ''));
    $type = trim((string) ($_POST['txn_type'] ?? 'udhar'));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'cash'));
    $receivedAccountId = (int) ($_POST['received_account_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!in_array($type, ['udhar', 'payment'], true)) {
        $type = 'udhar';
    }

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $error = 'Amount must be a positive number.';
    } elseif ($type === 'payment' && !array_key_exists($paymentMethod, udhar_edit_method_labels())) {
        $error = 'Invalid payment method.';
    } elseif ($type === 'payment' && !in_array($paymentMethod, ['cash', 'other'], true) && $receivedAccountId <= 0) {
        $error = 'Please select where the recovery was received.';
    } else {
        $before = $txn;
        $stmt = $pdo->prepare("SELECT name, phone FROM udhar_customers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $udharId]);
        $customer = $stmt->fetch() ?: [];
        $customerName = trim((string) ($customer['name'] ?? ''));
        $customerPhone = trim((string) ($customer['phone'] ?? ''));

        $linkedWalletTxnId = (int) ($txn['linked_wallet_txn_id'] ?? 0);
        $accountId = null;

        $pdo->beginTransaction();
        try {
            if ($linkedWalletTxnId > 0 && ($type !== 'payment' || $paymentMethod === 'other')) {
                $stmt = $pdo->prepare("DELETE FROM wallet_transactions WHERE id = :id");
                $stmt->execute([':id' => $linkedWalletTxnId]);
                $linkedWalletTxnId = 0;
            }

            if ($type === 'payment' && $paymentMethod !== 'other') {
                $accountId = udhar_edit_account_id($pdo, $paymentMethod, $receivedAccountId);
                if ($accountId === null || $accountId <= 0) {
                    throw new RuntimeException('Please select a valid receiving account.');
                }

                if ($linkedWalletTxnId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE wallet_transactions
                        SET account_id = :account_id,
                            date = :date,
                            customer_name = :customer_name,
                            number = :number,
                            type = 'receiving',
                            amount = :amount,
                            charges = 0,
                            commission_method = 'separate_cash',
                            account_amount = :account_amount,
                            payment_status = 'completed',
                            completed_at = COALESCE(completed_at, NOW()),
                            entry_context = :entry_context,
                            remarks = :remarks
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':account_id' => $accountId,
                        ':date' => $date,
                        ':customer_name' => $customerName,
                        ':number' => $customerPhone !== '' ? $customerPhone : null,
                        ':amount' => (float) $amount,
                        ':account_amount' => (float) $amount,
                        ':entry_context' => $paymentMethod === 'cash' ? 'external' : 'udhar_recovery_online',
                        ':remarks' => 'Udhar Recovery #' . $udharId . ' [' . ($paymentMethod === 'cash' ? 'Cash' : ucfirst($paymentMethod)) . ']' . ($notes !== '' ? ' - ' . $notes : ''),
                        ':id' => $linkedWalletTxnId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO wallet_transactions
                            (account_id, date, customer_name, number, transaction_id, type, amount, charges, commission_method, account_amount, payment_status, completed_at, entry_context, remarks)
                        VALUES
                            (:account_id, :date, :customer_name, :number, :transaction_id, 'receiving', :amount, 0, 'separate_cash', :account_amount, 'completed', NOW(), :entry_context, :remarks)
                    ");
                    $stmt->execute([
                        ':account_id' => $accountId,
                        ':date' => $date,
                        ':customer_name' => $customerName,
                        ':number' => $customerPhone !== '' ? $customerPhone : null,
                        ':transaction_id' => 'UDHAR-' . $udharId . '-' . date('His'),
                        ':amount' => (float) $amount,
                        ':account_amount' => (float) $amount,
                        ':entry_context' => $paymentMethod === 'cash' ? 'external' : 'udhar_recovery_online',
                        ':remarks' => 'Udhar Recovery #' . $udharId . ' [' . ($paymentMethod === 'cash' ? 'Cash' : ucfirst($paymentMethod)) . ']' . ($notes !== '' ? ' - ' . $notes : ''),
                    ]);
                    $linkedWalletTxnId = (int) $pdo->lastInsertId();
                }
            }

            $stmt = $pdo->prepare("
                UPDATE udhar_transactions
                SET txn_date = :txn_date,
                    txn_type = :txn_type,
                    amount = :amount,
                    payment_method = :payment_method,
                    received_account_id = :received_account_id,
                    linked_wallet_txn_id = :linked_wallet_txn_id,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':txn_date' => $date,
                ':txn_type' => $type,
                ':amount' => (float) $amount,
                ':payment_method' => $type === 'payment' ? $paymentMethod : null,
                ':received_account_id' => $type === 'payment' ? $accountId : null,
                ':linked_wallet_txn_id' => $type === 'payment' ? ($linkedWalletTxnId > 0 ? $linkedWalletTxnId : null) : null,
                ':notes' => $notes !== '' ? $notes : null,
                ':id' => $id,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not update transaction.';
        }

        if ($error === '') {
            $stmt = $pdo->prepare("SELECT * FROM udhar_transactions WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $after = $stmt->fetch() ?: null;

            app_audit_log('udhar_transactions', $id, 'edit', is_array($before) ? $before : null, is_array($after) ? $after : null);

            try {
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN txn_type = 'udhar' THEN amount ELSE 0 END), 0) AS udhar_total,
                        COALESCE(SUM(CASE WHEN txn_type = 'payment' THEN amount ELSE 0 END), 0) AS paid_total
                    FROM udhar_transactions
                    WHERE udhar_id = :udhar_id
                ");
                $stmt->execute([':udhar_id' => $udharId]);
                $totals = $stmt->fetch() ?: [];
                $balance = (float) ($totals['udhar_total'] ?? 0) - (float) ($totals['paid_total'] ?? 0);
                $status = $balance <= 0 ? 'cleared' : 'pending';
                $upd = $pdo->prepare('UPDATE udhar_customers SET status = :status WHERE id = :id');
                $upd->execute([':status' => $status, ':id' => $udharId]);
            } catch (Throwable $e) {
            }

            flash_set('success', 'Transaction updated.');
            app_redirect('udhar/view.php?id=' . $udharId);
        }
    }
}

$pageTitle = 'Edit Udhar Transaction - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Udhar Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/view.php?id=' . $udharId)) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_date">Date</label>
                <input class="form-control" type="date" id="txn_date" name="txn_date" value="<?= h($date) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_type">Type</label>
                <select class="form-select" id="txn_type" name="txn_type">
                    <option value="udhar" <?= $type === 'udhar' ? 'selected' : '' ?>>+ Udhar</option>
                    <option value="payment" <?= $type === 'payment' ? 'selected' : '' ?>>- Payment</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="payment_method">Payment Method</label>
                <select class="form-select udhar-method-toggle" id="payment_method" name="payment_method">
                    <option value="cash" <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="jazzcash" <?= $paymentMethod === 'jazzcash' ? 'selected' : '' ?>>JazzCash</option>
                    <option value="easypaisa" <?= $paymentMethod === 'easypaisa' ? 'selected' : '' ?>>EasyPaisa</option>
                    <option value="bank" <?= $paymentMethod === 'bank' ? 'selected' : '' ?>>Bank Account</option>
                    <option value="other" <?= $paymentMethod === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <div class="col-12 col-md-3 udhar-method-account" data-method-type="jazzcash"<?= $type === 'payment' && $paymentMethod === 'jazzcash' ? '' : ' style="display:none;"' ?>>
                <label class="form-label" for="received_account_jazzcash">Received In JazzCash</label>
                <select class="form-select" id="received_account_jazzcash" name="received_account_id">
                    <option value="">-- Select JazzCash --</option>
                    <?php foreach (($methodAccounts['jazzcash'] ?? []) as $account): ?>
                        <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $paymentMethod === 'jazzcash' && $receivedAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 udhar-method-account" data-method-type="easypaisa"<?= $type === 'payment' && $paymentMethod === 'easypaisa' ? '' : ' style="display:none;"' ?>>
                <label class="form-label" for="received_account_easypaisa">Received In EasyPaisa</label>
                <select class="form-select" id="received_account_easypaisa" name="received_account_id">
                    <option value="">-- Select EasyPaisa --</option>
                    <?php foreach (($methodAccounts['easypaisa'] ?? []) as $account): ?>
                        <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $paymentMethod === 'easypaisa' && $receivedAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 udhar-method-account" data-method-type="bank"<?= $type === 'payment' && $paymentMethod === 'bank' ? '' : ' style="display:none;"' ?>>
                <label class="form-label" for="received_account_bank">Received In Bank</label>
                <select class="form-select" id="received_account_bank" name="received_account_id">
                    <option value="">-- Select Bank Account --</option>
                    <?php foreach (($methodAccounts['bank'] ?? []) as $account): ?>
                        <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $paymentMethod === 'bank' && $receivedAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const txnType = document.getElementById('txn_type');
    const paymentMethod = document.getElementById('payment_method');
    const paymentMethodWrap = paymentMethod ? paymentMethod.closest('.col-12.col-md-3') : null;
    const syncMethodFields = function () {
        const showPaymentFields = txnType && txnType.value === 'payment';
        const method = paymentMethod ? paymentMethod.value : 'cash';
        if (paymentMethodWrap) {
            paymentMethodWrap.style.display = showPaymentFields ? '' : 'none';
        }
        if (paymentMethod) {
            paymentMethod.disabled = !showPaymentFields;
        }
        document.querySelectorAll('.udhar-method-account').forEach(function (wrap) {
            const show = showPaymentFields && wrap.getAttribute('data-method-type') === method;
            wrap.style.display = show ? '' : 'none';
            wrap.querySelectorAll('select, input').forEach(function (field) {
                field.disabled = !show;
            });
        });
    };
    if (txnType) {
        txnType.addEventListener('change', syncMethodFields);
    }
    if (paymentMethod) {
        paymentMethod.addEventListener('change', syncMethodFields);
    }
    syncMethodFields();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

