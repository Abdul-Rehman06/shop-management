<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../inventory/inv_lib.php';

function money($value): string
{
    return number_format((float) $value, 2);
}

function pct(float $value): string
{
    return number_format($value, 1) . '%';
}

function growth_pct(float $current, float $previous): float
{
    if (abs($previous) < 0.00001) {
        return $current > 0 ? 100.0 : 0.0;
    }

    return (($current - $previous) / abs($previous)) * 100;
}

function range_bounds(string $range, string $today, string $fromRaw = '', string $toRaw = ''): array
{
    $from = $today;
    $to = $today;
    $label = 'Today';

    if ($range === 'yesterday') {
        $from = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        $to = $from;
        $label = 'Yesterday';
    } elseif ($range === 'week') {
        $from = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
        $to = $today;
        $label = 'This Week';
    } elseif ($range === 'month') {
        $from = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $to = $today;
        $label = 'This Month';
    } elseif ($range === 'last_month') {
        $from = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
        $to = (new DateTimeImmutable('last day of last month'))->format('Y-m-d');
        $label = 'Last Month';
    } elseif ($range === 'custom') {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) === 1 ? $fromRaw : $today;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw) === 1 ? $toRaw : $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $label = $from === $to ? $from : ($from . ' to ' . $to);
    }

    return ['from' => $from, 'to' => $to, 'label' => $label];
}

function days_between(string $from, string $to): int
{
    return ((int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a')) + 1;
}

function previous_bounds(string $from, string $to): array
{
    $days = days_between($from, $to);
    $prevTo = (new DateTimeImmutable($from))->modify('-1 day');
    $prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');
    return ['from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')];
}

function account_type_label(string $type): string
{
    return match ($type) {
        'cash' => 'Cash Drawer',
        'bank' => 'Bank',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}

function account_sort_rank(array $account): array
{
    $type = strtolower(trim((string) ($account['account_type'] ?? '')));
    $name = strtolower(trim((string) ($account['account_name'] ?? '')));

    if ($type === 'bank') {
        $priority = str_contains($name, 'ubl') ? 1 : (str_contains($name, 'alfalah') ? 2 : 9);
        return [1, $priority, $name];
    }
    if ($type === 'easypaisa') {
        $priority = str_contains($name, '1') ? 1 : (str_contains($name, '2') ? 2 : 9);
        return [2, $priority, $name];
    }
    if ($type === 'jazzcash') {
        $priority = str_contains($name, '1') ? 1 : (str_contains($name, '2') ? 2 : 9);
        return [3, $priority, $name];
    }
    if ($type === 'cash') {
        return [4, 99, $name];
    }
    return [5, 99, $name];
}

function alphabet_serial(int $index): string
{
    return chr(65 + $index);
}

function wallet_commission_range(PDO $pdo, string $from, string $to): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(charges), 0)
        FROM wallet_transactions
        WHERE date >= :from_date
          AND date <= :to_date
          AND (
                (type = 'receiving' AND payment_status = 'completed')
                OR (type = 'sending' AND payment_status = 'completed')
          )
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    return (float) $stmt->fetchColumn();
}

function wallet_open_for(PDO $pdo, int $accountId, string $date): float
{
    $stmt = $pdo->prepare("SELECT amount FROM wallet_transactions WHERE account_id = :id AND date = :date AND type = 'opening' ORDER BY id DESC LIMIT 1");
    $stmt->execute([':id' => $accountId, ':date' => $date]);
    $manual = $stmt->fetchColumn();
    if ($manual !== false && $manual !== null) {
        return (float) $manual;
    }

    $stmt = $pdo->prepare("SELECT date, amount FROM wallet_transactions WHERE account_id = :id AND type = 'opening' AND date < :date ORDER BY date DESC, id DESC LIMIT 1");
    $stmt->execute([':id' => $accountId, ':date' => $date]);
    $baseline = $stmt->fetch() ?: [];
    $baselineDate = (string) ($baseline['date'] ?? '');
    $baselineAmount = (float) ($baseline['amount'] ?? 0);
    if ($baselineDate !== '') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN type = 'receiving' AND payment_status = 'completed' THEN amount
                    WHEN type = 'sending' AND payment_status = 'completed' THEN -account_amount
                    ELSE 0
                END
            ), 0)
            FROM wallet_transactions
            WHERE account_id = :id AND date >= :baseline_date AND date < :target_date AND type IN ('receiving','sending')
        ");
        $stmt->execute([':id' => $accountId, ':baseline_date' => $baselineDate, ':target_date' => $date]);
        return $baselineAmount + (float) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN type = 'receiving' AND payment_status = 'completed' THEN amount
                WHEN type = 'sending' AND payment_status = 'completed' THEN -account_amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions
        WHERE account_id = :id AND date < :date AND type IN ('receiving','sending')
    ");
    $stmt->execute([':id' => $accountId, ':date' => $date]);
    return (float) $stmt->fetchColumn();
}

function wallet_period(PDO $pdo, int $accountId, string $from, string $to): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type = 'receiving' AND payment_status = 'completed' THEN amount ELSE 0 END), 0) AS received_total,
            COALESCE(SUM(CASE WHEN type = 'sending' AND payment_status = 'completed' THEN amount ELSE 0 END), 0) AS sent_total,
            COALESCE(SUM(CASE WHEN type = 'sending' AND payment_status = 'completed' THEN account_amount ELSE 0 END), 0) AS deduction_total
        FROM wallet_transactions
        WHERE account_id = :id AND date >= :from_date AND date <= :to_date AND type IN ('receiving','sending')
    ");
    $stmt->execute([':id' => $accountId, ':from_date' => $from, ':to_date' => $to]);
    $row = $stmt->fetch() ?: [];
    $opening = wallet_open_for($pdo, $accountId, $from);
    $received = (float) ($row['received_total'] ?? 0);
    $sent = (float) ($row['sent_total'] ?? 0);
    $deduction = (float) ($row['deduction_total'] ?? 0);
    return [
        'opening' => $opening,
        'received' => $received,
        'sent' => $sent,
        'closing' => $opening + $received - $deduction,
    ];
}

function non_cash_sum(PDO $pdo, string $from, string $to, string $type): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN wt.type = 'receiving' AND wt.payment_status = 'completed' THEN wt.amount
                WHEN wt.type = 'sending' AND wt.payment_status = 'completed' THEN wt.amount
                ELSE 0
            END
        ), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type IN ('easypaisa','jazzcash','bank')
          AND wt.date >= :from_date
          AND wt.date <= :to_date
          AND wt.type = :txn_type
          AND COALESCE(wt.entry_context, 'external') NOT IN ('internal_transfer', 'dealer_payment_online', 'bill_collection_online', 'bill_payment_online', 'udhar_recovery_online')
          AND (wt.remarks IS NULL OR wt.remarks NOT LIKE 'Dealer payment #%')
          AND (wt.remarks IS NULL OR wt.remarks NOT LIKE 'Bank Deposit #%')
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to, ':txn_type' => $type]);
    return (float) $stmt->fetchColumn();
}

