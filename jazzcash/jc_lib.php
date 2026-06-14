<?php

declare(strict_types=1);

function jc_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM jazzcash_transactions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function jc_totals(PDO $pdo, ?string $from = null, ?string $to = null): array
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
            COALESCE(SUM(CASE WHEN type='receiving' THEN amount ELSE 0 END), 0) AS total_receiving,
            COALESCE(SUM(CASE WHEN type='sending' THEN amount ELSE 0 END), 0) AS total_sending,
            COALESCE(SUM(charges), 0) AS total_charges
        FROM jazzcash_transactions
        {$where}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
        'receiving' => (float) ($row['total_receiving'] ?? 0),
        'sending' => (float) ($row['total_sending'] ?? 0),
        'commission' => (float) ($row['total_charges'] ?? 0),
    ];
}

