<?php

declare(strict_types=1);

namespace Rerm\Roster;

use RuntimeException;

/**
 * Picks the reader for a roster file — by looking inside it, never at its name.
 *
 * The extension is the least reliable thing about an uploaded roster. "Save as
 * CSV" in Excel offers to keep the .xls name; a workbook mailed as .xls is
 * often really .xlsx; and a file dragged out of an email can arrive as
 * roster.xls.txt. Sniffing the first bytes costs nothing and removes an entire
 * class of "the import says my file is corrupt" support conversation.
 *
 *   D0 CF 11 E0 A1 B1 1A E1   OLE2 compound file  -> XlsReader
 *   50 4B 03 04 ("PK")        zip                 -> XlsxReader
 *   anything else                                 -> CsvReader
 */
final class Spreadsheet
{
    private const OLE2 = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const ZIP  = "PK\x03\x04";

    public static function open(string $path): SpreadsheetReader
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        return match (self::detect($path)) {
            'xls'  => new XlsReader($path),
            'xlsx' => new XlsxReader($path),
            default => new CsvReader($path, self::sniffSeparator($path)),
        };
    }

    /** 'xls', 'xlsx' or 'csv', decided by content. */
    public static function detect(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$path}");
        }

        $magic = (string) fread($handle, 8);
        fclose($handle);

        if (str_starts_with($magic, self::OLE2)) {
            return 'xls';
        }
        if (str_starts_with($magic, self::ZIP)) {
            return 'xlsx';
        }

        return 'csv';
    }

    /**
     * Comma or semicolon, from the header line.
     *
     * Excel writes the list separator of the machine's locale, so a roster
     * saved on a machine set to most of Europe is semicolon-delimited. Read as
     * commas it yields one enormous column and a "missing Customer Number"
     * rejection that says nothing about the real cause.
     */
    private static function sniffSeparator(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ',';
        }
        $line = (string) fgets($handle, 8192);
        fclose($handle);

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }
}