function cash_period(PDO $pdo, string $from, string $to): array
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(wt.amount), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash' AND wt.date = :from_date AND wt.type = 'opening' AND COALESCE(wt.entry_context, 'external') <> 'internal_transfer'
    ");
    $stmt->execute([':from_date' => $from]);
    $opening = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(wt.amount), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash' AND wt.date >= :from_date AND wt.date <= :to_date AND wt.type = 'receiving' AND wt.payment_status = 'completed' AND COALESCE(wt.entry_context, 'external') <> 'internal_transfer'
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $walletReceived = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(wt.amount), 0)
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.account_type = 'cash' AND wt.date >= :from_date AND wt.date <= :to_date AND wt.type = 'sending' AND wt.payment_status = 'completed' AND COALESCE(wt.entry_context, 'external') <> 'internal_transfer'
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $walletSent = (float) $stmt->fetchColumn();
    $walletCommission = wallet_commission_range($pdo, $from, $to);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity * sale_price), 0) FROM sales WHERE created_at >= :from_dt AND created_at <= :to_dt");
    $stmt->execute([':from_dt' => $from . ' 00:00:00', ':to_dt' => $to . ' 23:59:59']);
    $salesCash = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(sold_balance), 0) FROM load_entries WHERE date >= :from_date AND date <= :to_date");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $loadCash = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM udhar_transactions
        WHERE txn_date >= :from_date AND txn_date <= :to_date AND txn_type = 'payment'
          AND COALESCE(linked_wallet_txn_id, 0) = 0 AND COALESCE(payment_method, 'cash') = 'cash'
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $udharCash = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE txn_date >= :from_date AND txn_date <= :to_date AND txn_type = 'advance'");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $creditCash = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date >= :from_date AND date <= :to_date AND COALESCE(payment_status, 'paid') = 'paid'");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $expenses = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM dealer_payments
        WHERE payment_date >= :from_date AND payment_date <= :to_date
          AND entry_type IN ('advance_payment','dealer_payment')
          AND linked_wallet_txn_id IS NULL
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $dealerPayments = (float) $stmt->fetchColumn();

    $received = $walletReceived + $salesCash + $loadCash + $udharCash + $creditCash + non_cash_sum($pdo, $from, $to, 'sending') + $walletCommission;
    $sent = $walletSent + $expenses + $dealerPayments + non_cash_sum($pdo, $from, $to, 'receiving');

    return ['opening' => $opening, 'received' => $received, 'sent' => $sent, 'closing' => $opening + $received - $sent, 'commission' => $walletCommission];
}

function load_opening(PDO $pdo, string $network, string $date): float
{
    $stmt = $pdo->prepare("SELECT opening_balance FROM load_entries WHERE network = :network AND date = :date ORDER BY id DESC LIMIT 1");
    $stmt->execute([':network' => $network, ':date' => $date]);
    $opening = $stmt->fetchColumn();
    if ($opening !== false && $opening !== null) {
        return (float) $opening;
    }

    $stmt = $pdo->prepare("SELECT closing_balance FROM load_entries WHERE network = :network AND date < :date ORDER BY date DESC, id DESC LIMIT 1");
    $stmt->execute([':network' => $network, ':date' => $date]);
    return (float) ($stmt->fetchColumn() ?: 0);
}

function load_period(PDO $pdo, array $networks, string $from, string $to): array
{
    $stmt = $pdo->prepare("
        SELECT network, COALESCE(SUM(purchased_balance), 0) AS purchased_total, COALESCE(SUM(sold_balance), 0) AS sold_total
        FROM load_entries
        WHERE date >= :from_date AND date <= :to_date
        GROUP BY network
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) ($row['network'] ?? '')] = [
            'purchased' => (float) ($row['purchased_total'] ?? 0),
            'sold' => (float) ($row['sold_total'] ?? 0),
        ];
    }

    $rows = [];
    $totals = ['opening' => 0.0, 'purchased' => 0.0, 'sold' => 0.0, 'remaining' => 0.0];
    foreach ($networks as $network) {
        $opening = load_opening($pdo, $network, $from);
        $purchased = (float) ($map[$network]['purchased'] ?? 0);
        $sold = (float) ($map[$network]['sold'] ?? 0);
        $remaining = $opening + $purchased - $sold;
        $rows[] = compact('network', 'opening', 'purchased', 'sold', 'remaining');
        $totals['opening'] += $opening;
        $totals['purchased'] += $purchased;
        $totals['sold'] += $sold;
        $totals['remaining'] += $remaining;
    }
    return ['rows' => $rows, 'totals' => $totals];
}

