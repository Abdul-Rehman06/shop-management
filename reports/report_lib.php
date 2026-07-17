<?php

declare(strict_types=1);

require_once __DIR__ . '/../inventory/inv_lib.php';

function report_modules(): array
{
    return [
        'all' => 'All (Daily Summary)',
        'load' => 'Load',
        'load_txn' => 'Load Transactions',
        'wallet' => 'Mobile Accounts (Wallet)',
        'easypaisa' => 'EasyPaisa',
        'jazzcash' => 'JazzCash',
        'bank' => 'Bank Transfer',
        'expenses' => 'Expenses',
        'sales' => 'Sales',
        'inventory' => 'Inventory',
        'dealer_ledger' => 'Dealer Statement',
        'dealer_payments' => 'Dealer Payments',
        'bill_payments' => 'Bill Payments',
        'udhar' => 'Udhar Ledger',
        'credit' => 'Credit (Advance)',
    ];
}

function report_get_date(string $value, string $fallback): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }
    return $fallback;
}

function report_method_label(string $method): string
{
    $labels = [
        'cash' => 'Cash',
        'jazzcash' => 'JazzCash',
        'easypaisa' => 'EasyPaisa',
        'bank' => 'Bank Account',
        'other' => 'Other',
    ];
    return $labels[$method] ?? ucfirst(str_replace('_', ' ', $method));
}

