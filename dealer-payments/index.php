<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Dealer Payments - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canEditDelete = app_can_edit_delete_records();

$networks = ['Jazz', 'Zong', 'Telenor', 'Ufone'];

$dealerNames = [];
try {
    $stmt = $pdo->query("SELECT dealer_name FROM dealers WHERE status = 'active' ORDER BY dealer_name ASC");
    $dealerNames = array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['dealer_name'] ?? ''), $stmt->fetchAll())));
} catch (Throwable $e) {
    $dealerNames = [];
}
if (!$dealerNames) {
    $dealerNames = ['Khalid', 'Nouman', 'Saifullah', 'Imran'];
}

$dealer = trim((string) ($_GET['dealer'] ?? ''));
$network = trim((string) ($_GET['network'] ?? ''));
$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));

if ($dealer !== '' && !in_array($dealer, $dealerNames, true)) {
    $dealer = '';
}
if ($network !== '' && !in_array($network, $networks, true)) {
    $network = '';
}

$whereParts = ['payment_date >= :from', 'payment_date <= :to'];
$params = [':from' => $from, ':to' => $to];
if ($dealer !== '') {
    $whereParts[] = 'dealer_name = :dealer';
    $params[':dealer'] = $dealer;
}
if ($network !== '') {
    $whereParts[] = 'network = :network';
    $params[':network'] = $network;
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$stmt = $pdo->prepare("
    SELECT id, dealer_name, network, payment_date, amount, notes, created_at
    FROM dealer_payments
    {$where}
    ORDER BY payment_date DESC, id DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM dealer_payments {$where}");
$stmt->execute($params);
$rangeTotal = (float) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM dealer_payments
    WHERE payment_date = CURDATE()
");
$todayTotal = (float) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM dealer_payments
    WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND payment_date <= LAST_DAY(CURDATE())
");
$monthTotal = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT dealer_name, COALESCE(SUM(amount), 0) AS total
    FROM dealer_payments
    {$where}
    GROUP BY dealer_name
    ORDER BY dealer_name ASC
");
$stmt->execute($params);
$totalsByDealer = [];
foreach ($stmt->fetchAll() as $r) {
    $totalsByDealer[(string) $r['dealer_name']] = (float) $r['total'];
}

$stmt = $pdo->prepare("
    SELECT network, COALESCE(SUM(amount), 0) AS total
    FROM dealer_payments
    {$where}
    GROUP BY network
    ORDER BY network ASC
");
$stmt->execute($params);
$totalsByNetwork = [];
foreach ($stmt->fetchAll() as $r) {
    $totalsByNetwork[(string) $r['network']] = (float) $r['total'];
}

$loadPurchasesByNetwork = [];
try {
    $stmt = $pdo->prepare("
        SELECT network, COALESCE(SUM(purchased_balance), 0) AS total
        FROM load_entries
        WHERE date >= :from AND date <= :to
        GROUP BY network
    ");
    $stmt->execute([':from' => $from, ':to' => $to]);
    foreach ($stmt->fetchAll() as $r) {
        $loadPurchasesByNetwork[(string) $r['network']] = (float) $r['total'];
    }
} catch (Throwable $e) {
}

$remainingPayableByNetwork = [];
$remainingPayableTotal = 0.0;
foreach ($networks as $n) {
    $purchased = (float) ($loadPurchasesByNetwork[$n] ?? 0);
    $paid = (float) ($totalsByNetwork[$n] ?? 0);
    $rem = $purchased - $paid;
    $remainingPayableByNetwork[$n] = $rem;
    $remainingPayableTotal += $rem;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Dealer Payments</h1>
        <div class="text-muted small">Maintain payments made to load dealers</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= h(app_url('dealer-payments/add.php')) ?>">Add Payment</a>
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
                <label class="form-label">Dealer</label>
                <select class="form-select" name="dealer">
                    <option value="">All</option>
                    <?php foreach ($dealerNames as $d): ?>
                        <option value="<?= h($d) ?>" <?= $dealer === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Network</label>
                <select class="form-select" name="network">
                    <option value="">All</option>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $network === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">From</label>
                <input class="form-control" type="date" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">To</label>
                <input class="form-control" type="date" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-outline-primary w-100">Filter</button>
                <a class="btn btn-outline-secondary w-100" href="<?= h(app_url('dealer-payments/index.php')) ?>">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Payments (Range)</div>
                <div class="h5 mb-0"><?= h(number_format($rangeTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Today Payments</div>
                <div class="h5 mb-0"><?= h(number_format($todayTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Monthly Payments</div>
                <div class="h5 mb-0"><?= h(number_format($monthTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Remaining Payable (Range)</div>
                <div class="h5 mb-0"><?= h(number_format($remainingPayableTotal, 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="fw-semibold mb-2">Total Payment by Dealer</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Dealer</th>
                            <th class="text-end">Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dealerNames as $d): ?>
                            <tr>
                                <td><?= h($d) ?></td>
                                <td class="text-end"><?= h(number_format((float) ($totalsByDealer[$d] ?? 0), 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="fw-semibold mb-2">Network-wise Payments / Payable (Range)</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Network</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Payable</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($networks as $n): ?>
                            <tr>
                                <td><?= h($n) ?></td>
                                <td class="text-end"><?= h(number_format((float) ($totalsByNetwork[$n] ?? 0), 2)) ?></td>
                                <td class="text-end"><?= h(number_format((float) ($remainingPayableByNetwork[$n] ?? 0), 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                    <th>Dealer</th>
                    <th>Network</th>
                    <th class="text-end">Amount</th>
                    <th>Notes</th>
                    <th>Created At</th>
                    <?php if ($canEditDelete): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['payment_date']) ?></td>
                        <td><?= h((string) $r['dealer_name']) ?></td>
                        <td><?= h((string) $r['network']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td><?= h((string) ($r['notes'] ?? '')) ?></td>
                        <td><?= h((string) $r['created_at']) ?></td>
                        <?php if ($canEditDelete): ?>
                            <td class="text-end">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('dealer-payments/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('dealer-payments/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (6 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-4">No payments found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
