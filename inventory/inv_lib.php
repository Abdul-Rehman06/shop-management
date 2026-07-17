<?php

declare(strict_types=1);

function inv_categories(): array
{
    return [
        'Earbuds',
        'Handfree',
        'Chargers',
        'Adapters',
        'USB Cables',
        'Type-C Cable',
        'Lightning Cable',
        'Micro USB Cable',
        'DC Pins',
        'Power Banks',
        'Car Chargers',
        'Mobile Glass',
        'Mobile Covers',
        'Memory Cards',
        'OTG',
        'Speakers',
        'Bluetooth Devices',
        'Mobile Holders',
        'Ring Lights',
        'Tripods',
        'Others',
    ];
}

function inv_transaction_type_labels(): array
{
    return [
        'opening_stock' => 'Opening Stock',
        'purchase' => 'Purchase',
        'sale' => 'Sale',
        'customer_return' => 'Customer Return',
        'damage' => 'Damage',
        'manual_adjustment' => 'Manual Adjustment',
    ];
}

function inv_stock_status(int $currentStock, int $lowStockLimit): string
{
    if ($currentStock <= 0) {
        return 'out_of_stock';
    }

    if ($currentStock <= max(0, $lowStockLimit)) {
        return 'low_stock';
    }

    return 'in_stock';
}

function inv_stock_status_label(string $status): string
{
    $labels = [
        'in_stock' => 'In Stock',
        'low_stock' => 'Low Stock',
        'out_of_stock' => 'Out of Stock',
    ];

    return $labels[$status] ?? 'In Stock';
}

function inv_stock_status_badge_class(string $status): string
{
    $classes = [
        'in_stock' => 'bg-success',
        'low_stock' => 'bg-warning text-dark',
        'out_of_stock' => 'bg-danger',
    ];

    return $classes[$status] ?? 'bg-success';
}

function inv_normalize_date(string $value, string $fallback = ''): string
{
    $value = trim($value);
    if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }

    return $fallback !== '' ? $fallback : date('Y-m-d');
}

function inv_current_admin_id(): ?int
{
    if (!function_exists('app_current_admin')) {
        return null;
    }

    $admin = app_current_admin();
    $adminId = (int) ($admin['id'] ?? 0);
    return $adminId > 0 ? $adminId : null;
}

function inv_generate_sku(PDO $pdo): string
{
    for ($i = 0; $i < 10; $i++) {
        $candidate = 'SKU' . date('ymd') . random_int(1000, 9999);
        $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = :sku LIMIT 1');
        $stmt->execute([':sku' => $candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }

    return 'SKU' . date('ymdHis') . random_int(10, 99);
}

function inv_fill_missing_sku(PDO $pdo, int $productId): string
{
    $fallback = 'SKU' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare('UPDATE products SET sku = :sku WHERE id = :id AND (sku IS NULL OR sku = "")');
    $stmt->execute([
        ':sku' => $fallback,
        ':id' => $productId,
    ]);
    return $fallback;
}

function inv_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            p.stock AS current_stock,
            (p.stock * p.purchase_price) AS stock_value,
            (p.stock * p.sale_price) AS selling_value,
            ((p.stock * p.sale_price) - (p.stock * p.purchase_price)) AS expected_profit
        FROM products p
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function inv_current_stock(PDO $pdo, int $productId): int
{
    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $productId]);
    $stock = $stmt->fetchColumn();
    return $stock === false ? 0 : (int) $stock;
}

