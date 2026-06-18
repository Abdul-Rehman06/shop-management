<?php

declare(strict_types=1);

function load_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS load_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            network VARCHAR(50) NOT NULL,
            date DATE NOT NULL,
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            purchased_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sold_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            profit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            closing_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_load_entries_date_network (date, network),
            KEY idx_load_entries_date (date),
            KEY idx_load_entries_network (network)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $count = (int) $pdo->query("SELECT COUNT(*) FROM load_entries")->fetchColumn();
    if ($count !== 0) {
        return;
    }

    $hasOld = (bool) $pdo->query("SHOW TABLES LIKE 'load_transactions'")->fetchColumn();
    if (!$hasOld) {
        return;
    }

    $oldCount = (int) $pdo->query("SELECT COUNT(*) FROM load_transactions")->fetchColumn();
    if ($oldCount === 0) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("
            SELECT
                network,
                date,
                MIN(opening_balance) AS first_opening_guess,
                COALESCE(SUM(CASE WHEN type='purchase' THEN purchased ELSE 0 END), 0) AS purchased_total,
                COALESCE(SUM(CASE WHEN type='sale' THEN sold ELSE 0 END), 0) AS sold_total,
                COALESCE(SUM(profit), 0) AS profit_total
            FROM load_transactions
            GROUP BY network, date
            ORDER BY date ASC
        ");
        $groups = $stmt->fetchAll();

        $closingStmt = $pdo->prepare("
            SELECT closing_balance
            FROM load_transactions
            WHERE network = :network AND date = :date
            ORDER BY id DESC
            LIMIT 1
        ");

        $ins = $pdo->prepare("
            INSERT INTO load_entries
                (network, date, opening_balance, purchased_balance, sold_balance, profit, closing_balance)
            VALUES
                (:network, :date, :opening, :purchased, :sold, :profit, :closing)
        ");

        foreach ($groups as $g) {
            $network = (string) ($g['network'] ?? '');
            $date = (string) ($g['date'] ?? '');
            if ($network === '' || $date === '') {
                continue;
            }

            $opening = (float) ($g['first_opening_guess'] ?? 0);
            $purchased = (float) ($g['purchased_total'] ?? 0);
            $sold = (float) ($g['sold_total'] ?? 0);
            $profit = (float) ($g['profit_total'] ?? 0);

            $closingStmt->execute([':network' => $network, ':date' => $date]);
            $closing = (float) ($closingStmt->fetchColumn() ?: 0);
            if ($closing == 0.0) {
                $closing = $opening + $purchased - $sold;
            }

            $ins->execute([
                ':network' => $network,
                ':date' => $date,
                ':opening' => $opening,
                ':purchased' => $purchased,
                ':sold' => $sold,
                ':profit' => $profit,
                ':closing' => $closing,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

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

function load_entry(PDO $pdo, string $date, string $network): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, network, date, opening_balance, purchased_balance, sold_balance, profit, closing_balance
        FROM load_entries
        WHERE date = :date AND network = :network
        LIMIT 1
    ");
    $stmt->execute([':date' => $date, ':network' => $network]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function load_upsert_entry(PDO $pdo, string $date, string $network, float $opening, float $purchased, float $sold, float $profit): void
{
    $closing = $opening + $purchased - $sold;
    $stmt = $pdo->prepare("
        INSERT INTO load_entries (network, date, opening_balance, purchased_balance, sold_balance, profit, closing_balance)
        VALUES (:network, :date, :opening, :purchased, :sold, :profit, :closing)
        ON DUPLICATE KEY UPDATE
            opening_balance = VALUES(opening_balance),
            purchased_balance = VALUES(purchased_balance),
            sold_balance = VALUES(sold_balance),
            profit = VALUES(profit),
            closing_balance = VALUES(closing_balance)
    ");
    $stmt->execute([
        ':network' => $network,
        ':date' => $date,
        ':opening' => $opening,
        ':purchased' => $purchased,
        ':sold' => $sold,
        ':profit' => $profit,
        ':closing' => $closing,
    ]);
}
