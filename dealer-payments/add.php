<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Dealer Payment - Shop Management';

$pdo = db();
$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);
$paymentSourceAccounts = $pdo->query("
    SELECT id, account_name, account_type
    FROM accounts
    WHERE status = 'active'
      AND account_type IN ('cash', 'easypaisa', 'jazzcash', 'bank')
    ORDER BY FIELD(account_type, 'cash', 'easypaisa', 'jazzcash', 'bank'), account_name ASC
")->fetchAll();

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
$paymentSourceAccountId = (int) ($_POST['payment_source_account_id'] ?? ($_GET['payment_source_account_id'] ?? 0));
$description = trim((string) ($_POST['description'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$error = '';

$syncDealerPaymentWallet = static function (PDO $pdo, int $dealerPaymentId, string $entryType, int $paymentSourceAccountId, string $date, string $dealer, string $network, float $amount, ?int $existingWalletTxnId = null): ?int {
    $needsWalletTxn = in_array($entryType, ['advance_payment', 'dealer_payment'], true) && $paymentSourceAccountId > 0 && $amount > 0;
    if (!$needsWalletTxn) {
        if (($existingWalletTxnId ?? 0) > 0) {
            $stmt = $pdo->prepare('DELETE FROM wallet_transactions WHERE id = :id');
            $stmt->execute([':id' => $existingWalletTxnId]);
        }
        return null;
    }

    $account = wallet_account($pdo, $paymentSourceAccountId);
    if (!$account) {
        throw new RuntimeException('Invalid payment source account.');
    }

    $payload = [
        ':account_id' => $paymentSourceAccountId,
        ':date' => $date,
        ':customer_name' => $dealer,
        ':number' => $network,
        ':transaction_id' => 'DEALER-' . $dealerPaymentId,
        ':txn_amount' => $amount,
        ':account_amount' => $amount,
        ':remarks' => 'Dealer payment #' . $dealerPaymentId . ' - ' . $dealer . ' (' . $network . ')',
    ];

    if (($existingWalletTxnId ?? 0) > 0) {
        $payload[':id'] = $existingWalletTxnId;
        $stmt = $pdo->prepare("
            UPDATE wallet_transactions
            SET account_id = :account_id,
                date = :date,
                customer_name = :customer_name,
                number = :number,
                transaction_id = :transaction_id,
                type = 'sending',
                amount = :txn_amount,
                charges = 0,
                commission_method = 'separate_cash',
                account_amount = :account_amount,
                payment_status = 'completed',
                completed_at = NOW(),
                remarks = :remarks
            WHERE id = :id
        ");
        $stmt->execute($payload);
        return $existingWalletTxnId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions
            (account_id, date, customer_name, number, transaction_id, type, amount, charges, commission_method, account_amount, payment_status, completed_at, remarks)
        VALUES
            (:account_id, :date, :customer_name, :number, :transaction_id, 'sending', :txn_amount, 0, 'separate_cash', :account_amount, 'completed', NOW(), :remarks)
    ");
    $stmt->execute($payload);
    return (int) $pdo->lastInsertId();
};

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
    $paymentSourceAccountId = (int) ($_POST['payment_source_account_id'] ?? 0);
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
    } elseif (in_array($entryType, ['advance_payment', 'dealer_payment'], true) && $paymentSourceAccountId <= 0) {
        $error = 'Please select payment source.';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO dealer_payments (dealer_name, network, payment_date, amount, notes, entry_type, description, payment_source_account_id, created_by)
                VALUES (:dealer_name, :network, :payment_date, :amount, :notes, :entry_type, :description, :payment_source_account_id, :created_by)
            ");
            $stmt->execute([
                ':dealer_name' => $dealer,
                ':network' => $network,
                ':payment_date' => $date,
                ':amount' => (float) $amount,
                ':notes' => $notes !== '' ? $notes : null,
                ':entry_type' => $entryType,
                ':description' => $description !== '' ? $description : null,
                ':payment_source_account_id' => $paymentSourceAccountId > 0 ? $paymentSourceAccountId : null,
                ':created_by' => $adminId > 0 ? $adminId : null,
            ]);
            $dealerPaymentId = (int) $pdo->lastInsertId();
            $walletTxnId = $syncDealerPaymentWallet($pdo, $dealerPaymentId, $entryType, $paymentSourceAccountId, $date, $dealer, $network, (float) $amount);

            if ($walletTxnId !== null || $paymentSourceAccountId > 0) {
                $stmt = $pdo->prepare("UPDATE dealer_payments SET payment_source_account_id = :payment_source_account_id, linked_wallet_txn_id = :linked_wallet_txn_id WHERE id = :id");
                $stmt->execute([
                    ':payment_source_account_id' => $paymentSourceAccountId > 0 ? $paymentSourceAccountId : null,
                    ':linked_wallet_txn_id' => $walletTxnId,
                    ':id' => $dealerPaymentId,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not save dealer entry.';
        }

        if ($error === '') {
            flash_set('success', 'Dealer entry added.');
            app_redirect('dealer-payments/index.php?dealer=' . urlencode($dealer) . '&from=' . urlencode(date('Y-m-01')) . '&to=' . urlencode(date('Y-m-d')));
        }
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
                <label class="form-label" for="payment_source_account_id">Payment Source</label>
                <select class="form-select" id="payment_source_account_id" name="payment_source_account_id">
                    <option value="0">No account deduction</option>
                    <?php foreach ($paymentSourceAccounts as $acc): ?>
                        <option value="<?= (int) ($acc['id'] ?? 0) ?>" <?= (int) ($acc['id'] ?? 0) === $paymentSourceAccountId ? 'selected' : '' ?>>
                            <?= h((string) ($acc['account_name'] ?? '')) ?> (<?= h((string) ($acc['account_type'] ?? '')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
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
