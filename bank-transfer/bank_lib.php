<?php

declare(strict_types=1);

function bank_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM bank_transactions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bank_totals(PDO $pdo, ?string $from = null, ?string $to = null): array
{
    $where = '';
    $params = [];
    if ($from !== null && $to !== null) {
        $where = 'WHERE date >= :from AND date <= :to';
        $params[':from'] = $from;
        $params[':to'] = $to;
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN type='receiving' THEN amount ELSE 0 END), 0) AS total_received,
            COALESCE(SUM(CASE WHEN type='sending' THEN amount ELSE 0 END), 0) AS total_sent,
            COALESCE(SUM(charges), 0) AS total_charges
        FROM bank_transactions
        {$where}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    $received = (float) ($row['total_received'] ?? 0);
    $sent = (float) ($row['total_sent'] ?? 0);
    $charges = (float) ($row['total_charges'] ?? 0);

    return [
        'received' => $received,
        'sent' => $sent,
        'charges' => $charges,
        'net' => $received - $sent,
    ];
}

