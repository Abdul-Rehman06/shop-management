<?php

declare(strict_types=1);

function sales_products(PDO $pdo): array
{
    return $pdo->query('
        SELECT id, product_name, purchase_price, sale_price
        FROM products
        ORDER BY product_name ASC
    ')->fetchAll();
}

function sales_product_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT id, product_name, purchase_price, sale_price
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

function sales_profit_total(float $purchasePrice, float $salePrice, int $quantity): float
{
    return ($salePrice - $purchasePrice) * $quantity;
}

