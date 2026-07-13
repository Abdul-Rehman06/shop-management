<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

function money($value): string
{
    return number_format((float) $value, 2);
}

$pdo = db();
$canViewProfit = app_can_view_profit();
$admin = app_current_admin();

$range = trim((string) ($_GET['range'] ?? 'today'));
if (!in_array($range, ['today', '7days', 'month', 'custom'], true)) {
    $range = 'today';
}

$today = date('Y-m-d');
$fromDate = $today;
$toDate = $today;
if ($range === '7days') {
    $fromDate = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
    $toDate = $today;
} elseif ($range === 'month') {
    $fromDate = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $toDate = $today;
} elseif ($range === 'custom') {
    $fromRaw = trim((string) ($_GET['from'] ?? $today));
    $toRaw = trim((string) ($_GET['to'] ?? $today));
    $fromDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) === 1 ? $fromRaw : $today;
    $toDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) === 1 ? $toRaw : $today;
}
if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

$rangeLabel = $range === 'today' ? 'Today' : ($range === '7days' ? 'Last 7 Days' : ($range === 'month' ? 'This Month' : ($fromDate . ' to ' . $toDate)));

$networks = ['Jazz', 'Zong', 'Ufone', 'Telenor'];

$walletOpenForDate = function (PDO $pdo, int $accountId, string $date): float {
    $stmt = $pdo->prepare("
        SELECT amount
        FROM wallet_transactions
        WHERE account_id = ? AND date = ? AND type = 'opening'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$accountId, $date]);
    $manual = $stmt->fetchColumn();
    if ($manual !== false && $manual !== null) {
        return (float) $manual;
    }

    $stmt = $pdo->prepare("
        SELECT date, amount
        FROM wallet_transactions
        WHERE account_id = ? AND type = 'opening' AND date < ?
        ORDER BY date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$accountId, $date]);
    $baseline = $stmt->fetch();

    $baselineDate = is_array($baseline) ? (string) ($baseline['date'] ?? '') : '';
    $baselineAmount = is_array($baseline) ? (float) ($baseline['amount'] ?? 0) : 0.0;

    if ($baselineDate !== '') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN type = 'receiving' AND payment_status <> 'cancelled' THEN amount
                    WHEN type = 'sending' AND payment_status = 'completed' THEN -account_amount
                    ELSE 0
                END
            ), 0)
            FROM wallet_transactions
            WHERE account_id = ?
              AND date >= ?
              AND date < ?
              AND type IN ('receiving', 'sending')
        ");
        $stmt->execute([$accountId, $baselineDate, $date]);
        $net = (float) $stmt->fetchColumn();
        return $baselineAmount + $net;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN type = 'receiving' AND payment_status <> 'cancelled' THEN amount
                WHEN type = 'sending' AND payment_status = 'completed' THEN -account_amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions
        WHERE account_id = ?
          AND date < ?
          AND type IN ('receiving', 'sending')
    ");
    $stmt->execute([$accountId, $date]);
    return (float) $stmt->fetchColumn();
};

$walletAll = ['opening' => 0.0, 'receiving' => 0.0, 'sending' => 0.0, 'closing' => 0.0];
$rangeExpense = 0.0;
$rangeSales = 0.0;
$rangeProfit = 0.0;
$rangeDealerPayments = 0.0;
$rangeLoadSold = 0.0;
$rangeUdharRecovery = 0.0;
$rangeCreditAdvance = 0.0;
$rangeWalletCommission = 0.0;
$rangeWalletAccountDeduction = 0.0;
$rangePendingPayments = 0;
$rangePendingAmount = 0.0;
try {
    $stmt = $pdo->query("SELECT id FROM accounts WHERE status = 'active'");
    $accountIds = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $stmt->fetchAll());
    $accountIds = array_values(array_filter($accountIds, static fn (int $x): bool => $x > 0));

    foreach ($accountIds as $aid) {
        $opening = $walletOpenForDate($pdo, $aid, $fromDate);

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN type = 'receiving' AND payment_status <> 'cancelled' THEN amount ELSE 0 END), 0) AS receiving_total,
                COALESCE(SUM(CASE WHEN type = 'sending' AND payment_status = 'completed' THEN amount ELSE 0 END), 0) AS sending_total,
                COALESCE(SUM(CASE WHEN type = 'sending' AND payment_status = 'completed' THEN account_amount ELSE 0 END), 0) AS account_deduction_total,
                COALESCE(SUM(CASE WHEN type <> 'opening' AND payment_status <> 'cancelled' AND (type <> 'sending' OR payment_status = 'completed') THEN charges ELSE 0 END), 0) AS commission_total,
                COALESCE(SUM(CASE WHEN type = 'receiving' AND payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN type = 'receiving' AND payment_status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
            FROM wallet_transactions
            WHERE account_id = ?
              AND date >= ?
              AND date <= ?
              AND type IN ('receiving', 'sending')
        ");
        $stmt->execute([$aid, $fromDate, $toDate]);
        $r = $stmt->fetch() ?: [];
        $recv = (float) ($r['receiving_total'] ?? 0);
        $sent = (float) ($r['sending_total'] ?? 0);
        $accountDeduction = (float) ($r['account_deduction_total'] ?? 0);

        $walletAll['opening'] += $opening;
        $walletAll['receiving'] += $recv;
        $walletAll['sending'] += $sent;
        $walletAll['closing'] += ($opening + $recv - $accountDeduction);
        $rangeWalletCommission += (float) ($r['commission_total'] ?? 0);
        $rangeWalletAccountDeduction += $accountDeduction;
        $rangePendingPayments += (int) ($r['pending_count'] ?? 0);
        $rangePendingAmount += (float) ($r['pending_amount'] ?? 0);
    }
} catch (Throwable $e) {
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date >= :from AND date <= :to");
    $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
    $rangeExpense = (float) $stmt->fetchColumn();
} catch (Throwable $e) {
}

