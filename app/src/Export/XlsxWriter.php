<?php

declare(strict_types=1);

namespace Rerm\Export;

use RuntimeException;
use ZipArchive;

/**
 * Writes a `.xlsx` with no Composer and no build step (Phase 8, open 1).
 *
 * A `.xlsx` is a zip of XML, and this host has `zip` — `XlsxReader` already
 * reads one with `ZipArchive` + `XMLReader`, so the pieces were all here. Five
 * parts make a workbook Excel, LibreOffice, Numbers and our own reader all
 * open:
 *
 *     [Content_Types].xml          what each part IS
 *     _rels/.rels                  the package points at the workbook
 *     xl/workbook.xml              the workbook names one sheet
 *     xl/_rels/workbook.xml.rels   that name resolves to a file
 *     xl/worksheets/sheet1.xml     the cells
 *
 * Three decisions, all deliberate:
 *
 * **Inline strings, not a shared-strings table.** Every cell this application
 * writes is a STRING — `Customer Number` 1234567 must not become 1234567.0,
 * which is the same rule the reader enforces coming the other way. With
 * `t="inlineStr"` that is structural: there is no numeric cell type in this
 * writer to reach for by accident. A shared table would save bytes on a
 * roster full of repeated team names, and would also mean holding every
 * distinct string in memory until the sheet was finished — which is exactly
 * the "never assembled in memory" this is written to avoid.
 *
 * **Escaped concatenation, not `XMLWriter`.** `XMLWriter` is present on this
 * development machine and is NOT in `docs/hosting.md`'s Present list, so it
 * is not something to discover missing on a Sunday. `xmlEscape()` is six
 * lines and the only thing that reaches the file.
 *
 * **A temp file, then a zip, then `readfile()`.** `ZipArchive` writes to a
 * PATH; it cannot write to `php://output`. So spec 7.5's "never assembled in
 * memory" holds in spirit and changes in mechanism: rows are appended to a
 * temp file one at a time, the finished sheet is added to the archive with
 * `addFile()` (which streams from disk rather than taking a string), and the
 * archive is sent with `readfile()`. Peak memory is one row, not one roster.
 *
 * Both temp files are unlinked by `close()`, and by the destructor if a
 * caller throws before reaching it — an export is ~1,950 people's home
 * addresses and it must not survive on disk.
 */
final class XlsxWriter
{
    /**
     * The hard ceiling on a cell, from the format itself. Excel refuses to
     * open a file with a longer one, so a 1,000-character contact note is
     * truncated with an ellipsis rather than producing a workbook nobody can
     * read.
     */
    public const MAX_CELL_CHARS = 32767;

    private string $sheetPath;

    /** @var resource */
    private $sheet;

    private int $rowNumber = 0;

    private bool $closed = false;

    private function __construct(
        private readonly string $directory,
        private readonly string $sheetName,
    ) {
        $path = tempnam($this->directory, 'rerm-sheet-');
        if ($path === false) {
            throw new RuntimeException("Could not create a temporary file in {$this->directory}.");
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);

            throw new RuntimeException("Could not open {$path} for writing.");
        }

        $this->sheetPath = $path;
        $this->sheet     = $handle;

