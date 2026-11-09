<?php

declare(strict_types=1);

namespace School\Accounting\Report;

/**
 * One-sheet Excel 2003 workbook (SpreadsheetML). No extra library, and Bengali
 * text stays UTF-8. Numbers are real numeric cells.
 */
final class Workbook
{
    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public static function download(string $filename, string $sheetName, array $headers, array $rows): void
    {
        $xml = self::xml($sheetName, $headers, $rows);
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . self::filename($filename) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $xml;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public static function xml(string $sheetName, array $headers, array $rows): string
    {
        $sheet = self::escape(mb_substr($sheetName, 0, 31));
        $body = '<Row>';
        foreach ($headers as $header) {
            $body .= '<Cell><Data ss:Type="String">' . self::escape((string) $header) . '</Data></Cell>';
        }
        $body .= '</Row>';
        foreach ($rows as $row) {
            $body .= '<Row>';
            foreach ($row as $cell) {
                if (is_int($cell) || (is_string($cell) && preg_match('/^-?\d+\.\d{2}$/', $cell) === 1)) {
                    $body .= '<Cell><Data ss:Type="Number">' . $cell . '</Data></Cell>';
                } else {
                    $body .= '<Cell><Data ss:Type="String">' . self::escape((string) $cell) . '</Data></Cell>';
                }
            }
            $body .= '</Row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<?mso-application progid="Excel.Sheet"?>'
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Worksheet ss:Name="' . $sheet . '"><Table>' . $body . '</Table></Worksheet></Workbook>';
    }

    private static function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function filename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?: 'report.xls';

        return str_ends_with($name, '.xls') ? $name : $name . '.xls';
    }
}
