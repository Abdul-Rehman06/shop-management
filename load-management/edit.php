<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pdo = db();
load_ensure_schema($pdo);
flash_set('success', 'Load Management has been updated to Daily Totals. Use the main Load Management page.');
app_redirect('load-management/index.php');

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid transaction.');
    app_redirect('load-management/index.php');
}

$row = load_find_transaction($pdo, $id);
if (!$row) {
    flash_set('error', 'Transaction not found.');
    app_redirect('load-management/index.php');
}

$networks = load_get_networks($pdo);

$type = (string) $row['type'];
$date = (string) $row['date'];
$network = (string) $row['network'];
$remarks = (string) ($row['remarks'] ?? '');

$openingBalance = (string) $row['opening_balance'];
$purchased = (string) $row['purchased'];
$sold = (string) $row['sold'];
$supplier = (string) ($row['supplier'] ?? '');
$customerNumber = (string) ($row['customer_number'] ?? '');
$profit = (string) ($row['profit'] ?? '');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? ''));
    $network = trim((string) ($_POST['network'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($network === '') {
        $error = 'Network is required.';
    } else {
        $originalNetwork = (string) $row['network'];

        if ($type === 'opening') {
            $openingBalance = trim((string) ($_POST['opening_balance'] ?? ''));
            if ($openingBalance === '' || !is_numeric($openingBalance)) {
                $error = 'Opening balance must be a number.';
            } else {
                $stmt = $pdo->prepare('
                    UPDATE load_transactions
                    SET date = :date,
                        network = :network,
                        opening_balance = :opening_balance,
                        purchased = 0,
                        sold = 0,
                        customer_number = NULL,
                        profit = 0,
                        closing_balance = :closing_balance,
                        supplier = NULL,
                        remarks = :remarks
                    WHERE id = :id
                ');
                $opening = (float) $openingBalance;
                $stmt->execute([
                    ':date' => $date,
                    ':network' => $network,
                    ':opening_balance' => $opening,
                    ':closing_balance' => $opening,
                    ':remarks' => $remarks !== '' ? $remarks : null,
                    ':id' => $id,
                ]);
            }
        } elseif ($type === 'purchase') {
            $purchased = trim((string) ($_POST['purchased'] ?? ''));
            $supplier = trim((string) ($_POST['supplier'] ?? ''));
            if ($purchased === '' || !is_numeric($purchased)) {
                $error = 'Purchase amount must be a number.';
            } else {
                $stmt = $pdo->prepare('
                    UPDATE load_transactions
                    SET date = :date,
                        network = :network,
                        purchased = :purchased,
                        sold = 0,
                        customer_number = NULL,
                        profit = 0,
                        supplier = :supplier,
                        remarks = :remarks
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':date' => $date,
                    ':network' => $network,
                    ':purchased' => (float) $purchased,
                    ':supplier' => $supplier !== '' ? $supplier : null,
                    ':remarks' => $remarks !== '' ? $remarks : null,
                    ':id' => $id,
                ]);
            }
        } elseif ($type === 'sale') {
            $sold = trim((string) ($_POST['sold'] ?? ''));
            $customerNumber = trim((string) ($_POST['customer_number'] ?? ''));
            $profit = trim((string) ($_POST['profit'] ?? ''));
            if ($customerNumber === '') {
                $error = 'Customer number is required.';
            } elseif ($sold === '' || !is_numeric($sold)) {
                $error = 'Sale amount must be a number.';
            } elseif ($profit !== '' && !is_numeric($profit)) {
                $error = 'Profit must be a number.';
            } else {
                $stmt = $pdo->prepare('
                    UPDATE load_transactions
                    SET date = :date,
                        network = :network,
                        purchased = 0,
                        sold = :sold,
                        customer_number = :customer_number,
                        profit = :profit,
                        supplier = NULL,
                        remarks = :remarks
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':date' => $date,
                    ':network' => $network,
                    ':sold' => (float) $sold,
                    ':customer_number' => $customerNumber,
                    ':profit' => $profit === '' ? 0.0 : (float) $profit,
                    ':remarks' => $remarks !== '' ? $remarks : null,
                    ':id' => $id,
                ]);
            }
        } else {
            $error = 'Unsupported transaction type.';
        }

        if ($error === '') {
            load_recalculate_network($pdo, $originalNetwork);
            if ($network !== $originalNetwork) {
                load_recalculate_network($pdo, $network);
            }
            flash_set('success', 'Transaction updated successfully.');
            app_redirect('load-management/index.php');
        }
    }
}

$pageTitle = 'Edit Transaction - Load Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Transaction</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/index.php')) ?>">Back</a>
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
                    <label class="form-label" for="network">Network</label>
                    <select class="form-select" id="network" name="network" required>
                        <?php foreach ($networks as $n): ?>
                            <option value="<?= h($n) ?>" <?= $n === $network ? 'selected' : '' ?>><?= h($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label">Type</label>
                    <input class="form-control" value="<?= h($type) ?>" disabled>
                </div>

                <?php if ($type === 'opening'): ?>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="opening_balance">Opening Balance</label>
                        <input class="form-control" type="number" step="0.01" id="opening_balance" name="opening_balance" value="<?= h($openingBalance) ?>" required>
                    </div>
                <?php elseif ($type === 'purchase'): ?>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="purchased">Amount</label>
                        <input class="form-control" type="number" step="0.01" id="purchased" name="purchased" value="<?= h($purchased) ?>" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="supplier">Supplier</label>
                        <input class="form-control" type="text" id="supplier" name="supplier" value="<?= h($supplier) ?>">
                    </div>
                <?php elseif ($type === 'sale'): ?>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="customer_number">Customer Number</label>
                        <input class="form-control" type="text" id="customer_number" name="customer_number" value="<?= h($customerNumber) ?>" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="sold">Amount</label>
                        <input class="form-control" type="number" step="0.01" id="sold" name="sold" value="<?= h($sold) ?>" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="profit">Profit</label>
                        <input class="form-control" type="number" step="0.01" id="profit" name="profit" value="<?= h($profit) ?>">
                    </div>
                <?php endif; ?>

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
