<?php

declare(strict_types=1);

function load_get_networks(PDO $pdo): array
{
    $rows = $pdo->query('SELECT network_name FROM load_networks ORDER BY network_name ASC')->fetchAll();
    $networks = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['network_name'] ?? ''));
        if ($name !== '') {
            $networks[] = $name;
        }
    }
    if ($networks) {
        return $networks;
    }
    return ['Jazz', 'Zong', 'Ufone', 'Telenor'];
}

function load_recalculate_network(PDO $pdo, string $network): void
{
    $network = trim($network);
    if ($network === '') {
        return;
    }

    $stmt = $pdo->prepare('
        SELECT id, type, opening_balance, purchased, sold
        FROM load_transactions
        WHERE network = :network
        ORDER BY date ASC, id ASC
    ');
    $stmt->execute([':network' => $network]);
    $transactions = $stmt->fetchAll();

    $balance = 0.0;

    $update = $pdo->prepare('
        UPDATE load_transactions
        SET opening_balance = :opening_balance,
            purchased = :purchased,
            sold = :sold,
            closing_balance = :closing_balance
        WHERE id = :id
    ');

    foreach ($transactions as $t) {
        $id = (int) $t['id'];
        $type = (string) $t['type'];
        $opening = 0.0;
        $purchased = (float) $t['purchased'];
        $sold = (float) $t['sold'];
        $closing = 0.0;

        if ($type === 'opening') {
            $opening = (float) $t['opening_balance'];
            $purchased = 0.0;
            $sold = 0.0;
            $closing = $opening;
            $balance = $closing;
        } elseif ($type === 'purchase') {
            $opening = $balance;
            $closing = $opening + $purchased;
            $balance = $closing;
            $sold = 0.0;
        } elseif ($type === 'sale') {
            $opening = $balance;
            $closing = $opening - $sold;
            $balance = $closing;
            $purchased = 0.0;
        } else {
            $opening = $balance;
            $closing = $opening + $purchased - $sold;
            $balance = $closing;
        }

        $update->execute([
            ':opening_balance' => $opening,
            ':purchased' => $purchased,
            ':sold' => $sold,
            ':closing_balance' => $closing,
            ':id' => $id,
        ]);
    }
}

function load_find_transaction(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM load_transactions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

