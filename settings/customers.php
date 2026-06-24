<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pdo = db();
app_require_owner_access();

$pageTitle = 'Customers - Shop Management';

$success = flash_get('success');
$errorFlash = flash_get('error');

$name = trim((string) ($_POST['name'] ?? ($_GET['name'] ?? '')));
$phone = trim((string) ($_POST['phone'] ?? ($_GET['phone'] ?? '')));
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if ($name === '') {
        $formError = 'Customer name is required.';
    } elseif ($phone === '') {
        $formError = 'Phone is required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO customers (name, phone)
            VALUES (:name, :phone)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name)
        ");
        $stmt->execute([
            ':name' => $name,
            ':phone' => $phone,
        ]);

        flash_set('success', 'Customer saved.');
        app_redirect('settings/customers.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($q !== '') {
    $where = "WHERE name LIKE :q OR phone LIKE :q";
    $params[':q'] = '%' . $q . '%';
}

$stmt = $pdo->prepare("
    SELECT id, name, phone, created_at, updated_at
    FROM customers
    {$where}
    ORDER BY updated_at DESC, id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$phones = [];
foreach ($rows as $r) {
    $p = trim((string) ($r['phone'] ?? ''));
    if ($p !== '') {
        $phones[] = $p;
    }
}
$phones = array_values(array_unique($phones));

$walletAgg = [];
$loadAgg = [];
$creditAgg = [];
$udharAgg = [];

if ($phones) {
    $in = implode(',', array_fill(0, count($phones), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT
                number AS phone,
                COUNT(*) AS txn_count,
                COALESCE(SUM(CASE WHEN type='receiving' THEN amount ELSE 0 END), 0) AS receiving_total,
                COALESCE(SUM(CASE WHEN type='sending' THEN amount ELSE 0 END), 0) AS sending_total,
                COALESCE(SUM(charges), 0) AS commission_total
            FROM wallet_transactions
            WHERE number IN ($in)
              AND type IN ('receiving','sending')
            GROUP BY number
        ");
        $stmt->execute($phones);
        foreach ($stmt->fetchAll() as $r) {
            $walletAgg[(string) $r['phone']] = $r;
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                customer_phone AS phone,
                COUNT(*) AS txn_count,
                COALESCE(SUM(amount), 0) AS load_total,
                COALESCE(SUM(profit), 0) AS profit_total
            FROM load_customer_transactions
            WHERE customer_phone IN ($in)
            GROUP BY customer_phone
        ");
        $stmt->execute($phones);
        foreach ($stmt->fetchAll() as $r) {
            $loadAgg[(string) $r['phone']] = $r;
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                cc.phone AS phone,
                COALESCE(SUM(CASE WHEN ct.txn_type='advance' THEN ct.amount ELSE 0 END), 0) AS adv_total,
                COALESCE(SUM(CASE WHEN ct.txn_type='used' THEN ct.amount ELSE 0 END), 0) AS used_total
            FROM credit_customers cc
            LEFT JOIN credit_transactions ct ON ct.customer_id = cc.id
            WHERE cc.phone IN ($in)
            GROUP BY cc.phone
        ");
        $stmt->execute($phones);
        foreach ($stmt->fetchAll() as $r) {
            $creditAgg[(string) $r['phone']] = $r;
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                uc.phone AS phone,
                COALESCE(SUM(CASE WHEN ut.txn_type='udhar' THEN ut.amount ELSE 0 END), 0) AS udhar_total,
                COALESCE(SUM(CASE WHEN ut.txn_type='payment' THEN ut.amount ELSE 0 END), 0) AS paid_total
            FROM udhar_customers uc
            LEFT JOIN udhar_transactions ut ON ut.udhar_id = uc.id
            WHERE uc.phone IN ($in)
            GROUP BY uc.phone
        ");
        $stmt->execute($phones);
        foreach ($stmt->fetchAll() as $r) {
            $udharAgg[(string) $r['phone']] = $r;
        }
    } catch (Throwable $e) {
    }
}

$viewId = (int) ($_GET['id'] ?? 0);
$viewCustomer = null;
if ($viewId > 0) {
    $stmt = $pdo->prepare("SELECT id, name, phone, created_at, updated_at FROM customers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $viewId]);
    $viewCustomer = $stmt->fetch() ?: null;
}

$detail = [
    'wallet' => ['count' => 0, 'receiving' => 0.0, 'sending' => 0.0, 'commission' => 0.0, 'rows' => []],
    'load' => ['count' => 0, 'amount' => 0.0, 'profit' => 0.0, 'rows' => []],
    'credit' => ['advance' => 0.0, 'used' => 0.0, 'remaining' => 0.0, 'rows' => []],
    'udhar' => ['udhar' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'rows' => []],
];

if ($viewCustomer) {
    $phoneKey = (string) ($viewCustomer['phone'] ?? '');

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS txn_count,
            COALESCE(SUM(CASE WHEN wt.type='receiving' THEN wt.amount ELSE 0 END), 0) AS receiving_total,
            COALESCE(SUM(CASE WHEN wt.type='sending' THEN wt.amount ELSE 0 END), 0) AS sending_total,
            COALESCE(SUM(wt.charges), 0) AS commission_total
        FROM wallet_transactions wt
        WHERE wt.number = :phone AND wt.type IN ('receiving','sending')
    ");
    $stmt->execute([':phone' => $phoneKey]);
    $row = $stmt->fetch() ?: [];
    $detail['wallet']['count'] = (int) ($row['txn_count'] ?? 0);
    $detail['wallet']['receiving'] = (float) ($row['receiving_total'] ?? 0);
    $detail['wallet']['sending'] = (float) ($row['sending_total'] ?? 0);
    $detail['wallet']['commission'] = (float) ($row['commission_total'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT wt.date, wt.type, wt.amount, wt.charges, wt.transaction_id, wt.remarks, wt.created_at, a.account_type, a.account_name
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE wt.number = :phone
          AND wt.type IN ('receiving','sending')
        ORDER BY wt.date DESC, wt.id DESC
        LIMIT 50
    ");
    $stmt->execute([':phone' => $phoneKey]);
    $detail['wallet']['rows'] = $stmt->fetchAll();

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS txn_count, COALESCE(SUM(amount), 0) AS amt, COALESCE(SUM(profit), 0) AS prof
            FROM load_customer_transactions
            WHERE customer_phone = :phone
        ");
        $stmt->execute([':phone' => $phoneKey]);
        $row = $stmt->fetch() ?: [];
        $detail['load']['count'] = (int) ($row['txn_count'] ?? 0);
        $detail['load']['amount'] = (float) ($row['amt'] ?? 0);
        $detail['load']['profit'] = (float) ($row['prof'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT txn_date, network, amount, profit, notes, created_at
            FROM load_customer_transactions
            WHERE customer_phone = :phone
            ORDER BY txn_date DESC, id DESC
            LIMIT 50
        ");
        $stmt->execute([':phone' => $phoneKey]);
        $detail['load']['rows'] = $stmt->fetchAll();
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN ct.txn_type='advance' THEN ct.amount ELSE 0 END), 0) AS adv_total,
                COALESCE(SUM(CASE WHEN ct.txn_type='used' THEN ct.amount ELSE 0 END), 0) AS used_total
            FROM credit_customers cc
            LEFT JOIN credit_transactions ct ON ct.customer_id = cc.id
            WHERE cc.phone = :phone
        ");
        $stmt->execute([':phone' => $phoneKey]);
        $row = $stmt->fetch() ?: [];
        $detail['credit']['advance'] = (float) ($row['adv_total'] ?? 0);
        $detail['credit']['used'] = (float) ($row['used_total'] ?? 0);
        $detail['credit']['remaining'] = $detail['credit']['advance'] - $detail['credit']['used'];

        $stmt = $pdo->prepare("
            SELECT ct.txn_date, ct.txn_type, ct.amount, ct.notes, ct.created_at, cc.name AS customer_name
            FROM credit_transactions ct
            JOIN credit_customers cc ON cc.id = ct.customer_id
            WHERE cc.phone = :phone
            ORDER BY ct.txn_date DESC, ct.id DESC
            LIMIT 50
        ");
        $stmt->execute([':phone' => $phoneKey]);
        $detail['credit']['rows'] = $stmt->fetchAll();
    } catch (Throwable $e) {
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN ut.txn_type='udhar' THEN ut.amount ELSE 0 END), 0) AS udhar_total,
                COALESCE(SUM(CASE WHEN ut.txn_type='payment' THEN ut.amount ELSE 0 END), 0) AS paid_total
            FROM udhar_customers uc
            LEFT JOIN udhar_transactions ut ON ut.udhar_id = uc.id
            WHERE uc.phone = :phone
        ");
        $stmt->execute([':phone' => $phoneKey]);
        $row = $stmt->fetch() ?: [];
        $detail['udhar']['udhar'] = (float) ($row['udhar_total'] ?? 0);
        $detail['udhar']['paid'] = (float) ($row['paid_total'] ?? 0);
        $detail['udhar']['balance'] = $detail['udhar']['udhar'] - $detail['udhar']['paid'];

        $stmt = $pdo->prepare("
            SELECT ut.txn_date, ut.txn_type, ut.amount, ut.notes, ut.created_at, uc.name AS customer_name
            FROM udhar_transactions ut
            JOIN udhar_customers uc ON uc.id = ut.udhar_id
            WHERE uc.phone = :phone
            ORDER BY ut.txn_date DESC, ut.id DESC
            LIMIT 50
        ");
        $stmt->execute([':phone' => $phoneKey]);
        $detail['udhar']['rows'] = $stmt->fetchAll();
    } catch (Throwable $e) {
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Customers</h1>
        <div class="text-muted small">Save customer once (phone) and reuse in entries. View all activities by customer.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($errorFlash !== ''): ?>
    <div class="alert alert-danger"><?= h($errorFlash) ?></div>
<?php endif; ?>

<?php if ($formError !== ''): ?>
    <div class="alert alert-danger"><?= h($formError) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" value="<?= h($name) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control" id="phone" name="phone" value="<?= h($phone) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-gradient shadow-glow w-100">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end mb-3">
            <div class="col-12 col-md-6">
                <label class="form-label">Search</label>
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search name or phone">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-outline-primary w-100">Search</button>
                <a class="btn btn-outline-secondary w-100" href="<?= h(app_url('settings/customers.php')) ?>">Clear</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th class="text-end">Mobile Txns</th>
                    <th class="text-end">Load</th>
                    <th class="text-end">Udhar Balance</th>
                    <th class="text-end">Credit Remaining</th>
                    <th>Updated</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $p = (string) ($r['phone'] ?? '');
                    $w = $p !== '' ? ($walletAgg[$p] ?? null) : null;
                    $l = $p !== '' ? ($loadAgg[$p] ?? null) : null;
                    $u = $p !== '' ? ($udharAgg[$p] ?? null) : null;
                    $c = $p !== '' ? ($creditAgg[$p] ?? null) : null;

                    $udharBalance = $u ? ((float) ($u['udhar_total'] ?? 0) - (float) ($u['paid_total'] ?? 0)) : 0.0;
                    $creditRemaining = $c ? ((float) ($c['adv_total'] ?? 0) - (float) ($c['used_total'] ?? 0)) : 0.0;
                    ?>
                    <tr>
                        <td><?= h((string) $r['name']) ?></td>
                        <td><?= h((string) $r['phone']) ?></td>
                        <td class="text-end"><?= h(number_format((float) ($w['txn_count'] ?? 0), 0)) ?></td>
                        <td class="text-end"><?= h(number_format((float) ($l['load_total'] ?? 0), 2)) ?></td>
                        <td class="text-end"><?= h(number_format($udharBalance, 2)) ?></td>
                        <td class="text-end"><?= h(number_format($creditRemaining, 2)) ?></td>
                        <td><?= h((string) $r['updated_at']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('settings/customers.php?id=' . (int) $r['id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No customers found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($viewCustomer): ?>
    <div class="mt-4 mb-2 d-flex align-items-center justify-content-between">
        <div>
            <div class="h5 mb-0"><?= h((string) $viewCustomer['name']) ?></div>
            <div class="text-muted small"><?= h((string) $viewCustomer['phone']) ?></div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/customers.php')) ?>">Back to Customers</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Mobile Receiving</div>
                    <div class="h6 mb-0"><?= h(number_format($detail['wallet']['receiving'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Mobile Sending</div>
                    <div class="h6 mb-0"><?= h(number_format($detail['wallet']['sending'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Load Total</div>
                    <div class="h6 mb-0"><?= h(number_format($detail['load']['amount'], 2)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Udhar Balance</div>
                    <div class="h6 mb-0"><?= h(number_format($detail['udhar']['balance'], 2)) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="fw-semibold mb-2">Mobile Transactions (Last 50)</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Comm</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($detail['wallet']['rows'] as $t): ?>
                                <tr>
                                    <td><?= h((string) $t['date']) ?></td>
                                    <td><?= h((string) $t['account_name']) ?> (<?= h((string) $t['account_type']) ?>)</td>
                                    <td><?= h((string) $t['type']) ?></td>
                                    <td class="text-end"><?= h(number_format((float) $t['amount'], 2)) ?></td>
                                    <td class="text-end"><?= h(number_format((float) ($t['charges'] ?? 0), 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$detail['wallet']['rows']): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No mobile transactions found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="fw-semibold mb-2">Load Transactions (Last 50)</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Network</th>
                                <th class="text-end">Amount</th>
                                <?php if (app_can_view_profit()): ?>
                                    <th class="text-end">Profit</th>
                                <?php endif; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($detail['load']['rows'] as $t): ?>
                                <tr>
                                    <td><?= h((string) $t['txn_date']) ?></td>
                                    <td><?= h((string) $t['network']) ?></td>
                                    <td class="text-end"><?= h(number_format((float) $t['amount'], 2)) ?></td>
                                    <?php if (app_can_view_profit()): ?>
                                        <td class="text-end"><?= h(number_format((float) ($t['profit'] ?? 0), 2)) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$detail['load']['rows']): ?>
                                <tr><td colspan="<?= h((string) (3 + (app_can_view_profit() ? 1 : 0))) ?>" class="text-center text-muted py-3">No load transactions found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="fw-semibold mb-2">Credit (Advance) (Last 50)</div>
                    <div class="mb-2 text-muted small">
                        Advance: <?= h(number_format($detail['credit']['advance'], 2)) ?> • Used: <?= h(number_format($detail['credit']['used'], 2)) ?> • Remaining: <?= h(number_format($detail['credit']['remaining'], 2)) ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th class="text-end">Amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($detail['credit']['rows'] as $t): ?>
                                <tr>
                                    <td><?= h((string) $t['txn_date']) ?></td>
                                    <td><?= h((string) $t['txn_type']) ?></td>
                                    <td class="text-end"><?= h(number_format((float) $t['amount'], 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$detail['credit']['rows']): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No credit records found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="fw-semibold mb-2">Udhar Ledger (Last 50)</div>
                    <div class="mb-2 text-muted small">
                        Udhar: <?= h(number_format($detail['udhar']['udhar'], 2)) ?> • Paid: <?= h(number_format($detail['udhar']['paid'], 2)) ?> • Balance: <?= h(number_format($detail['udhar']['balance'], 2)) ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th class="text-end">Amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($detail['udhar']['rows'] as $t): ?>
                                <tr>
                                    <td><?= h((string) $t['txn_date']) ?></td>
                                    <td><?= h((string) $t['txn_type']) ?></td>
                                    <td class="text-end"><?= h(number_format((float) $t['amount'], 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$detail['udhar']['rows']): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No udhar records found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
