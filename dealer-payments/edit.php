<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pdo = db();
app_require_owner_access();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid payment.');
    app_redirect('dealer-payments/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM dealer_payments WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    flash_set('error', 'Payment not found.');
    app_redirect('dealer-payments/index.php');
}

$networks = ['Jazz', 'Zong', 'Telenor', 'Ufone'];

$dealer = (string) $row['dealer_name'];
$network = (string) $row['network'];
$date = (string) $row['payment_date'];
$amount = (string) $row['amount'];
$notes = (string) ($row['notes'] ?? '');
$error = '';

$dealerMap = [];
try {
    $stmt = $pdo->query("SELECT dealer_name, network FROM dealers WHERE status = 'active' ORDER BY dealer_name ASC");
    foreach ($stmt->fetchAll() as $r) {
        $dn = trim((string) ($r['dealer_name'] ?? ''));
        $nw = trim((string) ($r['network'] ?? ''));
        if ($dn !== '' && in_array($nw, $networks, true)) {
            $dealerMap[$dn] = $nw;
        }
    }
} catch (Throwable $e) {
    $dealerMap = [];
}
if (!$dealerMap) {
    $dealerMap = [
        'Khalid' => 'Jazz',
        'Nouman' => 'Ufone',
        'Saifullah' => 'Telenor',
        'Imran' => 'Zong',
    ];
}
if ($dealer !== '' && !array_key_exists($dealer, $dealerMap) && in_array($network, $networks, true)) {
    $dealerMap[$dealer] = $network;
}
$dealerNames = array_keys($dealerMap);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dealer = trim((string) ($_POST['dealer_name'] ?? ''));
    $network = trim((string) ($_POST['network'] ?? ''));
    $date = trim((string) ($_POST['payment_date'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($dealer === '' || !in_array($dealer, $dealerNames, true)) {
        $error = 'Please select a dealer.';
    } elseif (($dealerMap[$dealer] ?? '') !== '' && $network !== (string) $dealerMap[$dealer]) {
        $network = (string) $dealerMap[$dealer];
    }
    if ($error === '' && ($network === '' || !in_array($network, $networks, true))) {
        $error = 'Please select a network.';
    } elseif ($date === '') {
        $error = 'Payment date is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $error = 'Amount must be a positive number.';
    } else {
        $before = $row;

        $stmt = $pdo->prepare("
            UPDATE dealer_payments
            SET dealer_name = :dealer_name,
                network = :network,
                payment_date = :payment_date,
                amount = :amount,
                notes = :notes
            WHERE id = :id
        ");
        $stmt->execute([
            ':dealer_name' => $dealer,
            ':network' => $network,
            ':payment_date' => $date,
            ':amount' => (float) $amount,
            ':notes' => $notes !== '' ? $notes : null,
            ':id' => $id,
        ]);

        $stmt = $pdo->prepare("SELECT * FROM dealer_payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $after = $stmt->fetch() ?: null;

        app_audit_log('dealer_payments', $id, 'edit', is_array($before) ? $before : null, is_array($after) ? $after : null);

        flash_set('success', 'Payment updated.');
        app_redirect('dealer-payments/index.php?dealer=' . urlencode($dealer) . '&from=' . urlencode(date('Y-m-01')) . '&to=' . urlencode(date('Y-m-d')));
    }
}

$pageTitle = 'Edit Dealer Payment - Shop Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Edit Dealer Payment</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('dealer-payments/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="dealer_name">Dealer</label>
                <select class="form-select" id="dealer_name" name="dealer_name" required>
                    <?php foreach ($dealerNames as $d): ?>
                        <option value="<?= h($d) ?>" <?= $dealer === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="network">Network</label>
                <select class="form-select" id="network" name="network" required>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $network === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="payment_date">Payment Date</label>
                <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($date) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label" for="notes">Notes</label>
                <input class="form-control" type="text" id="notes" name="notes" value="<?= h($notes) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-gradient shadow-glow">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const map = <?= json_encode($dealerMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const dealer = document.getElementById('dealer_name');
        const network = document.getElementById('network');
        if (!dealer || !network) return;

        dealer.addEventListener('change', () => {
            const d = dealer.value || '';
            const n = map[d] || '';
            if (n) network.value = n;
        });
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
