<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Dealer Payment - Shop Management';

$pdo = db();
$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);

$networks = ['Jazz', 'Zong', 'Telenor', 'Ufone'];
$entryTypes = [
    'advance_payment' => 'Advance Payment to Dealer',
    'load_received_against_advance' => 'Load Received Against Advance',
    'credit_load_received' => 'Credit Load Received',
    'dealer_payment' => 'Dealer Payment',
];

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
$dealerNames = array_keys($dealerMap);

$dealer = trim((string) ($_POST['dealer_name'] ?? ($_GET['dealer'] ?? '')));
$network = trim((string) ($_POST['network'] ?? ($_GET['network'] ?? ($dealer !== '' ? ($dealerMap[$dealer] ?? '') : ''))));
$date = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
$entryType = trim((string) ($_POST['entry_type'] ?? ($_GET['type'] ?? 'dealer_payment')));
$amount = trim((string) ($_POST['amount'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$error = '';

if ($dealer !== '' && !in_array($dealer, $dealerNames, true)) {
    $dealer = '';
}
if ($network !== '' && !in_array($network, $networks, true)) {
    $network = '';
}
if (!array_key_exists($entryType, $entryTypes)) {
    $entryType = 'dealer_payment';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dealer = trim((string) ($_POST['dealer_name'] ?? ''));
    $network = trim((string) ($_POST['network'] ?? ''));
    $date = trim((string) ($_POST['payment_date'] ?? ''));
    $entryType = trim((string) ($_POST['entry_type'] ?? 'dealer_payment'));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!array_key_exists($entryType, $entryTypes)) {
        $error = 'Please select a valid transaction type.';
    }
    if ($dealer === '' || !in_array($dealer, $dealerNames, true)) {
        $error = 'Please select a dealer.';
    } elseif (($dealerMap[$dealer] ?? '') !== '' && $network !== (string) $dealerMap[$dealer]) {
        $network = (string) $dealerMap[$dealer];
    }
    if ($error === '' && ($network === '' || !in_array($network, $networks, true))) {
        $error = 'Please select a network.';
    } elseif ($date === '') {
        $error = 'Date is required.';
    } elseif ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        $error = 'Amount must be a positive number.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO dealer_payments (dealer_name, network, payment_date, amount, notes, entry_type, description, created_by)
            VALUES (:dealer_name, :network, :payment_date, :amount, :notes, :entry_type, :description, :created_by)
        ");
        $stmt->execute([
            ':dealer_name' => $dealer,
            ':network' => $network,
            ':payment_date' => $date,
            ':amount' => (float) $amount,
            ':notes' => $notes !== '' ? $notes : null,
            ':entry_type' => $entryType,
            ':description' => $description !== '' ? $description : null,
            ':created_by' => $adminId > 0 ? $adminId : null,
        ]);

        flash_set('success', 'Dealer entry added.');
        app_redirect('dealer-payments/index.php?dealer=' . urlencode($dealer) . '&from=' . urlencode(date('Y-m-01')) . '&to=' . urlencode(date('Y-m-d')));
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Dealer Payment</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('dealer-payments/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label" for="entry_type">Transaction Type</label>
                <select class="form-select" id="entry_type" name="entry_type" required>
                    <?php foreach ($entryTypes as $k => $label): ?>
                        <option value="<?= h($k) ?>" <?= $entryType === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="dealer_name">Dealer</label>
                <select class="form-select" id="dealer_name" name="dealer_name" required>
                    <option value="">Select dealer</option>
                    <?php foreach ($dealerNames as $d): ?>
                        <option value="<?= h($d) ?>" <?= $dealer === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="network">Network</label>
                <select class="form-select" id="network" name="network" required>
                    <option value="">Select network</option>
                    <?php foreach ($networks as $n): ?>
                        <option value="<?= h($n) ?>" <?= $network === $n ? 'selected' : '' ?>><?= h($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="payment_date">Date</label>
                <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($date) ?>" required>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="amount">Amount</label>
                <input class="form-control" type="number" step="0.01" id="amount" name="amount" value="<?= h($amount) ?>" required>
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label" for="description">Description</label>
                <input class="form-control" type="text" id="description" name="description" value="<?= h($description) ?>" placeholder="Optional">
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
