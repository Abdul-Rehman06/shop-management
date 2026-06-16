<?php

declare(strict_types=1);

function ep_find(PDO $pdo, int $id): ?array
{
    $row = wallet_transaction($pdo, $id);
    if (!$row || (string) ($row['account_type'] ?? '') !== 'easypaisa') {
        return null;
    }
    return $row;
}

function ep_totals(PDO $pdo, int $accountId): array
{
    return wallet_balance_summary($pdo, $accountId);
}
