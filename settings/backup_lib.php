<?php

declare(strict_types=1);

function backup_dir(): string
{
    $dir = realpath(__DIR__ . '/../backup');
    if ($dir === false) {
        $dir = __DIR__ . '/../backup';
    }
    return $dir;
}

function backup_filename(): string
{
    return 'shop_backup_' . date('Ymd_His') . '.sql';
}

function backup_sql_escape(string $value): string
{
    return str_replace(["\\", "\0", "\n", "\r", "\x1a", "'", '"'], ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'", '\\"'], $value);
}

function backup_generate_sql(PDO $pdo): string
{
    $sql = [];
    $sql[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
    $sql[] = "SET time_zone = '+00:00';";
    $sql[] = "SET foreign_key_checks = 0;";
    $sql[] = "";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $t) {
        $table = (string) ($t[0] ?? '');
        if ($table === '') {
            continue;
        }

        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = (string) ($createRow['Create Table'] ?? '');
        if ($createSql !== '') {
            $sql[] = "DROP TABLE IF EXISTS `{$table}`;";
            $sql[] = $createSql . ';';
            $sql[] = '';
        }

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            continue;
        }

        $columns = array_keys($rows[0]);
        $colList = '`' . implode('`,`', array_map(static fn($c) => str_replace('`', '``', (string) $c), $columns)) . '`';

        foreach ($rows as $r) {
            $vals = [];
            foreach ($columns as $c) {
                $v = $r[$c];
                if ($v === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($v) || is_float($v)) {
                    $vals[] = (string) $v;
                } elseif (is_numeric($v) && preg_match('/^-?\d+(\.\d+)?$/', (string) $v) === 1) {
                    $vals[] = (string) $v;
                } else {
                    $vals[] = "'" . backup_sql_escape((string) $v) . "'";
                }
            }
            $sql[] = "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(',', $vals) . ");";
        }

        $sql[] = '';
    }

    $sql[] = "SET foreign_key_checks = 1;";
    $sql[] = '';
    return implode("\n", $sql);
}

function restore_split_sql(string $sql): array
{
    $len = strlen($sql);
    $statements = [];
    $buffer = '';

    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($ch === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

