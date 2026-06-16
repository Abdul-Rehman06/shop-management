<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'JazzCash Opening Balance - Shop Management';

$pdo = db();
$accounts = wallet_accounts($pdo, 'jazzcash');
$accountId = (int) ($_GET['account_id'] ?? ($_POST['account_id'] ?? ($accounts[0]['id'] ?? 0)));
$date = (string) ($_POST['date'] ?? date('Y-m-d'));
$amount = (string) ($_POST['amount'] ?? '');
$remarks = (string) ($_POST['remarks'] ?? '');
$error = '';

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
    $date = trim((string) ($_POST['date'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $accountId = (int) ($_POST['account_id'] ?? 0);

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
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO wallet_transactions (account_id, date, type, amount, charges, remarks)
            VALUES (:account_id, :date, :type, :amount, 0, :remarks)
        ');
        $stmt->execute([
            ':account_id' => $accountId,
            ':date' => $date,
            ':type' => 'opening',
            ':amount' => (float) $amount,
            ':remarks' => $remarks !== '' ? $remarks : null,
        ]);
        flash_set('success', 'Opening balance saved.');
        app_redirect('jazzcash/index.php?account_id=' . $accountId);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">JazzCash Opening Balance</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('jazzcash/index.php?account_id=' . $accountId)) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="account_id">Account</label>
                <select class="form-select" id="account_id" name="account_id" required>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= h((string) (int) $a['id']) ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>>
                            <?= h((string) $a['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="date">Date</label>
                <input class="form-control" type="date" id="date" name="date" value="<?= h($date) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="amount">Opening Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="remarks">Remarks</label>
                <input class="form-control" type="text" id="remarks" name="remarks" value="<?= h($remarks) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

