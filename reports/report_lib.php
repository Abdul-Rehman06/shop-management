<?php

declare(strict_types=1);

function report_modules(): array
{
    return [
        'load' => 'Load',
        'easypaisa' => 'EasyPaisa',
        'jazzcash' => 'JazzCash',
        'bank' => 'Bank Transfer',
        'expenses' => 'Expenses',
        'sales' => 'Sales',
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

function report_filters_from_request(): array
{
    $today = date('Y-m-d');
    $from = report_get_date((string) ($_GET['from'] ?? ''), $today);
    $to = report_get_date((string) ($_GET['to'] ?? ''), $today);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $module = trim((string) ($_GET['module'] ?? 'load'));
    $modules = report_modules();
    if (!isset($modules[$module])) {
        $module = 'load';
    }

    $network = trim((string) ($_GET['network'] ?? ''));
    $type = trim((string) ($_GET['type'] ?? ''));

    return [
        'module' => $module,
        'from' => $from,
        'to' => $to,
        'network' => $network,
        'type' => $type,
    ];
}

function report_load_networks(PDO $pdo): array
{
    $rows = $pdo->query('SELECT network_name FROM load_networks ORDER BY network_name ASC')->fetchAll();
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

    $params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
    $stmt = $pdo->prepare("
        SELECT DATE(s.created_at) AS sale_date, p.product_name, s.quantity, s.sale_price, s.profit, s.created_at
        FROM sales s
        JOIN products p ON p.id = s.product_id
        WHERE s.created_at >= :from AND s.created_at <= :to
        ORDER BY s.created_at ASC, s.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $totalSales = 0.0;
    $totalProfit = 0.0;
    foreach ($rows as $r) {
        $totalSales += (int) $r['quantity'] * (float) $r['sale_price'];
        $totalProfit += (float) $r['profit'];
    }

    return [
        'headers' => ['Date', 'Product', 'Qty', 'Sale Price', 'Total', 'Profit'],
        'rows' => array_map(static function (array $r): array {
            $total = (int) $r['quantity'] * (float) $r['sale_price'];
            return [
                (string) $r['sale_date'],
                (string) $r['product_name'],
                (string) (int) $r['quantity'],
                number_format((float) $r['sale_price'], 2, '.', ''),
                number_format((float) $total, 2, '.', ''),
                number_format((float) $r['profit'], 2, '.', ''),
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
