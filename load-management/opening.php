<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pageTitle = 'Add Opening Balance - Load Management';

$pdo = db();
$networks = load_get_networks($pdo);

$date = date('Y-m-d');
$network = $networks[0] ?? '';
$amount = '';
$remarks = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = trim((string) ($_POST['date'] ?? ''));
    $network = trim((string) ($_POST['network'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($date === '') {
        $error = 'Date is required.';
    } elseif ($network === '') {
        $error = 'Network is required.';
    } elseif ($amount === '' || !is_numeric($amount)) {
        $error = 'Opening balance must be a number.';
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO load_transactions
                (date, network, type, opening_balance, purchased, sold, customer_number, profit, closing_balance, supplier, remarks)
            VALUES
                (:date, :network, :type, :opening_balance, 0, 0, NULL, 0, :closing_balance, NULL, :remarks)
        ');
        $opening = (float) $amount;
        $stmt->execute([
            ':date' => $date,
            ':network' => $network,
            ':type' => 'opening',
            ':opening_balance' => $opening,
            ':closing_balance' => $opening,
            ':remarks' => $remarks !== '' ? $remarks : null,
        ]);

        load_recalculate_network($pdo, $network);
        flash_set('success', 'Opening balance added successfully.');
        app_redirect('load-management/index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Opening Balance</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/index.php')) ?>">Back</a>
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
                    <label class="form-label" for="network">Network</label>
                    <select class="form-select" id="network" name="network" required>
                        <?php foreach ($networks as $n): ?>
                            <option value="<?= h($n) ?>" <?= $n === $network ? 'selected' : '' ?>><?= h($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label" for="amount">Opening Balance</label>
                    <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
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

