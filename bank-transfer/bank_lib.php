<?php

declare(strict_types=1);

function bank_find(PDO $pdo, int $id): ?array
{
    $row = wallet_transaction($pdo, $id);
    if (!$row || (string) ($row['account_type'] ?? '') !== 'bank') {
        return null;
    }
    return $row;
}

function bank_totals(PDO $pdo, int $accountId): array
{
    $t = wallet_balance_summary($pdo, $accountId);
    $received = (float) ($t['receiving'] ?? 0);
    $sent = (float) ($t['sending'] ?? 0);
    $charges = (float) ($t['commission'] ?? 0);

    return [
        'opening' => (float) ($t['opening'] ?? 0),
        'received' => $received,
        'sent' => $sent,
        'charges' => $charges,
        'net' => $received - $sent,
        'closing' => (float) ($t['closing'] ?? 0),
    ];
}
