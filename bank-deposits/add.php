<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Bank Deposit - Shop Management';

$pdo = db();

$canCreateBankAccount = app_is_owner();
$banks = wallet_accounts($pdo, 'bank', true);
$cashAccounts = wallet_accounts($pdo, 'cash', true);
$cashAccountId = (int) ($cashAccounts[0]['id'] ?? 0);

if ($cashAccountId <= 0) {
    $pdo->prepare("INSERT INTO accounts (account_name, account_type, account_number, status) VALUES ('Cash', 'cash', NULL, 'active')")->execute();
    $cashAccountId = (int) $pdo->lastInsertId();
}

$depositDate = date('Y-m-d');
$bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
$bankName = (string) ($_POST['bank_name'] ?? '');
$amount = (string) ($_POST['amount'] ?? '');
$note = (string) ($_POST['note'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $depositDate = trim((string) ($_POST['deposit_date'] ?? ''));
    $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    $selectedBank = null;
    foreach ($banks as $b) {
        if ((int) $b['id'] === $bankAccountId) {
            $selectedBank = $b;
            break;
        }
    }

    if ($depositDate === '') {
        $error = 'Deposit date is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $error = 'Amount must be a positive number.';
    } elseif ($bankAccountId <= 0 && $bankName === '') {
        $error = 'Please select a bank or enter bank name.';
    } elseif ($bankAccountId <= 0 && !$canCreateBankAccount) {
        $error = 'Only Owner can create a new bank. Please select an existing bank.';
    } else {
        $pdo->beginTransaction();
        try {
            if ($bankAccountId <= 0 && $bankName !== '') {
                $stmt = $pdo->prepare("
                    INSERT INTO accounts (account_name, account_type, account_number, status)
                    VALUES (:name, 'bank', NULL, 'active')
                ");
                $stmt->execute([':name' => $bankName]);
                $bankAccountId = (int) $pdo->lastInsertId();
                $selectedBank = wallet_account($pdo, $bankAccountId);
            }

            $amountFloat = (float) $amount;
            $bankLabel = $selectedBank ? (string) ($selectedBank['account_name'] ?? '') : $bankName;
            $depositNote = trim('Deposit: ' . $bankLabel . ($note !== '' ? (' - ' . $note) : ''));

            $stmt = $pdo->prepare("
                INSERT INTO bank_deposits (bank_account_id, bank_name, amount, deposit_date, note)
                VALUES (:bank_account_id, :bank_name, :amount, :deposit_date, :note)
            ");
            $stmt->execute([
                ':bank_account_id' => $bankAccountId > 0 ? $bankAccountId : null,
                ':bank_name' => $bankLabel !== '' ? $bankLabel : null,
                ':amount' => $amountFloat,
                ':deposit_date' => $depositDate,
                ':note' => $note !== '' ? $note : null,
            ]);
            $depositId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO wallet_transactions (account_id, date, type, amount, charges, remarks)
                VALUES (:account_id, :date, 'receiving', :amount, 0, :remarks)
            ");
            $stmt->execute([
                ':account_id' => $bankAccountId,
                ':date' => $depositDate,
                ':amount' => $amountFloat,
                ':remarks' => 'Bank Deposit #' . $depositId . ($depositNote !== '' ? (' - ' . $depositNote) : ''),
            ]);
            $bankTxnId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO wallet_transactions (account_id, date, type, amount, charges, remarks)
                VALUES (:account_id, :date, 'sending', :amount, 0, :remarks)
            ");
            $stmt->execute([
                ':account_id' => $cashAccountId,
                ':date' => $depositDate,
                ':amount' => $amountFloat,
                ':remarks' => 'Bank Deposit #' . $depositId . ($depositNote !== '' ? (' - ' . $depositNote) : ''),
            ]);
            $cashTxnId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                UPDATE bank_deposits
                SET bank_wallet_transaction_id = :bank_txn,
                    cash_wallet_transaction_id = :cash_txn
                WHERE id = :id
            ");
            $stmt->execute([
                ':bank_txn' => $bankTxnId,
                ':cash_txn' => $cashTxnId,
                ':id' => $depositId,
            ]);

            $pdo->commit();
            flash_set('success', 'Deposit added.');
            app_redirect('bank-deposits/index.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not save deposit.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Bank / CDM Deposit</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bank-deposits/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="deposit_date">Deposit Date</label>
                <input class="form-control" type="date" id="deposit_date" name="deposit_date" value="<?= h($depositDate) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="bank_account_id">Bank (Select)</label>
                <select class="form-select" id="bank_account_id" name="bank_account_id">
                    <option value="0">-- Select --</option>
                    <?php foreach ($banks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $bankAccountId ? 'selected' : '' ?>>
                            <?= h((string) $b['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="text-muted small mt-1">Or enter bank name below</div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="bank_name">Bank Name (Optional)</label>
                <input class="form-control" type="text" id="bank_name" name="bank_name" value="<?= h($bankName) ?>" placeholder="e.g., Meezan / UBL">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="note">Note (optional)</label>
                <input class="form-control" type="text" id="note" name="note" value="<?= h($note) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Save Deposit</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

