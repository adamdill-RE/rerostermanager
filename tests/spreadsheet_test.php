<?php

declare(strict_types=1);

/**
 * The roster readers (spec 6.1).
 *
 * Rodeo Houston sends a legacy .xls, so reading one is not a convenience —
 * asking an administrator to re-save every roster before a 1,954-row import
 * adds a manual step, and the step people forget is the one that matters.
 *
 * Fixtures are generated rather than committed: a real roster is PII and this
 * repository is public, and CI fails the build if any spreadsheet is tracked.
 * BiffFixture writes a genuine BIFF8 workbook, and its own correctness is
 * pinned by the container tests below.
 */

require_once __DIR__ . '/BiffFixture.php';

foreach (['SpreadsheetReader', 'CompoundFile', 'XlsReader', 'XlsxReader', 'CsvReader', 'Spreadsheet'] as $class) {
    require_once __DIR__ . '/../app/src/Roster/' . $class . '.php';
}

use Rerm\Roster\CompoundFile;
use Rerm\Roster\CsvReader;
use Rerm\Roster\Spreadsheet;
use Rerm\Roster\XlsReader;
use Rerm\Roster\XlsxReader;

/** A scratch path that cleans itself up at the end of the run. */
function fixture_path(string $name): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/rerm-test-' . getmypid();
        @mkdir($dir, 0700, true);
        register_shutdown_function(static function () use (&$dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });
    }

    return $dir . '/' . $name;
}

/** @return array<int, array<int, string>> */
function read_all(iterable $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = $row;
    }

    return $out;
}

/**
 * The workbook every .xls test reads. Deliberately exercises the record types
 * the real export does NOT contain — NUMBER, MULRK, FORMULA, STRING, BOOLERR
 * and date-formatted cells — because the sample only ever produces LABELSST,
 * RK and MULBLANK, and an untested path is where the next bug lives.
 */
function sample_xls(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }

    return $path = (new BiffFixture('Full Roster'))
        ->label(0, 0, 'Title')->label(0, 1, 'Customer Number')->label(0, 2, 'Name')
        ->label(0, 3, 'Amount')->label(0, 4, 'When')->label(0, 5, 'Calc')->label(0, 6, 'Flag')

        ->label(1, 0, 'Chairman')->rkInt(1, 1, 1234567)->label(1, 2, "O'Brien, Seán")
        ->number(1, 3, 1234.5)->date(1, 4, '2026-03-01')
        ->formulaString(1, 5, 'from formula')->boolean(1, 6, true)

        ->label(2, 0, 'Captain')->rkInt(2, 1, 12345)->label(2, 2, 'Example, Pat')
        ->rkScaled(2, 3, -0.25)->date(2, 4, '2026-12-31')
        ->formulaNumber(2, 5, 42.0)->error(2, 6, 0x2A)

        ->label(3, 0, 'Assistant Captain')->rkInt(3, 1, 7654321)->blank(3, 2)
        ->mulRk(3, 3, [10, 20, 30])->label(3, 6, 'N')

        ->write(fixture_path('sample.xls'));
}

/** The same data as .xlsx, written by hand — it is only zipped XML. */
function sample_xlsx(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }

    $path = fixture_path('sample.xlsx');

    $shared = ['Title', 'Customer Number', 'Name', "O'Brien, Seán", 'Chairman'];
    $sst = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">';
    foreach ($shared as $string) {
        $sst .= '<si><t>' . htmlspecialchars($string, ENT_XML1) . '</t></si>';
    }
    $sst .= '</sst>';

    // Row 2 deliberately omits column B entirely — the gap is what proves the
    // reader addresses cells by their r="" reference rather than positionally.
    $sheet = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
        . '<row r="2"><c r="A2" t="s"><v>4</v></c><c r="C2" t="s"><v>3</v></c></row>'
        . '<row r="3"><c r="A3" t="inlineStr"><is><r><t>Assistant </t></r><r><t>Captain</t></r></is></c>'
        . '<c r="B3"><v>7654321</v></c><c r="C3" s="1"><v>46082</v></c></row>'
        . '</sheetData></worksheet>';

    // Style 1 uses numFmtId 14, a built-in date format.
    $styles = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>';

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $zip->addFromString(
        'xl/workbook.xml',
        '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Full Roster" sheetId="1" r:id="rId1"/></sheets></workbook>'
    );
    $zip->addFromString(
        'xl/_rels/workbook.xml.rels',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>'
    );
    $zip->addFromString('xl/sharedStrings.xml', $sst);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    return $path;
}

// ---------------------------------------------------------------------------
// The OLE2 container
// ---------------------------------------------------------------------------

