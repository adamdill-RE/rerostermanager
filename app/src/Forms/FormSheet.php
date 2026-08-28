<?php

declare(strict_types=1);

namespace Rerm\Forms;

use Rerm\Export\XlsxWriter;
use RuntimeException;
use ZipArchive;

/**
 * A `.xlsx` with STYLES — one sheet, a fixed grid, no Composer and no build
 * step (Phase 9, Create Forms).
 *
 * `Rerm\Export\XlsxWriter` already writes a workbook this host can produce,
 * and everything it decided still holds here: a `.xlsx` is a zip of XML,
 * `ZipArchive` is present, `xmlEscape()` is the only thing that reaches the
 * file, and the archive is built to a path outside the document root because
 * `ZipArchive` cannot write to `php://output`. This class exists beside it
 * rather than inside it because a form and an export are shaped differently
 * in three ways, and each difference is load-bearing:
 *
 * **It is a grid, not a stream.** The export is ~1,954 rows appended one at a
 * time to a temp file so a roster is never held in memory. A Roster Change
 * Form is 51 rows by 12 columns, forever — 600-odd cells, addressed by name
 * (`'D27'`) rather than by arrival order, because a form is filled in by
 * position. Holding that in memory is a few kilobytes, and building it in
 * order would mean the caller could not fill in the header after the rows.
 *
 * **Every cell carries a style id, and the style sheet is shipped whole.**
 * `app/templates/rcf/styles.xml` is the Rodeo Houston workbook's own, byte
 * for byte, so a generated form is the form officers already know rather
 * than something that resembles it. The ids in `RosterChangeForm` index into
 * it. See that directory's README for why it is a shipped asset.
 *
 * **It has to say more than cells.** Column widths, row heights, merged
 * ranges and a landscape page setup at 67% are the difference between a form
 * and a spreadsheet full of the same words.
 *
 * What it keeps from `XlsxWriter`, deliberately and by calling straight into
 * it rather than by copying: `xmlEscape()`, `columnName()` and
 * `sheetName()`. Text is written as an INLINE STRING for the same reason —
 * `t="inlineStr"` means there is no numeric cell type to reach for by
 * accident, and a member number that becomes 1234567.0 is the bug this whole
 * application keeps not having.
 *
 * `number()` is the single exception, and it exists for one honest reason
 * rather than for arithmetic: the blank form Rodeo Houston hands out has a
 * literal `0` sitting in two columns of all twenty-five entry rows. It is
 * plainly a leftover of theirs. Reproducing it is what "looks exactly like
 * the form" means for the rows nobody filled in, so the writer can emit it —
 * and `RosterChangeForm` is the only caller, on untouched rows only.
 *
 * The built file is unlinked by `close()`, and by the destructor if a caller
 * throws before reaching it. A filled-in Roster Change Form names members and
 * carries their member numbers: it is PII leaving the building, and it is
 * handled like the export is.
 */
final class FormSheet
{
    /** Column A is 1 here, matching `<col min= max=>`, not `columnName()`'s 0. */
    private const FIRST_COLUMN = 1;

    /**
     * A cell reference, split. `preg_match` rather than trust: every ref in
     * this application is a literal in `RosterChangeForm`, and a typo'd one
     * would otherwise produce a workbook Excel refuses to open with no
     * indication of which cell did it.
     */
    private const REFERENCE = '/^([A-Z]{1,3})([1-9][0-9]{0,6})$/';

    /** @var array<int, array<int, string>> row number => column index => cell XML */
    private array $cells = [];

    /** @var array<int, string> row number => the attributes its `<row>` carries */
    private array $rowAttributes = [];

    /** @var array<int, string> `<col>` elements, in column order */
    private array $columns = [];

    /** @var array<int, string> merged ranges, as `A1:B2` */
    private array $merges = [];

    private bool $closed = false;

    private function __construct(
        private readonly string $directory,
        private readonly string $styles,
        private readonly string $sheetName,
        private readonly string $pageSetup,
        private readonly string $pageMargins,
    ) {
    }