function period_metrics(PDO $pdo, string $from, string $to, bool $canViewProfit): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS sales_count, COALESCE(SUM(quantity), 0) AS qty_total, COALESCE(SUM(quantity * sale_price), 0) AS sales_total, COALESCE(SUM(profit), 0) AS profit_total
        FROM sales
        WHERE created_at >= :from_dt AND created_at <= :to_dt
    ");
    $stmt->execute([':from_dt' => $from . ' 00:00:00', ':to_dt' => $to . ' 23:59:59']);
    $sales = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(profit_adjustment), 0) FROM sales_returns WHERE return_date >= :from_date AND return_date <= :to_date");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $returnProfit = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date >= :from_date AND date <= :to_date AND COALESCE(payment_status, 'paid') = 'paid'");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $expenses = (float) $stmt->fetchColumn();

    $billSummary = bill_summary($pdo, ['from' => $from, 'to' => $to]);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM dealer_payments WHERE payment_date >= :from_date AND payment_date <= :to_date AND entry_type IN ('advance_payment','dealer_payment')");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $dealerPayments = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN txn_type = 'udhar' THEN amount ELSE 0 END), 0) AS given_total,
            COALESCE(SUM(CASE WHEN txn_type = 'payment' THEN amount ELSE 0 END), 0) AS recovered_total
        FROM udhar_transactions
        WHERE txn_date >= :from_date AND txn_date <= :to_date
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $udhar = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE txn_date >= :from_date AND txn_date <= :to_date AND txn_type = 'advance'");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $creditAdvance = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(purchased_balance), 0) AS purchased_total, COALESCE(SUM(sold_balance), 0) AS sold_total, COALESCE(SUM(profit), 0) AS profit_total FROM load_entries WHERE date >= :from_date AND date <= :to_date");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $load = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare("
        SELECT
            a.account_type,
            COALESCE(SUM(CASE WHEN wt.type = 'receiving' AND wt.payment_status = 'completed' THEN wt.amount ELSE 0 END), 0) AS received_total,
            COALESCE(SUM(CASE WHEN wt.type = 'sending' AND wt.payment_status = 'completed' THEN wt.amount ELSE 0 END), 0) AS sent_total,
            COALESCE(SUM(CASE WHEN wt.type = 'receiving' AND wt.payment_status = 'completed' THEN wt.charges WHEN wt.type = 'sending' AND wt.payment_status = 'completed' THEN wt.charges ELSE 0 END), 0) AS commission_total,
            COALESCE(SUM(CASE WHEN wt.type = 'receiving' AND wt.payment_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN wt.type = 'receiving' AND wt.payment_status = 'pending' THEN wt.amount ELSE 0 END), 0) AS pending_amount
        FROM wallet_transactions wt
        JOIN accounts a ON a.id = wt.account_id
        WHERE a.status = 'active' AND wt.date >= :from_date AND wt.date <= :to_date AND wt.type IN ('receiving','sending')
        GROUP BY a.account_type
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $wallet = [];
    foreach ($stmt->fetchAll() as $row) {
        $wallet[(string) ($row['account_type'] ?? '')] = [
            'received' => (float) ($row['received_total'] ?? 0),
            'sent' => (float) ($row['sent_total'] ?? 0),
            'commission' => (float) ($row['commission_total'] ?? 0),
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'pending_amount' => (float) ($row['pending_amount'] ?? 0),
        ];
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM udhar_transactions
        WHERE txn_date >= :from_date AND txn_date <= :to_date AND txn_type = 'payment'
          AND COALESCE(linked_wallet_txn_id, 0) = 0 AND COALESCE(payment_method, 'cash') = 'cash'
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $cashUdhar = (float) $stmt->fetchColumn();

    $salesAmount = (float) ($sales['sales_total'] ?? 0);
    $salesProfit = (float) ($sales['profit_total'] ?? 0) + $returnProfit;
    $walletCommission =
        (float) ($wallet['cash']['commission'] ?? 0)
        + (float) ($wallet['bank']['commission'] ?? 0)
        + (float) ($wallet['jazzcash']['commission'] ?? 0)
        + (float) ($wallet['easypaisa']['commission'] ?? 0);
    $billCommission = (float) ($billSummary['service_charge'] ?? 0);
    $serviceCommission = $walletCommission + $billCommission + ($canViewProfit ? (float) ($load['profit_total'] ?? 0) : 0.0);
    $overallBusinessProfit = $salesProfit + $serviceCommission;

    return [
        'sales_count' => (int) ($sales['sales_count'] ?? 0),
        'sales_qty' => (int) ($sales['qty_total'] ?? 0),
        'sales_amount' => $salesAmount,
        'sales_cogs' => max(0.0, $salesAmount - $salesProfit),
        'sales_gross_profit' => $salesProfit,
        'expenses' => $expenses,
        'bills_paid' => bill_paid_amount_by_date($pdo, $from, $to),
        'bill_commission' => $billCommission,
        'dealer_payments' => $dealerPayments,
        'udhar_given' => (float) ($udhar['given_total'] ?? 0),
        'udhar_recovered' => (float) ($udhar['recovered_total'] ?? 0),
        'credit_advance' => $creditAdvance,
        'load_purchased' => (float) ($load['purchased_total'] ?? 0),
        'load_sold' => (float) ($load['sold_total'] ?? 0),
        'load_profit' => $canViewProfit ? (float) ($load['profit_total'] ?? 0) : 0.0,
        'cash_received' => (float) ($wallet['cash']['received'] ?? 0) + $salesAmount + (float) ($load['sold_total'] ?? 0) + $cashUdhar + $creditAdvance + $walletCommission,
        'online_received' => (float) ($wallet['bank']['received'] ?? 0) + (float) ($wallet['jazzcash']['received'] ?? 0) + (float) ($wallet['easypaisa']['received'] ?? 0),
        'sending' => (float) ($wallet['cash']['sent'] ?? 0) + (float) ($wallet['bank']['sent'] ?? 0) + (float) ($wallet['jazzcash']['sent'] ?? 0) + (float) ($wallet['easypaisa']['sent'] ?? 0),
        'bank_received' => (float) ($wallet['bank']['received'] ?? 0),
        'bank_sent' => (float) ($wallet['bank']['sent'] ?? 0),
        'jazzcash_received' => (float) ($wallet['jazzcash']['received'] ?? 0),
        'jazzcash_sent' => (float) ($wallet['jazzcash']['sent'] ?? 0),
        'easypaisa_received' => (float) ($wallet['easypaisa']['received'] ?? 0),
        'easypaisa_sent' => (float) ($wallet['easypaisa']['sent'] ?? 0),
        'wallet_received_total' => (float) ($wallet['jazzcash']['received'] ?? 0) + (float) ($wallet['easypaisa']['received'] ?? 0),
        'wallet_sent_total' => (float) ($wallet['jazzcash']['sent'] ?? 0) + (float) ($wallet['easypaisa']['sent'] ?? 0),
        'wallet_commission' => $walletCommission,
        'commission' => $serviceCommission,
        'accessories_profit' => $salesProfit,
        'overall_business_profit' => $overallBusinessProfit,
        'net_profit' => $overallBusinessProfit - $expenses,
        'pending_count' => (int) ($wallet['cash']['pending_count'] ?? 0) + (int) ($wallet['bank']['pending_count'] ?? 0) + (int) ($wallet['jazzcash']['pending_count'] ?? 0) + (int) ($wallet['easypaisa']['pending_count'] ?? 0),
        'pending_amount' => (float) ($wallet['cash']['pending_amount'] ?? 0) + (float) ($wallet['bank']['pending_amount'] ?? 0) + (float) ($wallet['jazzcash']['pending_amount'] ?? 0) + (float) ($wallet['easypaisa']['pending_amount'] ?? 0),
    ];
}

function udhar_outstanding(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT c.id,
            (
                COALESCE(SUM(CASE WHEN t.txn_type = 'udhar' THEN t.amount ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN t.txn_type = 'payment' THEN t.amount ELSE 0 END), 0)
            ) AS balance
        FROM udhar_customers c
        LEFT JOIN udhar_transactions t ON t.udhar_id = c.id
        GROUP BY c.id
    ");
    $count = 0;
    $amount = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $balance = max(0.0, (float) ($row['balance'] ?? 0));
        if ($balance > 0) {
            $count++;
            $amount += $balance;
        }
    }
    return ['count' => $count, 'amount' => $amount];
}

