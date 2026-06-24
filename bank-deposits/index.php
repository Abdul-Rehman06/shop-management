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

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Bank / CDM Deposits</h1>
        <div class="text-gray-500 text-sm">Track physical cash deposits into bank accounts</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-gradient shadow-glow rounded-xl" href="<?= h(app_url('bank-deposits/add.php')) ?>">
            <i class="bi bi-plus-lg"></i> Add Deposit
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="glass-card mb-4 animate-slide-up stagger-1">
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-bold text-gray-600" for="from">From Date</label>
                <input class="form-control bg-light border-0 shadow-sm" type="date" id="from" name="from" value="<?= h($from) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-bold text-gray-600" for="to">To Date</label>
                <input class="form-control bg-light border-0 shadow-sm" type="date" id="to" name="to" value="<?= h($to) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-bold text-gray-600" for="bank_account_id">Bank Account</label>
                <select class="form-select bg-light border-0 shadow-sm" id="bank_account_id" name="bank_account_id">
                    <option value="0">All Banks</option>
                    <?php foreach ($banks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $bankAccountId ? 'selected' : '' ?>>
                            <?= h((string) $b['account_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button class="btn btn-gradient shadow-glow w-100 shadow-sm hover-lift d-inline-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<div class="row g-4 mb-4 animate-slide-up stagger-2">
    <div class="col-12 col-md-4">
        <div class="p-4 bg-light rounded-4 border-start border-primary border-4 h-100 transition-all hover-lift">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="text-muted small fw-bold text-uppercase tracking-wider">Total Deposits</div>
                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-circle">
                    <i class="bi bi-bank"></i>
                </div>
            </div>
            <div class="h3 mb-0 font-bold text-primary"><?= h(number_format($total, 2)) ?></div>
        </div>
    </div>
</div>

<div class="glass-card animate-slide-up stagger-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-table">
                <thead class="bg-light bg-opacity-50">
                <tr>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Date</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Bank</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Amount</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Note</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Created At</th>
                    <?php if ($canEditDelete): ?>
                        <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody class="border-top-0">
                <?php foreach ($rows as $r): ?>
                    <?php
                    $bankLabel = (string) ($r['account_name'] ?? '');
                    if ($bankLabel === '') {
                        $bankLabel = (string) ($r['bank_name'] ?? '');
                    }
                    ?>
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3 font-medium text-gray-600"><?= h((string) $r['deposit_date']) ?></td>
                        <td class="px-4 py-3">
                            <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1 rounded-pill border border-primary border-opacity-25">
                                <i class="bi bi-building me-1"></i> <?= h($bankLabel) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-end font-bold text-success">+ <?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= h((string) ($r['note'] ?? '')) ?></td>
                        <td class="px-4 py-3 text-muted small"><?= h((string) $r['created_at']) ?></td>
                        <?php if ($canEditDelete): ?>
                            <td class="px-4 py-3 text-end">
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('bank-deposits/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (5 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i class="bi bi-bank fs-1 text-gray-300 mb-2"></i>
                                <p class="mb-0">No deposits found.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

