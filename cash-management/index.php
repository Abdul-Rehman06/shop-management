<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Cash Management - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');

$canEditDelete = app_can_edit_delete_records();
$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);

$date = trim((string) ($_GET['date'] ?? date('Y-m-d')));

$cashAccounts = wallet_accounts($pdo, 'cash', true);
$cashAccountId = (int) ($cashAccounts[0]['id'] ?? 0);
if ($cashAccountId <= 0) {
    $pdo->prepare("INSERT INTO accounts (account_name, account_type, account_number, status) VALUES ('Cash', 'cash', NULL, 'active')")->execute();
    $cashAccountId = (int) $pdo->lastInsertId();
    $cashAccounts = wallet_accounts($pdo, 'cash', true);
}

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

function cash_sum_wallet(PDO $pdo, string $date, string $type): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(wt.amount), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash'
          AND wt.date = :date
          AND wt.type = :type
    ");
    $stmt->execute([':date' => $date, ':type' => $type]);
    return (float) $stmt->fetchColumn();
}

function sales_total_amount(PDO $pdo, string $date): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity * sale_price), 0)
        FROM sales
        WHERE DATE(created_at) = :d
    ");
    $stmt->execute([':d' => $date]);
    return (float) $stmt->fetchColumn();
}

function load_sales_total(PDO $pdo, string $date): float
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM load_customer_transactions WHERE txn_date = :d");
        $stmt->execute([':d' => $date]);
        $hasCustomerRows = (int) $stmt->fetchColumn() > 0;

        if ($hasCustomerRows) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM load_customer_transactions WHERE txn_date = :d");
            $stmt->execute([':d' => $date]);
            return (float) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(sold_balance), 0) FROM load_entries WHERE date = :d");
        $stmt->execute([':d' => $date]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function udhar_recovery_total(PDO $pdo, string $date): float
{
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM udhar_transactions
            WHERE txn_date = :d AND txn_type = 'payment'
        ");
        $stmt->execute([':d' => $date]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function credit_advance_total(PDO $pdo, string $date): float
{
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM credit_transactions
            WHERE txn_date = :d AND txn_type = 'advance'
        ");
        $stmt->execute([':d' => $date]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function expenses_total(PDO $pdo, string $date): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date = :d");
    $stmt->execute([':d' => $date]);
    return (float) $stmt->fetchColumn();
}

function dealer_payments_total(PDO $pdo, string $date): float
{
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM dealer_payments WHERE payment_date = :d");
        $stmt->execute([':d' => $date]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function non_cash_wallet_sum(PDO $pdo, string $date, string $type): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(wt.amount), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type IN ('easypaisa', 'jazzcash', 'bank')
          AND wt.date = :date
          AND wt.type = :type
          AND (wt.remarks IS NULL OR wt.remarks NOT LIKE 'Bank Deposit #%' )
    ");
    $stmt->execute([':date' => $date, ':type' => $type]);
    return (float) $stmt->fetchColumn();
}

$openingCash = cash_sum_wallet($pdo, $date, 'opening');
$walletCashReceived = cash_sum_wallet($pdo, $date, 'receiving');
$walletCashSent = cash_sum_wallet($pdo, $date, 'sending');

$nonCashReceiving = non_cash_wallet_sum($pdo, $date, 'receiving');
$nonCashSending = non_cash_wallet_sum($pdo, $date, 'sending');

$salesTotal = sales_total_amount($pdo, $date);
$loadSales = load_sales_total($pdo, $date);
$udharRecovery = udhar_recovery_total($pdo, $date);
$creditAdvance = credit_advance_total($pdo, $date);
$expensesTotal = expenses_total($pdo, $date);
$dealerPayments = dealer_payments_total($pdo, $date);

$cashReceivedTotal = $walletCashReceived + $salesTotal + $loadSales + $udharRecovery + $creditAdvance + $nonCashSending;
$cashSentTotal = $walletCashSent + $expensesTotal + $dealerPayments + $nonCashReceiving;
$expectedCash = $openingCash + $cashReceivedTotal - $cashSentTotal;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'add_cash_entry') {
        $entryDate = trim((string) ($_POST['entry_date'] ?? $date));
        $entryType = trim((string) ($_POST['entry_type'] ?? 'receiving'));
        $entryAmount = trim((string) ($_POST['entry_amount'] ?? ''));
        $entryNotes = trim((string) ($_POST['entry_notes'] ?? ''));
        $entryCustomerId = (int) ($_POST['entry_customer_id'] ?? 0);

        if ($entryDate === '') {
            flash_set('error', 'Date is required.');
            app_redirect('cash-management/index.php?date=' . urlencode($date));
        }
        if (!in_array($entryType, ['receiving', 'sending'], true)) {
            flash_set('error', 'Invalid entry type.');
            app_redirect('cash-management/index.php?date=' . urlencode($entryDate));
        }
        if ($entryAmount === '' || !is_numeric($entryAmount) || (float) $entryAmount <= 0) {
            flash_set('error', 'Amount must be a positive number.');
            app_redirect('cash-management/index.php?date=' . urlencode($entryDate));
        }

        $prefix = $entryType === 'receiving' ? 'Cash Received' : 'Cash Sent';
        $custName = '';
        $custPhone = '';
        if ($entryCustomerId > 0) {
            foreach ($savedCustomers as $c) {
                if ((int) ($c['id'] ?? 0) === $entryCustomerId) {
                    $label = trim((string) ($c['name'] ?? ''));
                    $phone = trim((string) ($c['phone'] ?? ''));
                    $custName = $label;
                    $custPhone = $phone;
                    $prefix .= $label !== '' ? (' - ' . $label) : '';
                    $prefix .= $phone !== '' ? (' (' . $phone . ')') : '';
                    break;
                }
            }
        }
        $remarks = trim($prefix . ($entryNotes !== '' ? (' - ' . $entryNotes) : ''));

        $stmt = $pdo->prepare("
            INSERT INTO wallet_transactions (account_id, date, type, amount, charges, customer_name, number, remarks)
            VALUES (:account_id, :date, :type, :amount, 0, :customer_name, :number, :remarks)
        ");
        $stmt->execute([
            ':account_id' => $cashAccountId,
            ':date' => $entryDate,
            ':type' => $entryType,
            ':amount' => (float) $entryAmount,
            ':customer_name' => $custName !== '' ? $custName : null,
            ':number' => $custPhone !== '' ? $custPhone : null,
            ':remarks' => $remarks !== '' ? $remarks : null,
        ]);

        if ($custName !== '' && $custPhone !== '') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (name, phone)
                    VALUES (:name, :phone)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name)
                ");
                $stmt->execute([':name' => $custName, ':phone' => $custPhone]);
            } catch (Throwable $e) {
            }
        }

        flash_set('success', 'Cash entry saved.');
        app_redirect('cash-management/index.php?date=' . urlencode($entryDate));
    } elseif ($action === 'count_cash') {
        $countDate = trim((string) ($_POST['count_date'] ?? $date));
        $actual = trim((string) ($_POST['actual_cash'] ?? ''));
        if ($countDate === '') {
            flash_set('error', 'Date is required.');
            app_redirect('cash-management/index.php?date=' . urlencode($date));
        }
        if ($actual === '' || !is_numeric($actual)) {
            flash_set('error', 'Actual cash must be a number.');
            app_redirect('cash-management/index.php?date=' . urlencode($countDate));
        }

        $openingCash = cash_sum_wallet($pdo, $countDate, 'opening');
        $walletCashReceived = cash_sum_wallet($pdo, $countDate, 'receiving');
        $walletCashSent = cash_sum_wallet($pdo, $countDate, 'sending');
        $nonCashReceiving = non_cash_wallet_sum($pdo, $countDate, 'receiving');
        $nonCashSending = non_cash_wallet_sum($pdo, $countDate, 'sending');
        $salesTotal = sales_total_amount($pdo, $countDate);
        $loadSales = load_sales_total($pdo, $countDate);
        $udharRecovery = udhar_recovery_total($pdo, $countDate);
        $creditAdvance = credit_advance_total($pdo, $countDate);
        $expensesTotal = expenses_total($pdo, $countDate);
        $dealerPayments = dealer_payments_total($pdo, $countDate);

        $cashReceivedTotal = $walletCashReceived + $salesTotal + $loadSales + $udharRecovery + $creditAdvance + $nonCashSending;
        $cashSentTotal = $walletCashSent + $expensesTotal + $dealerPayments + $nonCashReceiving;
        $expectedCash = $openingCash + $cashReceivedTotal - $cashSentTotal;

        $actualCash = (float) $actual;
        $difference = $actualCash - $expectedCash;

        $stmt = $pdo->prepare("
            INSERT INTO cash_counts (count_date, expected_cash, actual_cash, difference, created_by)
            VALUES (:count_date, :expected_cash, :actual_cash, :difference, :created_by)
            ON DUPLICATE KEY UPDATE
                expected_cash = VALUES(expected_cash),
                actual_cash = VALUES(actual_cash),
                difference = VALUES(difference),
                created_by = VALUES(created_by)
        ");
        $stmt->execute([
            ':count_date' => $countDate,
            ':expected_cash' => $expectedCash,
            ':actual_cash' => $actualCash,
            ':difference' => $difference,
            ':created_by' => $adminId > 0 ? $adminId : null,
        ]);

        flash_set('success', 'Cash count saved.');
        app_redirect('cash-management/index.php?date=' . urlencode($countDate));
    }
}

$stmt = $pdo->prepare("
    SELECT id, count_date, expected_cash, actual_cash, difference, created_at
    FROM cash_counts
    WHERE count_date >= DATE_SUB(:d, INTERVAL 30 DAY)
    ORDER BY count_date DESC
    LIMIT 31
");
$stmt->execute([':d' => $date]);
$counts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM cash_counts WHERE count_date = :d LIMIT 1");
$stmt->execute([':d' => $date]);
$todayCount = $stmt->fetch() ?: null;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h4 mb-0">Cash Management</h1>
        <div class="text-muted small">Expected cash vs actual cash (drawer reconciliation)</div>
    </div>
    <form method="get" class="d-flex gap-2 align-items-end">
        <div>
            <label class="form-label mb-0">Date</label>
            <input class="form-control" type="date" name="date" value="<?= h($date) ?>">
        </div>
        <button class="btn btn-outline-primary">Go</button>
    </form>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Opening Cash</div>
                <div class="h5 mb-0"><?= h(number_format($openingCash, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Cash Received</div>
                <div class="h5 mb-0"><?= h(number_format($cashReceivedTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Cash Sent</div>
                <div class="h5 mb-0"><?= h(number_format($cashSentTotal, 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Expected Cash (Drawer)</div>
                <div class="h5 mb-0"><?= h(number_format($expectedCash, 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="fw-semibold mb-2">Cash Breakdown (<?= h($date) ?>)</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                        <tr>
                            <td>Sales</td>
                            <td class="text-end"><?= h(number_format($salesTotal, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Load Sales</td>
                            <td class="text-end"><?= h(number_format($loadSales, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Udhar Recovery</td>
                            <td class="text-end"><?= h(number_format($udharRecovery, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Customer Advance (Credit)</td>
                            <td class="text-end"><?= h(number_format($creditAdvance, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Cash Receiving (Manual)</td>
                            <td class="text-end"><?= h(number_format($walletCashReceived, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Cash Received (Wallet Sending)</td>
                            <td class="text-end"><?= h(number_format($nonCashSending, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Cash Sending (Manual)</td>
                            <td class="text-end"><?= h(number_format($walletCashSent, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Cash Sent (Wallet Receiving)</td>
                            <td class="text-end"><?= h(number_format($nonCashReceiving, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Expenses</td>
                            <td class="text-end"><?= h(number_format($expensesTotal, 2)) ?></td>
                        </tr>
                        <tr>
                            <td>Dealer Payments</td>
                            <td class="text-end"><?= h(number_format($dealerPayments, 2)) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="fw-semibold mb-2">Add Cash Entry</div>
                <form method="post" class="row g-3 align-items-end mb-4">
                    <input type="hidden" name="action" value="add_cash_entry">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="entry_date">Date</label>
                        <input class="form-control" type="date" id="entry_date" name="entry_date" value="<?= h($date) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="entry_type">Type</label>
                        <select class="form-select" id="entry_type" name="entry_type" required>
                            <option value="receiving">Cash Received</option>
                            <option value="sending">Cash Sent</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="entry_amount">Amount</label>
                        <input class="form-control" type="number" step="0.01" id="entry_amount" name="entry_amount" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="entry_customer_id">Customer (Optional)</label>
                        <select class="form-select" id="entry_customer_id" name="entry_customer_id">
                            <option value="0">-- Select --</option>
                            <?php foreach ($savedCustomers as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="entry_notes">Notes</label>
                        <input class="form-control" type="text" id="entry_notes" name="entry_notes">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary">Save Cash Entry</button>
                    </div>
                </form>

                <div class="fw-semibold mb-2">Count Cash</div>
                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="count_cash">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="count_date">Date</label>
                        <input class="form-control" type="date" id="count_date" name="count_date" value="<?= h($date) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="expected_cash">Expected Cash</label>
                        <input class="form-control" type="text" id="expected_cash" value="<?= h(number_format($expectedCash, 2)) ?>" disabled>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="actual_cash">Actual Cash</label>
                        <input class="form-control" type="number" step="0.01" id="actual_cash" name="actual_cash" value="<?= h((string) ($todayCount['actual_cash'] ?? '')) ?>" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">Save Count</button>
                    </div>
                </form>

                <?php if ($todayCount): ?>
                    <?php
                    $diff = (float) ($todayCount['difference'] ?? 0);
                    $diffLabel = $diff < 0 ? 'Shortage' : ($diff > 0 ? 'Excess' : 'Matched');
                    ?>
                    <hr>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="text-muted small">Expected</div>
                            <div class="fw-semibold"><?= h(number_format((float) $todayCount['expected_cash'], 2)) ?></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-muted small">Actual</div>
                            <div class="fw-semibold"><?= h(number_format((float) $todayCount['actual_cash'], 2)) ?></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-muted small">Difference (<?= h($diffLabel) ?>)</div>
                            <div class="fw-semibold"><?= h(number_format($diff, 2)) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
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
                    <th class="text-end">Expected</th>
                    <th class="text-end">Actual</th>
                    <th class="text-end">Difference</th>
                    <th>Created At</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($counts as $c): ?>
                    <tr>
                        <td><?= h((string) $c['count_date']) ?></td>
                        <td class="text-end"><?= h(number_format((float) $c['expected_cash'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $c['actual_cash'], 2)) ?></td>
                        <td class="text-end"><?= h(number_format((float) $c['difference'], 2)) ?></td>
                        <td><?= h((string) $c['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$counts): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No cash counts yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