function dealer_outstanding(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT dealer_name, network,
            COALESCE(SUM(CASE WHEN entry_type = 'advance_payment' THEN amount ELSE 0 END), 0) AS adv_total,
            COALESCE(SUM(CASE WHEN entry_type = 'dealer_payment' THEN amount ELSE 0 END), 0) AS pay_total,
            COALESCE(SUM(CASE WHEN entry_type = 'load_received_against_advance' THEN amount ELSE 0 END), 0) AS load_total,
            COALESCE(SUM(CASE WHEN entry_type = 'credit_load_received' THEN amount ELSE 0 END), 0) AS credit_total
        FROM dealer_payments
        GROUP BY dealer_name, network
    ");
    $count = 0;
    $amount = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $balance = ((float) ($row['adv_total'] ?? 0) + (float) ($row['pay_total'] ?? 0)) - (float) ($row['load_total'] ?? 0) - (float) ($row['credit_total'] ?? 0);
        if ($balance < 0) {
            $count++;
            $amount += abs($balance);
        }
    }
    return ['count' => $count, 'amount' => $amount];
}

function sales_series(PDO $pdo, string $from, string $to): array
{
    $days = days_between($from, $to);
    if ($days <= 31) {
        $stmt = $pdo->prepare("SELECT DATE(created_at) AS bucket, COALESCE(SUM(quantity * sale_price), 0) AS total FROM sales WHERE created_at >= :from_dt AND created_at <= :to_dt GROUP BY DATE(created_at) ORDER BY bucket ASC");
        $stmt->execute([':from_dt' => $from . ' 00:00:00', ':to_dt' => $to . ' 23:59:59']);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) ($row['bucket'] ?? '')] = (float) ($row['total'] ?? 0);
        }
        $labels = [];
        $totals = [];
        $cursor = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $labels[] = $cursor->format('d M');
            $totals[] = $map[$key] ?? 0.0;
            $cursor = $cursor->modify('+1 day');
        }
        return ['labels' => $labels, 'totals' => $totals];
    }

    $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COALESCE(SUM(quantity * sale_price), 0) AS total FROM sales WHERE created_at >= :from_dt AND created_at <= :to_dt GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY bucket ASC");
    $stmt->execute([':from_dt' => $from . ' 00:00:00', ':to_dt' => $to . ' 23:59:59']);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) ($row['bucket'] ?? '')] = (float) ($row['total'] ?? 0);
    }
    $labels = [];
    $totals = [];
    $cursor = new DateTimeImmutable(substr($from, 0, 7) . '-01');
    $end = new DateTimeImmutable(substr($to, 0, 7) . '-01');
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m');
        $labels[] = $cursor->format('M Y');
        $totals[] = $map[$key] ?? 0.0;
        $cursor = $cursor->modify('+1 month');
    }
    return ['labels' => $labels, 'totals' => $totals];
}

function monthly_sales_series(PDO $pdo, int $months = 6): array
{
    $start = (new DateTimeImmutable(date('Y-m-01')))->modify('-' . ($months - 1) . ' months');
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS bucket, COALESCE(SUM(quantity * sale_price), 0) AS total FROM sales WHERE created_at >= :from_dt GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY bucket ASC");
    $stmt->execute([':from_dt' => $start->format('Y-m-01 00:00:00')]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) ($row['bucket'] ?? '')] = (float) ($row['total'] ?? 0);
    }
    $labels = [];
    $totals = [];
    $cursor = $start;
    for ($i = 0; $i < $months; $i++) {
        $key = $cursor->format('Y-m');
        $labels[] = $cursor->format('M Y');
        $totals[] = $map[$key] ?? 0.0;
        $cursor = $cursor->modify('+1 month');
    }
    return ['labels' => $labels, 'totals' => $totals];
}

function top_products(PDO $pdo, string $from, string $to): array
{
    $stmt = $pdo->prepare("
        SELECT p.product_name, COALESCE(SUM(s.quantity), 0) AS sold_qty
        FROM sales s
        JOIN products p ON p.id = s.product_id
        WHERE s.created_at >= :from_dt AND s.created_at <= :to_dt
        GROUP BY p.id, p.product_name
        ORDER BY sold_qty DESC, p.product_name ASC
        LIMIT 6
    ");
    $stmt->execute([':from_dt' => $from . ' 00:00:00', ':to_dt' => $to . ' 23:59:59']);
    $labels = [];
    $totals = [];
    foreach ($stmt->fetchAll() as $row) {
        $labels[] = (string) ($row['product_name'] ?? 'Product');
        $totals[] = (float) ($row['sold_qty'] ?? 0);
    }
    return ['labels' => $labels, 'totals' => $totals];
}

function load_chart(PDO $pdo, array $networks, string $from, string $to): array
{
    $stmt = $pdo->prepare("SELECT network, COALESCE(SUM(sold_balance), 0) AS total FROM load_entries WHERE date >= :from_date AND date <= :to_date GROUP BY network");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) ($row['network'] ?? '')] = (float) ($row['total'] ?? 0);
    }
    $labels = [];
    $totals = [];
    foreach ($networks as $network) {
        $labels[] = $network;
        $totals[] = $map[$network] ?? 0.0;
    }
    return ['labels' => $labels, 'totals' => $totals];
}

$pdo = db();
$canViewProfit = app_can_view_profit();
$canManageStock = app_can_manage_stock();
$admin = app_current_admin();
$today = date('Y-m-d');
$monthFrom = date('Y-m-01');
$range = trim((string) ($_GET['range'] ?? 'today'));
if (!in_array($range, ['today', 'yesterday', 'week', 'month', 'last_month', 'custom'], true)) {
    $range = 'today';
}
$bounds = range_bounds($range, $today, trim((string) ($_GET['from'] ?? '')), trim((string) ($_GET['to'] ?? '')));
$fromDate = (string) $bounds['from'];
$toDate = (string) $bounds['to'];
$rangeLabel = (string) $bounds['label'];
$prev = previous_bounds($fromDate, $toDate);
$networks = ['Jazz', 'Zong', 'Ufone', 'Telenor'];

$selected = period_metrics($pdo, $fromDate, $toDate, $canViewProfit);
$previous = period_metrics($pdo, $prev['from'], $prev['to'], $canViewProfit);
$todayMetrics = period_metrics($pdo, $today, $today, $canViewProfit);
$monthMetrics = period_metrics($pdo, $monthFrom, $today, $canViewProfit);
$todayCash = cash_period($pdo, $today, $today);
$monthCash = cash_period($pdo, $monthFrom, $today);
$todayLoad = load_period($pdo, $networks, $today, $today);
$monthLoad = load_period($pdo, $networks, $monthFrom, $today);
$inventory = $canManageStock ? inv_inventory_summary($pdo) : ['purchase_value' => 0.0, 'low_stock_count' => 0, 'out_of_stock_count' => 0];
$billPending = bill_current_overview($pdo);
$udharPending = udhar_outstanding($pdo);
$dealerPending = dealer_outstanding($pdo);

