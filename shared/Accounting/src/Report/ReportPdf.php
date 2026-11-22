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
        if (!\function_exists('schoolPlainAddress')) {
            require_once dirname(__DIR__, 4) . '/bmlhs/includes/site.php';
        }
        $settings = \function_exists('schoolSettings') && \function_exists('getDatabaseConnection')
            ? \schoolSettings(\getDatabaseConnection())
            : [];
        $mpdf = \createBanglaMpdf($title, $title, 'A4', 'L');
        $mpdf->SetMargins(12, 12, 12);
        $mpdf->SetAutoPageBreak(true, 12);
        $head = '';
        foreach ($headers as $header) {
            $head .= '<th>' . \mpdfClearBnDigitsHtml($header) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $cell) {
                $body .= '<td>' . \mpdfClearBnDigitsHtml($cell) . '</td>';
            }
            $body .= '</tr>';
        }
        $pad = $settings !== [] ? \mpdfSchoolPadHtml($settings) : '';
        $html = $pad
            . '<h1>' . \mpdfClearBnDigitsHtml(\h($title)) . '</h1>'
            . '<p class="sub">' . \mpdfClearBnDigitsHtml(\h($subtitle)) . '</p>'
            . '<table class="report"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
        $font = \mpdfBanglaFontFamily();
        $digitFont = \mpdfBanglaDigitsFontFamily();
        $css = \mpdfSchoolPadCss()
            . "body{font-family:{$font},sans-serif;font-size:11pt}"
            . ".bn-num{font-family:{$digitFont},{$font},sans-serif}"
            . 'h1{font-size:14pt;margin:2mm 0 1mm;text-align:center}'
            . '.sub{color:#444;margin:0 0 3mm;text-align:center;font-size:10pt}'
            . 'table.report{width:100%;border-collapse:collapse;font-size:10pt}'
            . 'table.report th,table.report td{border:0.25mm solid #687b72;padding:1.5mm 1.5mm;vertical-align:top}'
            . 'table.report th{background:#e5ede9;font-weight:bold}';
        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'report.pdf';
        $mpdf->Output(str_ends_with($safe, '.pdf') ? $safe : $safe . '.pdf', \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    }
}