function inv_product_rows(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $whereParts = ['1=1'];
    $params = [];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $whereParts[] = '(
            p.product_name LIKE :q_name ESCAPE "\\"
            OR p.category LIKE :q_category ESCAPE "\\"
            OR COALESCE(p.brand, "") LIKE :q_brand ESCAPE "\\"
            OR COALESCE(p.sku, "") LIKE :q_sku ESCAPE "\\"
            OR COALESCE(p.barcode, "") LIKE :q_barcode ESCAPE "\\"
        )';
        $params[':q_name'] = $like;
        $params[':q_category'] = $like;
        $params[':q_brand'] = $like;
        $params[':q_sku'] = $like;
        $params[':q_barcode'] = $like;
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '') {
        $whereParts[] = 'p.category = :category';
        $params[':category'] = $category;
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status === 'in_stock') {
        $whereParts[] = 'p.stock > p.low_stock_limit';
    } elseif ($status === 'low_stock') {
        $whereParts[] = 'p.stock > 0 AND p.stock <= p.low_stock_limit';
    } elseif ($status === 'out_of_stock') {
        $whereParts[] = 'p.stock <= 0';
    }

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.product_name,
            p.category,
            p.brand,
            p.sku,
            p.barcode,
            p.purchase_price,
            p.sale_price,
            p.opening_stock,
            p.stock AS current_stock,
            p.low_stock_limit,
            p.unit,
            (p.stock * p.purchase_price) AS stock_value,
            (p.stock * p.sale_price) AS selling_value,
            ((p.stock * p.sale_price) - (p.stock * p.purchase_price)) AS expected_profit,
            p.created_at,
            p.updated_at
        FROM products p
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY p.product_name ASC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function inv_product_rows_with_stock(PDO $pdo, int $limit = 100): array
{
    return inv_product_rows($pdo, [], $limit);
}

function inv_product_categories_in_use(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT DISTINCT category
        FROM products
        WHERE COALESCE(category, '') <> ''
        ORDER BY category ASC
    ")->fetchAll();

    $categories = [];
    foreach ($rows as $row) {
        $category = trim((string) ($row['category'] ?? ''));
        if ($category !== '') {
            $categories[] = $category;
        }
    }

    if ($categories) {
        return $categories;
    }

    return inv_categories();
}