        $this->write(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>'
        );
    }

    /**
     * $directory is where the two temp files live. It must exist, be
     * writable, and NOT be web-reachable: an export is PII, so `var/exports`
     * beside `var/imports` and `var/mail`, never anything under the document
     * root.
     */
    public static function create(string $directory, string $sheetName = 'Roster'): self
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException(
                "The export directory {$directory} does not exist or is not writable. "
                . 'It is created by .cpanel.yml at 0700 and is deliberately outside the document root.'
            );
        }

        return new self($directory, self::sheetName($sheetName));
    }

    /**
     * One row. Every value is written as a string, always — see the class
     * comment: a numeric cell here is how a member number loses its leading
     * zero and gains a decimal point.
     *
     * @param array<int, string> $cells
     */
    public function addRow(array $cells): void
    {
        if ($this->closed) {
            throw new RuntimeException('This writer is closed.');
        }

        $this->rowNumber++;

        $xml = '<row r="' . $this->rowNumber . '">';

        foreach (array_values($cells) as $index => $value) {
            // An empty cell is written as nothing at all rather than as an
            // empty <is><t/>: the reader fills implicit gaps back in
            // (XlsxReader::rows()), and 1,954 rows x the six dead columns is
            // ~200KB of markup that carries no information.
            if ($value === '') {
                continue;
            }

            $xml .= '<c r="' . self::columnName($index) . $this->rowNumber . '" t="inlineStr">'
                . '<is><t xml:space="preserve">' . self::xmlEscape($value) . '</t></is></c>';
        }

        $this->write($xml . '</row>');
    }

    /**
     * Finishes the sheet, builds the archive and returns its path. The caller
     * sends it and then calls close(); nothing else may touch this writer.
     */
    public function finish(): string
    {
        if ($this->closed) {
            throw new RuntimeException('This writer is closed.');
        }

        $this->write('</sheetData></worksheet>');
        fclose($this->sheet);

        $zipPath = tempnam($this->directory, 'rerm-export-');
        if ($zipPath === false) {
            throw new RuntimeException("Could not create a temporary file in {$this->directory}.");
        }

        $zip = new ZipArchive();

        // OVERWRITE, because tempnam() already created the file: CREATE alone
        // would open the existing empty file and append to it, and CREATE |
        // EXCL would refuse outright.
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);

            throw new RuntimeException("Could not open {$zipPath} as a zip archive.");
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::packageRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($this->sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());

        // addFile(), not addFromString(): the sheet is read from disk as the
        // archive is written, so a 1,954-row export never has the whole sheet
        // in memory at once.
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');

        if (!$zip->close()) {
            @unlink($zipPath);

            throw new RuntimeException('The export archive could not be written.');
        }

        return $zipPath;
    }

    /**
     * Removes both temp files. Safe to call twice, and called by the
     * destructor for the path where a caller throws before reaching it: an
     * export is PII and must not be left on disk.
     */
    public function close(?string $zipPath = null): void
    {
        if (!$this->closed) {
            $this->closed = true;

            if (is_resource($this->sheet)) {
                fclose($this->sheet);
            }
            if (is_file($this->sheetPath)) {
                @unlink($this->sheetPath);
            }
        }

        if ($zipPath !== null && is_file($zipPath)) {
            @unlink($zipPath);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** How many rows have been written, header included. */
    public function rowCount(): int
    {
        return $this->rowNumber;
    }

    /**
     * A zero-based column index as a spreadsheet column name: 0 -> A,
     * 25 -> Z, 26 -> AA. The export is ~53 columns wide, so BA is reachable
     * and this is not theoretical.
     */
    public static function columnName(int $index): string
    {
        $name = '';

        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $name = chr(65 + $n % 26) . $name;
        }

        return $name;
    }

    /**
     * Text as XML character data, and the only thing that reaches the file.
     *
     * Control characters below 0x20 other than tab, newline and carriage
     * return are not legal in XML 1.0 AT ALL — not even escaped — and a
     * contact note pasted out of a mail client is exactly where one arrives.
     * They are stripped rather than encoded, because a workbook Excel refuses
     * to open is worse than a note missing a character nobody can see.
     */
    public static function xmlEscape(string $value): string
    {
        if (mb_strlen($value) > self::MAX_CELL_CHARS) {
            $value = mb_substr($value, 0, self::MAX_CELL_CHARS - 1) . '…';
        }

        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A sheet name Excel will accept: at most 31 characters, and none of
     * : \ / ? * [ ]. A name it rejects is a file that will not open.
     */
    public static function sheetName(string $name): string
    {
        $name = (string) preg_replace('/[:\\\\\\/?*\[\]]/', ' ', trim($name));
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            $name = 'Sheet1';
        }

        return mb_substr($name, 0, 31);
    }

    private function write(string $xml): void
    {
        if (fwrite($this->sheet, $xml) === false) {
            throw new RuntimeException('Could not write to the export temporary file.');
        }
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private static function packageRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xmlEscape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }
}