$stmt = $pdo->query("SELECT id, account_name, account_type FROM accounts WHERE status = 'active'");
$accounts = $stmt->fetchAll();
usort($accounts, static function (array $a, array $b): int {
    return account_sort_rank($a) <=> account_sort_rank($b);
});
$todayAccounts = [];
$monthAccounts = [];
$live = ['cash' => 0.0, 'bank' => 0.0, 'jazzcash' => 0.0, 'easypaisa' => 0.0];
$todayTotals = ['received' => 0.0, 'sent' => 0.0, 'closing' => 0.0];
$monthTotals = ['opening' => 0.0, 'received' => 0.0, 'sent' => 0.0, 'closing' => 0.0];

foreach ($accounts as $account) {
    $id = (int) ($account['id'] ?? 0);
    $type = (string) ($account['account_type'] ?? '');
    $name = (string) ($account['account_name'] ?? 'Account');
    if ($type === 'cash') {
        $liveClosing = $todayCash['closing'];
        $todayRow = ['name' => 'Cash Drawer', 'received' => $todayCash['received'], 'sent' => $todayCash['sent'], 'closing' => $todayCash['closing']];
        $monthRow = ['name' => 'Cash Drawer', 'opening' => $monthCash['opening'], 'received' => $monthCash['received'], 'sent' => $monthCash['sent'], 'closing' => $monthCash['closing']];
    } else {
        $liveSummary = wallet_balance_summary($pdo, $id);
        $liveClosing = (float) ($liveSummary['closing'] ?? 0);
        $todaySummary = wallet_period($pdo, $id, $today, $today);
        $monthSummary = wallet_period($pdo, $id, $monthFrom, $today);
        $todayRow = ['name' => $name, 'received' => $todaySummary['received'], 'sent' => $todaySummary['sent'], 'closing' => $todaySummary['closing']];
        $monthRow = ['name' => $name, 'opening' => $monthSummary['opening'], 'received' => $monthSummary['received'], 'sent' => $monthSummary['sent'], 'closing' => $monthSummary['closing']];
    }
    $live[$type] += $liveClosing;
    $todayAccounts[] = $todayRow;
    $monthAccounts[] = $monthRow;
    $todayTotals['received'] += (float) $todayRow['received'];
    $todayTotals['sent'] += (float) $todayRow['sent'];
    $todayTotals['closing'] += (float) $todayRow['closing'];
    $monthTotals['opening'] += (float) $monthRow['opening'];
    $monthTotals['received'] += (float) $monthRow['received'];
    $monthTotals['sent'] += (float) $monthRow['sent'];
    $monthTotals['closing'] += (float) $monthRow['closing'];
}

$currentBusinessValue = $live['cash'] + $live['bank'] + $live['jazzcash'] + $live['easypaisa'] + (float) $monthLoad['totals']['remaining'] + (float) ($inventory['purchase_value'] ?? 0);
$selectedBusinessGrowth = $selected['overall_business_profit'] - $selected['expenses'];
$yesterdayBusinessValue = $currentBusinessValue - $todayMetrics['net_profit'];
$selectedStartBusinessValue = $currentBusinessValue - $selectedBusinessGrowth;
$todayGrowth = $todayMetrics['net_profit'];
$monthGrowth = $monthMetrics['net_profit'];
$profitPct = $selected['sales_amount'] > 0 ? ($selected['overall_business_profit'] / $selected['sales_amount']) * 100 : 0.0;
$expensePct = $selected['sales_amount'] > 0 ? ($selected['expenses'] / $selected['sales_amount']) * 100 : 0.0;
$salesGrowth = growth_pct($selected['sales_amount'], $previous['sales_amount']);
$loadGrowth = growth_pct($selected['load_sold'], $previous['load_sold']);

$stmt = $pdo->query("SELECT COALESCE(COUNT(*), 0) AS total_count, COALESCE(SUM(bill_amount), 0) AS total_amount FROM bill_payments WHERE status = 'pending' AND due_date IS NOT NULL AND due_date >= CURDATE() AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$upcomingBills = $stmt->fetch() ?: ['total_count' => 0, 'total_amount' => 0];
$stmt = $pdo->query("SELECT COALESCE(COUNT(*), 0) AS pending_count, COALESCE(SUM(amount), 0) AS pending_amount FROM wallet_transactions WHERE type = 'receiving' AND payment_status = 'pending'");
$pendingCustomers = $stmt->fetch() ?: ['pending_count' => 0, 'pending_amount' => 0];

$salesTrend = sales_series($pdo, $fromDate, $toDate);
$monthlySales = monthly_sales_series($pdo, 6);
$loadTrend = load_chart($pdo, $networks, $fromDate, $toDate);
$topProductsChart = top_products($pdo, $fromDate, $toDate);
$growthLabels = [];
$growthTotals = [];
$cursor = (new DateTimeImmutable(date('Y-m-01')))->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $start = $cursor->format('Y-m-01');
    $end = min($cursor->format('Y-m-t'), $today);
    $m = period_metrics($pdo, $start, $end, $canViewProfit);
    $growthLabels[] = $cursor->format('M Y');
    $growthTotals[] = (float) $m['net_profit'];
    $cursor = $cursor->modify('+1 month');
}

