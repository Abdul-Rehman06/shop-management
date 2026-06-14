<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/jc_lib.php';

$pageTitle = 'JazzCash - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');

$totals = jc_totals($pdo);

$stmt = $pdo->query('
    SELECT id, date, customer_name, number, transaction_id, type, amount, charges, remarks
    FROM jazzcash_transactions
    ORDER BY date DESC, id DESC
    LIMIT 50
');
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">JazzCash</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-secondary btn-sm" href="<?= h(app_url('jazzcash/report.php')) ?>">Reports</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('jazzcash/receiving.php')) ?>">Add Receiving</a>
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('jazzcash/sending.php')) ?>">Add Sending</a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Receiving</div>
                <div class="h5 mb-0"><?= h(number_format($totals['receiving'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Sending</div>
                <div class="h5 mb-0"><?= h(number_format($totals['sending'], 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Commission Earned</div>
                <div class="h5 mb-0"><?= h(number_format($totals['commission'], 2)) ?></div>
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
                    <th>Type</th>
                    <th>Customer</th>
                    <th>Number</th>
                    <th>Transaction ID</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Charges</th>
                    <th>Remarks</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['type']) ?></td>
                        <td><?= h((string) ($r['customer_name'] ?? '')) ?></td>
                        <td><?= h((string) $r['number']) ?></td>
                        <td><?= h((string) ($r['transaction_id'] ?? '')) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['charges'], 2)) ?></td>
                        <td><?= h((string) ($r['remarks'] ?? '')) ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('jazzcash/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                            <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('jazzcash/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No transactions yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

