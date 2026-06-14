<?php

declare(strict_types=1);

function exp_categories(): array
{
    return ['Rent', 'Bills', 'Salary', 'Maintenance', 'Other'];
}

function exp_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function exp_total(PDO $pdo, string $from, string $to, string $category = ''): float
{
    $params = [
        ':from' => $from,
        ':to' => $to,
    ];
    $where = 'WHERE date >= :from AND date <= :to';
    if ($category !== '') {
        $where .= ' AND category = :category';
        $params[':category'] = $category;
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses {$where}");
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

