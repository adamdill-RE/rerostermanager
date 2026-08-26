<?php

declare(strict_types=1);

namespace Rerm\Roster;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Reads .xlsx (and .xlsm) with the zip and xmlreader extensions, both of which
 * this host has (docs/hosting.md). No Composer, so no PhpSpreadsheet.
 *
 * An .xlsx is a zip of XML. Four parts matter:
 *
 *   xl/workbook.xml              sheet names, each pointing at a relationship
 *   xl/_rels/workbook.xml.rels   that relationship's actual file path
 *   xl/sharedStrings.xml         the string table most cell text lives in
 *   xl/worksheets/sheetN.xml     the cells
 *
 * Everything is pulled with XMLReader rather than SimpleXML. SimpleXML builds
 * the whole DOM, and a 1,954-row sheet is a 6MB document once decompressed —
 * affordable once, not affordable alongside the import that is consuming it
 * against a 128M limit.
 *
 * Two details cost more than they look:
 *
 *   * **Empty cells are omitted entirely.** A row is not a list of cells in
 *     order; each cell carries an r="C7" reference and the gaps are implied.
 *     Reading them positionally shifts every column after the first blank.
 *   * **Dates are numbers wearing a format.** The serial number is meaningless
 *     without xl/styles.xml, so the style table is read to decide which
 *     columns are dates. This export has no populated date column, but
 *     Badge Issue Date exists and will one day carry one.
 */
final class XlsxReader implements SpreadsheetReader
{
    /** Built-in numFmtId values that mean a date or a time (ECMA-376 18.8.30). */
    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    private ZipArchive $zip;

    /** @var array<int, array{name: string, path: string}> */
    private array $sheets;

    /** @var array<int, string>|null Shared strings, loaded on first use. */
    private ?array $strings = null;

    /** @var array<int, bool>|null Style index => is a date, loaded on first use. */
    private ?array $dateStyles = null;

    public function __construct(private readonly string $path)
    {
        if (!is_file($this->path)) {
            throw new RuntimeException("Spreadsheet not found: {$this->path}");
        }

        $this->zip = new ZipArchive();
        $opened = $this->zip->open($this->path);
        if ($opened !== true) {
            throw new RuntimeException(sprintf(
                'Not a readable .xlsx (zip error %d). A file saved as "Excel 97-2003" is a '
                . 'different format entirely — try XlsReader.',
                is_int($opened) ? $opened : -1
            ));
        }

        if ($this->zip->locateName('xl/workbook.xml') === false) {
            throw new RuntimeException(
                'This is a zip but not a spreadsheet: xl/workbook.xml is missing.'
            );
        }

        $this->sheets = $this->readSheetIndex();
    }

    public function __destruct()
    {
        // @phpstan-ignore-next-line — close() on an already-closed archive warns.
        @$this->zip->close();
    }

    /** @return array<int, string> */
    public function sheets(): array
    {
        return array_map(static fn (array $s): string => $s['name'], $this->sheets);
    }

    /** @return iterable<int, array<int, string>> */
    public function rows(?string $sheet = null): iterable
    {
        $target = null;
        foreach ($this->sheets as $candidate) {
            if ($sheet === null || $candidate['name'] === $sheet) {
                $target = $candidate;
                break;
            }
        }

        if ($target === null) {
            throw new RuntimeException(sprintf(
                'No sheet named "%s". This workbook has: %s',
                (string) $sheet,
                implode(', ', $this->sheets())
            ));
        }

        yield from $this->readSheet($target['path']);
    }