    /**
     * $directory is where the archive is built. It must exist, be writable
     * and NOT be web-reachable — `var/exports`, beside the roster export's
     * temp files, for the roster export's reason.
     *
     * $stylesPath is the shipped style sheet. It is read once, here, so that
     * a missing or unreadable asset fails at the top of the build rather
     * than after the form has been assembled.
     */
    public static function create(
        string $directory,
        string $stylesPath,
        string $sheetName,
        string $pageSetup = '<pageSetup scale="67" orientation="landscape"/>',
        string $pageMargins = '<pageMargins left="0.25" right="0.25" top="0.6437"'
            . ' bottom="0.6437" header="0.25" footer="0.25"/>'
    ): self {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException(
                "The export directory {$directory} does not exist or is not writable. "
                . 'It is created by .cpanel.yml at 0700 and is deliberately outside the document root.'
            );
        }

        $styles = @file_get_contents($stylesPath);
        if ($styles === false || $styles === '') {
            throw new RuntimeException(
                "The style sheet {$stylesPath} could not be read. It ships with the "
                . 'application in app/templates/ and is copied to the server by .cpanel.yml.'
            );
        }

        return new self(
            $directory,
            $styles,
            XlsxWriter::sheetName($sheetName),
            $pageSetup,
            $pageMargins
        );
    }

    /**
     * One column's width, in Excel's character units, exactly as the source
     * workbook records it.
     *
     * $style is the column's DEFAULT cell style. Every cell this class writes
     * names its own, so the column style only reaches the empty space to the
     * right of column L — which is the difference between a form that ends
     * and one that trails off in a different font.
     */
    public function column(int $first, int $last, string $width, ?int $style = null): void
    {
        $this->guard();

        if ($first < self::FIRST_COLUMN || $last < $first) {
            throw new RuntimeException("Columns {$first}..{$last} are not a range.");
        }

        $this->columns[] = '<col min="' . $first . '" max="' . $last . '"'
            . ' width="' . self::decimal($width) . '"'
            . ($style === null ? '' : ' style="' . $style . '"')
            . ' customWidth="1"/>';
    }

    /**
     * A row's own attributes: its height, and the format Excel carries on the
     * row itself rather than on its cells.
     *
     * `thickBot` is not decoration — it is how the heavy rule under the
     * column headers is drawn, and a form without it reads as a table.
     */
    public function row(
        int $row,
        ?string $height = null,
        bool $customHeight = false,
        bool $thickBottom = false,
        ?int $style = null
    ): void {
        $this->guard();

        $attributes = '';

        if ($style !== null) {
            $attributes .= ' s="' . $style . '" customFormat="1"';
        }
        if ($height !== null) {
            $attributes .= ' ht="' . self::decimal($height) . '"';
        }
        if ($customHeight) {
            $attributes .= ' customHeight="1"';
        }
        if ($thickBottom) {
            $attributes .= ' thickBot="1"';
        }

        $this->rowAttributes[$row] = $attributes;
    }

    /** A merged range, as `A2:I2`. Both ends are validated. */
    public function merge(string $range): void
    {
        $this->guard();

        [$from, $to] = array_pad(explode(':', $range, 2), 2, '');
        self::split($from);
        self::split($to);

        $this->merges[] = $range;
    }

    /**
     * Text, as an inline string — the only way this class writes a value a
     * person typed. An empty string still writes a cell, because an empty
     * STYLED cell is what draws the box somebody writes in by hand.
     */
    public function text(string $reference, int $style, string $value = ''): void
    {
        $this->guard();

        [$row, $column] = self::split($reference);

        $this->cells[$row][$column] = $value === ''
            ? '<c r="' . $reference . '" s="' . $style . '"/>'
            : '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                . XlsxWriter::xmlEscape($value) . '</t></is></c>';
    }

    /**
     * A literal number. The ONLY caller is `RosterChangeForm`, reproducing
     * the `0` the blank Rodeo Houston form carries in its ROOKIE and WAIT
     * LIST columns — see the class comment. $value is held to digits so that
     * this cannot quietly become a general numeric path and start writing
     * member numbers.
     */
    public function number(string $reference, int $style, string $value): void
    {
        $this->guard();

        if (preg_match('/^-?[0-9]+$/', $value) !== 1) {
            throw new RuntimeException(
                "'{$value}' is not an integer. This writer has no general numeric cell: "
                . 'everything a person typed is written as a string, so that a member '
                . 'number cannot become a float.'
            );
        }

        [$row, $column] = self::split($reference);

        $this->cells[$row][$column] = '<c r="' . $reference . '" s="' . $style . '"><v>'
            . $value . '</v></c>';
    }

    /**
     * Builds the archive and returns its path. The caller sends it and then
     * calls `close()`; nothing else may touch this sheet afterwards.
     */
    public function finish(): string
    {
        $this->guard();

        $path = tempnam($this->directory, 'rerm-form-');
        if ($path === false) {
            throw new RuntimeException("Could not create a temporary file in {$this->directory}.");
        }

        $zip = new ZipArchive();

        // OVERWRITE, because tempnam() already created the file: CREATE alone
        // would append to the existing empty one and EXCL would refuse.
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException("Could not open {$path} as a zip archive.");
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::packageRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($this->sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());

        if (!$zip->close()) {
            @unlink($path);

            throw new RuntimeException('The form archive could not be written.');
        }

        return $path;
    }

    /**
     * Removes the built file. Safe to call twice, and called by the
     * destructor for the path where a caller throws before reaching it: a
     * filled-in form names members and must not survive on disk.
     */
    public function close(?string $path = null): void
    {
        $this->closed = true;

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** The sheet part. Public so a test can read the XML without a zip. */
    public function sheet(): string
    {
        $rows = $this->cells;
        ksort($rows, SORT_NUMERIC);

        // A row that carries only attributes — a height, a format — still has
        // to be emitted, or the form loses it.
        foreach ($this->rowAttributes as $number => $unused) {
            if (!isset($rows[$number])) {
                $rows[$number] = [];
            }
        }
        ksort($rows, SORT_NUMERIC);

        $body = '';
        foreach ($rows as $number => $cells) {
            ksort($cells, SORT_NUMERIC);

            $body .= '<row r="' . $number . '"' . ($this->rowAttributes[$number] ?? '') . '>'
                . implode('', $cells) . '</row>';
        }

        $merges = '';
        if ($this->merges !== []) {
            $merges = '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as $range) {
                $merges .= '<mergeCell ref="' . $range . '"/>';
            }
            $merges .= '</mergeCells>';
        }

        // The order of these elements is fixed by the schema, and Excel
        // refuses a file that gets it wrong: sheetPr, dimension, sheetViews,
        // sheetFormatPr, cols, sheetData, mergeCells, pageMargins, pageSetup.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr/>'
            . '<dimension ref="' . $this->dimension($rows) . '"/>'
            . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr baseColWidth="10" defaultColWidth="8.5" defaultRowHeight="14"/>'
            . ($this->columns === [] ? '' : '<cols>' . implode('', $this->columns) . '</cols>')
            . '<sheetData>' . $body . '</sheetData>'
            . $merges
            . $this->pageMargins
            . $this->pageSetup
            . '</worksheet>';
    }

    /**
     * The used range. Excel recalculates it, but a wrong one makes a
     * hand-inspected file confusing and an empty sheet invalid.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function dimension(array $rows): string
    {
        if ($rows === []) {
            return 'A1';
        }

        $lastRow    = max(array_keys($rows));
        $lastColumn = 0;
        foreach ($rows as $cells) {
            foreach (array_keys($cells) as $column) {
                $lastColumn = max($lastColumn, $column);
            }
        }

        return 'A1:' . XlsxWriter::columnName($lastColumn) . $lastRow;
    }

    /**
     * A cell reference as [row, zero-based column].
     *
     * @return array{0: int, 1: int}
     */
    private static function split(string $reference): array
    {
        if (preg_match(self::REFERENCE, $reference, $match) !== 1) {
            throw new RuntimeException("'{$reference}' is not a cell reference.");
        }

        $column = 0;
        foreach (str_split($match[1]) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return [(int) $match[2], $column - 1];
    }

    /**
     * A width or a height as XML. Held to a decimal number: these come from
     * the transcribed template, and a locale that formats a float with a
     * comma would produce a file Excel will not open.
     */
    private static function decimal(string $value): string
    {
        if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $value) !== 1) {
            throw new RuntimeException("'{$value}' is not a measurement.");
        }

        return $value;
    }

    private function guard(): void
    {
        if ($this->closed) {
            throw new RuntimeException('This form sheet is closed.');
        }
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
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
            . '<sheets><sheet name="' . XlsxWriter::xmlEscape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>'
            . '</Relationships>';
    }
}
