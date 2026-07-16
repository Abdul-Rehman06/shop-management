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
        SELECT COALESCE(SUM(
            CASE
                WHEN wt.type = 'opening' THEN wt.amount
                WHEN wt.type = 'receiving' AND wt.payment_status <> 'cancelled' THEN wt.amount
                WHEN wt.type = 'sending' AND wt.payment_status = 'completed' THEN wt.amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash'
          AND wt.date = :date
          AND wt.type = :type
          AND COALESCE(wt.entry_context, 'external') <> 'internal_transfer'
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
            WHERE txn_date = :d
              AND txn_type = 'payment'
              AND COALESCE(linked_wallet_txn_id, 0) = 0
              AND COALESCE(payment_method, 'cash') = 'cash'
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
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM dealer_payments
            WHERE payment_date = :d
              AND entry_type IN ('advance_payment', 'dealer_payment')
              AND linked_wallet_txn_id IS NULL
        ");
        $stmt->execute([':d' => $date]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function non_cash_wallet_sum(PDO $pdo, string $date, string $type): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN wt.type = 'receiving' AND wt.payment_status <> 'cancelled' THEN wt.amount
                WHEN wt.type = 'sending' AND wt.payment_status = 'completed' THEN wt.amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type IN ('easypaisa', 'jazzcash', 'bank')
          AND wt.date = :date
          AND wt.type = :type
          AND COALESCE(wt.entry_context, 'external') NOT IN ('internal_transfer', 'dealer_payment_online', 'bill_collection_online', 'bill_payment_online', 'udhar_recovery_online')
          AND (wt.remarks IS NULL OR wt.remarks NOT LIKE 'Dealer payment #%')
          AND (wt.remarks IS NULL OR wt.remarks NOT LIKE 'Bank Deposit #%' )
    ");
    $stmt->execute([':date' => $date, ':type' => $type]);
    return (float) $stmt->fetchColumn();
}

function wallet_commission_total(PDO $pdo, string $date): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN wt.type = 'opening' THEN 0
                WHEN wt.type = 'receiving' AND wt.payment_status <> 'cancelled' THEN wt.charges
                WHEN wt.type = 'sending' AND wt.payment_status = 'completed' THEN wt.charges
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions wt
        WHERE wt.date = :date
    ");
    $stmt->execute([':date' => $date]);
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
$commissionEarned = wallet_commission_total($pdo, $date);

$cashReceivedTotal = $walletCashReceived + $salesTotal + $loadSales + $udharRecovery + $creditAdvance + $nonCashSending;
$cashSentTotal = $walletCashSent + $expensesTotal + $dealerPayments + $nonCashReceiving;
$expectedCash = $openingCash + $cashReceivedTotal - $cashSentTotal;
$billPendingOverview = bill_current_overview($pdo);
$billTodaySummary = bill_summary($pdo, ['from' => $date, 'to' => $date]);
$billPaidToday = bill_paid_amount_by_date($pdo, $date, $date);
$actualShopCash = $expectedCash - (float) ($billPendingOverview['pending_amount'] ?? 0);

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

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Cash Management</h1>
        <div class="text-gray-500 text-sm">Expected cash vs actual cash (drawer reconciliation)</div>
    </div>
    <form method="get" class="d-flex gap-2 align-items-end bg-light p-2 rounded-4">
        <div>
            <label class="form-label mb-1 small fw-bold text-gray-600 px-1">Date</label>
            <input class="form-control border-0 bg-white shadow-sm" type="date" name="date" value="<?= h($date) ?>">
        </div>
        <button class="btn btn-gradient shadow-glow shadow-sm hover-lift px-4">Go</button>
    </form>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4 animate-slide-up stagger-1">
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-primary border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Opening Cash</div>
            <div class="h4 mb-0 font-bold text-primary"><?= h(number_format($openingCash, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-success border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Total Cash Received</div>
            <div class="h4 mb-0 font-bold text-success"><?= h(number_format($cashReceivedTotal, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-danger border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Total Cash Sent</div>
            <div class="h4 mb-0 font-bold text-danger"><?= h(number_format($cashSentTotal, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-success border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Commission Income</div>
            <div class="h4 mb-0 font-bold text-success"><?= h(number_format($commissionEarned, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-gradient-premium rounded-4 border-0 h-100 transition-all hover-lift text-white shadow">
            <div class="text-white-50 small fw-medium mb-1 text-uppercase tracking-wider">Expected Cash (Drawer)</div>
            <div class="h3 mb-0 font-bold"><?= h(number_format($expectedCash, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-warning border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Pending Bills Amount</div>
            <div class="h4 mb-0 font-bold text-warning"><?= h(number_format((float) ($billPendingOverview['pending_amount'] ?? 0), 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-info border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Actual Shop Cash</div>
            <div class="h4 mb-0 font-bold text-info"><?= h(number_format($actualShopCash, 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-success border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Today's Bill Commission</div>
            <div class="h4 mb-0 font-bold text-success"><?= h(number_format((float) ($billTodaySummary['service_charge'] ?? 0), 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-primary border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Pending Bills Count</div>
            <div class="h4 mb-0 font-bold text-primary"><?= h((string) (int) ($billPendingOverview['pending_count'] ?? 0)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="p-3 bg-light rounded-4 border-start border-secondary border-4 h-100 transition-all hover-lift">
            <div class="text-muted small fw-medium mb-1 text-uppercase tracking-wider">Paid Bills Amount</div>
            <div class="h4 mb-0 font-bold text-secondary"><?= h(number_format($billPaidToday, 2)) ?></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4 animate-slide-up stagger-2">
    <div class="col-12 col-lg-6">
        <div class="glass-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-4">
                    <div class="bg-primary bg-opacity-10 p-2 rounded-3 text-primary">
                        <i data-lucide="pie-chart" class="w-5 h-5"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-gray-800">Cash Breakdown <span class="text-muted fw-normal fs-6">(<?= h($date) ?>)</span></h5>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 custom-table border-0">
                        <tbody class="border-top-0">
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Sales</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-success">+ <?= h(number_format($salesTotal, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Load Sales</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-success">+ <?= h(number_format($loadSales, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Udhar Recovery</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-success">+ <?= h(number_format($udharRecovery, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Customer Advance (Credit)</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-success">+ <?= h(number_format($creditAdvance, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Cash Receiving (Manual)</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-success">+ <?= h(number_format($walletCashReceived, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Cash Received (Wallet Sending)</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-success">+ <?= h(number_format($nonCashSending, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light bg-danger bg-opacity-10">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Cash Sending (Manual)</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-danger">- <?= h(number_format($walletCashSent, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light bg-danger bg-opacity-10">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Cash Sent (Wallet Receiving)</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-danger">- <?= h(number_format($nonCashReceiving, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light bg-danger bg-opacity-10">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Expenses</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-danger">- <?= h(number_format($expensesTotal, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light bg-danger bg-opacity-10">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Dealer Payments</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-danger">- <?= h(number_format($dealerPayments, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Commission Income</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-success">+ <?= h(number_format($commissionEarned, 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Pending Bills (Liability)</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-warning">- <?= h(number_format((float) ($billPendingOverview['pending_amount'] ?? 0), 2)) ?></td>
                        </tr>
                        <tr class="transition-all hover-bg-light">
                            <td class="px-3 py-3 border-0 border-bottom text-gray-700">Actual Shop Cash</td>
                            <td class="px-3 py-3 border-0 border-bottom text-end font-medium text-info"><?= h(number_format($actualShopCash, 2)) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="glass-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-4">
                    <div class="bg-success bg-opacity-10 p-2 rounded-3 text-success">
                        <i data-lucide="plus-circle" class="w-5 h-5"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-gray-800">Add Cash Entry</h5>
                </div>
                
                <form method="post" class="row g-3 align-items-end mb-5 bg-light p-3 rounded-4 border-0">
                    <input type="hidden" name="action" value="add_cash_entry">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-gray-600" for="entry_date">Date</label>
                        <input class="form-control border-0 shadow-sm" type="date" id="entry_date" name="entry_date" value="<?= h($date) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-gray-600" for="entry_type">Type</label>
                        <select class="form-select border-0 shadow-sm" id="entry_type" name="entry_type" required>
                            <option value="receiving">Cash Received</option>
                            <option value="sending">Cash Sent</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-gray-600" for="entry_amount">Amount</label>
                        <input class="form-control border-0 shadow-sm" type="number" step="0.01" id="entry_amount" name="entry_amount" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-gray-600" for="entry_customer_id">Customer (Optional)</label>
                        <select class="form-select border-0 shadow-sm" id="entry_customer_id" name="entry_customer_id">
                            <option value="0">-- Select --</option>
                            <?php foreach ($savedCustomers as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-gray-600" for="entry_notes">Notes</label>
                        <input class="form-control border-0 shadow-sm" type="text" id="entry_notes" name="entry_notes">
                    </div>
                    <div class="col-12 text-end mt-4">
                        <button class="btn btn-gradient shadow-glow rounded-pill px-4 shadow-sm hover-lift d-inline-flex align-items-center gap-2">
                            <i data-lucide="save" class="w-4 h-4"></i> Save Entry
                        </button>
                    </div>
                </form>

                <hr class="text-gray-300 my-4">

                <div class="d-flex align-items-center gap-2 mb-4">
                    <div class="bg-warning bg-opacity-10 p-2 rounded-3 text-warning">
                        <i data-lucide="calculator" class="w-5 h-5"></i>
                    </div>
                    <h5 class="fw-bold mb-0 text-gray-800">Count Cash</h5>
                </div>
                
                <form method="post" class="row g-3 align-items-end bg-light p-3 rounded-4 border-0">
                    <input type="hidden" name="action" value="count_cash">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-gray-600" for="count_date">Date</label>
                        <input class="form-control border-0 shadow-sm" type="date" id="count_date" name="count_date" value="<?= h($date) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-gray-600" for="expected_cash">Expected Cash</label>
                        <input class="form-control border-0 shadow-sm bg-white" type="text" id="expected_cash" value="<?= h(number_format($expectedCash, 2)) ?>" disabled>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-gray-600" for="actual_cash">Actual Cash</label>
                        <input class="form-control border-0 shadow-sm border-warning" type="number" step="0.01" id="actual_cash" name="actual_cash" value="<?= h((string) ($todayCount['actual_cash'] ?? '')) ?>" required>
                    </div>
                    <div class="col-12 text-end mt-4">
                        <button class="btn btn-warning text-dark rounded-pill px-4 shadow-sm hover-lift d-inline-flex align-items-center gap-2 fw-bold">
                            <i data-lucide="check-circle-2" class="w-4 h-4"></i> Save Count
                        </button>
                    </div>
                </form>

                <?php if ($todayCount): ?>
                    <?php
                    $diff = (float) ($todayCount['difference'] ?? 0);
                    $diffLabel = $diff < 0 ? 'Shortage' : ($diff > 0 ? 'Excess' : 'Matched');
                    $diffColor = $diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-warning' : 'text-success');
                    ?>
                    <div class="row g-3 mt-3 bg-white border rounded-4 p-2 shadow-sm">
                        <div class="col-12 col-md-4 text-center border-end">
                            <div class="text-muted small text-uppercase tracking-wider mb-1">Expected</div>
                            <div class="fw-bold fs-5 text-gray-800"><?= h(number_format((float) $todayCount['expected_cash'], 2)) ?></div>
                        </div>
                        <div class="col-12 col-md-4 text-center border-end">
                            <div class="text-muted small text-uppercase tracking-wider mb-1">Actual</div>
                            <div class="fw-bold fs-5 text-primary"><?= h(number_format((float) $todayCount['actual_cash'], 2)) ?></div>
                        </div>
                        <div class="col-12 col-md-4 text-center">
                            <div class="text-muted small text-uppercase tracking-wider mb-1">Difference (<?= h($diffLabel) ?>)</div>
                            <div class="fw-bold fs-5 <?= $diffColor ?>"><?= h(number_format($diff, 2)) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
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
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Expected</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Actual</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Difference</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Created At</th>
                </tr>
                </thead>
                <tbody class="border-top-0">
                <?php foreach ($counts as $c): ?>
                    <?php 
                    $diff = (float) $c['difference'];
                    $diffColor = $diff < 0 ? 'text-danger' : ($diff > 0 ? 'text-warning' : 'text-success');
                    ?>
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= h((string) $c['count_date']) ?></td>
                        <td class="px-4 py-3 text-end text-gray-600"><?= h(number_format((float) $c['expected_cash'], 2)) ?></td>
                        <td class="px-4 py-3 text-end font-medium text-primary"><?= h(number_format((float) $c['actual_cash'], 2)) ?></td>
                        <td class="px-4 py-3 text-end fw-bold <?= $diffColor ?>"><?= h(number_format($diff, 2)) ?></td>
                        <td class="px-4 py-3 text-muted small"><?= h((string) $c['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$counts): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i data-lucide="clock" class="w-8 h-8 text-gray-300 mb-2"></i>
                                <p class="mb-0">No cash counts yet.</p>
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
