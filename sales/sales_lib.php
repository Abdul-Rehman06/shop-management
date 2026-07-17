<?php

declare(strict_types=1);

require_once __DIR__ . '/../inventory/inv_lib.php';

function sales_products(PDO $pdo): array
{
    return $pdo->query('
        SELECT id, product_name, category, brand, sku, purchase_price, sale_price, stock
        FROM products
        ORDER BY product_name ASC
    ')->fetchAll();
}

function sales_product_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT id, product_name, category, brand, sku, purchase_price, sale_price, stock
        FROM products
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sales_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sales_returned_qty(PDO $pdo, int $saleId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM sales_returns WHERE sale_id = :sale_id");
    $stmt->execute([':sale_id' => $saleId]);
    return (int) $stmt->fetchColumn();
}

function sales_profit_adjustment_for_return(float $saleProfit, int $saleQty, int $returnQty): float
{
    if ($saleQty <= 0 || $returnQty <= 0) {
        return 0.0;
    }
    $unitProfit = $saleProfit / $saleQty;
    return -1 * $unitProfit * $returnQty;
}

function sales_profit_total(float $purchasePrice, float $salePrice, int $quantity): float
{
    return ($salePrice - $purchasePrice) * $quantity;
}

function sales_has_returns(PDO $pdo, int $saleId): bool
{
    return sales_returned_qty($pdo, $saleId) > 0;
}