$pageTitle = 'Dashboard - Shop Management';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1 text-gray-900 font-bold">Business Control Center</h1>
        <div class="text-muted">Welcome, <?= h((string) ($admin['name'] ?? 'Admin')) ?>. Financial overview for <strong><?= h($rangeLabel) ?></strong>.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= h(app_url('reports/index.php?module=all&from=' . urlencode($fromDate) . '&to=' . urlencode($toDate))) ?>">Detailed Reports</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('cash-management/index.php')) ?>">Cash Management</a>
        <a class="btn btn-gradient btn-sm" href="<?= h(app_url('sales/add.php')) ?>">New Sale</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label">Dashboard Filter</label>
                <select class="form-select" name="range">
                    <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="yesterday" <?= $range === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                    <option value="week" <?= $range === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="last_month" <?= $range === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                    <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom Date Range</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">From</label>
                <input class="form-control" type="date" name="from" value="<?= h($fromDate) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">To</label>
                <input class="form-control" type="date" name="to" value="<?= h($toDate) ?>">
            </div>
            <div class="col-12 col-md-3">
                <button class="btn btn-primary w-100">Apply Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h5 mb-0">Selected Range Snapshot</h2>
        <div class="text-muted small"><?= h($fromDate) ?> to <?= h($toDate) ?></div>
    </div>
    <div class="row g-3">
        <?php
        $selectedCards = [
            ['label' => 'Sales', 'value' => 'Rs ' . money($selected['sales_amount']), 'hint' => $rangeLabel],
            ['label' => 'Accessories Profit', 'value' => 'Rs ' . money($selected['accessories_profit']), 'hint' => 'Products only'],
            ['label' => 'Wallet Commission', 'value' => 'Rs ' . money($selected['commission']), 'hint' => 'Wallet + bill + load service'],
            ['label' => 'Overall Business Profit', 'value' => 'Rs ' . money($selected['overall_business_profit']), 'hint' => 'Accessories + wallet services'],
            ['label' => 'Cash Received', 'value' => 'Rs ' . money($selected['cash_received']), 'hint' => 'Cash drawer inflow'],
            ['label' => 'Online Received', 'value' => 'Rs ' . money($selected['online_received']), 'hint' => 'Bank + JazzCash + EasyPaisa'],
            ['label' => 'Sending', 'value' => 'Rs ' . money($selected['sending']), 'hint' => 'Total wallet outflow'],
        ];
        foreach ($selectedCards as $card):
        ?>
            <div class="col-12 col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small"><?= h($card['label']) ?></div>
                        <div class="h4 mb-1"><?= h($card['value']) ?></div>
                        <div class="small text-muted"><?= h($card['hint']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="mb-4">
    <h2 class="h5 mb-3">Today's Summary</h2>
    <div class="row g-3">
        <?php
        $todayCards = [
            ['label' => 'Accessories Sales', 'value' => $todayMetrics['sales_amount']],
            ['label' => 'Bank Receiving', 'value' => $todayMetrics['bank_received']],
            ['label' => 'Bank Sending', 'value' => $todayMetrics['bank_sent']],
            ['label' => 'JazzCash Receiving', 'value' => $todayMetrics['jazzcash_received']],
            ['label' => 'JazzCash Sending', 'value' => $todayMetrics['jazzcash_sent']],
            ['label' => 'EasyPaisa Receiving', 'value' => $todayMetrics['easypaisa_received']],
            ['label' => 'EasyPaisa Sending', 'value' => $todayMetrics['easypaisa_sent']],
            ['label' => 'Total Wallet Receiving', 'value' => $todayMetrics['wallet_received_total']],
            ['label' => 'Total Wallet Sending', 'value' => $todayMetrics['wallet_sent_total']],
            ['label' => 'Total Expenses', 'value' => $todayMetrics['expenses']],
            ['label' => 'Total Bills Paid', 'value' => $todayMetrics['bills_paid']],
            ['label' => 'Total Pending Bills', 'value' => (float) ($billPending['pending_amount'] ?? 0)],
            ['label' => 'Total Dealer Payments', 'value' => $todayMetrics['dealer_payments']],
            ['label' => 'Total Udhar Given', 'value' => $todayMetrics['udhar_given']],
            ['label' => 'Total Udhar Recovered', 'value' => $todayMetrics['udhar_recovered']],
            ['label' => 'Wallet Commission', 'value' => $todayMetrics['commission']],
            ['label' => 'Accessories Profit', 'value' => $todayMetrics['accessories_profit']],
            ['label' => 'Overall Business Profit', 'value' => $todayMetrics['overall_business_profit']],
        ];
        foreach ($todayCards as $card):
        ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small"><?= h($card['label']) ?></div>
                        <div class="h5 mb-0">Rs <?= h(money($card['value'])) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Accounts Summary (Today)</h2>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Account</th><th class="text-end">Received</th><th class="text-end">Sent</th><th class="text-end">Closing Balance</th></tr></thead>
                        <tbody>
                        <?php foreach ($todayAccounts as $row): ?>
                            <tr>
                                <td><?= h((string) $row['name']) ?></td>
                                <td class="text-end">Rs <?= h(money((float) $row['received'])) ?></td>
                                <td class="text-end">Rs <?= h(money((float) $row['sent'])) ?></td>
                                <td class="text-end fw-semibold">Rs <?= h(money((float) $row['closing'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold">
                            <td>Grand Total</td>
                            <td class="text-end">Rs <?= h(money($todayTotals['received'])) ?></td>
                            <td class="text-end">Rs <?= h(money($todayTotals['sent'])) ?></td>
                            <td class="text-end">Rs <?= h(money($todayTotals['closing'])) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-end small text-muted mt-3">Total Balance: Rs <?= h(money($todayTotals['closing'])) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Mobile Load Summary (Today)</h2>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>S.No</th><th>Network</th><th class="text-end">Opening</th><th class="text-end">Purchased</th><th class="text-end">Sold</th><th class="text-end">Remaining</th></tr></thead>
                        <tbody>
                        <?php foreach ($todayLoad['rows'] as $index => $row): ?>
                            <tr>
                                <td><?= h(alphabet_serial((int) $index)) ?></td>
                                <td><?= h((string) $row['network']) ?></td>
                                <td class="text-end"><?= h(money((float) $row['opening'])) ?></td>
                                <td class="text-end"><?= h(money((float) $row['purchased'])) ?></td>
                                <td class="text-end"><?= h(money((float) $row['sold'])) ?></td>
                                <td class="text-end fw-semibold"><?= h(money((float) $row['remaining'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold">
                            <td></td>
                            <td>Total</td>
                            <td class="text-end"><?= h(money((float) $todayLoad['totals']['opening'])) ?></td>
                            <td class="text-end"><?= h(money((float) $todayLoad['totals']['purchased'])) ?></td>
                            <td class="text-end"><?= h(money((float) $todayLoad['totals']['sold'])) ?></td>
                            <td class="text-end"><?= h(money((float) $todayLoad['totals']['remaining'])) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <h2 class="h5 mb-3">Sales Summary (Today)</h2>
    <div class="row g-3">
        <?php
        $salesTodayCards = [
            ['label' => 'Products Sold', 'value' => (string) $todayMetrics['sales_qty'], 'prefix' => ''],
            ['label' => 'Sales Amount', 'value' => money($todayMetrics['sales_amount']), 'prefix' => 'Rs '],
            ['label' => 'Cost Of Goods Sold', 'value' => money($todayMetrics['sales_cogs']), 'prefix' => 'Rs '],
            ['label' => 'Accessories Profit', 'value' => money($todayMetrics['accessories_profit']), 'prefix' => 'Rs '],
            ['label' => 'Wallet Commission', 'value' => money($todayMetrics['commission']), 'prefix' => 'Rs '],
            ['label' => 'Overall Business Profit', 'value' => money($todayMetrics['overall_business_profit']), 'prefix' => 'Rs '],
        ];
        foreach ($salesTodayCards as $card):
        ?>
            <div class="col-12 col-sm-6 col-xl">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small"><?= h($card['label']) ?></div>
                        <div class="h5 mb-0"><?= h($card['prefix'] . $card['value']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="mb-4">
    <h2 class="h5 mb-3">Monthly Summary</h2>
    <div class="row g-3">
        <?php
        $monthCards = [
            ['label' => 'Monthly Sales', 'value' => $monthMetrics['sales_amount']],
            ['label' => 'Monthly Expenses', 'value' => $monthMetrics['expenses']],
            ['label' => 'Monthly Bills Paid', 'value' => $monthMetrics['bills_paid']],
            ['label' => 'Monthly Dealer Payments', 'value' => $monthMetrics['dealer_payments']],
            ['label' => 'Monthly Udhar Given', 'value' => $monthMetrics['udhar_given']],
            ['label' => 'Monthly Udhar Recovery', 'value' => $monthMetrics['udhar_recovered']],
            ['label' => 'Monthly Cash Received', 'value' => $monthMetrics['cash_received']],
            ['label' => 'Monthly Online Received', 'value' => $monthMetrics['online_received']],
            ['label' => 'Monthly Sending', 'value' => $monthMetrics['sending']],
            ['label' => 'Monthly Wallet Commission', 'value' => $monthMetrics['commission']],
            ['label' => 'Monthly Accessories Profit', 'value' => $monthMetrics['accessories_profit']],
            ['label' => 'Monthly Overall Business Profit', 'value' => $monthMetrics['overall_business_profit']],
        ];
        foreach ($monthCards as $card):
        ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small"><?= h($card['label']) ?></div>
                        <div class="h5 mb-0">Rs <?= h(money($card['value'])) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Monthly Accounts Summary</h2>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Account</th><th class="text-end">Opening</th><th class="text-end">Received</th><th class="text-end">Sent</th><th class="text-end">Closing</th></tr></thead>
                        <tbody>
                        <?php foreach ($monthAccounts as $row): ?>
                            <tr>
                                <td><?= h((string) $row['name']) ?></td>
                                <td class="text-end">Rs <?= h(money((float) $row['opening'])) ?></td>
                                <td class="text-end">Rs <?= h(money((float) $row['received'])) ?></td>
                                <td class="text-end">Rs <?= h(money((float) $row['sent'])) ?></td>
                                <td class="text-end fw-semibold">Rs <?= h(money((float) $row['closing'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold">
                            <td>Grand Total</td>
                            <td class="text-end">Rs <?= h(money($monthTotals['opening'])) ?></td>
                            <td class="text-end">Rs <?= h(money($monthTotals['received'])) ?></td>
                            <td class="text-end">Rs <?= h(money($monthTotals['sent'])) ?></td>
                            <td class="text-end">Rs <?= h(money($monthTotals['closing'])) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-end small text-muted mt-3">Total Balance: Rs <?= h(money($monthTotals['closing'])) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Monthly Load Summary</h2>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>S.No</th><th>Network</th><th class="text-end">Opening</th><th class="text-end">Purchased</th><th class="text-end">Sold</th><th class="text-end">Remaining</th></tr></thead>
                        <tbody>
                        <?php foreach ($monthLoad['rows'] as $index => $row): ?>
                            <tr>
                                <td><?= h(alphabet_serial((int) $index)) ?></td>
                                <td><?= h((string) $row['network']) ?></td>
                                <td class="text-end"><?= h(money((float) $row['opening'])) ?></td>
                                <td class="text-end"><?= h(money((float) $row['purchased'])) ?></td>
                                <td class="text-end"><?= h(money((float) $row['sold'])) ?></td>
                                <td class="text-end fw-semibold"><?= h(money((float) $row['remaining'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold">
                            <td></td>
                            <td>Total</td>
                            <td class="text-end"><?= h(money((float) $monthLoad['totals']['opening'])) ?></td>
                            <td class="text-end"><?= h(money((float) $monthLoad['totals']['purchased'])) ?></td>
                            <td class="text-end"><?= h(money((float) $monthLoad['totals']['sold'])) ?></td>
                            <td class="text-end"><?= h(money((float) $monthLoad['totals']['remaining'])) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-1">Current Business Assets (Live Balance)</h2>
                <div class="small text-muted mb-3">This section always shows latest live balances, independent of the selected date filter.</div>
                <div class="row g-3">
                    <?php
                    $worthCards = [
                        ['label' => 'Actual Cash Drawer', 'value' => $live['cash']],
                        ['label' => 'All Bank Balances', 'value' => $live['bank']],
                        ['label' => 'JazzCash Balance', 'value' => $live['jazzcash']],
                        ['label' => 'Easypaisa Balance', 'value' => $live['easypaisa']],
                        ['label' => 'Remaining Load Value', 'value' => (float) $monthLoad['totals']['remaining']],
                        ['label' => 'Inventory Stock Value', 'value' => (float) ($inventory['purchase_value'] ?? 0)],
                        ['label' => 'Total Business Assets', 'value' => $currentBusinessValue],
                    ];
                    foreach ($worthCards as $card):
                    ?>
                        <div class="col-12 col-sm-6 col-lg-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small"><?= h($card['label']) ?></div>
                                <div class="h5 mb-0">Rs <?= h(money((float) $card['value'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-1">Rolling Business Value</h2>
                <div class="small text-muted mb-3">Movement below follows the selected date filter, while the final business value stays live.</div>
                <div class="border rounded p-3 mb-3">
                    <div class="text-muted small">Business Value At Range Start</div>
                    <div class="h5 mb-0">Rs <?= h(money($selectedStartBusinessValue)) ?></div>
                </div>
                <div class="border rounded p-3 mb-3">
                    <div class="text-muted small"><?= h($rangeLabel) ?> Business Growth</div>
                    <div class="h5 mb-0">Rs <?= h(money($selectedBusinessGrowth)) ?></div>
                </div>
                <div class="border rounded p-3">
                    <div class="text-muted small">Current Business Value</div>
                    <div class="h4 mb-0">Rs <?= h(money($currentBusinessValue)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <h2 class="h5 mb-3">Financial Growth Cards</h2>
    <div class="row g-3">
        <?php
        $growthCards = [
            ['label' => "Today's Business Growth", 'value' => 'Rs ' . money($todayGrowth)],
            ['label' => 'Monthly Business Growth', 'value' => 'Rs ' . money($monthGrowth)],
            ['label' => 'Profit Percentage', 'value' => pct($profitPct)],
            ['label' => 'Expense Percentage', 'value' => pct($expensePct)],
            ['label' => 'Sales Growth', 'value' => pct($salesGrowth)],
            ['label' => 'Load Sales Growth', 'value' => pct($loadGrowth)],
        ];
        foreach ($growthCards as $card):
        ?>
            <div class="col-12 col-sm-6 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small"><?= h($card['label']) ?></div>
                        <div class="h5 mb-0"><?= h($card['value']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="mb-4">
    <h2 class="h5 mb-3">Quick Statistics</h2>
    <div class="row g-3">
        <?php
        $quickCards = [
            ['label' => 'Pending Bills', 'value' => (int) ($billPending['pending_count'] ?? 0), 'amount' => (float) ($billPending['pending_amount'] ?? 0), 'url' => app_url('bill-payments/index.php?status=pending')],
            ['label' => 'Upcoming Bills', 'value' => (int) ($upcomingBills['total_count'] ?? 0), 'amount' => (float) ($upcomingBills['total_amount'] ?? 0), 'url' => app_url('bill-payments/index.php?status=pending')],
            ['label' => 'Low Stock Products', 'value' => (int) ($inventory['low_stock_count'] ?? 0), 'amount' => 0.0, 'url' => app_url('inventory/index.php?status=low_stock')],
            ['label' => 'Pending Dealer Payments', 'value' => (int) ($dealerPending['count'] ?? 0), 'amount' => (float) ($dealerPending['amount'] ?? 0), 'url' => app_url('dealer-payments/index.php')],
            ['label' => 'Pending Udhar Recovery', 'value' => (int) ($udharPending['count'] ?? 0), 'amount' => (float) ($udharPending['amount'] ?? 0), 'url' => app_url('udhar/index.php?tab=pending')],
            ['label' => 'Pending Customer Payments', 'value' => (int) ($pendingCustomers['pending_count'] ?? 0), 'amount' => (float) ($pendingCustomers['pending_amount'] ?? 0), 'url' => app_url('mobile-accounts/index.php?search=1&search_status=pending')],
        ];
        foreach ($quickCards as $card):
        ?>
            <div class="col-12 col-sm-6 col-xl-2">
                <a class="card border-0 shadow-sm h-100 text-decoration-none text-reset" href="<?= h($card['url']) ?>">
                    <div class="card-body">
                        <div class="text-muted small"><?= h($card['label']) ?></div>
                        <div class="h4 mb-1"><?= h((string) $card['value']) ?></div>
                        <div class="small text-muted"><?= $card['amount'] > 0 ? 'Rs ' . h(money($card['amount'])) : 'Open module' ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5 mb-3">Sales Trend (Selected Range)</h2><div style="height:320px;"><canvas id="salesTrendChart"></canvas></div></div></div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5 mb-3">Monthly Sales Trend</h2><div style="height:320px;"><canvas id="monthlySalesChart"></canvas></div></div></div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5 mb-3">Expense vs Profit</h2><div style="height:300px;"><canvas id="expenseProfitChart"></canvas></div></div></div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5 mb-3">Cash vs Online Collection</h2><div style="height:300px;"><canvas id="cashOnlineChart"></canvas></div></div></div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5 mb-3">Load Sales By Network</h2><div style="height:300px;"><canvas id="loadNetworkChart"></canvas></div></div></div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5 mb-3">Top Selling Products</h2><div style="height:320px;"><canvas id="topProductsChart"></canvas></div></div></div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5 mb-3">Monthly Business Growth</h2><div style="height:320px;"><canvas id="businessGrowthChart"></canvas></div></div></div>
    </div>
</div>

<script>
const salesTrendLabels = <?= json_encode($salesTrend['labels'], JSON_UNESCAPED_SLASHES) ?>;
const salesTrendTotals = <?= json_encode($salesTrend['totals'], JSON_UNESCAPED_SLASHES) ?>;
const monthlySalesLabels = <?= json_encode($monthlySales['labels'], JSON_UNESCAPED_SLASHES) ?>;
const monthlySalesTotals = <?= json_encode($monthlySales['totals'], JSON_UNESCAPED_SLASHES) ?>;
const loadLabels = <?= json_encode($loadTrend['labels'], JSON_UNESCAPED_SLASHES) ?>;
const loadTotals = <?= json_encode($loadTrend['totals'], JSON_UNESCAPED_SLASHES) ?>;
const topProductLabels = <?= json_encode($topProductsChart['labels'], JSON_UNESCAPED_SLASHES) ?>;
const topProductTotals = <?= json_encode($topProductsChart['totals'], JSON_UNESCAPED_SLASHES) ?>;
const growthLabels = <?= json_encode($growthLabels, JSON_UNESCAPED_SLASHES) ?>;
const growthTotals = <?= json_encode($growthTotals, JSON_UNESCAPED_SLASHES) ?>;
const expenseProfitTotals = <?= json_encode([(float) $selected['expenses'], (float) $selected['accessories_profit'], (float) $selected['commission'], (float) $selected['overall_business_profit']], JSON_UNESCAPED_SLASHES) ?>;
const cashOnlineTotals = <?= json_encode([(float) $selected['cash_received'], (float) $selected['online_received'], (float) $selected['sending']], JSON_UNESCAPED_SLASHES) ?>;

Chart.defaults.color = '#6b7280';
Chart.defaults.font.family = 'Inter, sans-serif';

new Chart(document.getElementById('salesTrendChart'), {
    type: 'line',
    data: { labels: salesTrendLabels, datasets: [{ label: 'Sales', data: salesTrendTotals, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.12)', fill: true, tension: 0.35 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('monthlySalesChart'), {
    type: 'bar',
    data: { labels: monthlySalesLabels, datasets: [{ label: 'Monthly Sales', data: monthlySalesTotals, backgroundColor: '#10b981' }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('expenseProfitChart'), {
    type: 'bar',
    data: { labels: ['Expenses', 'Accessories Profit', 'Wallet Commission', 'Overall Business Profit'], datasets: [{ data: expenseProfitTotals, backgroundColor: ['#ef4444', '#22c55e', '#f59e0b', '#3b82f6'] }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('cashOnlineChart'), {
    type: 'doughnut',
    data: { labels: ['Cash Received', 'Online Received', 'Sending'], datasets: [{ data: cashOnlineTotals, backgroundColor: ['#3b82f6', '#10b981', '#f97316'] }] },
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('loadNetworkChart'), {
    type: 'bar',
    data: { labels: loadLabels, datasets: [{ label: 'Load Sold', data: loadTotals, backgroundColor: '#8b5cf6' }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('topProductsChart'), {
    type: 'bar',
    data: { labels: topProductLabels, datasets: [{ label: 'Qty Sold', data: topProductTotals, backgroundColor: '#ec4899' }] },
    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('businessGrowthChart'), {
    type: 'line',
    data: { labels: growthLabels, datasets: [{ label: 'Business Growth', data: growthTotals, borderColor: '#14b8a6', backgroundColor: 'rgba(20,184,166,0.14)', fill: true, tension: 0.35 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
