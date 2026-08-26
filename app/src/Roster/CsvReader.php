<?php

declare(strict_types=1);

namespace Rerm\Roster;

use RuntimeException;

/**
 * Reads a CSV, and reads it the way a spreadsheet actually saves one.
 *
 * Three things Excel does that a naive fgetcsv() loop gets wrong:
 *
 *   * It writes a UTF-8 BOM. Left in place, the first header becomes
 *     "\xEF\xBB\xBFTitle", which matches nothing, and the import rejects the
 *     file for missing a column that is plainly there.
 *   * "Save as CSV" on a Windows machine may write Windows-1252 rather than
 *     UTF-8, which turns an apostrophe in O'Brien into a byte no UTF-8 decoder
 *     accepts and MySQL rejects on insert.
 *   * A cell containing a newline is quoted and spans lines. fgetcsv handles
 *     that; splitting on "\n" does not.
 */
final class CsvReader implements SpreadsheetReader
{
    private const BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly string $path,
        private readonly string $separator = ',',
    ) {
        if (!is_file($this->path)) {
            throw new RuntimeException("File not found: {$this->path}");
        }

        $this->refuseBinary();
    }

    /**
     * CSV is what Spreadsheet::open() falls back to when a file is neither an
     * OLE2 workbook nor a zip — which means it is also what an unrecognised
     * binary lands in. fgetcsv will happily read 5,000 bytes of noise as one
     * enormous single-column row, and the import then rejects it for a missing
     * "Customer Number" column, which is true and completely unhelpful.
     *
     * A NUL byte settles it: no text CSV contains one, and every truncated
     * download, PDF, image and half-copied workbook does.
     */
    private function refuseBinary(): void
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$this->path}");
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);

        if (str_contains($head, "\0")) {
            throw new RuntimeException(
                'This file is not a roster: it contains binary data but is neither an Excel '
                . 'workbook (.xls) nor an .xlsx. If it came from Excel, open it and use '
                . 'File > Save As, choosing "CSV UTF-8" or "Excel Workbook".'
            );
        }
    }

    /** @return array<int, string> */
    public function sheets(): array
    {
        return [pathinfo($this->path, PATHINFO_FILENAME)];
    }

    /** @return iterable<int, array<int, string>> */
    public function rows(?string $sheet = null): iterable
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$this->path}");
        }

        try {
            $first = true;
            $width = 0;

            while (($row = fgetcsv($handle, 0, $this->separator, '"', '')) !== false) {
                // A blank line reads as [null]; it is not a row.
                if ($row === [null]) {
                    continue;
                }

                $values = [];
                foreach ($row as $index => $value) {
                    $value = (string) $value;

                    if ($first && $index === 0) {
                        $value = self::stripBom($value);
                    }

                    $values[$index] = self::toUtf8($value);
                }

                if ($first) {
                    $width = count($values);
                    $first = false;
                }
                if (count($values) < $width) {
                    $values = array_pad($values, $width, '');
                }

                yield $values;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, self::BOM) ? substr($value, strlen(self::BOM)) : $value;
    }

    /**
     * Already-valid UTF-8 is left alone; anything else is treated as
     * Windows-1252, which is what "Save as CSV" produces on Windows and is a
     * superset of Latin-1 over the range that actually appears in names.
     */
    private static function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
