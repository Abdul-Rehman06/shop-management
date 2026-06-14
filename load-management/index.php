<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/load_lib.php';

$pageTitle = 'Load Management - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');

$stmt = $pdo->query('
    SELECT id, date, network, type, opening_balance, purchased, sold, profit, closing_balance, customer_number, supplier, remarks
    FROM load_transactions
    ORDER BY date DESC, id DESC
    LIMIT 50
');
$rows = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-1 text-gray-900 font-bold tracking-tight">Load Management</h1>
        <p class="text-gray-500 text-sm mb-0">Manage network balances, purchases, and sales</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-secondary btn-sm" href="<?= h(app_url('load-management/report.php')) ?>">
            <i data-lucide="bar-chart-3" class="w-4 h-4"></i> Reports
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('load-management/opening.php')) ?>">
            <i data-lucide="plus-circle" class="w-4 h-4"></i> Opening
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('load-management/purchase.php')) ?>">
            <i data-lucide="shopping-bag" class="w-4 h-4"></i> Purchase
        </a>
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('load-management/sale.php')) ?>">
            <i data-lucide="shopping-cart" class="w-4 h-4"></i> Sale
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Network</th>
                    <th>Type</th>
                    <th class="text-end">Opening</th>
                    <th class="text-end">Purchased</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Closing</th>
                    <th>Party</th>
                    <th>Remarks</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['date']) ?></td>
                        <td><?= h((string) $r['network']) ?></td>
                        <td><?= h((string) $r['type']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['opening_balance'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['purchased'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['sold'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['profit'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['closing_balance'], 2)) ?></td>
                        <td>
                            <?php
                            $party = '';
                            if ((string) $r['type'] === 'purchase') {
                                $party = (string) ($r['supplier'] ?? '');
                            } elseif ((string) $r['type'] === 'sale') {
                                $party = (string) ($r['customer_number'] ?? '');
                            }
                            ?>
                            <?= h($party) ?>
                        </td>
                        <td><?= h((string) ($r['remarks'] ?? '')) ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('load-management/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                            <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('load-management/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">No transactions yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

