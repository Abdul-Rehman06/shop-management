<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/report_lib.php';

$pdo = db();
$filters = report_filters_from_request();
$canViewProfit = app_can_view_profit();
if (!$canViewProfit && in_array($filters['module'], ['sales', 'load'], true)) {
    http_response_code(403);
    exit;
}
$data = report_fetch($pdo, $filters);

$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'xls', 'pdf'], true)) {
    $format = 'csv';
}

$filename = report_filename($filters, $format);

function report_output_csv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
}

function report_output_xls(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<table border="1">';
    echo '<thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $cell) {
            echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function report_pdf_escape(string $s): string
{
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace('(', '\\(', $s);
    $s = str_replace(')', '\\)', $s);
    return $s;
}

function report_output_pdf(string $filename, array $headers, array $rows, array $filters, array $summary): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $modules = report_modules();
    $title = ($modules[$filters['module']] ?? 'Report') . ' (' . $filters['from'] . ' to ' . $filters['to'] . ')';

    $lines = [];
    $lines[] = $title;
    if ($summary) {
        $pairs = [];
        foreach ($summary as $k => $v) {
            $pairs[] = $k . ': ' . $v;
        }
        $lines[] = implode('   ', $pairs);
    }
    $lines[] = '';

    $colWidths = [];
    foreach ($headers as $i => $h) {
        $colWidths[$i] = max(4, min(20, strlen((string) $h)));
    }
    foreach ($rows as $r) {
        foreach ($r as $i => $cell) {
            $colWidths[$i] = max($colWidths[$i] ?? 4, min(20, strlen((string) $cell)));
        }
    }

    $renderRow = static function (array $r) use ($colWidths): string {
        $cells = [];
        foreach ($r as $i => $cell) {
            $w = $colWidths[$i] ?? 10;
            $text = (string) $cell;
            if (strlen($text) > $w) {
                $text = substr($text, 0, $w - 1) . '…';
            }
            $cells[] = str_pad($text, $w);
        }
        return rtrim(implode(' | ', $cells));
    };

    $lines[] = $renderRow($headers);
    $lines[] = str_repeat('-', min(120, strlen($lines[count($lines) - 1])));
    foreach ($rows as $r) {
        $lines[] = $renderRow($r);
    }

    $fontSize = 9;
    $leading = 11;
    $x = 40;
    $y = 780;

    $content = "BT\n/F1 {$fontSize} Tf\n{$leading} TL\n{$x} {$y} Td\n";
    foreach ($lines as $idx => $line) {
        $content .= '(' . report_pdf_escape($line) . ") Tj\n";
        if ($idx !== count($lines) - 1) {
            $content .= "T*\n";
        }
    }
    $content .= "ET\n";

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    for ($i = 0; $i < count($objects); $i++) {
        $offsets[] = strlen($pdf);
        $objNum = $i + 1;
        $pdf .= "{$objNum} 0 obj\n{$objects[$i]}\nendobj\n";
    }

    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefStart}\n%%EOF";

    echo $pdf;
}

if ($format === 'xls') {
    report_output_xls($filename, $data['headers'], $data['rows']);
    exit;
}

if ($format === 'pdf') {
    report_output_pdf($filename, $data['headers'], $data['rows'], $filters, $data['summary'] ?? []);
    exit;
}

report_output_csv($filename, $data['headers'], $data['rows']);
exit;
