<?php

declare(strict_types=1);

namespace School\Accounting\Report;

/**
 * Landscape A4 statement. Cells are escaped by the caller with h().
 */
final class ReportPdf
{
    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows already escaped HTML text
     */
    public static function download(string $filename, string $title, string $subtitle, array $headers, array $rows): void
    {
        require_once dirname(__DIR__, 3) . '/mpdf-bangla.php';
        $mpdf = \createBanglaMpdf($title, $title, 'A4', 'L');
        $mpdf->SetMargins(10, 10, 12);
        $mpdf->SetAutoPageBreak(true, 12);
        $head = '';
        foreach ($headers as $header) {
            $head .= '<th>' . $header . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $cell) {
                $body .= '<td>' . $cell . '</td>';
            }
            $body .= '</tr>';
        }
        $html = '<h1>' . \h($title) . '</h1><p class="sub">' . \h($subtitle) . '</p>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
        $css = 'body{font-size:11px} h1{font-size:16px;margin:0 0 4px} .sub{color:#444;margin:0 0 10px} table{width:100%;border-collapse:collapse} th,td{border:1px solid #bbb;padding:4px 6px;vertical-align:top} th{background:#eef4f1}';
        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'report.pdf';
        $mpdf->Output(str_ends_with($safe, '.pdf') ? $safe : $safe . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    }
}
