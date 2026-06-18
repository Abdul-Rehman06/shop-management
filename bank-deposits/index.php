<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Bank Deposits - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canEditDelete = app_can_edit_delete_records();

$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-t')));
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$bankAccountId = (int) ($_GET['bank_account_id'] ?? 0);

$banks = wallet_accounts($pdo, 'bank', true);

$params = [':from' => $from, ':to' => $to];
$where = "WHERE bd.deposit_date >= :from AND bd.deposit_date <= :to";
if ($bankAccountId > 0) {
    $where .= " AND bd.bank_account_id = :bank_account_id";
    $params[':bank_account_id'] = $bankAccountId;
}

$stmt = $pdo->prepare("
    SELECT
        bd.id,
        bd.bank_account_id,
        bd.bank_name,
        bd.amount,
        bd.deposit_date,
        bd.note,
        bd.bank_wallet_transaction_id,
        bd.cash_wallet_transaction_id,
        bd.created_at,
        a.account_name
    FROM bank_deposits bd
    LEFT JOIN accounts a ON a.id = bd.bank_account_id
    {$where}
    ORDER BY bd.deposit_date DESC, bd.id DESC
    LIMIT 300
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = 0.0;
foreach ($rows as $r) {
    $total += (float) ($r['amount'] ?? 0);
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Bank / CDM Deposits</h1>
        <div class="text-muted small">Deposit history</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('bank-deposits/add.php')) ?>">Add Deposit</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= h($from) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= h($to) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="bank_account_id">Bank</label>
                <select class="form-select" id="bank_account_id" name="bank_account_id">
                    <option value="0">All</option>
                    <?php foreach ($banks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $bankAccountId ? 'selected' : '' ?>>
                            <?= h((string) $b['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Deposits</div>
                <div class="h5 mb-0"><?= h(number_format($total, 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Bank</th>
                    <th class="text-end">Amount</th>
                    <th>Note</th>
                    <th>Created At</th>
                    <?php if ($canEditDelete): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $bankLabel = (string) ($r['account_name'] ?? '');
                    if ($bankLabel === '') {
                        $bankLabel = (string) ($r['bank_name'] ?? '');
                    }
                    ?>
                    <tr>
                        <td><?= h((string) $r['deposit_date']) ?></td>
                        <td><?= h($bankLabel) ?></td>
                        <td class="text-end fw-semibold"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td><?= h((string) ($r['note'] ?? '')) ?></td>
                        <td><?= h((string) $r['created_at']) ?></td>
                        <?php if ($canEditDelete): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('bank-deposits/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (5 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-4">No deposits found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