try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(s.quantity * s.sale_price), 0)
        FROM sales s
        WHERE s.created_at >= :from AND s.created_at <= :to
    ");
    $stmt->execute([':from' => $fromDate . ' 00:00:00', ':to' => $toDate . ' 23:59:59']);
    $rangeSales = (float) $stmt->fetchColumn();
} catch (Throwable $e) {
}

if ($canViewProfit) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE((SELECT SUM(profit) FROM sales WHERE created_at >= :from1 AND created_at <= :to1), 0)
                + COALESCE((SELECT SUM(profit_adjustment) FROM sales_returns WHERE return_date >= :from2 AND return_date <= :to2), 0)
        ");
        $stmt->execute([
            ':from1' => $fromDate . ' 00:00:00',
            ':to1' => $toDate . ' 23:59:59',
            ':from2' => $fromDate,
            ':to2' => $toDate,
        ]);
        $rangeProfit = (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM dealer_payments
        WHERE payment_date >= :from AND payment_date <= :to
          AND entry_type IN ('advance_payment', 'dealer_payment')
    ");
    $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
    $rangeDealerPayments = (float) $stmt->fetchColumn();
} catch (Throwable $e) {
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM load_customer_transactions WHERE txn_date >= :from AND txn_date <= :to");
    $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
    $hasLoadTxn = (int) $stmt->fetchColumn() > 0;
    if ($hasLoadTxn) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM load_customer_transactions WHERE txn_date >= :from AND txn_date <= :to");
        $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
        $rangeLoadSold = (float) $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(sold_balance), 0) FROM load_entries WHERE date >= :from AND date <= :to");
        $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
        $rangeLoadSold = (float) $stmt->fetchColumn();
    }
} catch (Throwable $e) {
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM udhar_transactions WHERE txn_date >= :from AND txn_date <= :to AND txn_type = 'payment'");
    $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
    $rangeUdharRecovery = (float) $stmt->fetchColumn();
} catch (Throwable $e) {
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE txn_date >= :from AND txn_date <= :to AND txn_type = 'advance'");
    $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
    $rangeCreditAdvance = (float) $stmt->fetchColumn();
} catch (Throwable $e) {
}

