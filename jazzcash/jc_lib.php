<?php

declare(strict_types=1);

function jc_find(PDO $pdo, int $id): ?array
{
    $row = wallet_transaction($pdo, $id);
    if (!$row || (string) ($row['account_type'] ?? '') !== 'jazzcash') {
        return null;
    }
    return $row;
}

function jc_totals(PDO $pdo, int $accountId): array
{
    return wallet_balance_summary($pdo, $accountId);
}