function inv_inventory_summary(PDO $pdo): array
{
    $summary = [
        'total_products' => 0,
        'total_stock_units' => 0,
        'purchase_value' => 0.0,
        'selling_value' => 0.0,
        'expected_profit' => 0.0,
        'low_stock_count' => 0,
        'out_of_stock_count' => 0,
        'today_purchases_qty' => 0,
        'today_purchases_amount' => 0.0,
        'today_sales_qty' => 0,
        'today_sales_amount' => 0.0,
        'month_purchases_qty' => 0,
        'month_purchases_amount' => 0.0,
        'month_sales_qty' => 0,
        'month_sales_amount' => 0.0,
    ];

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_products,
            COALESCE(SUM(stock), 0) AS total_stock_units,
            COALESCE(SUM(stock * purchase_price), 0) AS purchase_value,
            COALESCE(SUM(stock * sale_price), 0) AS selling_value,
            COALESCE(SUM((stock * sale_price) - (stock * purchase_price)), 0) AS expected_profit,
            COALESCE(SUM(CASE WHEN stock > 0 AND stock <= low_stock_limit THEN 1 ELSE 0 END), 0) AS low_stock_count,
            COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count
        FROM products
    ");
    $row = $stmt->fetch() ?: [];
    $summary['total_products'] = (int) ($row['total_products'] ?? 0);
    $summary['total_stock_units'] = (int) ($row['total_stock_units'] ?? 0);
    $summary['purchase_value'] = (float) ($row['purchase_value'] ?? 0);
    $summary['selling_value'] = (float) ($row['selling_value'] ?? 0);
    $summary['expected_profit'] = (float) ($row['expected_profit'] ?? 0);
    $summary['low_stock_count'] = (int) ($row['low_stock_count'] ?? 0);
    $summary['out_of_stock_count'] = (int) ($row['out_of_stock_count'] ?? 0);

    $today = date('Y-m-d');
    $monthFrom = date('Y-m-01');
    $monthTo = date('Y-m-t');

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(total_amount), 0) AS amount
        FROM inventory_purchases
        WHERE purchase_date = :purchase_date
    ");
    $stmt->execute([':purchase_date' => $today]);
    $row = $stmt->fetch() ?: [];
    $summary['today_purchases_qty'] = (int) ($row['qty'] ?? 0);
    $summary['today_purchases_amount'] = (float) ($row['amount'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(quantity * sale_price), 0) AS amount
        FROM sales
        WHERE DATE(created_at) = :sale_date
    ");
    $stmt->execute([':sale_date' => $today]);
    $row = $stmt->fetch() ?: [];
    $summary['today_sales_qty'] = (int) ($row['qty'] ?? 0);
    $summary['today_sales_amount'] = (float) ($row['amount'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(total_amount), 0) AS amount
        FROM inventory_purchases
        WHERE purchase_date >= :from_date AND purchase_date <= :to_date
    ");
    $stmt->execute([':from_date' => $monthFrom, ':to_date' => $monthTo]);
    $row = $stmt->fetch() ?: [];
    $summary['month_purchases_qty'] = (int) ($row['qty'] ?? 0);
    $summary['month_purchases_amount'] = (float) ($row['amount'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(quantity * sale_price), 0) AS amount
        FROM sales
        WHERE DATE(created_at) >= :from_date AND DATE(created_at) <= :to_date
    ");
    $stmt->execute([':from_date' => $monthFrom, ':to_date' => $monthTo]);
    $row = $stmt->fetch() ?: [];
    $summary['month_sales_qty'] = (int) ($row['qty'] ?? 0);
    $summary['month_sales_amount'] = (float) ($row['amount'] ?? 0);

    return $summary;
}

function inv_inventory_analytics(PDO $pdo): array
{
    $bestSelling = $pdo->query("
        SELECT
            p.product_name,
            p.category,
            GREATEST(COALESCE(s.sold_qty, 0) - COALESCE(r.returned_qty, 0), 0) AS net_sold_qty
        FROM products p
        LEFT JOIN (
            SELECT product_id, COALESCE(SUM(quantity), 0) AS sold_qty
            FROM sales
            GROUP BY product_id
        ) s ON s.product_id = p.id
        LEFT JOIN (
            SELECT s.product_id, COALESCE(SUM(sr.quantity), 0) AS returned_qty
            FROM sales_returns sr
            JOIN sales s ON s.id = sr.sale_id
            GROUP BY s.product_id
        ) r ON r.product_id = p.id
        ORDER BY net_sold_qty DESC, p.product_name ASC
        LIMIT 5
    ")->fetchAll();

    $slowMoving = $pdo->query("
        SELECT
            p.product_name,
            p.category,
            GREATEST(COALESCE(s.sold_qty, 0) - COALESCE(r.returned_qty, 0), 0) AS net_sold_qty
        FROM products p
        LEFT JOIN (
            SELECT product_id, COALESCE(SUM(quantity), 0) AS sold_qty
            FROM sales
            GROUP BY product_id
        ) s ON s.product_id = p.id
        LEFT JOIN (
            SELECT s.product_id, COALESCE(SUM(sr.quantity), 0) AS returned_qty
            FROM sales_returns sr
            JOIN sales s ON s.id = sr.sale_id
            GROUP BY s.product_id
        ) r ON r.product_id = p.id
        ORDER BY net_sold_qty ASC, p.product_name ASC
        LIMIT 5
    ")->fetchAll();

    $highestProfit = $pdo->query("
        SELECT
            p.product_name,
            p.category,
            COALESCE(s.sale_profit, 0) + COALESCE(r.return_profit, 0) AS total_profit
        FROM products p
        LEFT JOIN (
            SELECT product_id, COALESCE(SUM(profit), 0) AS sale_profit
            FROM sales
            GROUP BY product_id
        ) s ON s.product_id = p.id
        LEFT JOIN (
            SELECT s.product_id, COALESCE(SUM(sr.profit_adjustment), 0) AS return_profit
            FROM sales_returns sr
            JOIN sales s ON s.id = sr.sale_id
            GROUP BY s.product_id
        ) r ON r.product_id = p.id
        ORDER BY total_profit DESC, p.product_name ASC
        LIMIT 5
    ")->fetchAll();

    $topCategories = $pdo->query("
        SELECT
            p.category,
            COALESCE(SUM(s.quantity), 0) AS sold_qty,
            COALESCE(SUM(s.quantity * s.sale_price), 0) AS sales_value
        FROM sales s
        JOIN products p ON p.id = s.product_id
        GROUP BY p.category
        ORDER BY sold_qty DESC, p.category ASC
        LIMIT 5
    ")->fetchAll();

    $trendRows = $pdo->query("
        SELECT
            DATE_FORMAT(created_at, '%Y-%m') AS ym,
            COALESCE(SUM(quantity * sale_price), 0) AS sales_value
        FROM sales
        WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY ym ASC
    ")->fetchAll();

    $trendMap = [];
    foreach ($trendRows as $row) {
        $trendMap[(string) ($row['ym'] ?? '')] = (float) ($row['sales_value'] ?? 0);
    }

    $monthlyTrend = [];
    $cursor = new DateTimeImmutable(date('Y-m-01'));
    for ($i = 5; $i >= 0; $i--) {
        $month = $cursor->modify('-' . $i . ' months');
        $key = $month->format('Y-m');
        $monthlyTrend[] = [
            'label' => $month->format('M Y'),
            'sales_value' => (float) ($trendMap[$key] ?? 0),
        ];
    }

    return [
        'best_selling' => $bestSelling,
        'slow_moving' => $slowMoving,
        'highest_profit' => $highestProfit,
        'top_categories' => $topCategories,
        'monthly_trend' => $monthlyTrend,
    ];
}

function inv_recent_purchases(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->query("
        SELECT
            ip.purchase_date,
            p.product_name,
            ip.supplier_name,
            ip.invoice_number,
            ip.quantity,
            ip.purchase_price,
            ip.total_amount
        FROM inventory_purchases ip
        JOIN products p ON p.id = ip.product_id
        ORDER BY ip.purchase_date DESC, ip.id DESC
        LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}

function inv_recent_damages(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->query("
        SELECT
            idm.damage_date,
            p.product_name,
            idm.quantity,
            idm.reason,
            idm.notes
        FROM inventory_damages idm
        JOIN products p ON p.id = idm.product_id
        ORDER BY idm.damage_date DESC, idm.id DESC
        LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}

function inv_purchase_rows(PDO $pdo, array $filters = [], int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $whereParts = ['ip.purchase_date >= :from_date', 'ip.purchase_date <= :to_date'];
    $params = [
        ':from_date' => inv_normalize_date((string) ($filters['from'] ?? ''), date('Y-m-01')),
        ':to_date' => inv_normalize_date((string) ($filters['to'] ?? ''), date('Y-m-d')),
    ];

    $productId = (int) ($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $whereParts[] = 'ip.product_id = :product_id';
        $params[':product_id'] = $productId;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $whereParts[] = '(
            p.product_name LIKE :q_product ESCAPE "\\"
            OR COALESCE(ip.supplier_name, "") LIKE :q_supplier ESCAPE "\\"
            OR COALESCE(ip.invoice_number, "") LIKE :q_invoice ESCAPE "\\"
        )';
        $params[':q_product'] = $like;
        $params[':q_supplier'] = $like;
        $params[':q_invoice'] = $like;
    }

    $stmt = $pdo->prepare("
        SELECT
            ip.*,
            p.product_name,
            p.category,
            a.name AS created_by_name
        FROM inventory_purchases ip
        JOIN products p ON p.id = ip.product_id
        LEFT JOIN admins a ON a.id = ip.created_by
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY ip.purchase_date DESC, ip.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function inv_damage_rows(PDO $pdo, array $filters = [], int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $whereParts = ['idm.damage_date >= :from_date', 'idm.damage_date <= :to_date'];
    $params = [
        ':from_date' => inv_normalize_date((string) ($filters['from'] ?? ''), date('Y-m-01')),
        ':to_date' => inv_normalize_date((string) ($filters['to'] ?? ''), date('Y-m-d')),
    ];

    $productId = (int) ($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $whereParts[] = 'idm.product_id = :product_id';
        $params[':product_id'] = $productId;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $whereParts[] = '(
            p.product_name LIKE :q_product ESCAPE "\\"
            OR COALESCE(idm.reason, "") LIKE :q_reason ESCAPE "\\"
            OR COALESCE(idm.notes, "") LIKE :q_notes ESCAPE "\\"
        )';
        $params[':q_product'] = $like;
        $params[':q_reason'] = $like;
        $params[':q_notes'] = $like;
    }

    $stmt = $pdo->prepare("
        SELECT
            idm.*,
            p.product_name,
            p.category,
            a.name AS created_by_name
        FROM inventory_damages idm
        JOIN products p ON p.id = idm.product_id
        LEFT JOIN admins a ON a.id = idm.created_by
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY idm.damage_date DESC, idm.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function inv_movement_rows(PDO $pdo, array $filters = [], int $limit = 300): array
{
    $limit = max(1, min(1000, $limit));
    $whereParts = ['im.movement_date >= :from_date', 'im.movement_date <= :to_date'];
    $params = [
        ':from_date' => inv_normalize_date((string) ($filters['from'] ?? ''), date('Y-m-01')),
        ':to_date' => inv_normalize_date((string) ($filters['to'] ?? ''), date('Y-m-d')),
    ];

    $productId = (int) ($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $whereParts[] = 'im.product_id = :product_id';
        $params[':product_id'] = $productId;
    }

    $type = trim((string) ($filters['type'] ?? ''));
    if ($type !== '') {
        $whereParts[] = 'im.transaction_type = :txn_type';
        $params[':txn_type'] = $type;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $whereParts[] = '(
            p.product_name LIKE :q_product ESCAPE "\\"
            OR COALESCE(im.reference_no, "") LIKE :q_reference ESCAPE "\\"
            OR COALESCE(im.remarks, "") LIKE :q_remarks ESCAPE "\\"
        )';
        $params[':q_product'] = $like;
        $params[':q_reference'] = $like;
        $params[':q_remarks'] = $like;
    }

    $stmt = $pdo->prepare("
        SELECT
            im.*,
            p.product_name,
            p.category,
            a.name AS created_by_name
        FROM inventory_movements im
        JOIN products p ON p.id = im.product_id
        LEFT JOIN admins a ON a.id = im.created_by
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY im.movement_date DESC, im.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function inv_log_movement(
    PDO $pdo,
    int $productId,
    string $movementDate,
    string $transactionType,
    int $quantity,
    int $previousStock,
    int $newStock,
    ?int $createdBy = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $referenceNo = null,
    ?string $remarks = null
): int {
    $stmt = $pdo->prepare("
        INSERT INTO inventory_movements
            (movement_date, product_id, transaction_type, quantity, previous_stock, new_stock, reference_type, reference_id, reference_no, remarks, created_by)
        VALUES
            (:movement_date, :product_id, :transaction_type, :quantity, :previous_stock, :new_stock, :reference_type, :reference_id, :reference_no, :remarks, :created_by)
    ");
    $stmt->execute([
        ':movement_date' => inv_normalize_date($movementDate),
        ':product_id' => $productId,
        ':transaction_type' => $transactionType,
        ':quantity' => abs($quantity),
        ':previous_stock' => $previousStock,
        ':new_stock' => $newStock,
        ':reference_type' => $referenceType !== null && $referenceType !== '' ? $referenceType : null,
        ':reference_id' => $referenceId !== null && $referenceId > 0 ? $referenceId : null,
        ':reference_no' => $referenceNo !== null && $referenceNo !== '' ? $referenceNo : null,
        ':remarks' => $remarks !== null && $remarks !== '' ? $remarks : null,
        ':created_by' => $createdBy,
    ]);

    return (int) $pdo->lastInsertId();
}

function inv_adjust_stock(
    PDO $pdo,
    int $productId,
    int $deltaQty,
    string $movementDate,
    string $transactionType,
    ?int $createdBy = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $referenceNo = null,
    ?string $remarks = null
): array {
    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = :id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $productId]);
    $stock = $stmt->fetchColumn();
    if ($stock === false) {
        throw new RuntimeException('Product not found.');
    }

    $previousStock = (int) $stock;
    $newStock = $previousStock + $deltaQty;
    if ($newStock < 0) {
        throw new RuntimeException('Insufficient stock for this product.');
    }

    $stmt = $pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
    $stmt->execute([
        ':stock' => $newStock,
        ':id' => $productId,
    ]);

    inv_log_movement(
        $pdo,
        $productId,
        $movementDate,
        $transactionType,
        abs($deltaQty),
        $previousStock,
        $newStock,
        $createdBy,
        $referenceType,
        $referenceId,
        $referenceNo,
        $remarks
    );

    return [
        'previous_stock' => $previousStock,
        'new_stock' => $newStock,
    ];
}

function inv_create_product(PDO $pdo, array $data, ?int $createdBy = null): int
{
    $productName = trim((string) ($data['product_name'] ?? ''));
    $category = trim((string) ($data['category'] ?? 'Others'));
    $brand = trim((string) ($data['brand'] ?? ''));
    $sku = trim((string) ($data['sku'] ?? ''));
    $barcode = trim((string) ($data['barcode'] ?? ''));
    $purchasePrice = (float) ($data['purchase_price'] ?? 0);
    $salePrice = (float) ($data['sale_price'] ?? 0);
    $openingStock = max(0, (int) ($data['opening_stock'] ?? 0));
    $lowStockLimit = max(0, (int) ($data['low_stock_limit'] ?? 0));
    $unit = trim((string) ($data['unit'] ?? 'Piece'));
    $createdDate = inv_normalize_date((string) ($data['created_date'] ?? ''), date('Y-m-d'));

    if ($productName === '') {
        throw new RuntimeException('Product name is required.');
    }

    if ($category === '') {
        $category = 'Others';
    }

    if ($unit === '') {
        $unit = 'Piece';
    }

    if ($sku === '') {
        $sku = inv_generate_sku($pdo);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO products
                (product_name, category, brand, sku, barcode, purchase_price, sale_price, opening_stock, stock, low_stock_limit, unit)
            VALUES
                (:product_name, :category, :brand, :sku, :barcode, :purchase_price, :sale_price, :opening_stock, :stock, :low_stock_limit, :unit)
        ");
        $stmt->execute([
            ':product_name' => $productName,
            ':category' => $category,
            ':brand' => $brand !== '' ? $brand : null,
            ':sku' => $sku,
            ':barcode' => $barcode !== '' ? $barcode : null,
            ':purchase_price' => $purchasePrice,
            ':sale_price' => $salePrice,
            ':opening_stock' => $openingStock,
            ':stock' => $openingStock,
            ':low_stock_limit' => $lowStockLimit,
            ':unit' => $unit,
        ]);
        $productId = (int) $pdo->lastInsertId();

        if ($openingStock > 0) {
            inv_log_movement(
                $pdo,
                $productId,
                $createdDate,
                'opening_stock',
                $openingStock,
                0,
                $openingStock,
                $createdBy,
                'product',
                $productId,
                'PRODUCT-' . $productId,
                'Opening stock added while creating product.'
            );
        }

        $pdo->commit();
        return $productId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function inv_update_product(PDO $pdo, int $productId, array $data, ?int $createdBy = null): void
{
    $existing = inv_find($pdo, $productId);
    if (!$existing) {
        throw new RuntimeException('Product not found.');
    }

    $productName = trim((string) ($data['product_name'] ?? $existing['product_name']));
    $category = trim((string) ($data['category'] ?? $existing['category'] ?? 'Others'));
    $brand = trim((string) ($data['brand'] ?? $existing['brand'] ?? ''));
    $sku = trim((string) ($data['sku'] ?? $existing['sku'] ?? ''));
    $barcode = trim((string) ($data['barcode'] ?? $existing['barcode'] ?? ''));
    $purchasePrice = (float) ($data['purchase_price'] ?? $existing['purchase_price']);
    $salePrice = (float) ($data['sale_price'] ?? $existing['sale_price']);
    $currentStock = max(0, (int) ($data['current_stock'] ?? $existing['current_stock']));
    $lowStockLimit = max(0, (int) ($data['low_stock_limit'] ?? $existing['low_stock_limit']));
    $unit = trim((string) ($data['unit'] ?? $existing['unit'] ?? 'Piece'));
    $adjustmentDate = inv_normalize_date((string) ($data['adjustment_date'] ?? ''), date('Y-m-d'));
    $adjustmentNotes = trim((string) ($data['adjustment_notes'] ?? ''));

    if ($productName === '') {
        throw new RuntimeException('Product name is required.');
    }

    if ($category === '') {
        $category = 'Others';
    }

    if ($unit === '') {
        $unit = 'Piece';
    }

    if ($sku === '') {
        $sku = (string) ($existing['sku'] ?? '');
        if ($sku === '') {
            $sku = inv_generate_sku($pdo);
        }
    }

    $previousStock = (int) ($existing['current_stock'] ?? 0);
    $deltaQty = $currentStock - $previousStock;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE products
            SET product_name = :product_name,
                category = :category,
                brand = :brand,
                sku = :sku,
                barcode = :barcode,
                purchase_price = :purchase_price,
                sale_price = :sale_price,
                low_stock_limit = :low_stock_limit,
                unit = :unit
            WHERE id = :id
        ");
        $stmt->execute([
            ':product_name' => $productName,
            ':category' => $category,
            ':brand' => $brand !== '' ? $brand : null,
            ':sku' => $sku,
            ':barcode' => $barcode !== '' ? $barcode : null,
            ':purchase_price' => $purchasePrice,
            ':sale_price' => $salePrice,
            ':low_stock_limit' => $lowStockLimit,
            ':unit' => $unit,
            ':id' => $productId,
        ]);

        if ($deltaQty !== 0) {
            inv_adjust_stock(
                $pdo,
                $productId,
                $deltaQty,
                $adjustmentDate,
                'manual_adjustment',
                $createdBy,
                'product',
                $productId,
                'ADJ-' . $productId,
                $adjustmentNotes !== '' ? $adjustmentNotes : 'Stock adjusted from product edit.'
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function inv_add_purchase(PDO $pdo, array $data, ?int $createdBy = null): int
{
    $productId = (int) ($data['product_id'] ?? 0);
    $purchaseDate = inv_normalize_date((string) ($data['purchase_date'] ?? ''), date('Y-m-d'));
    $supplierName = trim((string) ($data['supplier_name'] ?? ''));
    $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
    $quantity = max(0, (int) ($data['quantity'] ?? 0));
    $purchasePrice = (float) ($data['purchase_price'] ?? 0);
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($productId <= 0) {
        throw new RuntimeException('Please select a product.');
    }
    if ($quantity <= 0) {
        throw new RuntimeException('Quantity must be greater than zero.');
    }
    if ($purchasePrice < 0) {
        throw new RuntimeException('Purchase price is invalid.');
    }

    $totalAmount = round($quantity * $purchasePrice, 2);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory_purchases
                (purchase_date, supplier_name, invoice_number, product_id, quantity, purchase_price, total_amount, notes, created_by)
            VALUES
                (:purchase_date, :supplier_name, :invoice_number, :product_id, :quantity, :purchase_price, :total_amount, :notes, :created_by)
        ");
        $stmt->execute([
            ':purchase_date' => $purchaseDate,
            ':supplier_name' => $supplierName !== '' ? $supplierName : null,
            ':invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':purchase_price' => $purchasePrice,
            ':total_amount' => $totalAmount,
            ':notes' => $notes !== '' ? $notes : null,
            ':created_by' => $createdBy,
        ]);
        $purchaseId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('UPDATE products SET purchase_price = :purchase_price WHERE id = :id');
        $stmt->execute([
            ':purchase_price' => $purchasePrice,
            ':id' => $productId,
        ]);

        $remarksParts = [];
        if ($supplierName !== '') {
            $remarksParts[] = 'Supplier: ' . $supplierName;
        }
        if ($invoiceNumber !== '') {
            $remarksParts[] = 'Invoice: ' . $invoiceNumber;
        }
        if ($notes !== '') {
            $remarksParts[] = $notes;
        }

        inv_adjust_stock(
            $pdo,
            $productId,
            $quantity,
            $purchaseDate,
            'purchase',
            $createdBy,
            'purchase',
            $purchaseId,
            $invoiceNumber !== '' ? $invoiceNumber : ('PUR-' . $purchaseId),
            implode(' | ', $remarksParts)
        );

        $pdo->commit();
        return $purchaseId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function inv_add_damage(PDO $pdo, array $data, ?int $createdBy = null): int
{
    $productId = (int) ($data['product_id'] ?? 0);
    $damageDate = inv_normalize_date((string) ($data['damage_date'] ?? ''), date('Y-m-d'));
    $quantity = max(0, (int) ($data['quantity'] ?? 0));
    $reason = trim((string) ($data['reason'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($productId <= 0) {
        throw new RuntimeException('Please select a product.');
    }
    if ($quantity <= 0) {
        throw new RuntimeException('Quantity must be greater than zero.');
    }
    if ($reason === '') {
        throw new RuntimeException('Reason is required.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory_damages
                (product_id, quantity, damage_date, reason, notes, created_by)
            VALUES
                (:product_id, :quantity, :damage_date, :reason, :notes, :created_by)
        ");
        $stmt->execute([
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':damage_date' => $damageDate,
            ':reason' => $reason,
            ':notes' => $notes !== '' ? $notes : null,
            ':created_by' => $createdBy,
        ]);
        $damageId = (int) $pdo->lastInsertId();

        $remarks = $reason;
        if ($notes !== '') {
            $remarks .= ' | ' . $notes;
        }

        inv_adjust_stock(
            $pdo,
            $productId,
            -1 * $quantity,
            $damageDate,
            'damage',
            $createdBy,
            'damage',
            $damageId,
            'DMG-' . $damageId,
            $remarks
        );

        $pdo->commit();
        return $damageId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