    /**
     * Sheet names paired with the file each one actually lives in.
     *
     * The pairing is indirect on purpose in the format: workbook.xml names a
     * relationship id, and the .rels file resolves it. Assuming sheet1.xml is
     * the first sheet is right most of the time and wrong exactly when a
     * workbook has had a sheet deleted.
     *
     * @return array<int, array{name: string, path: string}>
     */
    private function readSheetIndex(): array
    {
        $relationships = [];
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if (is_string($rels) && $rels !== '') {
            $reader = new XMLReader();
            $reader->XML($rels);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                    $id = (string) $reader->getAttribute('Id');
                    $tgt = (string) $reader->getAttribute('Target');
                    if ($id !== '' && $tgt !== '') {
                        $relationships[$id] = $this->normaliseTarget($tgt);
                    }
                }
            }
            $reader->close();
        }

        $sheets = [];
        $workbook = $this->zip->getFromName('xl/workbook.xml');
        if (!is_string($workbook) || $workbook === '') {
            throw new RuntimeException('xl/workbook.xml is empty.');
        }

        $reader = new XMLReader();
        $reader->XML($workbook);
        $index = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'sheet') {
                continue;
            }

            $name = (string) $reader->getAttribute('name');
            // The r: prefix is bound to the relationships namespace; ask for it
            // by namespace rather than by prefix, which a writer may rename.
            $rid = $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
                ?? $reader->getAttribute('r:id');

            $path = null;
            if (is_string($rid) && isset($relationships[$rid])) {
                $path = $relationships[$rid];
            }
            $path ??= 'xl/worksheets/sheet' . ($index + 1) . '.xml';

            $sheets[] = ['name' => $name !== '' ? $name : 'Sheet' . ($index + 1), 'path' => $path];
            $index++;
        }
        $reader->close();

        if ($sheets === []) {
            throw new RuntimeException('This workbook declares no sheets.');
        }

        return $sheets;
    }

    /** Relationship targets are relative to xl/, and may be written either way. */
    private function normaliseTarget(string $target): string
    {
        $target = ltrim($target, '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    /**
     * The shared string table.
     *
     * Held in memory, which is the one unbounded thing here — but it is bounded
     * in practice by the roster itself: every distinct name, address and email
     * appears once. Measured at ~5,500 strings and under 1MB for the 1,954-row
     * sample. A string table large enough to matter would need a spreadsheet
     * far larger than this host's 2M upload limit can deliver.
     *
     * @return array<int, string>
     */
    private function strings(): array
    {
        if ($this->strings !== null) {
            return $this->strings;
        }

        $this->strings = [];

        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || $xml === '') {
            return $this->strings;   // Legal: a sheet may use only inline strings.
        }

        $reader = new XMLReader();
        $reader->XML($xml);

        // Sibling walk, for the third time in this class and for the third
        // time because mixing read() with readOuterXml()/next() drops every
        // second element. Here that would shift every shared-string index by a
        // growing amount: cells would show real text belonging to other cells,
        // which is far worse than a blank, and nothing would throw.
        $found = false;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $found = true;
                break;
            }
        }

        while ($found && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
            // A <si> is either a single <t>, or several <r> runs each with its
            // own <t> because part of the string was styled differently. The
            // runs concatenate; a reader that takes the first <t> silently
            // truncates every rich-text cell.
            $this->strings[] = $this->textOf($reader->readOuterXml());

            if (!$reader->next('si')) {
                break;
            }
        }
        $reader->close();

        return $this->strings;
    }

    /** Concatenated <t> content of an <si> or <is> fragment, entities resolved. */
    private function textOf(string $fragment): string
    {
        if ($fragment === '') {
            return '';
        }

        $text = '';
        $reader = new XMLReader();
        $reader->XML($fragment);
        $inRph = false;

        while ($reader->read()) {
            // <rPh> is a phonetic guide for Japanese text. It contains <t> and
            // is not part of the value.
            if ($reader->localName === 'rPh') {
                $inRph = $reader->nodeType === XMLReader::ELEMENT;
                continue;
            }
            if ($inRph) {
                continue;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $text .= $reader->readString();
            }
        }
        $reader->close();

        return $text;
    }

    /**
     * Which style indexes format their number as a date.
     *
     * @return array<int, bool>
     */
    private function dateStyles(): array
    {
        if ($this->dateStyles !== null) {
            return $this->dateStyles;
        }

        $this->dateStyles = [];

        $xml = $this->zip->getFromName('xl/styles.xml');
        if (!is_string($xml) || $xml === '') {
            return $this->dateStyles;
        }

        // Custom formats first: anything whose code carries a y, d, or a month
        // token. Quoted literals are stripped, so "Day" in a label like
        // "Day: "0 cannot masquerade as a date token.
        $custom = [];
        $reader = new XMLReader();
        $reader->XML($xml);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'numFmt') {
                $id   = (int) $reader->getAttribute('numFmtId');
                $code = (string) $reader->getAttribute('formatCode');
                $bare = (string) preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code);
                $custom[$id] = preg_match('/[ymd]/i', $bare) === 1;
            }
        }
        $reader->close();

        // Then cellXfs, in document order: a cell's s="N" indexes this list.
        $reader = new XMLReader();
        $reader->XML($xml);
        $inCellXfs = false;
        $index = 0;
        while ($reader->read()) {
            if ($reader->localName === 'cellXfs') {
                $inCellXfs = $reader->nodeType === XMLReader::ELEMENT;
                continue;
            }
            if (!$inCellXfs || $reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'xf') {
                continue;
            }

            $numFmtId = (int) $reader->getAttribute('numFmtId');
            $this->dateStyles[$index] = $custom[$numFmtId] ?? in_array($numFmtId, self::DATE_FORMATS, true);
            $index++;
        }
        $reader->close();

        return $this->dateStyles;
    }

    /**
     * @return iterable<int, array<int, string>>
     */
    private function readSheet(string $path): iterable
    {
        $stream = $this->zip->getStream($path);
        if ($stream === false) {
            throw new RuntimeException("Worksheet part is missing from the archive: {$path}");
        }

        $strings    = $this->strings();
        $dateStyles = $this->dateStyles();

        $reader = new XMLReader();
        // XMLReader cannot read a zip stream directly, so the worksheet is
        // pulled into a temporary stream first. php://temp spills to disk past
        // 2MB rather than growing the heap, which is what keeps a large sheet
        // inside memory_limit.
        $buffer = fopen('php://temp/maxmemory:2097152', 'r+b');
        if ($buffer === false) {
            throw new RuntimeException('Could not open a temporary stream for the worksheet.');
        }
        stream_copy_to_stream($stream, $buffer);
        fclose($stream);
        rewind($buffer);

        $xml = stream_get_contents($buffer);
        fclose($buffer);
        if ($xml === false) {
            throw new RuntimeException('Could not read the worksheet part.');
        }

        $reader->XML($xml);
        unset($xml);

        $width = 0;

        // Advance to the first <row>, then walk siblings with next().
        //
        // The obvious shape — while (read()) { if (row) { readOuterXml();
        // next('row'); } } — silently drops every second row. readOuterXml()
        // does not move the cursor, next() lands ON the following row, and
        // then the loop's own read() steps over it. Half a roster imports and
        // nothing reports an error.
        $found = false;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $found = true;
                break;
            }
        }

        while ($found && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
            $row = $this->parseRow($reader->readOuterXml(), $strings, $dateStyles);

            // The header establishes the width; later rows are padded to it so
            // every row is a dense, positionally indexable array.
            if ($width === 0) {
                $width = count($row);
            }
            if (count($row) < $width) {
                $row = array_pad($row, $width, '');
            }

            yield $row;

            if (!$reader->next('row')) {
                break;
            }
        }

        $reader->close();
    }

    /**
     * @param array<int, string> $strings
     * @param array<int, bool>   $dateStyles
     * @return array<int, string>
     */
    private function parseRow(string $rowXml, array $strings, array $dateStyles): array
    {
        $row = [];

        $reader = new XMLReader();
        $reader->XML($rowXml);

        // Same sibling walk as readSheet(), and for the same reason: mixing
        // read() with readOuterXml()/next() drops every second cell.
        $found = false;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $found = true;
                break;
            }
        }

        while ($found && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
            $ref   = (string) $reader->getAttribute('r');
            $type  = (string) $reader->getAttribute('t');
            $style = $reader->getAttribute('s');

            $column = $ref === '' ? count($row) : self::columnIndex($ref);

            $row[$column] = $this->cellValue(
                $reader->readOuterXml(),
                $type,
                $style,
                $strings,
                $dateStyles
            );

            if (!$reader->next('c')) {
                break;
            }
        }
        $reader->close();

        if ($row === []) {
            return [];
        }

        // Fill the gaps the format left implicit, so column 12 is column 12
        // whether or not columns 3 and 7 happened to be blank.
        $dense = [];
        for ($i = 0, $last = max(array_keys($row)); $i <= $last; $i++) {
            $dense[$i] = $row[$i] ?? '';
        }

        return $dense;
    }

    /**
     * @param array<int, string> $strings
     * @param array<int, bool>   $dateStyles
     */
    private function cellValue(
        string $cellXml,
        string $type,
        ?string $style,
        array $strings,
        array $dateStyles
    ): string {
        // An inline string keeps its text in <is> rather than the shared table.
        if ($type === 'inlineStr') {
            return $this->textOf($cellXml);
        }

        $raw = '';
        $reader = new XMLReader();
        $reader->XML($cellXml);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'v') {
                $raw = $reader->readString();
                break;
            }
        }
        $reader->close();

        if ($raw === '') {
            return '';
        }

        return match ($type) {
            's'  => $strings[(int) $raw] ?? '',       // shared string
            'b'  => $raw === '1' ? 'TRUE' : 'FALSE',
            'e'  => $raw,                             // #N/A and friends, verbatim
            // 'str' is a formula's cached string result; '' and 'n' are numeric.
            default => $this->numericOrDate($raw, $style, $dateStyles, $type),
        };
    }

    /** @param array<int, bool> $dateStyles */
    private function numericOrDate(string $raw, ?string $style, array $dateStyles, string $type): string
    {
        if ($type === 'str') {
            return $raw;
        }

        $isDate = $style !== null && ($dateStyles[(int) $style] ?? false);
        if ($isDate && is_numeric($raw)) {
            return self::excelSerialToDate((float) $raw);
        }

        // Returned as written. Casting to float here is what turns a seven-digit
        // Customer Number into 1234567.0, and it is the whole reason this
        // interface is defined in strings.
        return $raw;
    }

    /**
     * Excel's day 0 is 1899-12-30, not 1900-01-01: the format deliberately
     * reproduces a Lotus 1-2-3 bug that treats 1900 as a leap year, and every
     * serial from 61 onward is offset by it. Anchoring at 1899-12-30 absorbs
     * the offset for every date after 1900-03-01, which is every date a roster
     * will ever hold.
     */
    private static function excelSerialToDate(float $serial): string
    {
        $days    = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);

        $date = (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))
            ->modify("+{$days} days")
            ->modify("+{$seconds} seconds");

        return $seconds === 0 ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
    }

    /** "AB7" => 27. Base-26 with no zero, so A is 1 and Z is 26. */
    private static function columnIndex(string $reference): int
    {
        $letters = (string) preg_replace('/[^A-Za-z]/', '', $reference);
        $index = 0;
        $length = strlen($letters);

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord(strtoupper($letters[$i])) - 64);
        }

        return max(0, $index - 1);
    }
}