$loadBalances = array_fill_keys($networks, 0.0);
$pdo->exec("
    CREATE TABLE IF NOT EXISTS load_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        network VARCHAR(50) NOT NULL,
        date DATE NOT NULL,
        opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        purchased_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        sold_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        profit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        closing_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_load_entries_date_network (date, network),
        KEY idx_load_entries_date (date),
        KEY idx_load_entries_network (network)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$stmt = $pdo->query("
    SELECT e.network, e.closing_balance
    FROM load_entries e
    WHERE e.date = (
        SELECT MAX(e2.date)
        FROM load_entries e2
        WHERE e2.network = e.network
    )
");
foreach ($stmt->fetchAll() as $row) {
    $network = (string) ($row['network'] ?? '');
    if ($network !== '' && array_key_exists($network, $loadBalances)) {
        $loadBalances[$network] = (float) $row['closing_balance'];
    }
}
$loadTotalBalance = array_sum($loadBalances);

$loadLatestDate = (string) ($pdo->query("SELECT MAX(date) FROM load_entries")->fetchColumn() ?: '');
$loadTotalProfit = 0.0;
if ($canViewProfit && $loadLatestDate !== '') {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(profit),0) FROM load_entries WHERE date = :date");
    $stmt->execute([':date' => $loadLatestDate]);
    $loadTotalProfit = (float) $stmt->fetchColumn();
}

$easypaisaNet = (float) $pdo->query("
    SELECT COALESCE(SUM(
        CASE
            WHEN wt.type IN ('opening', 'receiving') THEN wt.amount
            WHEN wt.type = 'sending' THEN -wt.account_amount
            ELSE 0
        END
    ), 0)
    FROM wallet_transactions wt
    JOIN accounts a ON a.id = wt.account_id
    WHERE a.account_type = 'easypaisa'
      AND (wt.payment_status <> 'cancelled' OR wt.type = 'opening')
      AND (wt.type <> 'sending' OR wt.payment_status = 'completed')
")->fetchColumn();

$jazzcashNet = (float) $pdo->query("
    SELECT COALESCE(SUM(
        CASE
            WHEN wt.type IN ('opening', 'receiving') THEN wt.amount
            WHEN wt.type = 'sending' THEN -wt.account_amount
            ELSE 0
        END
    ), 0)
    FROM wallet_transactions wt
    JOIN accounts a ON a.id = wt.account_id
    WHERE a.account_type = 'jazzcash'
      AND (wt.payment_status <> 'cancelled' OR wt.type = 'opening')
      AND (wt.type <> 'sending' OR wt.payment_status = 'completed')
")->fetchColumn();

$bankNet = (float) $pdo->query("
    SELECT COALESCE(SUM(
        CASE
            WHEN wt.type IN ('opening', 'receiving') THEN wt.amount
            WHEN wt.type = 'sending' THEN -wt.account_amount
            ELSE 0
        END
    ), 0)
    FROM wallet_transactions wt
    JOIN accounts a ON a.id = wt.account_id
    WHERE a.account_type = 'bank'
      AND (wt.payment_status <> 'cancelled' OR wt.type = 'opening')
      AND (wt.type <> 'sending' OR wt.payment_status = 'completed')
")->fetchColumn();

$todayExpense = (float) $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE date = CURDATE()
")->fetchColumn();

$monthlyExpense = (float) $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND date <= LAST_DAY(CURDATE())
")->fetchColumn();

$creditAdvanceTotal = 0.0;
$creditUsedTotal = 0.0;
$creditRemainingTotal = 0.0;
try {
    $stmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN txn_type = 'advance' THEN amount ELSE 0 END), 0) AS adv_total,
            COALESCE(SUM(CASE WHEN txn_type = 'used' THEN amount ELSE 0 END), 0) AS used_total
        FROM credit_transactions
    ");
    $row = $stmt->fetch() ?: [];
    $creditAdvanceTotal = (float) ($row['adv_total'] ?? 0);
    $creditUsedTotal = (float) ($row['used_total'] ?? 0);
    $creditRemainingTotal = $creditAdvanceTotal - $creditUsedTotal;
} catch (Throwable $e) {
}

$dealerPaymentsMonthTotal = 0.0;
$dealerPaymentsTodayTotal = 0.0;
$dealerPaymentsByNetwork = ['Jazz' => 0.0, 'Zong' => 0.0, 'Telenor' => 0.0, 'Ufone' => 0.0];
$dealerRemainingPayableMonth = 0.0;
try {
    $dealerPaymentsTodayTotal = (float) $pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM dealer_payments
        WHERE payment_date = CURDATE()
          AND entry_type IN ('advance_payment', 'dealer_payment')
    ")->fetchColumn();
    $dealerPaymentsMonthTotal = (float) $pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM dealer_payments
        WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND payment_date <= LAST_DAY(CURDATE())
          AND entry_type IN ('advance_payment', 'dealer_payment')
    ")->fetchColumn();

    $stmt = $pdo->query("
        SELECT network, COALESCE(SUM(amount), 0) AS total
        FROM dealer_payments
        WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND payment_date <= LAST_DAY(CURDATE())
          AND entry_type IN ('advance_payment', 'dealer_payment')
        GROUP BY network
    ");
    foreach ($stmt->fetchAll() as $r) {
        $n = (string) ($r['network'] ?? '');
        if ($n !== '' && array_key_exists($n, $dealerPaymentsByNetwork)) {
            $dealerPaymentsByNetwork[$n] = (float) ($r['total'] ?? 0);
        }
    }

    $purchasedByNetwork = ['Jazz' => 0.0, 'Zong' => 0.0, 'Telenor' => 0.0, 'Ufone' => 0.0];
    $stmt = $pdo->query("
        SELECT network, COALESCE(SUM(purchased_balance), 0) AS total
        FROM load_entries
        WHERE date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND date <= LAST_DAY(CURDATE())
        GROUP BY network
    ");
    foreach ($stmt->fetchAll() as $r) {
        $n = (string) ($r['network'] ?? '');
        if ($n !== '' && array_key_exists($n, $purchasedByNetwork)) {
            $purchasedByNetwork[$n] = (float) ($r['total'] ?? 0);
        }
    }

    foreach ($purchasedByNetwork as $n => $purchased) {
        $dealerRemainingPayableMonth += (float) $purchased - (float) ($dealerPaymentsByNetwork[$n] ?? 0);
    }
} catch (Throwable $e) {
}

$dealerAdvanceOutstanding = 0.0;
$dealerCreditOutstanding = 0.0;
$dealerOutstandingTotal = 0.0;
$dealerWiseSummary = [];
try {
    $stmt = $pdo->query("
        SELECT
            dealer_name,
            network,
            COALESCE(SUM(CASE WHEN entry_type = 'advance_payment' THEN amount ELSE 0 END), 0) AS adv_total,
            COALESCE(SUM(CASE WHEN entry_type = 'dealer_payment' THEN amount ELSE 0 END), 0) AS pay_total,
            COALESCE(SUM(CASE WHEN entry_type = 'load_received_against_advance' THEN amount ELSE 0 END), 0) AS load_total,
            COALESCE(SUM(CASE WHEN entry_type = 'credit_load_received' THEN amount ELSE 0 END), 0) AS credit_total
        FROM dealer_payments
        GROUP BY dealer_name, network
        ORDER BY network ASC, dealer_name ASC
    ");
    foreach ($stmt->fetchAll() as $r) {
        $adv = (float) ($r['adv_total'] ?? 0);
        $pay = (float) ($r['pay_total'] ?? 0);
        $load = (float) ($r['load_total'] ?? 0);
        $credit = (float) ($r['credit_total'] ?? 0);
        $bal = ($adv + $pay) - $load - $credit;
        if ($bal >= 0) {
            $dealerAdvanceOutstanding += $bal;
        } else {
            $dealerCreditOutstanding += abs($bal);
        }
        $dealerOutstandingTotal += abs($bal);
        $dealerWiseSummary[] = [
            'dealer_name' => (string) ($r['dealer_name'] ?? ''),
            'network' => (string) ($r['network'] ?? ''),
            'balance' => $bal,
        ];
    }
} catch (Throwable $e) {
}

$dealerWiseSummaryTop = $dealerWiseSummary;
usort($dealerWiseSummaryTop, static function (array $a, array $b): int {
    $ab = abs((float) ($a['balance'] ?? 0));
    $bb = abs((float) ($b['balance'] ?? 0));
    return $bb <=> $ab;
});
$dealerWiseSummaryTop = array_slice($dealerWiseSummaryTop, 0, 8);

$cashOpeningToday = 0.0;
$cashReceivedToday = 0.0;
$cashSentToday = 0.0;
$cashExpectedToday = 0.0;
$cashCountToday = null;
$cashCommissionToday = 0.0;
$billPendingCurrent = ['pending_amount' => 0.0, 'pending_count' => 0];
$billTodaySummary = ['service_charge' => 0.0];
$billPaidToday = 0.0;
$actualShopCashToday = 0.0;
try {
    $cashOpeningToday = (float) $pdo->query("
        SELECT COALESCE(SUM(wt.amount), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash'
          AND wt.date = CURDATE()
          AND wt.type = 'opening'
    ")->fetchColumn();
    $walletCashReceiving = (float) $pdo->query("
        SELECT COALESCE(SUM(
            CASE
                WHEN wt.payment_status <> 'cancelled' THEN wt.amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash'
          AND wt.date = CURDATE()
          AND wt.type = 'receiving'
    ")->fetchColumn();
    $walletCashSending = (float) $pdo->query("
        SELECT COALESCE(SUM(
            CASE
                WHEN wt.payment_status = 'completed' THEN wt.amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash'
          AND wt.date = CURDATE()
          AND wt.type = 'sending'
    ")->fetchColumn();
    $cashCommissionToday = (float) $pdo->query("
        SELECT COALESCE(SUM(
            CASE
                WHEN wt.type = 'receiving' AND wt.payment_status <> 'cancelled' THEN wt.charges
                WHEN wt.type = 'sending' AND wt.payment_status = 'completed' THEN wt.charges
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions wt
        WHERE wt.date = CURDATE()
    ")->fetchColumn();
    $salesCash = (float) $pdo->query("
        SELECT COALESCE(SUM(quantity * sale_price), 0)
        FROM sales
        WHERE DATE(created_at) = CURDATE()
    ")->fetchColumn();
    $loadSalesCash = (float) $pdo->query("
        SELECT COALESCE(SUM(sold_balance), 0)
        FROM load_entries
        WHERE date = CURDATE()
    ")->fetchColumn();
    $udharRecoveryCash = (float) $pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM udhar_transactions
        WHERE txn_date = CURDATE() AND txn_type = 'payment'
    ")->fetchColumn();
    $creditAdvanceCash = (float) $pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM credit_transactions
        WHERE txn_date = CURDATE() AND txn_type = 'advance'
    ")->fetchColumn();

    $cashReceivedToday = $walletCashReceiving + $salesCash + $loadSalesCash + $udharRecoveryCash + $creditAdvanceCash;
    $cashSentToday = $walletCashSending + $todayExpense + $dealerPaymentsTodayTotal;
    $cashExpectedToday = $cashOpeningToday + $cashReceivedToday - $cashSentToday;
    $billPendingCurrent = bill_current_overview($pdo);
    $billTodaySummary = bill_summary($pdo, ['from' => $today, 'to' => $today]);
    $billPaidToday = bill_paid_amount_by_date($pdo, $today, $today);
    $actualShopCashToday = $cashExpectedToday - (float) ($billPendingCurrent['pending_amount'] ?? 0);

    $stmt = $pdo->query("SELECT * FROM cash_counts WHERE count_date = CURDATE() LIMIT 1");
    $cashCountToday = $stmt->fetch() ?: null;
} catch (Throwable $e) {
}

$todayProfit = 0.0;
$monthlyProfit = 0.0;
if ($canViewProfit) {
    $todayProfit = (float) $pdo->query("
        SELECT
            COALESCE((SELECT SUM(profit) FROM sales WHERE DATE(created_at) = CURDATE()), 0)
            + COALESCE((SELECT SUM(profit_adjustment) FROM sales_returns WHERE return_date = CURDATE()), 0)
    ")->fetchColumn();

    $monthlyProfit = (float) $pdo->query("
        SELECT
            COALESCE((
                SELECT SUM(profit)
                FROM sales
                WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00')
                  AND created_at <= CONCAT(LAST_DAY(CURDATE()), ' 23:59:59')
            ), 0)
            + COALESCE((
                SELECT SUM(profit_adjustment)
                FROM sales_returns
                WHERE return_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                  AND return_date <= LAST_DAY(CURDATE())
            ), 0)
    ")->fetchColumn();
}

$dailySalesData = [];
$stmt = $pdo->query("
    SELECT DATE(created_at) AS d, COALESCE(SUM(quantity * sale_price), 0) AS total
    FROM sales
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC
");
foreach ($stmt->fetchAll() as $row) {
    $dailySalesData[(string) $row['d']] = (float) $row['total'];
}

$dailySalesLabels = [];
$dailySalesTotals = [];
for ($i = 6; $i >= 0; $i--) {
    $d = (new DateTimeImmutable('today'))->modify("-{$i} days")->format('Y-m-d');
    $dailySalesLabels[] = $d;
    $dailySalesTotals[] = $dailySalesData[$d] ?? 0.0;
}

$monthlyProfitData = [];
if ($canViewProfit) {
    $stmt = $pdo->query("
        SELECT m, SUM(total) AS total
        FROM (
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COALESCE(SUM(profit), 0) AS total
            FROM sales
            WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            UNION ALL
            SELECT DATE_FORMAT(return_date, '%Y-%m') AS m, COALESCE(SUM(profit_adjustment), 0) AS total
            FROM sales_returns
            WHERE return_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
            GROUP BY DATE_FORMAT(return_date, '%Y-%m')
        ) x
        GROUP BY m
        ORDER BY m ASC
    ");
    foreach ($stmt->fetchAll() as $row) {
        $monthlyProfitData[(string) $row['m']] = (float) $row['total'];
    }
}

$monthlyProfitLabels = [];
$monthlyProfitTotals = [];
$monthCursor = new DateTimeImmutable(date('Y-m-01'));
$monthCursor = $monthCursor->modify('-11 months');
for ($i = 0; $i < 12; $i++) {
    $m = $monthCursor->format('Y-m');
    $monthlyProfitLabels[] = $m;
    $monthlyProfitTotals[] = $monthlyProfitData[$m] ?? 0.0;
    $monthCursor = $monthCursor->modify('+1 month');
}

$pageTitle = 'Dashboard - Shop Management';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>';

?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="mb-8 animate-slide-up stagger-1">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 glass-card p-8 rounded-3xl relative overflow-hidden group">
        <div class="absolute right-0 top-0 w-96 h-96 bg-gradient-premium rounded-full blur-[80px] opacity-10 group-hover:opacity-20 transition-opacity duration-700 -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute left-0 bottom-0 w-64 h-64 bg-pink-500 rounded-full blur-[80px] opacity-10 group-hover:opacity-20 transition-opacity duration-700 translate-y-1/3 -translate-x-1/3"></div>
        
        <div class="relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-50 text-brand-600 text-xs font-bold uppercase tracking-wider mb-3">
                <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span> Live Overview
            </div>
            <h1 class="page-header-title mb-2">
                Welcome back, <?= h((string) ($admin['name'] ?? 'Admin')) ?> 👋
            </h1>
            <p class="text-gray-500 text-lg">Reports view: <span class="font-bold text-transparent bg-clip-text bg-gradient-premium"><?= h($rangeLabel) ?></span> <span class="text-gray-400 text-sm">(<?= h($fromDate) ?> to <?= h($toDate) ?>)</span></p>
        </div>
        
        <div class="flex flex-col gap-4 relative z-10">
            <div class="flex flex-wrap gap-3 justify-end">
                <?php if ($canViewProfit): ?>
                    <a href="<?= h(app_url('load-management/index.php')) ?>" class="btn btn-outline-primary bg-white/50 backdrop-blur-sm hover:bg-brand-50">
                        <i data-lucide="smartphone" class="w-4 h-4"></i> Load Entry
                    </a>
                <?php endif; ?>
                <a href="<?= h(app_url('expenses/add.php')) ?>" class="btn btn-outline-danger bg-white/50 backdrop-blur-sm hover:bg-red-50">
                    <i data-lucide="receipt" class="w-4 h-4"></i> Add Expense
                </a>
                <a href="<?= h(app_url('sales/add.php')) ?>" class="btn btn-gradient shadow-glow">
                    <i data-lucide="shopping-cart" class="w-4 h-4"></i> New Sale
                </a>
            </div>

            <form method="get" class="flex flex-wrap gap-3 items-end bg-white/40 p-3 rounded-2xl border border-white/50 backdrop-blur-md shadow-sm">
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1 block">Range</label>
                    <select class="form-select form-select-sm border-0 bg-white/80 shadow-sm rounded-xl" name="range">
                        <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="7days" <?= $range === '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                        <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This Month</option>
                        <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
                    </select>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1 block">From</label>
                    <input class="form-control form-control-sm border-0 bg-white/80 shadow-sm rounded-xl" type="date" name="from" value="<?= h($fromDate) ?>">
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-wider text-gray-500 mb-1 block">To</label>
                    <input class="form-control form-control-sm border-0 bg-white/80 shadow-sm rounded-xl" type="date" name="to" value="<?= h($toDate) ?>">
                </div>
                <div>
                    <button class="btn btn-outline-secondary btn-sm bg-white/80 border-0 shadow-sm rounded-xl hover:bg-gray-100">Apply Filter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="mb-8 animate-slide-up stagger-2">
    <div class="glass-card rounded-3xl p-6">
        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-5">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-gradient-premium rounded-xl text-white shadow-sm">
                    <i data-lucide="pie-chart" class="w-5 h-5"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-900 m-0">Reports Summary <span class="text-sm font-normal text-gray-500 bg-gray-100 px-2 py-1 rounded-lg ml-2"><?= h($rangeLabel) ?></span></h2>
            </div>
            <a class="btn btn-outline-primary btn-sm rounded-xl bg-brand-50 border-0" href="<?= h(app_url('reports/index.php?module=all&range=' . urlencode($range) . '&from=' . urlencode($fromDate) . '&to=' . urlencode($toDate))) ?>">
                View Detailed Reports <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </a>
        </div>

        <div class="row g-4">
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="shopping-bag" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Sales</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeSales) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-red-100 text-red-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="receipt" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Expenses</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeExpense) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="smartphone" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Load Sold</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeLoadSold) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="users" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Dealer Payments</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeDealerPayments) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="hand-coins" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Udhar Recovery</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeUdharRecovery) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="circle-dollar-sign" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Credit Advance</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeCreditAdvance) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="badge-dollar-sign" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Commission Earned</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeWalletCommission) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="arrow-up-from-line" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Cash Withdrawals</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money((float) ($walletAll['sending'] ?? 0)) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="landmark" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Account Deductions</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900">Rs <?= money($rangeWalletAccountDeduction) ?></div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="bg-white/60 backdrop-blur-sm border border-white rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-8 h-8 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <i data-lucide="hourglass" class="w-4 h-4"></i>
                        </div>
                        <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Pending Payments</div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900"><?= h((string) $rangePendingPayments) ?></div>
                    <div class="text-sm text-gray-500 mt-1">Rs <?= money($rangePendingAmount) ?></div>
                </div>
            </div>
            <?php if ($canViewProfit): ?>
                <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                    <div class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-100 rounded-2xl p-4 h-100 shadow-sm hover:shadow-md transition-shadow group">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-full bg-emerald-500 text-white flex items-center justify-center group-hover:scale-110 transition-transform shadow-sm">
                                <i data-lucide="trending-up" class="w-4 h-4"></i>
                            </div>
                            <div class="text-sm font-bold text-emerald-700 uppercase tracking-wider">Total Profit</div>
                        </div>
                        <div class="text-2xl font-bold text-emerald-700">Rs <?= money($rangeProfit) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="flex items-center gap-3 mb-6 animate-slide-up stagger-3">
    <div class="h-8 w-1.5 bg-gradient-premium rounded-full"></div>
    <h2 class="text-2xl font-bold text-gray-900 m-0">Wallets Overview</h2>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10 animate-slide-up stagger-3">
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-gray-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Total Opening</div>
                <div class="p-2.5 bg-gray-100 text-gray-700 rounded-xl group-hover:bg-gray-200 transition-colors"><i data-lucide="layers" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money((float) ($walletAll['opening'] ?? 0)) ?></div>
        </div>
    </div>

    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Total Receiving</div>
                <div class="p-2.5 bg-emerald-100 text-emerald-600 rounded-xl group-hover:bg-emerald-200 transition-colors"><i data-lucide="arrow-down-left" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money((float) ($walletAll['receiving'] ?? 0)) ?></div>
        </div>
    </div>

    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Cash Withdrawals</div>
                <div class="p-2.5 bg-red-100 text-red-600 rounded-xl group-hover:bg-red-200 transition-colors"><i data-lucide="arrow-up-right" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money((float) ($walletAll['sending'] ?? 0)) ?></div>
        </div>
    </div>

    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-brand-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Account Balance</div>
                <div class="p-2.5 bg-brand-100 text-brand-600 rounded-xl group-hover:bg-brand-200 transition-colors"><i data-lucide="wallet" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight text-transparent bg-clip-text bg-gradient-premium">Rs <?= money((float) ($walletAll['closing'] ?? 0)) ?></div>
        </div>
    </div>
</div>

<div class="flex items-center gap-3 mb-6 animate-slide-up stagger-4">
    <div class="h-8 w-1.5 bg-gradient-premium rounded-full"></div>
    <h2 class="text-2xl font-bold text-gray-900 m-0">Business Metrics</h2>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10 animate-slide-up stagger-4">
    <!-- Load Summary -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-blue-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Load Summary</div>
                <div class="p-2.5 bg-blue-100 text-blue-600 rounded-xl group-hover:bg-blue-200 transition-colors"><i data-lucide="smartphone" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($loadTotalBalance) ?></div>
        </div>
    </div>

    <!-- Load Profit -->
    <?php if ($canViewProfit): ?>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-green-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Load Profit</div>
                    <div class="p-2.5 bg-green-100 text-green-600 rounded-xl group-hover:bg-green-200 transition-colors"><i data-lucide="trending-up" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($loadTotalProfit) ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Jazz Balance -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Jazz Balance</div>
                <div class="p-2.5 bg-red-100 text-red-600 rounded-xl group-hover:bg-red-200 transition-colors"><i data-lucide="signal" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Jazz'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Zong Balance -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-green-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Zong Balance</div>
                <div class="p-2.5 bg-green-100 text-green-600 rounded-xl group-hover:bg-green-200 transition-colors"><i data-lucide="radio-tower" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Zong'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Ufone Balance -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-orange-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Ufone Balance</div>
                <div class="p-2.5 bg-orange-100 text-orange-500 rounded-xl group-hover:bg-orange-200 transition-colors"><i data-lucide="wifi" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Ufone'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Telenor Balance -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-cyan-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Telenor Balance</div>
                <div class="p-2.5 bg-cyan-100 text-cyan-600 rounded-xl group-hover:bg-cyan-200 transition-colors"><i data-lucide="rss" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Telenor'] ?? 0) ?></div>
        </div>
    </div>

    <!-- EasyPaisa Total -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">EasyPaisa</div>
                <div class="p-2.5 bg-emerald-100 text-emerald-600 rounded-xl group-hover:bg-emerald-200 transition-colors"><i data-lucide="wallet" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($easypaisaNet) ?></div>
        </div>
    </div>

    <!-- JazzCash Total -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-rose-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">JazzCash</div>
                <div class="p-2.5 bg-rose-100 text-rose-600 rounded-xl group-hover:bg-rose-200 transition-colors"><i data-lucide="circle-dollar-sign" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($jazzcashNet) ?></div>
        </div>
    </div>

    <!-- Bank Transfer Total -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-indigo-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Bank Transfer</div>
                <div class="p-2.5 bg-indigo-100 text-indigo-600 rounded-xl group-hover:bg-indigo-200 transition-colors"><i data-lucide="building-2" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($bankNet) ?></div>
        </div>
    </div>

    <!-- Today Expense -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Today's Expense</div>
                <div class="p-2.5 bg-red-100 text-red-600 rounded-xl group-hover:bg-red-200 transition-colors"><i data-lucide="trending-down" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-danger tracking-tight">Rs <?= money($todayExpense) ?></div>
        </div>
    </div>

    <!-- Monthly Expense -->
    <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
        <div class="absolute -right-6 -top-6 w-32 h-32 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-6">
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Monthly Expense</div>
                <div class="p-2.5 bg-red-100 text-red-600 rounded-xl group-hover:bg-red-200 transition-colors"><i data-lucide="calendar-x" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-extrabold text-danger tracking-tight">Rs <?= money($monthlyExpense) ?></div>
        </div>
    </div>

    <!-- Today Profit -->
    <?php if ($canViewProfit): ?>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-green-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Today's Profit</div>
                    <div class="p-2.5 bg-green-100 text-green-600 rounded-xl group-hover:bg-green-200 transition-colors"><i data-lucide="trending-up" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-success tracking-tight">Rs <?= money($todayProfit) ?></div>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-green-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Monthly Profit</div>
                    <div class="p-2.5 bg-green-100 text-green-600 rounded-xl group-hover:bg-green-200 transition-colors"><i data-lucide="calendar-check" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-success tracking-tight">Rs <?= money($monthlyProfit) ?></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (app_is_owner()): ?>
    <div class="flex items-center gap-3 mb-6 animate-slide-up stagger-5">
        <div class="h-8 w-1.5 bg-gradient-premium rounded-full"></div>
        <h2 class="text-2xl font-bold text-gray-900 m-0">Owner Dashboard</h2>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10 animate-slide-up stagger-5">
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-brand-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Total Credit</div>
                    <div class="p-2.5 bg-brand-100 text-brand-600 rounded-xl group-hover:bg-brand-200 transition-colors"><i data-lucide="circle-dollar-sign" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($creditAdvanceTotal) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Advance received</div>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-gray-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Used Credit</div>
                    <div class="p-2.5 bg-gray-100 text-gray-700 rounded-xl group-hover:bg-gray-200 transition-colors"><i data-lucide="minus-circle" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($creditUsedTotal) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Credit used</div>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Remaining Credit</div>
                    <div class="p-2.5 bg-emerald-100 text-emerald-600 rounded-xl group-hover:bg-emerald-200 transition-colors"><i data-lucide="badge-check" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($creditRemainingTotal) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Advance - used</div>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-blue-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Dealer Balances</div>
                    <div class="p-2.5 bg-blue-100 text-blue-600 rounded-xl group-hover:bg-blue-200 transition-colors"><i data-lucide="users" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($dealerOutstandingTotal) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100 mb-2">
                    Advance: Rs <?= money($dealerAdvanceOutstanding) ?> • Credit: Rs <?= money($dealerCreditOutstanding) ?>
                </div>
                <div class="text-xs font-medium text-gray-500 flex flex-wrap gap-2 mb-2">
                    <span class="bg-gray-50 text-gray-700 px-2.5 py-1 rounded-lg border border-gray-100">This month cash-out: Rs <?= money($dealerPaymentsMonthTotal) ?></span>
                    <span class="bg-gray-50 text-gray-700 px-2.5 py-1 rounded-lg border border-gray-100">Payable: Rs <?= money($dealerRemainingPayableMonth) ?></span>
                </div>
                <a class="inline-flex items-center gap-2 text-sm font-semibold text-brand-600 hover:text-brand-700" href="<?= h(app_url('dealer-payments/index.php')) ?>">
                    View Dealer Ledger <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="glass-card rounded-3xl p-6 mb-10 animate-slide-up stagger-5">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Dealer Wise Summary</div>
                <div class="text-sm font-medium text-gray-600">Top balances (advance/credit)</div>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('dealer-payments/index.php')) ?>">Open Ledger</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-table">
                <thead class="table-light">
                <tr>
                    <th>Dealer</th>
                    <th>Network</th>
                    <th class="text-end">Balance</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($dealerWiseSummaryTop as $r): ?>
                    <?php $bal = (float) ($r['balance'] ?? 0); ?>
                    <tr>
                        <td class="fw-semibold"><?= h((string) ($r['dealer_name'] ?? '')) ?></td>
                        <td><?= h((string) ($r['network'] ?? '')) ?></td>
                        <td class="text-end fw-bold <?= $bal >= 0 ? 'text-success' : 'text-danger' ?>"><?= h(money($bal)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dealerWiseSummaryTop): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No dealer ledger data yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-10 animate-slide-up stagger-5">
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-amber-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Expected Cash</div>
                    <div class="p-2.5 bg-amber-100 text-amber-600 rounded-xl group-hover:bg-amber-200 transition-colors"><i data-lucide="banknote" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($cashExpectedToday) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Today (drawer)</div>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-gray-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Cash Count</div>
                    <div class="p-2.5 bg-gray-100 text-gray-700 rounded-xl group-hover:bg-gray-200 transition-colors"><i data-lucide="clipboard-check" class="w-5 h-5"></i></div>
                </div>
                <?php if (is_array($cashCountToday)): ?>
                    <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money((float) ($cashCountToday['actual_cash'] ?? 0)) ?></div>
                    <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Actual • Diff: Rs <?= money((float) ($cashCountToday['difference'] ?? 0)) ?></div>
                <?php else: ?>
                    <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Not Counted</div>
                    <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Count cash from Cash Management</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Cash Received</div>
                    <div class="p-2.5 bg-emerald-100 text-emerald-600 rounded-xl group-hover:bg-emerald-200 transition-colors"><i data-lucide="arrow-down-left" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($cashReceivedToday) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Today</div>
            </div>
        </div>

        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Cash Sent</div>
                    <div class="p-2.5 bg-red-100 text-red-600 rounded-xl group-hover:bg-red-200 transition-colors"><i data-lucide="arrow-up-right" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($cashSentToday) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Today</div>
            </div>
        </div>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Commission Income</div>
                    <div class="p-2.5 bg-emerald-100 text-emerald-600 rounded-xl group-hover:bg-emerald-200 transition-colors"><i data-lucide="badge-dollar-sign" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($cashCommissionToday) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Today</div>
            </div>
        </div>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-warning-subtle rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Pending Bills</div>
                    <div class="p-2.5 bg-warning-subtle text-warning-emphasis rounded-xl"><i data-lucide="file-clock" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money((float) ($billPendingCurrent['pending_amount'] ?? 0)) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100"><?= h((string) (int) ($billPendingCurrent['pending_count'] ?? 0)) ?> bills pending</div>
            </div>
        </div>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-cyan-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Actual Shop Cash</div>
                    <div class="p-2.5 bg-cyan-100 text-cyan-700 rounded-xl"><i data-lucide="safe" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($actualShopCashToday) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Cash drawer - pending bills</div>
            </div>
        </div>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Bill Commission</div>
                    <div class="p-2.5 bg-emerald-100 text-emerald-600 rounded-xl"><i data-lucide="badge-dollar-sign" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money((float) ($billTodaySummary['service_charge'] ?? 0)) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Today</div>
            </div>
        </div>
        <div class="glass-card rounded-3xl p-6 relative overflow-hidden group">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-primary-subtle rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-6">
                    <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Paid Bills Amount</div>
                    <div class="p-2.5 bg-primary-subtle text-primary-emphasis rounded-xl"><i data-lucide="file-check" class="w-5 h-5"></i></div>
                </div>
                <div class="text-3xl font-extrabold text-gray-900 tracking-tight">Rs <?= money($billPaidToday) ?></div>
                <div class="text-sm font-medium text-gray-500 mt-2 bg-gray-50 px-3 py-1.5 rounded-lg inline-block border border-gray-100">Paid today</div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-6 mb-10 animate-slide-up stagger-5">
    <div class="col-12 col-lg-6">
        <div class="glass-card rounded-3xl p-8 h-100 relative overflow-hidden group">
            <div class="absolute -right-12 -top-12 w-48 h-48 bg-brand-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
            <div class="relative z-10">
                <div class="d-flex justify-content-between align-items-center mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 mb-1">Daily Sales</h3>
                        <div class="text-sm font-medium text-gray-500">Revenue over last 7 days</div>
                    </div>
                    <div class="p-3 bg-brand-50 text-brand-600 rounded-2xl group-hover:bg-brand-100 transition-colors"><i data-lucide="activity" class="w-6 h-6"></i></div>
                </div>
                <div class="relative h-72 w-full mt-4">
                    <canvas id="dailySalesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canViewProfit): ?>
        <div class="col-12 col-lg-6">
            <div class="glass-card rounded-3xl p-8 h-100 relative overflow-hidden group">
                <div class="absolute -right-12 -top-12 w-48 h-48 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-700 ease-out z-0 opacity-50"></div>
                <div class="relative z-10">
                    <div class="d-flex justify-content-between align-items-center mb-6">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 mb-1">Monthly Profit</h3>
                            <div class="text-sm font-medium text-gray-500">Earnings over last 12 months</div>
                        </div>
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl group-hover:bg-emerald-100 transition-colors"><i data-lucide="bar-chart-2" class="w-6 h-6"></i></div>
                    </div>
                    <div class="relative h-72 w-full mt-4">
                        <canvas id="monthlyProfitChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Quick Navigation Grid -->
<div class="mb-10 animate-slide-up stagger-5">
    <div class="flex items-center gap-3 mb-6">
        <div class="h-8 w-1.5 bg-gradient-premium rounded-full"></div>
        <h2 class="text-2xl font-bold text-gray-900 m-0">Quick Navigation</h2>
    </div>
    
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-5">
        <?php if ($canViewProfit): ?>
            <a href="<?= h(app_url('load-management/index.php')) ?>" class="glass-card rounded-3xl p-6 text-center hover:-translate-y-2 transition-all duration-300 group flex flex-col items-center justify-center gap-3 text-gray-600 hover:text-brand-600 shadow-sm hover:shadow-xl hover:shadow-brand-500/10">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-brand-50 to-blue-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300 shadow-sm text-brand-600">
                    <i data-lucide="smartphone" class="w-7 h-7"></i>
                </div>
                <span class="text-sm font-bold tracking-wide">Load</span>
            </a>
        <?php endif; ?>
        <a href="<?= h(app_url('easypaisa/index.php')) ?>" class="glass-card rounded-3xl p-6 text-center hover:-translate-y-2 transition-all duration-300 group flex flex-col items-center justify-center gap-3 text-gray-600 hover:text-emerald-600 shadow-sm hover:shadow-xl hover:shadow-emerald-500/10">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300 shadow-sm text-emerald-600">
                <i data-lucide="wallet" class="w-7 h-7"></i>
            </div>
            <span class="text-sm font-bold tracking-wide">EasyPaisa</span>
        </a>
        <a href="<?= h(app_url('jazzcash/index.php')) ?>" class="glass-card rounded-3xl p-6 text-center hover:-translate-y-2 transition-all duration-300 group flex flex-col items-center justify-center gap-3 text-gray-600 hover:text-rose-600 shadow-sm hover:shadow-xl hover:shadow-rose-500/10">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-rose-50 to-pink-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300 shadow-sm text-rose-600">
                <i data-lucide="circle-dollar-sign" class="w-7 h-7"></i>
            </div>
            <span class="text-sm font-bold tracking-wide">JazzCash</span>
        </a>
        <a href="<?= h(app_url('bank-transfer/index.php')) ?>" class="glass-card rounded-3xl p-6 text-center hover:-translate-y-2 transition-all duration-300 group flex flex-col items-center justify-center gap-3 text-gray-600 hover:text-indigo-600 shadow-sm hover:shadow-xl hover:shadow-indigo-500/10">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-50 to-blue-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300 shadow-sm text-indigo-600">
                <i data-lucide="building-2" class="w-7 h-7"></i>
            </div>
            <span class="text-sm font-bold tracking-wide">Bank</span>
        </a>
        <?php if (app_can_manage_stock()): ?>
            <a href="<?= h(app_url('inventory/index.php')) ?>" class="glass-card rounded-3xl p-6 text-center hover:-translate-y-2 transition-all duration-300 group flex flex-col items-center justify-center gap-3 text-gray-600 hover:text-orange-600 shadow-sm hover:shadow-xl hover:shadow-orange-500/10">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-orange-50 to-amber-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300 shadow-sm text-orange-600">
                    <i data-lucide="package" class="w-7 h-7"></i>
                </div>
                <span class="text-sm font-bold tracking-wide">Inventory</span>
            </a>
        <?php endif; ?>
        <a href="<?= h(app_url('reports/index.php')) ?>" class="glass-card rounded-3xl p-6 text-center hover:-translate-y-2 transition-all duration-300 group flex flex-col items-center justify-center gap-3 text-gray-600 hover:text-cyan-600 shadow-sm hover:shadow-xl hover:shadow-cyan-500/10">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-cyan-50 to-sky-100 flex items-center justify-center group-hover:scale-110 transition-transform duration-300 shadow-sm text-cyan-600">
                <i data-lucide="bar-chart-3" class="w-7 h-7"></i>
            </div>
            <span class="text-sm font-bold tracking-wide">Reports</span>
        </a>
    </div>
</div>

<script>
    const dailySalesLabels = <?= json_encode($dailySalesLabels, JSON_UNESCAPED_SLASHES) ?>;
    const dailySalesTotals = <?= json_encode($dailySalesTotals, JSON_UNESCAPED_SLASHES) ?>;

    const brandColor = '#3B82F6';
    const successColor = '#10B981';
    const gridColor = '#E5E7EB';
    const textColor = '#6B7280';

    Chart.defaults.color = textColor;
    Chart.defaults.font.family = '"Plus Jakarta Sans", sans-serif';

    new Chart(document.getElementById('dailySalesChart'), {
        type: 'line',
        data: {
            labels: dailySalesLabels,
            datasets: [{
                label: 'Sales Revenue',
                data: dailySalesTotals,
                borderColor: brandColor,
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: brandColor,
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, backgroundColor: '#111827', titleColor: '#fff', bodyColor: '#fff', borderColor: 'rgba(0,0,0,0.1)', borderWidth: 1 } },
            scales: {
                x: { grid: { display: false, drawBorder: false } },
                y: { grid: { color: gridColor, drawBorder: false }, beginAtZero: true }
            },
            interaction: { mode: 'nearest', axis: 'x', intersect: false }
        }
    });

    <?php if ($canViewProfit): ?>
    const monthlyProfitLabels = <?= json_encode($monthlyProfitLabels, JSON_UNESCAPED_SLASHES) ?>;
    const monthlyProfitTotals = <?= json_encode($monthlyProfitTotals, JSON_UNESCAPED_SLASHES) ?>;
    const monthlyEl = document.getElementById('monthlyProfitChart');
    if (monthlyEl) {
        new Chart(monthlyEl, {
            type: 'bar',
            data: {
                labels: monthlyProfitLabels,
                datasets: [{
                    label: 'Profit',
                    data: monthlyProfitTotals,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)',
                    hoverBackgroundColor: successColor,
                    borderRadius: 4,
                    borderSkipped: false,
                    barThickness: 'flex',
                    maxBarThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { backgroundColor: '#111827', titleColor: '#fff', bodyColor: '#fff', borderColor: 'rgba(0,0,0,0.1)', borderWidth: 1 } },
                scales: {
                    x: { grid: { display: false, drawBorder: false } },
                    y: { grid: { color: gridColor, drawBorder: false }, beginAtZero: true }
                }
            }
        });
    }
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