test('CompoundFile finds the Workbook stream', function (): void {
    $file = CompoundFile::open(sample_xls());
    assertTrue(in_array('Workbook', $file->streamNames(), true), 'no Workbook stream');
    assertTrue(strlen((string) $file->stream('Workbook')) > 0, 'Workbook stream is empty');
});

test('CompoundFile rejects a file that is not a compound document', function (): void {
    $path = fixture_path('plain.txt');
    file_put_contents($path, 'Title,Customer Number' . PHP_EOL);
    assertThrows(static fn () => CompoundFile::open($path), 'signature');
});

test('CompoundFile refuses a truncated file rather than reading past the end', function (): void {
    $path = fixture_path('truncated.xls');
    file_put_contents($path, substr((string) file_get_contents(sample_xls()), 0, 1024));
    assertThrows(static fn () => CompoundFile::open($path));
});

// ---------------------------------------------------------------------------
// .xls — BIFF8
// ---------------------------------------------------------------------------

test('XlsReader names the sheet', function (): void {
    assertSame(['Full Roster'], (new XlsReader(sample_xls()))->sheets());
});

test('XlsReader reads every BIFF8 cell type', function (): void {
    $rows = read_all((new XlsReader(sample_xls()))->rows());

    assertSame(4, count($rows), 'row count');
    assertSame(
        ['Title', 'Customer Number', 'Name', 'Amount', 'When', 'Calc', 'Flag'],
        $rows[0],
        'header'
    );

    // RK integer, a wide (UTF-16) shared string, NUMBER, a date, a formula
    // whose result is a string, and a boolean.
    assertSame('1234567', $rows[1][1], 'RK integer');
    assertSame("O'Brien, Seán", $rows[1][2], 'wide shared string');
    assertSame('1234.5', $rows[1][3], 'NUMBER');
    assertSame('2026-03-01', $rows[1][4], 'date-formatted number');
    assertSame('from formula', $rows[1][5], 'FORMULA with a STRING result');
    assertSame('TRUE', $rows[1][6], 'BOOLERR boolean');

    assertSame('-0.25', $rows[2][3], 'RK scaled by 100');
    assertSame('2026-12-31', $rows[2][4], 'second date');
    assertSame('42', $rows[2][5], 'FORMULA with a numeric result');
    assertSame('#N/A', $rows[2][6], 'BOOLERR error code');

    assertSame('', $rows[3][2], 'BLANK is an empty string, not a missing key');
    assertSame(['10', '20', '30'], array_slice($rows[3], 3, 3), 'MULRK run');
});

test('XlsReader keeps a member number as a string, never a float', function (): void {
    $rows = read_all((new XlsReader(sample_xls()))->rows());

    foreach ([1 => '1234567', 2 => '12345', 3 => '7654321'] as $row => $expected) {
        assertSame($expected, $rows[$row][1], "row {$row} member number");
        assertTrue(is_string($rows[$row][1]), 'must be a string');
        assertTrue(!str_contains($rows[$row][1], '.'), 'must not gain a decimal point');
        assertTrue(!str_contains(strtolower($rows[$row][1]), 'e'), 'must not go exponential');
    }
});

test('XlsReader names an unknown sheet rather than returning nothing', function (): void {
    assertThrows(
        static fn () => read_all((new XlsReader(sample_xls()))->rows('Nope')),
        'Full Roster'
    );
});

// ---------------------------------------------------------------------------
// .xlsx
// ---------------------------------------------------------------------------

test('XlsxReader resolves the sheet through its relationship', function (): void {
    assertSame(['Full Roster'], (new XlsxReader(sample_xlsx()))->sheets());
});

test('XlsxReader addresses cells by reference, so a gap does not shift a row', function (): void {
    $rows = read_all((new XlsxReader(sample_xlsx()))->rows());

    assertSame(3, count($rows), 'row count');
    assertSame(['Title', 'Customer Number', 'Name'], $rows[0]);

    // Row 2 has no <c r="B2"> at all. Read positionally, the name would land
    // in the member-number column.
    assertSame('Chairman', $rows[1][0]);
    assertSame('', $rows[1][1], 'the omitted cell must be empty, not shifted');
    assertSame("O'Brien, Seán", $rows[1][2], 'the name must stay in column C');
});

test('XlsxReader concatenates rich-text runs and reads inline strings', function (): void {
    $rows = read_all((new XlsxReader(sample_xlsx()))->rows());
    assertSame('Assistant Captain', $rows[2][0], 'two runs must join, not truncate');
});