function report_filters_from_request(): array
{
    $today = date('Y-m-d');

    $range = trim((string) ($_GET['range'] ?? 'today'));
    if (!in_array($range, ['today', '7days', 'month', 'custom'], true)) {
        $range = 'today';
    }

    $defaultFrom = $today;
    $defaultTo = $today;
    if ($range === '7days') {
        $defaultFrom = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
        $defaultTo = $today;
    } elseif ($range === 'month') {
        $defaultFrom = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $defaultTo = $today;
    }

    $from = report_get_date((string) ($_GET['from'] ?? ''), $today);
    $to = report_get_date((string) ($_GET['to'] ?? ''), $today);
    if ($range !== 'custom') {
        $from = $defaultFrom;
        $to = $defaultTo;
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $module = trim((string) ($_GET['module'] ?? 'all'));
    $modules = report_modules();
    if (!isset($modules[$module])) {
        $module = 'all';
    }

    $network = trim((string) ($_GET['network'] ?? ''));
    $type = trim((string) ($_GET['type'] ?? ''));
    $dealer = trim((string) ($_GET['dealer'] ?? ''));
    $txnType = trim((string) ($_GET['txn_type'] ?? ''));
    $createdBy = (int) ($_GET['created_by'] ?? 0);
    $company = trim((string) ($_GET['company'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $q = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $stockStatus = trim((string) ($_GET['stock_status'] ?? ''));

    return [
        'module' => $module,
        'from' => $from,
        'to' => $to,
        'network' => $network,
        'type' => $type,
        'dealer' => $dealer,
        'txn_type' => $txnType,
        'created_by' => $createdBy,
        'company' => $company,
        'status' => $status,
        'q' => $q,
        'category' => $category,
        'stock_status' => $stockStatus,
        'range' => $range,
    ];
}

function report_load_networks(PDO $pdo): array
{
    $rows = [];
    try {
        $rows = $pdo->query('SELECT network_name FROM load_networks ORDER BY network_name ASC')->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $networks = [];
    foreach ($rows as $r) {
        $n = trim((string) ($r['network_name'] ?? ''));
        if ($n !== '') {
            $networks[] = $n;
        }
    }
    if ($networks) {
        return $networks;
    }
    return ['Jazz', 'Zong', 'Ufone', 'Telenor'];
}

function report_fetch(PDO $pdo, array $filters): array
{
    $module = $filters['module'];
    $from = $filters['from'];
    $to = $filters['to'];
    $network = $filters['network'];
    $type = $filters['type'];

    if ($module === 'all') {
        return [
            'headers' => [],
            'rows' => [],
            'summary' => [],
        ];
    }

    if ($module === 'wallet') {
        $params = [':from' => $from, ':to' => $to];
        $stmt = $pdo->prepare("
            SELECT wt.date, a.account_type, a.account_name, wt.type, wt.customer_name, wt.number, wt.transaction_id,
                   wt.amount, wt.charges, wt.commission_method, wt.account_amount, wt.payment_status, wt.completed_at, wt.remarks
            FROM wallet_transactions wt
            JOIN accounts a ON a.id = wt.account_id
            WHERE wt.date >= :from AND wt.date <= :to
              AND wt.type IN ('receiving','sending')
              AND a.account_type IN ('easypaisa','jazzcash','bank','cash')
            ORDER BY wt.date ASC, wt.id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $receiving = 0.0;
        $sending = 0.0;
        $accountDeduction = 0.0;
        $commission = 0.0;
        $pendingCount = 0;
        $pendingAmount = 0.0;
        foreach ($rows as $r) {
            $typeValue = (string) ($r['type'] ?? '');
            $statusValue = (string) ($r['payment_status'] ?? 'completed');
            if ($typeValue === 'receiving' && $statusValue !== 'cancelled') {
                $receiving += (float) ($r['amount'] ?? 0);
            } elseif ($typeValue === 'sending' && $statusValue === 'completed') {
                $sending += (float) ($r['amount'] ?? 0);
                $accountDeduction += (float) ($r['account_amount'] ?? 0);
            } elseif ($typeValue === 'receiving' && $statusValue === 'pending') {
                $pendingCount++;
                $pendingAmount += (float) ($r['amount'] ?? 0);
            }
            if (($typeValue === 'receiving' && $statusValue !== 'cancelled') || ($typeValue === 'sending' && $statusValue === 'completed')) {
                $commission += (float) ($r['charges'] ?? 0);
            }
        }

        return [
            'headers' => ['Date', 'Account Type', 'Account', 'Type', 'Customer', 'Number', 'Transaction ID', 'Amount', 'Commission', 'Commission Method', 'Account Deduction', 'Status', 'Completed At', 'Note'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) ($r['date'] ?? ''),
                    (string) ($r['account_type'] ?? ''),
                    (string) ($r['account_name'] ?? ''),
                    (string) ($r['type'] ?? ''),
                    (string) ($r['customer_name'] ?? ''),
                    (string) ($r['number'] ?? ''),
                    (string) ($r['transaction_id'] ?? ''),
                    number_format((float) ($r['amount'] ?? 0), 2, '.', ''),
                    number_format((float) ($r['charges'] ?? 0), 2, '.', ''),
                    (string) ($r['commission_method'] ?? ''),
                    number_format((float) ($r['account_amount'] ?? 0), 2, '.', ''),
                    (string) ($r['payment_status'] ?? ''),
                    (string) ($r['completed_at'] ?? ''),
                    (string) ($r['remarks'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Receiving' => number_format($receiving, 2),
                'Cash Withdrawals' => number_format($sending, 2),
                'Account Deductions' => number_format($accountDeduction, 2),
                'Commission' => number_format($commission, 2),
                'Pending Payments' => (string) $pendingCount,
                'Pending Amount' => number_format($pendingAmount, 2),
                'Net' => number_format($receiving - $accountDeduction, 2),
            ],
        ];
    }

    if ($module === 'bill_payments') {
        $filtersForBills = [
            'from' => $from,
            'to' => $to,
            'company' => trim((string) ($filters['company'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'q' => trim((string) ($filters['q'] ?? '')),
        ];
        $rows = bill_list($pdo, $filtersForBills, 500);
        $summary = bill_summary($pdo, $filtersForBills);

        return [
            'headers' => ['Bill ID', 'Customer Name', 'Company', 'Received In', 'Received Account', 'Bill Amount', 'Service Charge', 'Total Received', 'Payment Date', 'Due Date', 'Status', 'Paid From', 'Paid Account', 'Paid At', 'Notes'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) ($r['bill_id'] ?? ''),
                    (string) ($r['customer_name'] ?? ''),
                    (string) ($r['company_name'] ?? ''),
                    report_method_label((string) ($r['received_in_type'] ?? 'cash')),
                    (string) ($r['received_in_account_name'] ?? ''),
                    number_format((float) ($r['bill_amount'] ?? 0), 2, '.', ''),
                    number_format((float) ($r['service_charge'] ?? 0), 2, '.', ''),
                    number_format((float) ($r['total_received'] ?? 0), 2, '.', ''),
                    (string) ($r['payment_date'] ?? ''),
                    (string) ($r['due_date'] ?? ''),
                    (string) ($r['status'] ?? ''),
                    trim((string) ($r['paid_from_type'] ?? '')) !== '' ? report_method_label((string) ($r['paid_from_type'] ?? '')) : '',
                    (string) ($r['paid_from_account_name'] ?? ''),
                    (string) ($r['paid_at'] ?? ''),
                    (string) ($r['notes'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Bills Count' => (string) (int) ($summary['count'] ?? 0),
                'Bill Amount' => number_format((float) ($summary['bill_amount'] ?? 0), 2),
                'Bill Commission' => number_format((float) ($summary['service_charge'] ?? 0), 2),
                'Total Received' => number_format((float) ($summary['total_received'] ?? 0), 2),
                'Pending Bills Amount' => number_format((float) ($summary['pending_amount'] ?? 0), 2),
                'Pending Bills Count' => (string) (int) ($summary['pending_count'] ?? 0),
                'Paid Bills Amount' => number_format((float) ($summary['paid_amount'] ?? 0), 2),
            ],
        ];
    }

    if ($module === 'dealer_ledger') {
        $dealer = trim((string) ($filters['dealer'] ?? ''));
        $txnType = trim((string) ($filters['txn_type'] ?? ''));
        $createdBy = (int) ($filters['created_by'] ?? 0);

        $whereParts = ['dp.payment_date >= :from', 'dp.payment_date <= :to'];
        $params = [':from' => $from, ':to' => $to];

        if ($dealer !== '') {
            $whereParts[] = 'dp.dealer_name = :dealer';
            $params[':dealer'] = $dealer;
        }
        if ($network !== '') {
            $whereParts[] = 'dp.network = :network';
            $params[':network'] = $network;
        }
        if ($txnType !== '') {
            $whereParts[] = 'dp.entry_type = :entry_type';
            $params[':entry_type'] = $txnType;
        }
        if ($createdBy > 0) {
            $whereParts[] = 'dp.created_by = :created_by';
            $params[':created_by'] = $createdBy;
        }

        $where = 'WHERE ' . implode(' AND ', $whereParts);

        $stmt = $pdo->prepare("
            SELECT dp.payment_date, dp.dealer_name, dp.network, dp.entry_type, dp.amount, dp.description, dp.notes, dp.created_at,
                   a.name AS created_by_name,
                   acc.account_name AS payment_source_name,
                   acc.account_type AS payment_source_type
            FROM dealer_payments dp
            LEFT JOIN admins a ON a.id = dp.created_by
            LEFT JOIN accounts acc ON acc.id = dp.payment_source_account_id
            {$where}
            ORDER BY dp.dealer_name ASC, dp.payment_date ASC, dp.id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $runningByDealer = [];
        $totalAdvance = 0.0;
        $totalCredit = 0.0;
        $totalLoad = 0.0;
        $totalPayment = 0.0;

        $outRows = [];
        foreach ($rows as $r) {
            $d = (string) ($r['dealer_name'] ?? '');
            $type = trim((string) ($r['entry_type'] ?? ''));
            if ($type === '') {
                $type = 'dealer_payment';
            }
            $amt = (float) ($r['amount'] ?? 0);

            $adv = $type === 'advance_payment' ? $amt : 0.0;
            $credit = $type === 'credit_load_received' ? $amt : 0.0;
            $load = $type === 'load_received_against_advance' ? $amt : 0.0;
            $pay = $type === 'dealer_payment' ? $amt : 0.0;

            $totalAdvance += $adv;
            $totalCredit += $credit;
            $totalLoad += $load;
            $totalPayment += $pay;

            $runningByDealer[$d] = (float) ($runningByDealer[$d] ?? 0.0);
            $runningByDealer[$d] += ($adv + $pay) - ($load + $credit);

            $outRows[] = [
                (string) ($r['payment_date'] ?? ''),
                $d,
                (string) ($r['network'] ?? ''),
                $type,
                (string) ($r['description'] ?? ''),
                $adv > 0 ? number_format($adv, 2, '.', '') : '',
                $credit > 0 ? number_format($credit, 2, '.', '') : '',
                $load > 0 ? number_format($load, 2, '.', '') : '',
                $pay > 0 ? number_format($pay, 2, '.', '') : '',
                (string) ($r['payment_source_name'] ?? '') . ((string) ($r['payment_source_type'] ?? '') !== '' ? ' (' . (string) $r['payment_source_type'] . ')' : ''),
                number_format((float) $runningByDealer[$d], 2, '.', ''),
                (string) ($r['created_by_name'] ?? ''),
                (string) ($r['notes'] ?? ''),
            ];
        }

        $netBalance = ($totalAdvance + $totalPayment) - ($totalLoad + $totalCredit);

        return [
            'headers' => ['Date', 'Dealer', 'Network', 'Transaction Type', 'Description', 'Advance', 'Credit', 'Load Received', 'Payment Sent', 'Payment Source', 'Balance', 'Created By', 'Remarks'],
            'rows' => $outRows,
            'summary' => [
                'Total Dealer Advances' => number_format($totalAdvance, 2),
                'Total Dealer Credit' => number_format($totalCredit, 2),
                'Total Load Received' => number_format($totalLoad, 2),
                'Total Dealer Payments' => number_format($totalPayment, 2),
                'Net Balance' => number_format($netBalance, 2),
            ],
        ];
    }

    if ($module === 'dealer_payments') {
        $dealer = trim((string) ($filters['dealer'] ?? ''));
        $createdBy = (int) ($filters['created_by'] ?? 0);

        $whereParts = [
            'dp.payment_date >= :from',
            'dp.payment_date <= :to',
            "dp.entry_type IN ('advance_payment','dealer_payment')",
        ];
        $params = [':from' => $from, ':to' => $to];

        if ($dealer !== '') {
            $whereParts[] = 'dp.dealer_name = :dealer';
            $params[':dealer'] = $dealer;
        }
        if ($network !== '') {
            $whereParts[] = 'dp.network = :network';
            $params[':network'] = $network;
        }
        if ($createdBy > 0) {
            $whereParts[] = 'dp.created_by = :created_by';
            $params[':created_by'] = $createdBy;
        }

        $where = 'WHERE ' . implode(' AND ', $whereParts);

        $stmt = $pdo->prepare("
            SELECT dp.payment_date, dp.dealer_name, dp.network, dp.entry_type, dp.amount, dp.description, dp.notes, dp.created_at,
                   a.name AS created_by_name,
                   acc.account_name AS payment_source_name,
                   acc.account_type AS payment_source_type
            FROM dealer_payments dp
            LEFT JOIN admins a ON a.id = dp.created_by
            LEFT JOIN accounts acc ON acc.id = dp.payment_source_account_id
            {$where}
            ORDER BY dp.payment_date ASC, dp.id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = 0.0;
        $sourceTotals = [];
        foreach ($rows as $r) {
            $total += (float) ($r['amount'] ?? 0);
            $sourceKey = trim((string) ($r['payment_source_name'] ?? ''));
            if ($sourceKey === '') {
                $sourceKey = 'Not linked';
            }
            $sourceTotals[$sourceKey] = (float) ($sourceTotals[$sourceKey] ?? 0) + (float) ($r['amount'] ?? 0);
        }

        $summary = [
            'Total Payments' => number_format($total, 2),
        ];
        foreach ($sourceTotals as $sourceName => $sourceAmount) {
            $summary['Source: ' . $sourceName] = number_format($sourceAmount, 2);
        }

        return [
            'headers' => ['Date', 'Dealer', 'Network', 'Type', 'Amount', 'Payment Source', 'Description', 'Remarks', 'Created By', 'Created At'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) ($r['payment_date'] ?? ''),
                    (string) ($r['dealer_name'] ?? ''),
                    (string) ($r['network'] ?? ''),
                    (string) ($r['entry_type'] ?? ''),
                    number_format((float) ($r['amount'] ?? 0), 2, '.', ''),
                    (string) ($r['payment_source_name'] ?? '') . ((string) ($r['payment_source_type'] ?? '') !== '' ? ' (' . (string) $r['payment_source_type'] . ')' : ''),
                    (string) ($r['description'] ?? ''),
                    (string) ($r['notes'] ?? ''),
                    (string) ($r['created_by_name'] ?? ''),
                    (string) ($r['created_at'] ?? ''),
                ];
            }, $rows),
            'summary' => $summary,
        ];
    }

    if ($module === 'udhar') {
        $stmt = $pdo->prepare("
            SELECT ut.txn_date, uc.name, uc.phone, ut.txn_type, ut.amount, ut.payment_method, acc.account_name AS received_account_name, ut.notes, ut.created_at
            FROM udhar_transactions ut
            JOIN udhar_customers uc ON uc.id = ut.udhar_id
            LEFT JOIN accounts acc ON acc.id = ut.received_account_id
            WHERE ut.txn_date >= :from AND ut.txn_date <= :to
            ORDER BY ut.txn_date ASC, ut.id ASC
        ");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $rows = $stmt->fetchAll();

        $udhar = 0.0;
        $paid = 0.0;
        foreach ($rows as $r) {
            if ((string) ($r['txn_type'] ?? '') === 'udhar') {
                $udhar += (float) ($r['amount'] ?? 0);
            } else {
                $paid += (float) ($r['amount'] ?? 0);
            }
        }

        return [
            'headers' => ['Date', 'Customer', 'Phone', 'Type', 'Amount', 'Payment Method', 'Received In', 'Notes', 'Created At'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) ($r['txn_date'] ?? ''),
                    (string) ($r['name'] ?? ''),
                    (string) ($r['phone'] ?? ''),
                    (string) ($r['txn_type'] ?? ''),
                    number_format((float) ($r['amount'] ?? 0), 2, '.', ''),
                    (string) (($r['txn_type'] ?? '') === 'payment' ? report_method_label((string) ($r['payment_method'] ?? 'cash')) : ''),
                    (string) ($r['received_account_name'] ?? ''),
                    (string) ($r['notes'] ?? ''),
                    (string) ($r['created_at'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Udhar (+)' => number_format($udhar, 2),
                'Payment (-)' => number_format($paid, 2),
                'Balance' => number_format($udhar - $paid, 2),
            ],
        ];
    }

    if ($module === 'credit') {
        $stmt = $pdo->prepare("
            SELECT ct.txn_date, cc.name, cc.phone, ct.txn_type, ct.amount, ct.notes, ct.created_at
            FROM credit_transactions ct
            JOIN credit_customers cc ON cc.id = ct.customer_id
            WHERE ct.txn_date >= :from AND ct.txn_date <= :to
            ORDER BY ct.txn_date ASC, ct.id ASC
        ");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $rows = $stmt->fetchAll();

        $advance = 0.0;
        $used = 0.0;
        foreach ($rows as $r) {
            if ((string) ($r['txn_type'] ?? '') === 'advance') {
                $advance += (float) ($r['amount'] ?? 0);
            } else {
                $used += (float) ($r['amount'] ?? 0);
            }
        }

        return [
            'headers' => ['Date', 'Customer', 'Phone', 'Type', 'Amount', 'Notes', 'Created At'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) ($r['txn_date'] ?? ''),
                    (string) ($r['name'] ?? ''),
                    (string) ($r['phone'] ?? ''),
                    (string) ($r['txn_type'] ?? ''),
                    number_format((float) ($r['amount'] ?? 0), 2, '.', ''),
                    (string) ($r['notes'] ?? ''),
                    (string) ($r['created_at'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Advance (+)' => number_format($advance, 2),
                'Used (-)' => number_format($used, 2),
                'Remaining' => number_format($advance - $used, 2),
            ],
        ];
    }

    if ($module === 'load_txn') {
        $stmt = $pdo->prepare("
            SELECT txn_date, network, customer_name, customer_phone, amount, profit, notes, created_at
            FROM load_customer_transactions
            WHERE txn_date >= :from AND txn_date <= :to
            ORDER BY txn_date ASC, id ASC
        ");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $rows = $stmt->fetchAll();

        $amountTotal = 0.0;
        $profitTotal = 0.0;
        foreach ($rows as $r) {
            $amountTotal += (float) ($r['amount'] ?? 0);
            $profitTotal += (float) ($r['profit'] ?? 0);
        }

        return [
            'headers' => ['Date', 'Network', 'Customer', 'Phone', 'Amount', 'Profit', 'Notes', 'Created At'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) ($r['txn_date'] ?? ''),
                    (string) ($r['network'] ?? ''),
                    (string) ($r['customer_name'] ?? ''),
                    (string) ($r['customer_phone'] ?? ''),
                    number_format((float) ($r['amount'] ?? 0), 2, '.', ''),
                    number_format((float) ($r['profit'] ?? 0), 2, '.', ''),
                    (string) ($r['notes'] ?? ''),
                    (string) ($r['created_at'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Total Amount' => number_format($amountTotal, 2),
                'Total Profit' => number_format($profitTotal, 2),
            ],
        ];
    }

    if ($module === 'load') {
        $params = [':from' => $from, ':to' => $to];
        $where = 'WHERE date >= :from AND date <= :to';
        if ($network !== '') {
            $where .= ' AND network = :network';
            $params[':network'] = $network;
        }
        $stmt = $pdo->prepare("
            SELECT date, network, opening_balance, purchased_balance, sold_balance, profit, closing_balance
            FROM load_entries
            {$where}
            ORDER BY date ASC, network ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totals = [
            'opening' => 0.0,
            'purchased' => 0.0,
            'sold' => 0.0,
            'profit' => 0.0,
            'closing' => 0.0,
        ];
        foreach ($rows as $r) {
            $totals['opening'] += (float) $r['opening_balance'];
            $totals['purchased'] += (float) $r['purchased_balance'];
            $totals['sold'] += (float) $r['sold_balance'];
            $totals['profit'] += (float) ($r['profit'] ?? 0);
            $totals['closing'] += (float) $r['closing_balance'];
        }

        return [
            'headers' => ['Date', 'Network', 'Opening', 'Purchased', 'Sold', 'Profit', 'Closing'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) $r['date'],
                    (string) $r['network'],
                    number_format((float) $r['opening_balance'], 2, '.', ''),
                    number_format((float) $r['purchased_balance'], 2, '.', ''),
                    number_format((float) $r['sold_balance'], 2, '.', ''),
                    number_format((float) ($r['profit'] ?? 0), 2, '.', ''),
                    number_format((float) $r['closing_balance'], 2, '.', ''),
                ];
            }, $rows),
            'summary' => [
                'Opening' => number_format($totals['opening'], 2),
                'Purchased' => number_format($totals['purchased'], 2),
                'Sold' => number_format($totals['sold'], 2),
                'Profit' => number_format($totals['profit'], 2),
                'Closing' => number_format($totals['closing'], 2),
            ],
        ];
    }

    if ($module === 'easypaisa') {
        $params = [':from' => $from, ':to' => $to];
        $where = 'WHERE date >= :from AND date <= :to';
        if ($type !== '') {
            $where .= ' AND type = :type';
            $params[':type'] = $type;
        }
        $stmt = $pdo->prepare("
            SELECT date, type, customer_name, number, transaction_id, amount, charges, remarks
            FROM easypaisa_transactions
            {$where}
            ORDER BY date ASC, id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $receiving = 0.0;
        $sending = 0.0;
        $commission = 0.0;
        foreach ($rows as $r) {
            if ((string) $r['type'] === 'receiving') {
                $receiving += (float) $r['amount'];
            } else {
                $sending += (float) $r['amount'];
            }
            $commission += (float) $r['charges'];
        }

        return [
            'headers' => ['Date', 'Type', 'Customer', 'Number', 'Transaction ID', 'Amount', 'Charges', 'Remarks'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) $r['date'],
                    (string) $r['type'],
                    (string) ($r['customer_name'] ?? ''),
                    (string) $r['number'],
                    (string) ($r['transaction_id'] ?? ''),
                    number_format((float) $r['amount'], 2, '.', ''),
                    number_format((float) $r['charges'], 2, '.', ''),
                    (string) ($r['remarks'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Receiving' => number_format($receiving, 2),
                'Sending' => number_format($sending, 2),
                'Commission' => number_format($commission, 2),
            ],
        ];
    }

    if ($module === 'jazzcash') {
        $params = [':from' => $from, ':to' => $to];
        $where = 'WHERE date >= :from AND date <= :to';
        if ($type !== '') {
            $where .= ' AND type = :type';
            $params[':type'] = $type;
        }
        $stmt = $pdo->prepare("
            SELECT date, type, customer_name, number, transaction_id, amount, charges, remarks
            FROM jazzcash_transactions
            {$where}
            ORDER BY date ASC, id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $receiving = 0.0;
        $sending = 0.0;
        $commission = 0.0;
        foreach ($rows as $r) {
            if ((string) $r['type'] === 'receiving') {
                $receiving += (float) $r['amount'];
            } else {
                $sending += (float) $r['amount'];
            }
            $commission += (float) $r['charges'];
        }

        return [
            'headers' => ['Date', 'Type', 'Customer', 'Number', 'Transaction ID', 'Amount', 'Charges', 'Remarks'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) $r['date'],
                    (string) $r['type'],
                    (string) ($r['customer_name'] ?? ''),
                    (string) $r['number'],
                    (string) ($r['transaction_id'] ?? ''),
                    number_format((float) $r['amount'], 2, '.', ''),
                    number_format((float) $r['charges'], 2, '.', ''),
                    (string) ($r['remarks'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Receiving' => number_format($receiving, 2),
                'Sending' => number_format($sending, 2),
                'Commission' => number_format($commission, 2),
            ],
        ];
    }

    if ($module === 'bank') {
        $params = [':from' => $from, ':to' => $to];
        $where = 'WHERE date >= :from AND date <= :to';
        if ($type !== '') {
            $where .= ' AND type = :type';
            $params[':type'] = $type;
        }
        $stmt = $pdo->prepare("
            SELECT date, type, bank_name, account_number, transaction_id, amount, charges, remarks
            FROM bank_transactions
            {$where}
            ORDER BY date ASC, id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $received = 0.0;
        $sent = 0.0;
        $charges = 0.0;
        foreach ($rows as $r) {
            if ((string) $r['type'] === 'receiving') {
                $received += (float) $r['amount'];
            } else {
                $sent += (float) $r['amount'];
            }
            $charges += (float) $r['charges'];
        }

        return [
            'headers' => ['Date', 'Type', 'Bank', 'Account', 'Transaction ID', 'Amount', 'Charges', 'Remarks'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) $r['date'],
                    (string) $r['type'],
                    (string) $r['bank_name'],
                    (string) $r['account_number'],
                    (string) ($r['transaction_id'] ?? ''),
                    number_format((float) $r['amount'], 2, '.', ''),
                    number_format((float) $r['charges'], 2, '.', ''),
                    (string) ($r['remarks'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Received' => number_format($received, 2),
                'Sent' => number_format($sent, 2),
                'Net' => number_format($received - $sent, 2),
                'Charges' => number_format($charges, 2),
            ],
        ];
    }

    if ($module === 'expenses') {
        $params = [':from' => $from, ':to' => $to];
        $where = 'WHERE date >= :from AND date <= :to';
        if ($type !== '') {
            $where .= ' AND category = :category';
            $params[':category'] = $type;
        }
        $stmt = $pdo->prepare("
            SELECT date, category, amount, description
            FROM expenses
            {$where}
            ORDER BY date ASC, id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) $r['amount'];
        }

        return [
            'headers' => ['Date', 'Category', 'Amount', 'Description'],
            'rows' => array_map(static function (array $r): array {
                return [
                    (string) $r['date'],
                    (string) $r['category'],
                    number_format((float) $r['amount'], 2, '.', ''),
                    (string) ($r['description'] ?? ''),
                ];
            }, $rows),
            'summary' => [
                'Total' => number_format($total, 2),
            ],
        ];
    }

    if ($module === 'inventory') {
        $category = trim((string) ($filters['category'] ?? ''));
        $stockStatus = trim((string) ($filters['stock_status'] ?? ''));
        $q = trim((string) ($filters['q'] ?? ''));

        $productFilters = [
            'q' => $q,
            'category' => $category,
            'status' => $stockStatus,
        ];
        $products = inv_product_rows($pdo, $productFilters, 500);

        $purchaseRows = inv_purchase_rows($pdo, [
            'from' => $from,
            'to' => $to,
            'q' => $q,
        ], 1000);
        $damageRows = inv_damage_rows($pdo, [
            'from' => $from,
            'to' => $to,
            'q' => $q,
        ], 1000);

        $purchaseByProduct = [];
        foreach ($purchaseRows as $row) {
            $productKey = (int) ($row['product_id'] ?? 0);
            if ($productKey <= 0) {
                continue;
            }
            if (!isset($purchaseByProduct[$productKey])) {
                $purchaseByProduct[$productKey] = ['qty' => 0, 'amount' => 0.0];
            }
            $purchaseByProduct[$productKey]['qty'] += (int) ($row['quantity'] ?? 0);
            $purchaseByProduct[$productKey]['amount'] += (float) ($row['total_amount'] ?? 0);
        }

        $damageByProduct = [];
        foreach ($damageRows as $row) {
            $productKey = (int) ($row['product_id'] ?? 0);
            if ($productKey <= 0) {
                continue;
            }
            $damageByProduct[$productKey] = (int) ($damageByProduct[$productKey] ?? 0) + (int) ($row['quantity'] ?? 0);
        }

        $stmt = $pdo->prepare("
            SELECT
                s.product_id,
                COALESCE(SUM(s.quantity), 0) AS sold_qty,
                COALESCE(SUM(s.quantity * s.sale_price), 0) AS sales_value,
                COALESCE(SUM(s.profit), 0) + COALESCE(SUM(r.profit_adj), 0) AS gross_profit,
                COALESCE(SUM(r.returned_qty), 0) AS returned_qty
            FROM sales s
            LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(quantity), 0) AS returned_qty, COALESCE(SUM(profit_adjustment), 0) AS profit_adj
                FROM sales_returns
                GROUP BY sale_id
            ) r ON r.sale_id = s.id
            WHERE s.created_at >= :from AND s.created_at <= :to
            GROUP BY s.product_id
        ");
        $stmt->execute([
            ':from' => $from . ' 00:00:00',
            ':to' => $to . ' 23:59:59',
        ]);
        $salesRows = $stmt->fetchAll();

        $salesByProduct = [];
        foreach ($salesRows as $row) {
            $salesByProduct[(int) ($row['product_id'] ?? 0)] = [
                'qty' => (int) ($row['sold_qty'] ?? 0),
                'sales_value' => (float) ($row['sales_value'] ?? 0),
                'gross_profit' => (float) ($row['gross_profit'] ?? 0),
                'returned_qty' => (int) ($row['returned_qty'] ?? 0),
            ];
        }

        $reportRows = [];
        $summary = [
            'Purchased Quantity' => 0,
            'Sold Quantity' => 0,
            'Remaining Stock' => 0,
            'Purchase Value' => 0.0,
            'Sales Value' => 0.0,
            'Gross Profit' => 0.0,
            'Current Purchase Value' => 0.0,
            'Expected Selling Value' => 0.0,
            'Expected Profit' => 0.0,
        ];

        foreach ($products as $product) {
            $productId = (int) ($product['id'] ?? 0);
            $purchaseQty = (int) ($purchaseByProduct[$productId]['qty'] ?? 0);
            $purchaseValue = (float) ($purchaseByProduct[$productId]['amount'] ?? 0);
            $soldQty = (int) ($salesByProduct[$productId]['qty'] ?? 0);
            $returnedQty = (int) ($salesByProduct[$productId]['returned_qty'] ?? 0);
            $netSoldQty = max(0, $soldQty - $returnedQty);
            $salesValue = (float) ($salesByProduct[$productId]['sales_value'] ?? 0);
            $grossProfit = (float) ($salesByProduct[$productId]['gross_profit'] ?? 0);
            $remainingStock = (int) ($product['current_stock'] ?? 0);
            $currentPurchaseValue = (float) ($product['stock_value'] ?? 0);
            $expectedSellingValue = (float) ($product['selling_value'] ?? 0);
            $expectedProfit = (float) ($product['expected_profit'] ?? 0);

            $summary['Purchased Quantity'] += $purchaseQty;
            $summary['Sold Quantity'] += $netSoldQty;
            $summary['Remaining Stock'] += $remainingStock;
            $summary['Purchase Value'] += $purchaseValue;
            $summary['Sales Value'] += $salesValue;
            $summary['Gross Profit'] += $grossProfit;
            $summary['Current Purchase Value'] += $currentPurchaseValue;
            $summary['Expected Selling Value'] += $expectedSellingValue;
            $summary['Expected Profit'] += $expectedProfit;

            $reportRows[] = [
                (string) ($product['product_name'] ?? ''),
                (string) ($product['category'] ?? ''),
                (string) ($product['sku'] ?? ''),
                (string) $purchaseQty,
                (string) $netSoldQty,
                (string) $remainingStock,
                number_format($purchaseValue, 2, '.', ''),
                number_format($salesValue, 2, '.', ''),
                number_format($grossProfit, 2, '.', ''),
                number_format($currentPurchaseValue, 2, '.', ''),
                number_format($expectedSellingValue, 2, '.', ''),
            ];
        }

        return [
            'headers' => ['Product', 'Category', 'SKU', 'Purchased Qty', 'Sold Qty', 'Remaining Stock', 'Purchase Value', 'Sales Value', 'Gross Profit', 'Current Purchase Value', 'Expected Selling Value'],
            'rows' => $reportRows,
            'summary' => [
                'Purchased Quantity' => (string) $summary['Purchased Quantity'],
                'Sold Quantity' => (string) $summary['Sold Quantity'],
                'Remaining Stock' => (string) $summary['Remaining Stock'],
                'Purchase Value' => number_format((float) $summary['Purchase Value'], 2),
                'Sales Value' => number_format((float) $summary['Sales Value'], 2),
                'Gross Profit' => number_format((float) $summary['Gross Profit'], 2),
                'Current Purchase Value' => number_format((float) $summary['Current Purchase Value'], 2),
                'Expected Selling Value' => number_format((float) $summary['Expected Selling Value'], 2),
                'Expected Profit' => number_format((float) $summary['Expected Profit'], 2),
            ],
        ];
    }

    $params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
    $stmt = $pdo->prepare("
        SELECT
            DATE(s.created_at) AS sale_date,
            p.product_name,
            s.quantity,
            COALESCE(r.returned_qty, 0) AS returned_qty,
            s.sale_price,
            s.profit,
            COALESCE(r.profit_adj, 0) AS profit_adj,
            s.created_at
        FROM sales s
        JOIN products p ON p.id = s.product_id
        LEFT JOIN (
            SELECT sale_id, COALESCE(SUM(quantity), 0) AS returned_qty, COALESCE(SUM(profit_adjustment), 0) AS profit_adj
            FROM sales_returns
            GROUP BY sale_id
        ) r ON r.sale_id = s.id
        WHERE s.created_at >= :from AND s.created_at <= :to
        ORDER BY s.created_at ASC, s.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $totalSales = 0.0;
    $totalProfit = 0.0;
    foreach ($rows as $r) {
        $qty = (int) $r['quantity'];
        $returned = (int) ($r['returned_qty'] ?? 0);
        $netQty = max(0, $qty - $returned);
        $totalSales += $netQty * (float) $r['sale_price'];
        $totalProfit += (float) $r['profit'] + (float) ($r['profit_adj'] ?? 0);
    }

    return [
        'headers' => ['Date', 'Product', 'Qty', 'Returned', 'Net Qty', 'Sale Price', 'Total', 'Profit'],
        'rows' => array_map(static function (array $r): array {
            $qty = (int) $r['quantity'];
            $returned = (int) ($r['returned_qty'] ?? 0);
            $netQty = max(0, $qty - $returned);
            $total = $netQty * (float) $r['sale_price'];
            $profit = (float) $r['profit'] + (float) ($r['profit_adj'] ?? 0);
            return [
                (string) $r['sale_date'],
                (string) $r['product_name'],
                (string) $qty,
                (string) $returned,
                (string) $netQty,
                number_format((float) $r['sale_price'], 2, '.', ''),
                number_format((float) $total, 2, '.', ''),
                number_format($profit, 2, '.', ''),
            ];
        }, $rows),
        'summary' => [
            'Total Sales' => number_format($totalSales, 2),
            'Total Profit' => number_format($totalProfit, 2),
        ],
    ];
}

function report_filename(array $filters, string $format): string
{
    $modules = report_modules();
    $moduleName = $modules[$filters['module']] ?? 'Report';
    $safeModule = preg_replace('/[^A-Za-z0-9_-]+/', '_', $moduleName) ?: 'Report';
    $safeFrom = preg_replace('/[^0-9-]+/', '', $filters['from']);
    $safeTo = preg_replace('/[^0-9-]+/', '', $filters['to']);
    return $safeModule . '_' . $safeFrom . '_to_' . $safeTo . '.' . $format;
}
