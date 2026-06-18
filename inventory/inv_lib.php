<?php

declare(strict_types=1);

function inv_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function inv_product_rows_with_stock(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.product_name,
            p.purchase_price,
            p.sale_price,
            p.stock AS opening_stock,
            p.low_stock_limit,
            COALESCE(s.sold_qty, 0) AS sold_qty,
            COALESCE(r.returned_qty, 0) AS returned_qty,
            (p.stock - COALESCE(s.sold_qty, 0) + COALESCE(r.returned_qty, 0)) AS current_stock,
            p.created_at
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
        ORDER BY p.product_name ASC
        LIMIT {$limit}
    ");
    return $stmt->fetchAll();
}
