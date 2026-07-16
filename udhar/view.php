<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Udhar Details - Shop Management';

$pdo = db();

function udhar_method_labels(): array
{
    return [
        'cash' => 'Cash',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'bank' => 'Bank Account',
        'other' => 'Other',
    ];
}

function udhar_method_label(string $method): string
{
    $labels = udhar_method_labels();
    return $labels[$method] ?? ucfirst(str_replace('_', ' ', $method));
}

function udhar_method_accounts(PDO $pdo): array
{
    return [
        'jazzcash' => wallet_accounts($pdo, 'jazzcash', true),
        'easypaisa' => wallet_accounts($pdo, 'easypaisa', true),
        'bank' => wallet_accounts($pdo, 'bank', true),
    ];
}

function udhar_account_id_for_method(PDO $pdo, string $method, int $selectedAccountId = 0): ?int
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

function udhar_insert_wallet_recovery_txn(PDO $pdo, int $accountId, string $method, int $udharId, string $txnDate, string $customerName, string $phone, float $amount, ?string $notes = null): int
{
    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions
            (account_id, date, customer_name, number, transaction_id, type, amount, charges, commission_method, account_amount, payment_status, completed_at, entry_context, remarks)
        VALUES
            (:account_id, :date, :customer_name, :number, :transaction_id, 'receiving', :amount, 0, 'separate_cash', :account_amount, 'completed', NOW(), :entry_context, :remarks)
    ");
    $stmt->execute([
        ':account_id' => $accountId,
        ':date' => $txnDate,
        ':customer_name' => $customerName,
        ':number' => $phone !== '' ? $phone : null,
        ':transaction_id' => 'UDHAR-' . $udharId . '-' . date('His'),
        ':amount' => $amount,
        ':account_amount' => $amount,
        ':entry_context' => $method === 'cash' ? 'external' : 'udhar_recovery_online',
        ':remarks' => 'Udhar Recovery #' . $udharId . ' [' . udhar_method_label($method) . ']' . ($notes !== null && trim($notes) !== '' ? ' - ' . trim($notes) : ''),
    ]);
    return (int) $pdo->lastInsertId();
}

