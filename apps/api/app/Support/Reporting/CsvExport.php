<?php

namespace App\Support\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared CSV streaming + formula-injection mitigation for all seven
 * Phase 20 report exports (CSV is the only export format in V1 — no
 * Excel/PDF/printable, and no new spreadsheet dependency was added).
 * Streams directly to the response body via fputcsv() over a lazily
 * iterated row source (each report controller passes a
 * LazyCollection built from Eloquent's own cursor()) — the full result
 * set is never materialized in memory, a reasonable, dependency-free
 * approach to "potentially large result sets" at this application's
 * actual scale (~100 employees).
 */
final class CsvExport
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel (Windows in particular) reliably detects
            // UTF-8 rather than guessing a legacy codepage — a standard,
            // widely-used Excel-friendly convention, not a new dependency.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::sanitizeCell(...), $row));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Neutralizes CSV/formula injection: a cell value beginning with `=`,
     * `+`, `-`, `@`, a tab, or a carriage return is interpreted by
     * Excel/Sheets/LibreOffice as a formula rather than plain text when
     * the exported file is opened — a well-known risk for any CSV built
     * from user-controlled strings (titles, descriptions, reasons,
     * notes, names). Prefixing such a value with a single quote
     * neutralizes it while leaving the visible text unchanged for a
     * human reader. Every value passes through here, not only columns
     * known to be user-authored, so this protection can never
     * accidentally be skipped for one column.
     */
    private static function sanitizeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