test('XlsxReader converts a date-styled serial and leaves a plain number alone', function (): void {
    $rows = read_all((new XlsxReader(sample_xlsx()))->rows());
    assertSame('7654321', $rows[2][1], 'unstyled number stays verbatim');
    assertSame('2026-03-01', $rows[2][2], 'styled serial becomes a date');
});

test('XlsxReader rejects a zip that is not a workbook', function (): void {
    $path = fixture_path('notsheet.xlsx');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('hello.txt', 'hi');
    $zip->close();

    assertThrows(static fn () => new XlsxReader($path), 'xl/workbook.xml');
});

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

test('CsvReader strips the BOM Excel writes', function (): void {
    $path = fixture_path('bom.csv');
    file_put_contents($path, "\xEF\xBB\xBFTitle,Customer Number\r\nChairman,1234567\r\n");

    $rows = read_all((new CsvReader($path))->rows());
    assertSame('Title', $rows[0][0], 'a BOM left in place makes the header match nothing');
    assertSame('1234567', $rows[1][1]);
});

test('CsvReader repairs Windows-1252 that is not valid UTF-8', function (): void {
    $path = fixture_path('cp1252.csv');
    file_put_contents($path, "Name\n" . mb_convert_encoding("O'Brien-Ståhl", 'Windows-1252', 'UTF-8') . "\n");

    $rows = read_all((new CsvReader($path))->rows());
    assertSame("O'Brien-Ståhl", $rows[1][0]);
    assertTrue(mb_check_encoding($rows[1][0], 'UTF-8'), 'must be valid UTF-8 for MySQL');
});

test('CsvReader keeps a quoted newline inside one cell', function (): void {
    $path = fixture_path('multiline.csv');
    file_put_contents($path, "Name,Address\nExample,\"1 Example Way,\nApt 3\"\n");

    $rows = read_all((new CsvReader($path))->rows());
    assertSame(2, count($rows), 'a quoted newline must not split the row');
    assertSame("1 Example Way,\nApt 3", $rows[1][1]);
});

test('CsvReader pads a short row to the header width', function (): void {
    $path = fixture_path('short.csv');
    file_put_contents($path, "A,B,C\n1\n");

    $rows = read_all((new CsvReader($path))->rows());
    assertSame(['1', '', ''], $rows[1], 'a short row must stay indexable');
});

test('CsvReader refuses binary rather than reading it as one huge cell', function (): void {
    $path = fixture_path('binary.dat');
    file_put_contents($path, "%PDF-1.4\n\x00\x01\x02 not a roster");

    assertThrows(static fn () => new CsvReader($path), 'not a roster');
});

// ---------------------------------------------------------------------------
// Format detection
// ---------------------------------------------------------------------------

test('Spreadsheet detects format by content, not by extension', function (): void {
    // Every one of these is the wrong extension for its content, and every one
    // happens in practice: "Save as CSV" offers to keep the .xls name, and a
    // workbook mailed as .xls is very often really .xlsx.
    $xlsxNamedXls = fixture_path('mislabelled.xls');
    copy(sample_xlsx(), $xlsxNamedXls);

    $xlsNamedXlsx = fixture_path('mislabelled.xlsx');
    copy(sample_xls(), $xlsNamedXlsx);

    $csvNamedXls = fixture_path('saved-as.xls');
    file_put_contents($csvNamedXls, "Title,Customer Number\nChairman,1234567\n");

    assertSame('xlsx', Spreadsheet::detect($xlsxNamedXls));
    assertSame('xls', Spreadsheet::detect($xlsNamedXlsx));
    assertSame('csv', Spreadsheet::detect($csvNamedXls));

    assertTrue(Spreadsheet::open($xlsxNamedXls) instanceof XlsxReader);
    assertTrue(Spreadsheet::open($xlsNamedXlsx) instanceof XlsReader);
    assertTrue(Spreadsheet::open($csvNamedXls) instanceof CsvReader);
});

test('Spreadsheet reads a mislabelled file correctly end to end', function (): void {
    $path = fixture_path('really-xlsx.xls');
    copy(sample_xlsx(), $path);

    $rows = read_all(Spreadsheet::open($path)->rows());
    assertSame(['Title', 'Customer Number', 'Name'], $rows[0]);
});

test('Spreadsheet sniffs a semicolon separator', function (): void {
    // Excel writes the list separator of the machine locale, so a roster saved
    // on a machine set to most of Europe is semicolon-delimited. Read as
    // commas it becomes one enormous column.
    $path = fixture_path('euro.csv');
    file_put_contents($path, "Title;Customer Number;Name\nChairman;1234567;Example\n");

    $rows = read_all(Spreadsheet::open($path)->rows());
    assertSame(3, count($rows[0]), 'semicolons must split into three columns');
    assertSame('1234567', $rows[1][1]);
});
