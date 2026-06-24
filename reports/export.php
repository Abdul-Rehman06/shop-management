<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/report_lib.php';

$pdo = db();
$filters = report_filters_from_request();
$canViewProfit = app_can_view_profit();
if (!$canViewProfit && in_array($filters['module'], ['sales', 'load', 'load_txn'], true)) {
    http_response_code(403);
    exit;
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'xls', 'pdf'], true)) {
    $format = 'csv';
}

$filename = report_filename($filters, $format);

function report_output_csv_multi(string $filename, array $sections): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($sections as $s) {
        $title = (string) ($s['title'] ?? '');
        if ($title !== '') {
            fputcsv($out, [$title]);
        }
        $summary = $s['summary'] ?? [];
        if (is_array($summary) && $summary) {
            $pairs = [];
            foreach ($summary as $k => $v) {
                $pairs[] = (string) $k . ': ' . (string) $v;
            }
            fputcsv($out, [implode('   ', $pairs)]);
        }
        fputcsv($out, []);
        $headers = $s['headers'] ?? [];
        if (is_array($headers) && $headers) {
            fputcsv($out, $headers);
        }
        $rows = $s['rows'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (is_array($r)) {
                    fputcsv($out, $r);
                }
            }
        }
        fputcsv($out, []);
        fputcsv($out, []);
    }
    fclose($out);
}

function report_output_xls_multi(string $filename, array $sections, array $filters): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<div style="font-family: Arial, sans-serif; font-size: 12px;">';
    echo '<h2 style="margin: 0 0 6px 0;">All Reports</h2>';
    echo '<div style="margin: 0 0 14px 0; color: #555;">' . htmlspecialchars($filters['from'] . ' to ' . $filters['to'], ENT_QUOTES, 'UTF-8') . '</div>';
    foreach ($sections as $s) {
        $title = (string) ($s['title'] ?? '');
        if ($title !== '') {
            echo '<h3 style="margin: 18px 0 8px 0;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
        }
        $summary = $s['summary'] ?? [];
        if (is_array($summary) && $summary) {
            echo '<table border="1" style="border-collapse: collapse; margin-bottom: 10px;">';
            foreach ($summary as $k => $v) {
                echo '<tr><td><b>' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '</b></td><td>' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            echo '</table>';
        }
        $headers = $s['headers'] ?? [];
        $rows = $s['rows'] ?? [];
        if (is_array($headers) && $headers) {
            echo '<table border="1" style="border-collapse: collapse; width: 100%;">';
            echo '<thead><tr>';
            foreach ($headers as $h) {
                echo '<th style="background:#f3f4f6;">' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
            }
            echo '</tr></thead><tbody>';
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    echo '<tr>';
                    foreach ((array) $r as $cell) {
                        echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                    }
                    echo '</tr>';
                }
            }
            echo '</tbody></table>';
        }
    }
    echo '</div></body></html>';
}

function report_output_pdf_multi(string $filename, array $sections, array $filters): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if (class_exists('Mpdf\\Mpdf')) {
        $modules = report_modules();
        $title = ($modules[$filters['module']] ?? 'Report') . ' (' . $filters['from'] . ' to ' . $filters['to'] . ')';

        $html = '<div style="font-family: sans-serif;">';
        $html .= '<h2 style="margin: 0 0 6px 0; color: #111827;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
        $html .= '<div style="margin: 0 0 14px 0; color: #6b7280; font-size: 12px;">' . htmlspecialchars($filters['from'] . ' to ' . $filters['to'], ENT_QUOTES, 'UTF-8') . '</div>';

        foreach ($sections as $s) {
            $st = (string) ($s['title'] ?? '');
            if ($st !== '') {
                $html .= '<h3 style="margin: 18px 0 8px 0; color:#111827;">' . htmlspecialchars($st, ENT_QUOTES, 'UTF-8') . '</h3>';
            }
            $summary = $s['summary'] ?? [];
            if (is_array($summary) && $summary) {
                $html .= '<table style="width:100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11px;">';
                foreach ($summary as $k => $v) {
                    $html .= '<tr>';
                    $html .= '<td style="border:1px solid #e5e7eb; padding:6px; width: 30%; background:#f9fafb;"><b>' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '</b></td>';
                    $html .= '<td style="border:1px solid #e5e7eb; padding:6px;">' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }
            $headers = $s['headers'] ?? [];
            $rows = $s['rows'] ?? [];
            if (is_array($headers) && $headers) {
                $html .= '<table style="width:100%; border-collapse: collapse; font-size: 10px; margin-bottom: 10px;">';
                $html .= '<thead><tr style="background:#f3f4f6;">';
                foreach ($headers as $h) {
                    $html .= '<th style="border:1px solid #e5e7eb; padding:6px; text-align:left;">' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $html .= '<tr>';
                        foreach ((array) $r as $cell) {
                            $html .= '<td style="border:1px solid #e5e7eb; padding:6px;">' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                        }
                        $html .= '</tr>';
                    }
                }
                $html .= '</tbody></table>';
            }
        }

        $html .= '</div>';
        $mpdf = new \Mpdf\Mpdf(['orientation' => 'L']);
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, 'D');
        return;
    }

    $lines = [];
    $lines[] = 'All Reports (' . $filters['from'] . ' to ' . $filters['to'] . ')';
    $lines[] = '';
    foreach ($sections as $s) {
        $title = (string) ($s['title'] ?? '');
        if ($title !== '') {
            $lines[] = $title;
        }
        $summary = $s['summary'] ?? [];
        if (is_array($summary) && $summary) {
            $pairs = [];
            foreach ($summary as $k => $v) {
                $pairs[] = (string) $k . ': ' . (string) $v;
            }
            $lines[] = implode('   ', $pairs);
        }
        $headers = $s['headers'] ?? [];
        $rows = $s['rows'] ?? [];
        if (is_array($headers) && $headers) {
            $lines[] = implode(' | ', array_map(static fn ($x): string => (string) $x, $headers));
            $lines[] = str_repeat('-', 110);
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $lines[] = implode(' | ', array_map(static fn ($x): string => (string) $x, (array) $r));
                }
            }
        }
        $lines[] = '';
    }

    $fontSize = 8;
    $leading = 10;
    $x = 30;
    $y = 780;

    $content = "BT\n/F1 {$fontSize} Tf\n{$leading} TL\n{$x} {$y} Td\n";
    foreach ($lines as $idx => $line) {
        $content .= '(' . report_pdf_escape((string) $line) . ") Tj\n";
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

if ($filters['module'] === 'all') {
    $modules = report_modules();
    if (!$canViewProfit) {
        unset($modules['sales'], $modules['load'], $modules['load_txn']);
    }
    $sections = [];
    foreach ($modules as $k => $label) {
        if ($k === 'all') {
            continue;
        }
        $f = $filters;
        $f['module'] = $k;
        $d = report_fetch($pdo, $f);
        $sections[] = [
            'title' => (string) $label,
            'headers' => $d['headers'] ?? [],
            'rows' => $d['rows'] ?? [],
            'summary' => $d['summary'] ?? [],
        ];
    }

    if ($format === 'xls') {
        report_output_xls_multi($filename, $sections, $filters);
        exit;
    }
    if ($format === 'pdf') {
        report_output_pdf_multi($filename, $sections, $filters);
        exit;
    }
    report_output_csv_multi($filename, $sections);
    exit;
}

$data = report_fetch($pdo, $filters);
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