function udhar_ensure_ledger(PDO $pdo, int $udharId): void
{
    if ($udharId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM udhar_transactions WHERE udhar_id = :id');
        $stmt->execute([':id' => $udharId]);
        $cnt = (int) $stmt->fetchColumn();
        if ($cnt > 0) {
            return;
        }

        $stmt = $pdo->prepare('SELECT amount, udhar_date, notes FROM udhar_customers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $udharId]);
        $row = $stmt->fetch();
        if ($row) {
            $amount = (float) ($row['amount'] ?? 0);
            $date = (string) ($row['udhar_date'] ?? '');
            $notes = (string) ($row['notes'] ?? '');
            if ($amount > 0 && $date !== '') {
                $ins = $pdo->prepare("
                    INSERT INTO udhar_transactions (udhar_id, txn_date, txn_type, amount, notes)
                    VALUES (:udhar_id, :txn_date, 'udhar', :amount, :notes)
                ");
                $ins->execute([
                    ':udhar_id' => $udharId,
                    ':txn_date' => $date,
                    ':amount' => $amount,
                    ':notes' => $notes !== '' ? $notes : null,
                ]);
            }
        }

        try {
            $stmt = $pdo->prepare('SELECT payment_date, amount, created_at FROM udhar_payments WHERE udhar_id = :id ORDER BY payment_date ASC, id ASC');
            $stmt->execute([':id' => $udharId]);
            $payments = $stmt->fetchAll();
            if ($payments) {
                $ins = $pdo->prepare("
                    INSERT INTO udhar_transactions (udhar_id, txn_date, txn_type, amount, notes, created_at)
                    VALUES (:udhar_id, :txn_date, 'payment', :amount, NULL, :created_at)
                ");
                foreach ($payments as $p) {
                    $pDate = (string) ($p['payment_date'] ?? '');
                    $pAmount = (float) ($p['amount'] ?? 0);
                    $createdAt = (string) ($p['created_at'] ?? '');
                    if ($pDate !== '' && $pAmount > 0) {
                        $ins->execute([
                            ':udhar_id' => $udharId,
                            ':txn_date' => $pDate,
                            ':amount' => $pAmount,
                            ':created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
    }
}

function udhar_totals(PDO $pdo, int $udharId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN txn_type = 'udhar' THEN amount ELSE 0 END), 0) AS udhar_total,
            COALESCE(SUM(CASE WHEN txn_type = 'payment' THEN amount ELSE 0 END), 0) AS paid_total
        FROM udhar_transactions
        WHERE udhar_id = :id
    ");
    $stmt->execute([':id' => $udharId]);
    $row = $stmt->fetch() ?: [];
    $udharTotal = (float) ($row['udhar_total'] ?? 0);
    $paidTotal = (float) ($row['paid_total'] ?? 0);
    $balance = $udharTotal - $paidTotal;
    return [$udharTotal, $paidTotal, $balance];
}

function udhar_update_status(PDO $pdo, int $udharId): void
{
    try {
        [, , $balance] = udhar_totals($pdo, $udharId);
        $status = $balance <= 0 ? 'cleared' : 'pending';
        $stmt = $pdo->prepare('UPDATE udhar_customers SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $udharId]);
    } catch (Throwable $e) {
    }
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid udhar record.');
    app_redirect('udhar/index.php');
}

$success = flash_get('success');
$error = flash_get('error');

udhar_ensure_ledger($pdo, $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add_txn') {
        $txnDate = trim((string) ($_POST['txn_date'] ?? date('Y-m-d')));
        $txnType = trim((string) ($_POST['txn_type'] ?? ''));
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'cash'));
        $receivedAccountId = (int) ($_POST['received_account_id'] ?? 0);

        if ($txnDate === '') {
            flash_set('error', 'Date is required.');
            app_redirect('udhar/view.php?id=' . $id);
        }
        if ($txnType !== 'udhar' && $txnType !== 'payment') {
            flash_set('error', 'Invalid transaction type.');
            app_redirect('udhar/view.php?id=' . $id);
        }
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            flash_set('error', 'Amount must be a positive number.');
            app_redirect('udhar/view.php?id=' . $id);
        }
        if ($txnType === 'payment' && !array_key_exists($paymentMethod, udhar_method_labels())) {
            flash_set('error', 'Invalid payment method.');
            app_redirect('udhar/view.php?id=' . $id);
        }
        if ($txnType === 'payment' && !in_array($paymentMethod, ['cash', 'other'], true) && $receivedAccountId <= 0) {
            flash_set('error', 'Please select where the recovery was received.');
            app_redirect('udhar/view.php?id=' . $id);
        }

        $stmt = $pdo->prepare("SELECT name, phone FROM udhar_customers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $customerRow = $stmt->fetch() ?: [];
        $customerName = trim((string) ($customerRow['name'] ?? ''));
        $customerPhone = trim((string) ($customerRow['phone'] ?? ''));

        $walletTxnId = null;
        $accountId = null;

        $pdo->beginTransaction();
        try {
            if ($txnType === 'payment' && $paymentMethod !== 'other') {
                $accountId = udhar_account_id_for_method($pdo, $paymentMethod, $receivedAccountId);
                if ($accountId === null || $accountId <= 0) {
                    throw new RuntimeException('Please select a valid receiving account.');
                }
                $walletTxnId = udhar_insert_wallet_recovery_txn($pdo, $accountId, $paymentMethod, $id, $txnDate, $customerName, $customerPhone, (float) $amount, $notes !== '' ? $notes : null);
            }

            $stmt = $pdo->prepare("
                INSERT INTO udhar_transactions (udhar_id, txn_date, txn_type, amount, payment_method, received_account_id, linked_wallet_txn_id, notes)
                VALUES (:udhar_id, :txn_date, :txn_type, :amount, :payment_method, :received_account_id, :linked_wallet_txn_id, :notes)
            ");
            $stmt->execute([
                ':udhar_id' => $id,
                ':txn_date' => $txnDate,
                ':txn_type' => $txnType,
                ':amount' => (float) $amount,
                ':payment_method' => $txnType === 'payment' ? $paymentMethod : null,
                ':received_account_id' => $txnType === 'payment' ? $accountId : null,
                ':linked_wallet_txn_id' => $txnType === 'payment' ? $walletTxnId : null,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Could not save transaction.');
            app_redirect('udhar/view.php?id=' . $id);
        }

        udhar_update_status($pdo, $id);
        flash_set('success', $txnType === 'udhar' ? 'Udhar entry saved.' : 'Payment saved.');
        app_redirect('udhar/view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare("SELECT * FROM udhar_customers WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$customer = $stmt->fetch();
if (!$customer) {
    flash_set('error', 'Udhar record not found.');
    app_redirect('udhar/index.php');
}

udhar_ensure_ledger($pdo, $id);
[$udharTotal, $paidTotal, $balance] = udhar_totals($pdo, $id);
$remaining = $balance;
if ($remaining < 0) {
    $remaining = 0.0;
}
$status = $balance <= 0 ? 'cleared' : 'pending';

$stmt = $pdo->prepare("
    SELECT ut.id, ut.txn_date, ut.txn_type, ut.amount, ut.payment_method, ut.notes, ut.created_at,
           acc.account_name AS received_account_name
    FROM udhar_transactions ut
    LEFT JOIN accounts acc ON acc.id = ut.received_account_id
    WHERE ut.udhar_id = :id
    ORDER BY txn_date DESC, id DESC
");
$stmt->execute([':id' => $id]);
$txns = $stmt->fetchAll();

$txnDate = date('Y-m-d');
$txnType = 'payment';
$txnAmount = '';
$txnNotes = '';
$paymentMethod = 'cash';
$receivedAccountId = 0;
$methodAccounts = udhar_method_accounts($pdo);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Udhar Details</h1>
        <div class="text-muted small"><?= h((string) $customer['name']) ?> <?= ($customer['phone'] ?? '') !== '' ? ('• ' . h((string) $customer['phone'])) : '' ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/index.php')) ?>">Back</a>
        <a class="btn btn-gradient shadow-glow btn-sm" href="<?= h(app_url('udhar/add.php')) ?>">Add Udhar</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Udhar</div>
                <div class="h5 mb-0"><?= h(number_format($udharTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Paid</div>
                <div class="h5 mb-0"><?= h(number_format($paidTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Remaining</div>
                <div class="h5 mb-0"><?= h(number_format($remaining, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Status</div>
                <div class="h5 mb-0">
                    <?php if ($status === 'cleared'): ?>
                        <span class="badge text-bg-success">Cleared</span>
                    <?php else: ?>
                        <span class="badge text-bg-warning">Pending</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-md-3">
                <div class="text-muted small">Udhar Date</div>
                <div class="fw-semibold"><?= h((string) $customer['udhar_date']) ?></div>
            </div>
            <div class="col-12 col-md-9">
                <div class="text-muted small">Notes</div>
                <div class="fw-semibold"><?= h((string) ($customer['notes'] ?? '')) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6 fw-bold mb-3">Add Transaction</h2>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_txn">
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_date">Date</label>
                <input class="form-control" type="date" id="txn_date" name="txn_date" value="<?= h($txnDate) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="txn_type">Type</label>
                <select class="form-select" id="txn_type" name="txn_type" required>
                    <option value="udhar" <?= $txnType === 'udhar' ? 'selected' : '' ?>>+ Udhar</option>
                    <option value="payment" <?= $txnType === 'payment' ? 'selected' : '' ?>>- Payment</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($txnAmount) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="payment_method">Payment Received Method</label>
                <select class="form-select udhar-method-toggle" id="payment_method" name="payment_method">
                    <option value="cash" <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="jazzcash" <?= $paymentMethod === 'jazzcash' ? 'selected' : '' ?>>JazzCash</option>
                    <option value="easypaisa" <?= $paymentMethod === 'easypaisa' ? 'selected' : '' ?>>EasyPaisa</option>
                    <option value="bank" <?= $paymentMethod === 'bank' ? 'selected' : '' ?>>Bank Account</option>
                    <option value="other" <?= $paymentMethod === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <div class="col-12 col-md-3 udhar-method-account" data-method-type="jazzcash"<?= $paymentMethod === 'jazzcash' ? '' : ' style="display:none;"' ?>>
                <label class="form-label" for="received_account_jazzcash">Received In JazzCash</label>
                <select class="form-select" id="received_account_jazzcash" name="received_account_id">
                    <option value="">-- Select JazzCash --</option>
                    <?php foreach (($methodAccounts['jazzcash'] ?? []) as $account): ?>
                        <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $paymentMethod === 'jazzcash' && $receivedAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 udhar-method-account" data-method-type="easypaisa"<?= $paymentMethod === 'easypaisa' ? '' : ' style="display:none;"' ?>>
                <label class="form-label" for="received_account_easypaisa">Received In EasyPaisa</label>
                <select class="form-select" id="received_account_easypaisa" name="received_account_id">
                    <option value="">-- Select EasyPaisa --</option>
                    <?php foreach (($methodAccounts['easypaisa'] ?? []) as $account): ?>
                        <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $paymentMethod === 'easypaisa' && $receivedAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 udhar-method-account" data-method-type="bank"<?= $paymentMethod === 'bank' ? '' : ' style="display:none;"' ?>>
                <label class="form-label" for="received_account_bank">Received In Bank</label>
                <select class="form-select" id="received_account_bank" name="received_account_id">
                    <option value="">-- Select Bank Account --</option>
                    <?php foreach (($methodAccounts['bank'] ?? []) as $account): ?>
                        <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $paymentMethod === 'bank' && $receivedAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($txnNotes) ?>">
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-gradient shadow-glow w-100">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th>Payment Method</th>
                    <th>Received In</th>
                    <th>Notes</th>
                    <th>Created At</th>
                    <?php if (app_can_edit_delete_records()): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($txns as $t): ?>
                    <tr>
                        <td><?= h((string) $t['txn_date']) ?></td>
                        <td>
                            <?php if (($t['txn_type'] ?? '') === 'udhar'): ?>
                                <span class="badge text-bg-warning">+ Udhar</span>
                            <?php else: ?>
                                <span class="badge text-bg-success">- Payment</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= h(number_format((float) $t['amount'], 2)) ?></td>
                        <td><?= h(($t['txn_type'] ?? '') === 'payment' ? udhar_method_label((string) ($t['payment_method'] ?? 'cash')) : '-') ?></td>
                        <td><?= h(trim((string) ($t['received_account_name'] ?? '')) !== '' ? (string) ($t['received_account_name'] ?? '') : (($t['txn_type'] ?? '') === 'payment' ? udhar_method_label((string) ($t['payment_method'] ?? 'cash')) : '-')) ?></td>
                        <td><?= h((string) ($t['notes'] ?? '')) ?></td>
                        <td><?= h((string) $t['created_at']) ?></td>
                        <?php if (app_can_edit_delete_records()): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('udhar/edit_txn.php?id=' . (int) $t['id'] . '&udhar_id=' . $id)) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('udhar/delete_txn.php?id=' . (int) $t['id'] . '&udhar_id=' . $id)) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$txns): ?>
                    <tr>
                        <td colspan="<?= h((string) (7 + (app_can_edit_delete_records() ? 1 : 0))) ?>" class="text-center text-muted py-4">No transactions yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
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
