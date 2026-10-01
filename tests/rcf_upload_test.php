<?php

declare(strict_types=1);

/**
 * Upload RCFs (Phase 13, spec-v2 §14): Roster Change Forms that arrived by
 * email are read into the same record as the ones made here, in two steps
 * — read and shown back, then kept — and every line of every form, however
 * it got here, is listed with what the member was, what the line asks them
 * to become, and the import in which Rodeo Houston fulfilled it.
 *
 * What is asserted, in order of how much it would cost to get wrong:
 *
 *   1. The reader reads the template AS PEOPLE FILL IT IN — the generated
 *      form back to what was printed, and a hand-typed one with "s&t",
 *      "Yes", "4) Member Resigned", a number stored as a number and a date
 *      stored as a date — and SAYS every reshaping, keeps what it cannot
 *      read as typed, and refuses what is not a form by name.
 *   2. Nothing is kept until the second step, a kept form is an ordinary
 *      `rcf` row with its source said, the Division Chairman's number lands
 *      on every line, and each form is one audit row.
 *   3. The same file is not kept twice, a forwarded copy of a form already
 *      kept starts unticked, and a discard leaves nothing behind.
 *   4. "In the roster" is measured from an uploaded form's OWN date.
 *   5. Who may upload, who may open an upload, and what the change table
 *      shows each caller.
 *
 * Fixtures are generated and the expectations are TRANSCRIBED beside them.
 * Generated, never real: this repository is public. Member numbers here are
 * 'RU000001', addresses are @example.com and phones are the reserved
 * (555) 555-01xx fiction range.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/BiffFixture.php';

use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\User;
use Rerm\Forms\FormSheet;
use Rerm\Forms\RcfReader;
use Rerm\Forms\RcfReadException;
use Rerm\Forms\RcfStore;
use Rerm\Forms\RcfTracking;
use Rerm\Forms\RcfUpload;
use Rerm\Forms\RcfUploadException;
use Rerm\Forms\RosterChangeForm;
use Rerm\Routes;

function ru_app(): App
{
    return $GLOBALS['rerm_app'];
}

function ru_source(string $relative): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relative);
}

/** A scratch directory that cleans itself up at the end of the run. */
function ru_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/rerm-ru-' . getmypid();
        @mkdir($dir, 0700, true);
        register_shutdown_function(static function () use (&$dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });
    }

    return $dir;
}

function ru_path(string $name): string
{
    return ru_dir() . '/' . $name;
}

// ---------------------------------------------------------------------------
// What needs no database: the route, the capability, the verb, the screens
// ---------------------------------------------------------------------------

test('the upload route is guarded by upload_forms — Admin / Everywhere, its own row', function (): void {
    assertSame(Capability::UploadForms->value, Routes::guard('rcf-upload'));

    // spec-v2 §14.1, transcribed. Its own row so it can be WIDENED on
    // purpose: the request said Admins now and maybe other officers later.
    assertSame('upload_forms', Capability::UploadForms->value);
    assertSame(Level::Admin, Capability::UploadForms->minimumLevel());
    assertSame(Scope::Everywhere, Capability::UploadForms->scope());

    $user = static fn (Level $l): User => new User(1, 1, 'RU000000', $l, null, null, false, 'U');
    assertSame(false, Access::mayUse($user(Level::ExecutiveOfficer), Capability::UploadForms), 'a Division Chairman may not, today');
    assertSame(true, Access::mayUse($user(Level::Admin), Capability::UploadForms));
    assertSame(true, Access::allows($user(Level::Admin), Capability::UploadForms), 'Everywhere: no subject is needed');
});

test('uploading a form is its own audit verb, written by the keeper', function (): void {
    assertSame('upload_form', Action::UploadForm->value);
    assertTrue(Action::UploadForm->label() !== '');
    assertTrue(str_contains(ru_source('app/src/Forms/RcfUpload.php'), 'Action::UploadForm'), 'the keep goes to the audit log');
});

test('the upload card is on Track RCFs, posts several files to the upload route, and the menu says so', function (): void {
    assertTrue(str_contains(ru_source('app/views/rcfs.php'), 'View::rcfUploadForm('), 'Track RCFs carries the card');
    assertTrue(str_contains(ru_source('app/views/rcf-upload.php'), 'View::rcfUploadForm('), 'and so does the upload screen, through the one renderer');

    $view = ru_source('app/src/View.php');
    assertTrue(str_contains($view, 'name="forms[]"'), 'several files in one field');
    assertTrue(str_contains($view, ' multiple'), 'the control accepts several');
    assertTrue(str_contains($view, "url('rcf-upload')"), 'posting to the upload route');
    assertTrue(str_contains($view, 'enctype="multipart/form-data"'));

    assertTrue(str_contains(ru_source('app/views/menu.php'), 'made here or uploaded'), 'the tile says uploads are tracked too');
    assertTrue(str_contains(ru_source('app/views/rcfs.php'), 'Every change requested'), 'the change table is on the list');
});

test('nothing in the upload code writes the roster, deletes a kept form, or keeps the file', function (): void {
    foreach (['app/src/Forms/RcfReader.php', 'app/src/Forms/RcfUpload.php'] as $file) {
        $source = ru_source($file);
        foreach (['rcf', 'rcf_row', 'member', 'contact_log', 'audit_log', 'import_change', 'import_batch'] as $table) {
            assertSame(0, preg_match('/\bDELETE\s+FROM\s+`?' . $table . '`?\b/i', $source), "{$file} must never DELETE FROM {$table}");
        }
        assertSame(0, preg_match('/\bTRUNCATE\b|\bDROP\s+TABLE\b/i', $source), "{$file} has no TRUNCATE or DROP");
        assertSame(0, preg_match('/(INSERT\s+INTO|UPDATE)\s+`?member`?\b/i', $source), "{$file} never writes member");
        // The file is read from PHP's temporary upload and kept nowhere.
        assertSame(0, preg_match('/move_uploaded_file|\bcopy\(|\brename\(|file_put_contents\(/', $source), "{$file} keeps no file");
    }

    // The only DELETEs are of the staging rows, and only those two.
    preg_match_all('/DELETE\s+FROM\s+(\w+)/i', ru_source('app/src/Forms/RcfUpload.php'), $deletes);
    sort($deletes[1]);
    assertSame(['rcf_upload_batch', 'rcf_upload_file'], $deletes[1], 'staging is the only thing the stager deletes');
    assertSame(0, preg_match_all('/DELETE\s+FROM/i', ru_source('app/src/Forms/RcfReader.php')), 'the reader deletes nothing');
});

// ---------------------------------------------------------------------------
// The fixtures: a form this application generates, one typed by hand as an
// .xlsx, one as an .xls, and two things that are not forms
// ---------------------------------------------------------------------------

/** The generated form: an addition, a removal and a transfer at rows 1, 2 and 5. */
function ru_generated_form(array $overrides = []): array
{
    $entries = [];
    for ($i = 0; $i < RosterChangeForm::ROWS; $i++) {
        $entries[$i] = RosterChangeForm::emptyEntry();
    }

    $entries[0] = [
        'type' => 'A', 'rookie' => '1', 'member_name' => 'Jane Sample', 'member_number' => 'RU000091',
        'new_title' => 'Committee Member', 'previous_title' => '', 'wait_list' => '1', 'remove_reason' => '',
        'new_subcommittee' => '', 'sponsor' => 'Erin Delta',
    ];
    $entries[1] = [
        'type' => 'R', 'rookie' => '0', 'member_name' => 'John Sample', 'member_number' => 'RU000092',
        'new_title' => '', 'previous_title' => 'Captain', 'wait_list' => '0', 'remove_reason' => '4',
        'new_subcommittee' => '', 'sponsor' => '',
    ];
    $entries[4] = [
        'type' => 'S & T', 'rookie' => '0', 'member_name' => 'Pat Sample', 'member_number' => 'RU000093',
        'new_title' => 'Assistant Captain', 'previous_title' => 'Committee Member', 'wait_list' => '0',
        'remove_reason' => '', 'new_subcommittee' => 'RU Bus Ops Team A', 'sponsor' => '',
    ];

    return $overrides + [
        'year'         => '2027',
        'submitter'    => 'A. Officer, Captain',
        'date'         => '3/1/2027',
        'subcommittee' => 'RU Logistics Division - RU Bus Ops Team A',
        'entries'      => $entries,
    ];
}

/** A form written by this application's own writer, as a file. */
function ru_generated_xlsx(string $name, array $form): string
{
    $app   = ru_app();
    $sheet = FormSheet::create(
        ru_dir(),
        $app->path('app/templates/rcf/styles.xml'),
        'Sheet1',
        $app->path('app/templates/rcf/featurePropertyBag.xml')
    );
    RosterChangeForm::draw($sheet, $form);
    $built = $sheet->finish();

    $path = ru_path($name);
    copy($built, $path);
    $sheet->close($built);

    return $path;
}

/**
 * An .xlsx written by hand: `[sheet => [ref => [kind, value]]]`, kind
 * `s` text, `n` number, `b` boolean, `d` a date serial wearing a date
 * style. Style 1 is numFmtId 14, a built-in date format.
 *
 * @param array<string, array<string, array{0: string, 1: mixed}>> $sheets
 */
function ru_xlsx(string $name, array $sheets): string
{
    $cell = static function (string $ref, string $kind, mixed $value): string {
        return match ($kind) {
            'n' => '<c r="' . $ref . '"><v>' . $value . '</v></c>',
            'b' => '<c r="' . $ref . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>',
            'd' => '<c r="' . $ref . '" s="1"><v>' . $value . '</v></c>',
            default => '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $value, ENT_XML1) . '</t></is></c>',
        };
    };

    $zip = new ZipArchive();
    $path = ru_path($name);
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');

    $index = '';
    $rels  = '';
    $n     = 0;
    foreach ($sheets as $sheetName => $cells) {
        $n++;
        $index .= '<sheet name="' . htmlspecialchars((string) $sheetName, ENT_XML1) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels  .= '<Relationship Id="rId' . $n . '" Type="x" Target="worksheets/sheet' . $n . '.xml"/>';

        $rows = [];
        foreach ($cells as $ref => [$kind, $value]) {
            preg_match('/^([A-Z]+)(\d+)$/', (string) $ref, $m);
            $rows[(int) $m[2]][] = $cell((string) $ref, $kind, $value);
        }
        ksort($rows);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $list) {
            $xml .= '<row r="' . $r . '">' . implode('', $list) . '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $xml . '</sheetData></worksheet>');
    }

    $zip->addFromString(
        'xl/workbook.xml',
        '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $index . '</sheets></workbook>'
    );
    $zip->addFromString(
        'xl/_rels/workbook.xml.rels',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>'
    );
    $zip->addFromString(
        'xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>'
    );
    $zip->close();

    return $path;
}

/**
 * The template's chrome and grid headers, at their printed cells, shifted
 * $shift columns to the right when a hand-made copy has gained a column.
 *
 * @return array<string, array{0: string, 1: mixed}>
 */
function ru_template_cells(int $shift = 0): array
{
    $col = static function (string $letter) use ($shift): string {
        return chr(ord($letter) + $shift);
    };

    return [
        $col('J') . '1'  => ['s', 'CHANGE FORM  # '],
        $col('A') . '2'  => ['s', 'Rodeo Express Roster Change Form  - RODEO 2027'],
        $col('J') . '2'  => ['s', '(DC USE ONLY)'],
        $col('A') . '4'  => ['s', 'Name & Title of whom is submitting this form:'],
        $col('A') . '5'  => ['s', 'Date:'],
        $col('E') . '5'  => ['s', 'Sub-Committee:'],
        $col('F') . '23' => ['s', 'For Adds & Title Change Only'],
        $col('J') . '23' => ['s', 'For Adds & Team Changes Only'],
        $col('L') . '23' => ['s', 'For Adds only'],
        $col('C') . '24' => ['s', 'RE'],
        $col('D') . '24' => ['s', 'MEMBER NAME'],
        $col('E') . '24' => ['s', 'HLS&R NO'],
        $col('F') . '24' => ['s', 'CHANGE/ADD'],
        $col('G') . '24' => ['s', 'PREVIOUS'],
        $col('H') . '24' => ['s', 'WAIT LIST'],
        $col('I') . '24' => ['s', '**REMOVE REASON  '],
        $col('J') . '24' => ['s', 'NEW SUB-COMMITTEE (New Team)'],
        $col('L') . '24' => ['s', 'INTERVIEW REQUIRED or SPONSORED BY'],
        $col('B') . '25' => ['s', '*TYPE'],
        $col('C') . '25' => ['s', 'ROOKIE'],
        $col('F') . '25' => ['s', 'TITLE'],
        $col('G') . '25' => ['s', 'TITLE'],
        $col('C') . '26' => ['s', 'y/n'],
        $col('H') . '26' => ['s', '√'],
    ];
}

/**
 * The hand-typed form: the template filled in the way people fill it in.
 * Excel serial 46447 is 1 March 2027.
 */
function ru_hand_xlsx(string $name): string
{
    return ru_xlsx($name, [
        'Notes' => ['A1' => ['s', 'Notes to the DC']],
        'RCF'   => ru_template_cells() + [
            'K1'  => ['s', '2027-14'],
            'G4'  => ['s', 'Erin Delta, Vice Chairman'],
            'D5'  => ['d', 46447],
            'G5'  => ['s', 'RU Logistics Division - RU Bus Ops Team A'],
            // 1) a transfer with a title change, typed as "s&t", the number
            //    as a number, the box as "Yes"
            'A27' => ['s', '1)'], 'B27' => ['s', 's&t'], 'C27' => ['s', 'Yes'], 'D27' => ['s', 'Robert Alpha'],
            'E27' => ['n', 9000001], 'F27' => ['s', 'Captain'], 'G27' => ['s', 'Committee Member'],
            'H27' => ['s', 'No'], 'J27' => ['s', 'RU Bus Ops Team B'],
            // 2) a removal, with the number as Excel formats it and the
            //    reason as the legend prints it; no name — the roster's
            'A28' => ['s', '2)'], 'B28' => ['s', 'Remove'], 'E28' => ['s', '9,000,002'], 'I28' => ['s', '4) Member Resigned'],
            // 3) blank, on purpose: a gap the positions must survive
            'A29' => ['s', '3)'],
            // 4) an addition typed as the picker spells it, the box as "x"
            'A30' => ['s', '4)'], 'B30' => ['s', 'A'], 'C30' => ['s', 'x'], 'D30' => ['s', 'Newcomer Sample - 7654321'],
            'H30' => ['s', 'Yes'], 'L30' => ['s', 'Erin Delta'],
            // 5) a type and a reason that are none of the codes, and a number
            //    with its leading zeros that Excel turned into a float
            'A31' => ['s', '5)'], 'B31' => ['s', 'Z'], 'D31' => ['s', 'Frank Echo'], 'E31' => ['s', '0001234.0'], 'I31' => ['s', '7'],
        ],
    ]);
}

/** The same form as a legacy .xls, with real booleans and a real date cell. */
function ru_hand_xls(string $name, string $number = '9000002', string $serial = 'DC-77', string $year = '2026'): string
{
    return (new BiffFixture('Sheet1'))
        ->label(0, 9, 'CHANGE FORM  # ')->label(0, 10, $serial)
        ->label(1, 0, 'Rodeo Express Roster Change Form  - RODEO ' . $year)
        ->label(3, 0, 'Name & Title of whom is submitting this form:')->label(3, 6, 'Dana Chair, Division Chairman')
        ->label(4, 0, 'Date:')->date(4, 3, '2026-02-10')->label(4, 4, 'Sub-Committee:')->label(4, 6, 'RU Bus Ops Team B')
        ->label(23, 2, 'RE')->label(23, 3, 'MEMBER NAME')->label(23, 4, 'HLS&R NO')->label(23, 5, 'CHANGE/ADD')
        ->label(23, 6, 'PREVIOUS')->label(23, 7, 'WAIT LIST')->label(23, 8, '**REMOVE REASON  ')
        ->label(23, 9, 'NEW SUB-COMMITTEE (New Team)')->label(23, 11, 'INTERVIEW REQUIRED or SPONSORED BY')
        ->label(24, 1, '*TYPE')->label(24, 2, 'ROOKIE')->label(24, 5, 'TITLE')->label(24, 6, 'TITLE')
        ->label(25, 2, 'y/n')->label(25, 7, '√')
        ->label(26, 0, '1)')->label(26, 1, 'R')->boolean(26, 2, false)->label(26, 3, 'Carol Bravo')
        ->label(26, 4, $number)->boolean(26, 7, false)->rkInt(26, 8, 4)
        ->label(27, 0, '2)')->boolean(27, 2, false)->boolean(27, 7, false)
        ->write(ru_path($name));
}

/** A roster export, which is a spreadsheet and not a form. */
function ru_roster_xls(string $name): string
{
    return (new BiffFixture('Full Roster'))
        ->label(0, 0, 'Title')->label(0, 1, 'Customer Number')->label(0, 2, 'Subcommittee 1')
        ->label(1, 0, 'Captain')->rkInt(1, 1, 9000001)->label(1, 2, 'RU Bus Ops Team A')
        ->write(ru_path($name));
}

// ---------------------------------------------------------------------------
// The reader
// ---------------------------------------------------------------------------

test('a form this application generated reads back as exactly what was printed', function (): void {
    $form = ru_generated_form();
    $read = RcfReader::read(ru_generated_xlsx('generated.xlsx', $form));

    assertSame('xlsx', $read['format']);
    assertSame('Sheet1', $read['sheet']);
    // The columns, by their labels — B through L, with K the merged half.
    assertSame(
        ['member_name' => 3, 'member_number' => 4, 'type' => 1, 'rookie' => 2, 'new_title' => 5,
            'previous_title' => 6, 'wait_list' => 7, 'remove_reason' => 8, 'new_subcommittee' => 9, 'sponsor' => 11],
        $read['columns']
    );
    assertSame(3, $read['rows']);
    assertSame('', $read['serial'], 'the DC box on a generated form is empty');
    assertSame('Rodeo Express Roster Change Form  - RODEO 2027', $read['title']);
    assertSame('3/1/2027', $read['raw_date']);

    $kept = $read['form'];
    assertSame('2027', $kept['year']);
    assertSame('A. Officer, Captain', $kept['submitter']);
    assertSame('3/1/2027', $kept['date']);
    assertSame('2027-03-01', $kept['form_date']);
    assertSame('RU Logistics Division - RU Bus Ops Team A', $kept['subcommittee']);
    assertSame(RosterChangeForm::ROWS, count($kept['entries']));

    // The three filled rows, exactly as written; the blank ones carry an
    // unticked box in both checkbox columns, the shape RcfStore::form() has.
    assertSame($form['entries'][0], $kept['entries'][0]);
    assertSame($form['entries'][1], $kept['entries'][1]);
    assertSame($form['entries'][4], $kept['entries'][4]);
    assertSame(RosterChangeForm::UNTICKED, $kept['entries'][2]['rookie']);
    assertTrue(RosterChangeForm::entryIsBlank($kept['entries'][2]));
    assertSame([], $read['warnings'], 'nothing had to be reshaped or doubted');
});

test('a form filled in by hand is read the way its author meant it, and every reshaping is said', function (): void {
    $read = RcfReader::read(ru_hand_xlsx('hand.xlsx'));

    assertSame('RCF', $read['sheet'], 'the sheet with the grid, not the notes sheet before it');
    assertSame(4, $read['rows']);
    assertSame('2027-14', $read['serial'], 'the number in the cell beside CHANGE FORM #');
    assertSame('2027-03-01', $read['form']['form_date'], 'a real date cell is the day it holds');
    assertSame('3/1/2027', $read['form']['date']);
    assertSame('Erin Delta, Vice Chairman', $read['form']['submitter']);
    assertSame('RU Logistics Division - RU Bus Ops Team A', $read['form']['subcommittee']);
    assertSame('2027', $read['form']['year']);

    $e = $read['form']['entries'];
    assertSame('S & T', $e[0]['type'], '"s&t" is the form\'s own spelling');
    assertSame('1', $e[0]['rookie'], '"Yes" ticks');
    assertSame('0', $e[0]['wait_list'], '"No" does not');
    assertSame('9000001', $e[0]['member_number'], 'a number stored as a number');
    assertSame('Robert Alpha', $e[0]['member_name']);
    assertSame('Captain', $e[0]['new_title']);
    assertSame('Committee Member', $e[0]['previous_title']);
    assertSame('RU Bus Ops Team B', $e[0]['new_subcommittee']);

    assertSame('R', $e[1]['type']);
    assertSame('9000002', $e[1]['member_number'], 'thousands separators are nothing');
    assertSame('', $e[1]['member_name'], 'no name on the line — the stage fills it from the roster');
    assertSame('4', $e[1]['remove_reason'], 'the number, as the cell should hold');

    assertTrue(RosterChangeForm::entryIsBlank($e[2]), 'the gap at row 3 is a gap');

    assertSame('A', $e[3]['type']);
    assertSame('Newcomer Sample', $e[3]['member_name'], 'read as the picker reads it');
    assertSame('7654321', $e[3]['member_number']);
    assertSame('1', $e[3]['rookie'], '"x" ticks');
    assertSame('1', $e[3]['wait_list']);
    assertSame('Erin Delta', $e[3]['sponsor']);

    assertSame('Z', $e[4]['type'], 'none of the codes: kept AS TYPED');
    assertSame('7', $e[4]['remove_reason'], 'none of the reasons: kept as typed');
    assertSame('0001234', $e[4]['member_number'], 'the leading zeros survive Excel\'s float');

    // Every reshaping and every doubt, by row and kind, transcribed.
    $kinds = [];
    foreach ($read['warnings'] as $warning) {
        $kinds[] = ($warning['row'] ?? '-') . ':' . $warning['kind'];
    }
    sort($kinds);
    assertSame([
        '-:' . RcfReader::SERIAL_FOUND,
        '1:' . RcfReader::READ_AS,                                   // s&t
        '2:' . RcfReader::NO_NAME,
        '2:' . RcfReader::READ_AS, '2:' . RcfReader::READ_AS, '2:' . RcfReader::READ_AS,   // the number, Remove, 4) Member Resigned
        '4:' . RcfReader::READ_AS,                                   // Name - number
        '5:' . RcfReader::READ_AS,                                   // 0001234.0
        '5:' . RcfReader::UNKNOWN_REASON,
        '5:' . RcfReader::UNKNOWN_TYPE,
    ], $kinds);

    foreach ($read['warnings'] as $warning) {
        if ($warning['row'] === 1) {
            assertSame('*TYPE "s&t" read as S & T.', $warning['detail']);
        }
    }
});

test('an .xls form reads identically, booleans and a date cell included', function (): void {
    $read = RcfReader::read(ru_hand_xls('hand.xls'));

    assertSame('xls', $read['format']);
    assertSame(1, $read['rows'], 'the second numbered row carries only unticked boxes, which is blank');
    assertSame('DC-77', $read['serial']);
    assertSame('2026', $read['form']['year']);
    assertSame('2026-02-10', $read['form']['form_date']);
    assertSame('2/10/2026', $read['form']['date']);
    assertSame('Dana Chair, Division Chairman', $read['form']['submitter']);
    assertSame('RU Bus Ops Team B', $read['form']['subcommittee'], 'a team name alone');

    $line = $read['form']['entries'][0];
    assertSame('R', $line['type']);
    assertSame('Carol Bravo', $line['member_name']);
    assertSame('9000002', $line['member_number']);
    assertSame('4', $line['remove_reason'], 'a reason stored as a number');
    assertSame('0', $line['rookie']);
    assertSame('0', $line['wait_list']);

    $kinds = array_map(static fn (array $w): string => $w['kind'], $read['warnings']);
    assertSame([RcfReader::SERIAL_FOUND], $kinds, 'a real date cell is not a reshaping');
});

test('a form whose columns have moved still reads, by its labels, and without its numbering reads in order', function (): void {
    $cells = ru_template_cells(1);   // everything one column to the right
    unset($cells['K1']);
    $cells += [
        'H4'  => ['s', 'Casey Captain, Captain'],
        'E5'  => ['s', '3/2/2027'],
        'H5'  => ['s', 'RU Bus Ops Team A'],
        // No "1)" column: the rows after the header block, in order.
        'C27' => ['s', 'T'], 'E27' => ['s', 'Robert Alpha'], 'F27' => ['s', 'RU000001'], 'G27' => ['s', 'Assistant Captain'],
        'C28' => ['s', 'S'], 'E28' => ['s', 'Carol Bravo'], 'F28' => ['s', 'RU000002'], 'K28' => ['s', 'RU Bus Ops Team B'],
    ];
    $read = RcfReader::read(ru_xlsx('shifted.xlsx', ['Sheet1' => $cells]));

    assertSame(2, $read['rows']);
    assertSame(['member_name' => 4, 'member_number' => 5, 'type' => 2, 'rookie' => 3, 'new_title' => 6,
        'previous_title' => 7, 'wait_list' => 8, 'remove_reason' => 9, 'new_subcommittee' => 10, 'sponsor' => 12], $read['columns']);
    assertSame('T', $read['form']['entries'][0]['type']);
    assertSame('RU000001', $read['form']['entries'][0]['member_number']);
    assertSame('S', $read['form']['entries'][1]['type']);
    assertSame('RU Bus Ops Team B', $read['form']['entries'][1]['new_subcommittee']);
    assertSame('2027-03-02', $read['form']['form_date'], 'a typed US date');
    assertSame('', $read['serial']);
});

test('what is not a form is refused by name, and an empty form is refused too', function (): void {
    assertThrows(
        static fn () => RcfReader::read(ru_roster_xls('roster.xls')),
        'not a Roster Change Form',
        'a roster export is a spreadsheet and not a form'
    );
    assertThrows(
        static fn () => RcfReader::read(ru_roster_xls('roster.xls')),
        'Full Roster',
        'and the refusal names the sheets it looked at'
    );

    file_put_contents(ru_path('junk.bin'), "not a spreadsheet at all\n");
    assertThrows(static fn () => RcfReader::read(ru_path('junk.bin')), 'not an Excel workbook either');

    $empty = ru_generated_form(['entries' => array_fill(0, RosterChangeForm::ROWS, RosterChangeForm::emptyEntry())]);
    assertThrows(
        static fn () => RcfReader::read(ru_generated_xlsx('empty.xlsx', $empty)),
        'nothing filled in',
        'a blank form is no request'
    );

    try {
        RcfReader::read(ru_path('junk.bin'));
        assertTrue(false, 'should have thrown');
    } catch (RcfReadException) {
        assertTrue(true);
    }
});

test('the cell readers, as a table of cases', function (): void {
    // *TYPE
    assertSame(['S & T', false], RcfReader::type('S & T'));
    assertSame(['S & T', true], RcfReader::type('s&t'));
    assertSame(['S & T', true], RcfReader::type('T & S'));
    assertSame(['S & T', true], RcfReader::type('ST'));
    assertSame(['S & T', true], RcfReader::type('S+T'));
    assertSame(['S & T', true], RcfReader::type('Sub-Committee Change (Team Change) & Title Change'));
    assertSame(['A', false], RcfReader::type('A'));
    assertSame(['A', true], RcfReader::type('Add'));
    assertSame(['A', true], RcfReader::type('Addition'));
    assertSame(['A', true], RcfReader::type('A = Addition'));
    assertSame(['R', true], RcfReader::type('remove'));
    assertSame(['T', true], RcfReader::type('Title Change'));
    assertSame(['S', true], RcfReader::type('Team'));
    assertSame(['S', true], RcfReader::type('sub'));
    assertSame([null, false], RcfReader::type('Z'));
    assertSame([null, false], RcfReader::type(''));

    // **REMOVE REASON
    assertSame(['4', false], RcfReader::reason('4'));
    assertSame(['4', true], RcfReader::reason('4)'));
    assertSame(['4', true], RcfReader::reason('4.'));
    assertSame(['4', true], RcfReader::reason('4) Member Resigned'));
    assertSame(['4', true], RcfReader::reason('Member Resigned'));
    assertSame(['3', true], RcfReader::reason('Leadership Recommendation'));
    assertSame(['1', true], RcfReader::reason('Deceased Member'));
    assertSame([null, false], RcfReader::reason('Deceased'));
    assertSame([null, false], RcfReader::reason('7'));
    assertSame([null, false], RcfReader::reason(''));

    // The tick boxes
    foreach (['Yes', 'Y', 'y', 'x', 'X', '1', 'TRUE', '√', '✓'] as $yes) {
        assertSame(RosterChangeForm::TICKED, RcfReader::tick($yes), var_export($yes, true));
    }
    foreach (['No', 'n', '0', 'FALSE', '', '-'] as $no) {
        assertSame(RosterChangeForm::UNTICKED, RcfReader::tick($no), var_export($no, true));
    }
    assertSame(null, RcfReader::tick('maybe'));

    // HLS&R NO
    assertSame('1234567', RcfReader::memberNumber('1234567.0'));
    assertSame('1234567', RcfReader::memberNumber('1,234,567'));
    assertSame('1234567', RcfReader::memberNumber('1.234567E+6'));
    assertSame('0001234', RcfReader::memberNumber(' 0001234 '));
    assertSame('RU000001', RcfReader::memberNumber('ru000001'));
    assertSame('', RcfReader::memberNumber('none'));
    assertSame('', RcfReader::memberNumber(''));

    // The date
    assertSame('2027-03-01', RcfReader::isoDate('3/1/2027'));
    assertSame('2027-03-01', RcfReader::isoDate('March 1, 2027'));
    assertSame('2027-03-01', RcfReader::isoDate('1 Mar 2027'));
    assertSame('2027-03-01', RcfReader::isoDate('2027-03-01'));
    assertSame('2027-03-01', RcfReader::isoDate('3-1-27'));
    assertSame(null, RcfReader::isoDate('soon'));
    assertSame(null, RcfReader::isoDate(''));
    assertSame('3/1/2027', RcfReader::american('2027-03-01'));
});

test('a sub-committee label resolves to a team or a division by name, and the team half reads off it', function (): void {
    $teams = [
        'ru bus ops team a' => ['id' => 11, 'name' => 'RU Bus Ops Team A', 'division_id' => 1, 'division_name' => 'RU Logistics Division'],
    ];
    $divisions = ['ru logistics division' => ['id' => 1, 'name' => 'RU Logistics Division']];

    assertSame([11, null, 'RU Bus Ops Team A', 'RU Logistics Division'], RcfUpload::subcommittee('RU Logistics Division - RU Bus Ops Team A', $teams, $divisions));
    assertSame([11, null, 'RU Bus Ops Team A', 'RU Logistics Division'], RcfUpload::subcommittee('ru bus ops team a', $teams, $divisions), 'case does not matter');
    assertSame([11, null, 'RU Bus Ops Team A', 'RU Logistics Division'], RcfUpload::subcommittee('RU Logistics Division – RU Bus Ops Team A', $teams, $divisions), 'an en dash too');
    assertSame([null, 1, '', 'RU Logistics Division'], RcfUpload::subcommittee('RU Logistics Division', $teams, $divisions));
    assertSame([null, 1, '', 'RU Logistics Division'], RcfUpload::subcommittee('RU Logistics Division - Nowhere', $teams, $divisions), 'the division alone, when the team is not one');
    assertSame([null, null, '', ''], RcfUpload::subcommittee('Nowhere', $teams, $divisions));
    assertSame([null, null, '', ''], RcfUpload::subcommittee('', $teams, $divisions));

    assertSame('RU Bus Ops Team A', RcfTracking::teamOf('RU Logistics Division - RU Bus Ops Team A'));
    assertSame('RU Bus Ops Team A', RcfTracking::teamOf('RU Bus Ops Team A'));
    assertSame('', RcfTracking::teamOf(''));
});

test('was and becomes read off the line\'s own cells, in the form\'s vocabulary', function (): void {
    $line = static fn (array $o): array => $o + [
        'type' => '', 'subcommittee' => 'RU Logistics Division - RU Bus Ops Team A', 'previous_title' => '',
        'new_title' => '', 'new_subcommittee' => '', 'remove_reason' => '',
    ];

    assertSame(['Not on the roster', 'Added as Committee Member to RU Bus Ops Team A'],
        RcfTracking::wasBecomes($line(['type' => 'A', 'new_title' => 'Committee Member'])));
    assertSame(['On the roster as Captain, RU Bus Ops Team A', 'Removed — Member Resigned'],
        RcfTracking::wasBecomes($line(['type' => 'R', 'previous_title' => 'Captain', 'remove_reason' => '4'])));
    assertSame(['Committee Member', 'Captain'],
        RcfTracking::wasBecomes($line(['type' => 'T', 'previous_title' => 'Committee Member', 'new_title' => 'Captain'])));
    assertSame(['RU Bus Ops Team A', 'RU Bus Ops Team B'],
        RcfTracking::wasBecomes($line(['type' => 'S', 'new_subcommittee' => 'RU Bus Ops Team B'])));
    assertSame(['RU Bus Ops Team A, Committee Member', 'RU Bus Ops Team B, Captain'],
        RcfTracking::wasBecomes($line(['type' => 'S & T', 'previous_title' => 'Committee Member', 'new_title' => 'Captain', 'new_subcommittee' => 'RU Bus Ops Team B'])));
    assertSame(['Title not given', 'New title not given'], RcfTracking::wasBecomes($line(['type' => 'T'])));
    assertSame(['—', 'Z'], RcfTracking::wasBecomes($line(['type' => 'Z'])), 'an unknown code is shown as itself');
    assertSame(['—', 'No type'], RcfTracking::wasBecomes($line([])));
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function ru_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = ru_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $migrated = (int) $pdo
        ->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rcf_upload_file'")
        ->fetchColumn();
    if ($migrated === 0) {
        $failure = 'database is not migrated past 012 — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

function ru_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'RU%' OR member_number IN ('9000001', '9000002')";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";
    $forms   = "SELECT id FROM rcf WHERE generated_by IN ({$users})";
    $batches = "SELECT id FROM rcf_upload_batch WHERE uploaded_by IN ({$users})";

    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM rcf_upload_file WHERE batch_id IN ({$batches})");
    $pdo->exec("DELETE FROM rcf_upload_batch WHERE uploaded_by IN ({$users})");
    $pdo->exec("DELETE FROM rcf_row WHERE rcf_id IN ({$forms})");
    $pdo->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
    $pdo->exec("DELETE FROM import_change WHERE member_number LIKE 'RU%' OR member_number IN ('9000001', '9000002')");
    $pdo->exec("DELETE FROM import_batch WHERE filename LIKE 'RU-%'");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'RU%' OR member_number IN ('9000001', '9000002')");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'RU %'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'RU %'");
}

/**
 * One division, two teams, and six people, three with accounts:
 *
 *   RU Logistics Division   RU Bus Ops Team A   adm (Admin), cap (Captain, Officer), m1 Robert Alpha, m2 Carol Bravo
 *                           RU Bus Ops Team B   dc (Division Chairman, Executive Officer), m3 Frank Echo
 *
 * m1 and m2 carry the numbers the hand-typed fixtures name (9000001 and
 * 9000002, in the 900xxxx block the real export does not use), so an
 * uploaded form resolves to them.
 *
 * @return array<string, mixed>
 */
function ru_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = ru_pdo();
    ru_teardown($pdo);

    $pdo->prepare('INSERT INTO division (name, is_placeholder) VALUES (:name, 0)')->execute([':name' => 'RU Logistics Division']);
    $division = (int) $pdo->lastInsertId();

    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    $teams      = [];
    foreach (['a' => 'RU Bus Ops Team A', 'b' => 'RU Bus Ops Team B'] as $key => $name) {
        $insertTeam->execute([':name' => $name, ':division' => $division]);
        $teams[$key] = (int) $pdo->lastInsertId();
    }

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name,'
        . ' division_id, team_id, phone, phone_e164, phone_type, email, title, title_level)'
        . " VALUES (:number, :first, :last, '', :full, :division, :team,"
        . " '(555) 555-0131', '+15555550131', 'CELL PHONE', :email, :title, :level)"
    );
    $specs = [
        'adm' => ['a', 'RU000010', 'Alex',   'Admin',   'Committee Member',  'member'],
        'cap' => ['a', 'RU000011', 'Casey',  'Captain', 'Captain',           'officer'],
        'dc'  => ['b', 'RU000012', 'Dana',   'Chair',   'Division Chairman', 'executive_officer'],
        'm1'  => ['a', '9000001',  'Robert', 'Alpha',   'Committee Member',  'member'],
        'm2'  => ['a', '9000002',  'Carol',  'Bravo',   'Committee Member',  'member'],
        'm3'  => ['b', 'RU000003', 'Frank',  'Echo',    'Committee Member',  'member'],
    ];
    $members = [];
    foreach ($specs as $key => [$team, $number, $first, $last, $title, $level]) {
        $insertMember->execute([
            ':number' => $number, ':first' => $first, ':last' => $last, ':full' => $first . ' ' . $last,
            ':division' => $division, ':team' => $teams[$team],
            ':email' => strtolower($first) . '@example.com', ':title' => $title, ':level' => $level,
        ]);
        $members[$key] = ['id' => (int) $pdo->lastInsertId(), 'number' => $number];
    }

    $insertUser = $pdo->prepare(
        "INSERT INTO app_user (member_id, level, granted_level, password_hash, must_change_password, is_active)"
        . " VALUES (:m, :l, :g, '*', 0, 1)"
    );
    $users = [];
    foreach (['adm' => ['member', 'admin'], 'cap' => ['officer', null], 'dc' => ['executive_officer', null]] as $key => [$level, $granted]) {
        $insertUser->execute([':m' => $members[$key]['id'], ':l' => $level, ':g' => $granted]);
        $users[$key] = (int) $pdo->lastInsertId();
    }

    $year = $pdo->query('SELECT id, label FROM show_year WHERE is_active = 1')->fetch();

    return $fixture = [
        'division' => $division, 'teams' => $teams, 'members' => $members, 'users' => $users,
        'year' => (int) $year['id'], 'year_label' => (string) $year['label'],
    ];
}

function ru_user(string $key): User
{
    $f     = ru_fixture();
    $level = match ($key) {
        'adm' => Level::Admin,
        'cap' => Level::Officer,
        default => Level::ExecutiveOfficer,
    };

    return new User(
        id: $f['users'][$key],
        memberId: $f['members'][$key]['id'],
        memberNumber: $f['members'][$key]['number'],
        level: $level,
        scopeDivisionId: $f['division'],
        scopeTeamId: $key === 'dc' ? $f['teams']['b'] : $f['teams']['a'],
        mustChangePassword: false,
        displayName: $key,
    );
}

/** Forgets every form and upload the fixture's users made, so a test can count. */
function ru_reset(): void
{
    $f     = ru_fixture();
    $pdo   = ru_pdo();
    $users = implode(', ', array_map('intval', $f['users']));
    $pdo->exec("DELETE FROM rcf_upload_file WHERE batch_id IN (SELECT id FROM rcf_upload_batch WHERE uploaded_by IN ({$users}))");
    $pdo->exec("DELETE FROM rcf_upload_batch WHERE uploaded_by IN ({$users})");
    $pdo->exec("DELETE FROM rcf_row WHERE rcf_id IN (SELECT id FROM rcf WHERE generated_by IN ({$users}))");
    $pdo->exec("DELETE FROM rcf WHERE generated_by IN ({$users})");
    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM import_change WHERE member_number LIKE 'RU%' OR member_number IN ('9000001', '9000002')");
}

function ru_upload(): RcfUpload
{
    return new RcfUpload(ru_pdo(), new RcfStore(ru_pdo()), 24);
}

/** A generated form naming the fixture's members, dated on the active year. */
function ru_form_for_fixture(array $rows, string $date = '2027-03-01'): array
{
    $f       = ru_fixture();
    $entries = [];
    for ($i = 0; $i < RosterChangeForm::ROWS; $i++) {
        $entries[$i] = RosterChangeForm::emptyEntry();
    }
    foreach ($rows as $index => $row) {
        $entries[$index] = $row + RosterChangeForm::emptyEntry();
    }

    return [
        'year'         => $f['year_label'],
        'submitter'    => 'Casey Captain, Captain',
        'date'         => (new DateTimeImmutable($date))->format('n/j/Y'),
        'subcommittee' => 'RU Logistics Division - RU Bus Ops Team A',
        'entries'      => $entries,
        'form_date'        => $date,
        'submitter_number' => 'RU000011',
        'division_id'      => null,
        'team_id'          => $f['teams']['a'],
    ];
}

/** The files a test uploads, as the handler hands them to stage(). */
function ru_files(array $paths): array
{
    $files = [];
    foreach ($paths as $name => $path) {
        $files[] = ['path' => $path, 'name' => is_string($name) ? $name : basename($path), 'size' => (int) filesize($path)];
    }

    return $files;
}

// ---------------------------------------------------------------------------
// Reading an upload into a staged batch
// ---------------------------------------------------------------------------

test('an upload is read into a staged batch: one row per file, whatever became of it, and nothing in rcf', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    $gen  = ru_generated_xlsx('gen.xlsx', ru_form_for_fixture([
        0 => ['type' => 'T', 'member_name' => 'Robert Alpha', 'member_number' => '9000001', 'new_title' => 'Assistant Captain', 'previous_title' => 'Committee Member'],
        2 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'rookie' => '1', 'wait_list' => '1', 'sponsor' => 'Erin Delta'],
    ]));
    $xls  = ru_hand_xls('dc.xls', '9000002', 'DC-77', '1999');
    $copy = ru_path('gen-again.xlsx');
    copy($gen, $copy);

    $before = (int) $pdo->query('SELECT COUNT(*) FROM rcf')->fetchColumn();
    $files  = ru_files([
        'gen.xlsx'       => $gen,
        'dc.xls'         => $xls,
        'roster.xls'     => ru_roster_xls('roster.xls'),
        'gen-again.xlsx' => $copy,
    ]);
    // A fifth file PHP could not receive, handed over with its refusal.
    $files[] = ['path' => null, 'name' => 'lost.xlsx', 'size' => 0, 'error' => 'The upload of this file was cut off part way. Try again.'];
    $id = ru_upload()->stage(ru_user('adm'), $files);
    assertSame($before, (int) $pdo->query('SELECT COUNT(*) FROM rcf')->fetchColumn(), 'nothing is kept by reading');

    $batch = $pdo->query("SELECT * FROM rcf_upload_batch WHERE id = {$id}")->fetch();
    assertSame($f['users']['adm'], (int) $batch['uploaded_by']);
    assertSame(null, $batch['applied_at']);
    assertSame(5, (int) $batch['files_read']);
    assertSame(2, (int) $batch['files_ready']);
    assertSame(3, (int) $batch['files_refused']);

    $files = $pdo->query("SELECT * FROM rcf_upload_file WHERE batch_id = {$id} ORDER BY position")->fetchAll();
    assertSame(['gen.xlsx', 'dc.xls', 'roster.xls', 'gen-again.xlsx', 'lost.xlsx'], array_column($files, 'filename'), 'in the order sent');
    assertSame(['ready', 'ready', 'refused', 'refused', 'refused'], array_column($files, 'status'));

    // File 1: the generated form, resolved.
    $one  = $files[0];
    $json = json_decode((string) $one['form_json'], true);
    assertSame('xlsx', $one['format']);
    assertSame(hash_file('sha256', $gen), $one['sha256']);
    assertSame($f['year'], (int) $one['show_year_id'], 'the title names the active year');
    assertSame($f['teams']['a'], (int) $one['team_id'], 'the sub-committee label names the team');
    assertSame('2027-03-01', $one['form_date']);
    assertSame('', $one['serial']);
    assertSame(2, (int) $one['row_count']);
    assertSame(1, (int) $one['keep_by_default']);
    assertSame($f['members']['m1']['id'], (int) $json['resolved']['lines'][1]['member_id'], 'the number resolved to m1');
    assertSame(true, $json['resolved']['lines'][1]['on_roster']);
    assertSame(null, $json['resolved']['lines'][3]['member_id'], 'a newcomer by name has no row');
    assertSame('Assistant Captain', $json['form']['entries'][0]['new_title']);
    assertSame($f['year_label'], $json['form']['year']);

    // File 2: the Division Chairman's numbered .xls — the number, the name
    // and the previous title the roster supplied, the year assumed.
    $two  = $files[1];
    $json = json_decode((string) $two['form_json'], true);
    $warn = array_column(json_decode((string) $two['warnings_json'], true), 'kind');
    assertSame('DC-77', $two['serial']);
    assertSame($f['teams']['b'], (int) $two['team_id'], 'a team name alone');
    assertSame($f['year'], (int) $two['show_year_id'], 'RODEO 1999 is no show year here, so the active one');
    assertTrue(in_array(RcfUpload::YEAR_ASSUMED, $warn, true), 'and it says so');
    assertTrue(in_array(RcfUpload::TITLE_FROM_ROSTER, $warn, true), 'the previous title came from the roster');
    assertSame('Committee Member', $json['form']['entries'][0]['previous_title']);
    assertSame('Carol Bravo', $json['form']['entries'][0]['member_name']);
    assertSame($f['members']['m2']['id'], (int) $json['resolved']['lines'][1]['member_id']);

    // Files 3 to 5: refused, each saying why.
    assertTrue(str_contains((string) $files[2]['refusal'], 'not a Roster Change Form'));
    assertTrue(str_contains((string) $files[3]['refusal'], 'same file as #1 (gen.xlsx)'), 'the same bytes under another name');
    assertTrue(str_contains((string) $files[4]['refusal'], 'cut off part way'), 'a file PHP could not receive is a row, not silence');
    assertSame(null, $files[3]['form_json']);

    // The preview, and who may open it.
    $upload  = ru_upload();
    $preview = $upload->preview(ru_user('adm'), $id);
    assertTrue($preview !== null);
    assertSame(5, count($preview['files']));
    assertSame('Alex Admin', $preview['batch']['uploader_name']);
    assertSame(2, count($preview['files'][0]['lines']));
    assertSame('Title Change — Committee Member → Assistant Captain', $preview['files'][0]['lines'][0]['change']);
    assertSame(true, $preview['files'][0]['lines'][0]['on_roster']);
    assertSame([], $preview['files'][0]['lines'][0]['warnings']);
    assertSame(RcfReader::NO_NUMBER, $preview['files'][0]['lines'][1]['warnings'][0]['kind'], 'the newcomer has no number to watch for');
    assertSame(null, $upload->preview(ru_user('cap'), $id), 'not the uploader, and no view_all_forms: it does not exist');
    assertTrue($upload->preview(ru_user('dc'), $id) !== null, 'a Division Chairman may open anybody\'s upload');
    assertSame(null, $upload->preview(ru_user('adm'), 0));

    $recent = $upload->recent(ru_user('adm'));
    assertSame([$id], array_column($recent['staged'], 'id'));
    assertSame([], $recent['kept']);
});

test('reading nothing, or too much, or with no active show year, is refused before a row is staged', function (): void {
    ru_fixture();
    assertThrows(static fn () => ru_upload()->stage(ru_user('adm'), []), 'at least one');

    $many = [];
    for ($i = 0; $i <= RcfUpload::MAX_FILES; $i++) {
        $many[] = ['path' => null, 'name' => 'f' . $i . '.xlsx', 'size' => 0];
    }
    assertThrows(static fn () => ru_upload()->stage(ru_user('adm'), $many), 'smaller batches');
});

// ---------------------------------------------------------------------------
// Keeping
// ---------------------------------------------------------------------------

test('keep writes the ticked files as uploaded forms, numbered where the form was, and logs each one', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    $gen = ru_generated_xlsx('gen.xlsx', ru_form_for_fixture([
        0 => ['type' => 'T', 'member_name' => 'Robert Alpha', 'member_number' => '9000001', 'new_title' => 'Assistant Captain', 'previous_title' => 'Committee Member'],
        2 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'rookie' => '1', 'wait_list' => '1', 'sponsor' => 'Erin Delta'],
    ]));
    $xls = ru_hand_xls('dc.xls');
    $id  = ru_upload()->stage(ru_user('adm'), ru_files(['gen.xlsx' => $gen, 'dc.xls' => $xls]));

    $fileIds = array_map('intval', array_column($pdo->query("SELECT id FROM rcf_upload_file WHERE batch_id = {$id} ORDER BY position")->fetchAll(), 'id'));
    $result  = ru_upload()->keep(ru_user('adm'), $id, $fileIds);
    assertSame(2, $result['kept']);
    assertSame(0, $result['left_out']);
    assertSame(3, $result['lines'], 'two lines and one');

    $forms = $pdo->query("SELECT * FROM rcf WHERE generated_by = {$f['users']['adm']} ORDER BY id")->fetchAll();
    assertSame(2, count($forms));
    assertSame('uploaded', $forms[0]['source']);
    assertSame('gen.xlsx', $forms[0]['upload_filename']);
    assertSame(hash_file('sha256', $gen), $forms[0]['upload_sha256']);
    assertSame($f['year'], (int) $forms[0]['show_year_id']);
    assertSame('2027-03-01', $forms[0]['form_date'], 'the form\'s own date');
    assertSame($f['teams']['a'], (int) $forms[0]['team_id']);
    assertSame('Casey Captain, Captain', $forms[0]['submitter'], 'the submitter as printed, not the uploader');
    assertSame(2, (int) $forms[0]['row_count']);
    assertSame('dc.xls', $forms[1]['upload_filename']);
    assertSame('2026-02-10', $forms[1]['form_date']);
    assertSame('RU Bus Ops Team B', $forms[1]['subcommittee']);

    $rows = $pdo->query("SELECT * FROM rcf_row WHERE rcf_id = {$forms[0]['id']} ORDER BY position")->fetchAll();
    assertSame([1, 3], array_map(static fn (array $r): int => (int) $r['position'], $rows), 'at the positions on the form');
    assertSame($f['members']['m1']['id'], (int) $rows[0]['member_id']);
    assertSame('', $rows[0]['serial'], 'no number on the generated form');
    assertSame(null, $rows[0]['tracked_by']);
    assertSame(null, $rows[1]['member_id']);
    assertSame(1, (int) $rows[1]['rookie']);

    $numbered = $pdo->query("SELECT * FROM rcf_row WHERE rcf_id = {$forms[1]['id']} ORDER BY position")->fetchAll();
    assertSame(1, count($numbered));
    assertSame('DC-77', $numbered[0]['serial'], 'the Division Chairman\'s number, on every line');
    assertSame($f['users']['adm'], (int) $numbered[0]['tracked_by'], 'tracked by the keeper');
    assertTrue($numbered[0]['tracked_at'] !== null);
    assertSame($f['members']['m2']['id'], (int) $numbered[0]['member_id']);
    assertSame('Committee Member', $numbered[0]['previous_title'], 'the roster\'s title, filled in at stage time');
    assertSame(null, $numbered[0]['sent_to_dc_on'], 'the file does not say when it went where');

    // The batch and its files say what became of each.
    $batch = $pdo->query("SELECT * FROM rcf_upload_batch WHERE id = {$id}")->fetch();
    assertTrue($batch['applied_at'] !== null);
    assertSame(2, (int) $batch['files_kept']);
    assertSame(3, (int) $batch['lines_kept']);
    $files = $pdo->query("SELECT status, rcf_id FROM rcf_upload_file WHERE batch_id = {$id} ORDER BY position")->fetchAll();
    assertSame(['kept', 'kept'], array_column($files, 'status'));
    assertSame((int) $forms[0]['id'], (int) $files[0]['rcf_id']);

    // One audit row per form, naming the file.
    $audits = $pdo->query(
        "SELECT entity_id, after_json FROM audit_log WHERE actor_user_id = {$f['users']['adm']} AND action = 'upload_form' AND entity = 'rcf' ORDER BY id"
    )->fetchAll();
    assertSame(2, count($audits));
    assertSame((int) $forms[0]['id'], (int) $audits[0]['entity_id']);
    $after = json_decode((string) $audits[0]['after_json'], true);
    assertSame('gen.xlsx', $after['filename']);
    assertSame(2, $after['rows']);
    assertSame($id, $after['upload_batch']);
    assertSame('DC-77', json_decode((string) $audits[1]['after_json'], true)['serial']);

    // And the forms are on Track RCFs, saying where they came from.
    $tracking = new RcfTracking($pdo);
    $mine     = $tracking->mine(ru_user('adm'));
    assertSame(2, count($mine));
    assertSame('uploaded', $mine[0]['source']);
    assertSame('dc.xls', $mine[0]['upload_filename']);
    assertSame(1, $mine[0]['numbered']);
    assertSame(['DC-77'], $mine[0]['serials']);
    $one = $tracking->one(ru_user('adm'), (int) $forms[0]['id']);
    assertSame('uploaded', $one['form']['source']);
    assertSame(1, count($tracking->search(ru_user('adm'), 'Robert Alpha')['matches']), 'a member is found on an uploaded form');

    // Keeping it again is refused, and so is discarding it.
    assertThrows(static fn () => ru_upload()->keep(ru_user('adm'), $id, $fileIds), 'already kept');
    assertThrows(static fn () => ru_upload()->discard(ru_user('adm'), $id), 'it stays');
    assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM rcf WHERE generated_by = {$f['users']['adm']}")->fetchColumn());

    // The same file again is refused at the door: it is in the record.
    $again = ru_upload()->stage(ru_user('adm'), ru_files(['gen-from-tuesday.xlsx' => $gen]));
    $file  = $pdo->query("SELECT status, refusal FROM rcf_upload_file WHERE batch_id = {$again}")->fetch();
    assertSame('refused', $file['status']);
    assertTrue(str_contains((string) $file['refusal'], 'already kept as form #' . (int) $forms[0]['id']));
    ru_upload()->discard(ru_user('adm'), $again);

    // Somebody who may not open the batch may not keep it either.
    $other = ru_upload()->stage(ru_user('adm'), ru_files(['dc2.xls' => ru_hand_xls('dc2.xls', '9000001', 'DC-78')]));
    assertThrows(static fn () => ru_upload()->keep(ru_user('cap'), $other, []), 'no upload');
    ru_upload()->discard(ru_user('adm'), $other);
});

test('a file left unticked is left out, and a discarded upload leaves nothing behind', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    $a  = ru_generated_xlsx('a.xlsx', ru_form_for_fixture([0 => ['type' => 'S', 'member_name' => 'Frank Echo', 'member_number' => 'RU000003', 'new_subcommittee' => 'RU Bus Ops Team A']]));
    $b  = ru_generated_xlsx('b.xlsx', ru_form_for_fixture([0 => ['type' => 'T', 'member_name' => 'Carol Bravo', 'member_number' => '9000002', 'new_title' => 'Captain']], '2027-03-05'));
    $id = ru_upload()->stage(ru_user('adm'), ru_files(['a.xlsx' => $a, 'b.xlsx' => $b]));

    $files  = array_map('intval', array_column($pdo->query("SELECT id FROM rcf_upload_file WHERE batch_id = {$id} ORDER BY position")->fetchAll(), 'id'));
    $result = ru_upload()->keep(ru_user('adm'), $id, [$files[0], 999999, 'x']);
    assertSame(1, $result['kept']);
    assertSame(1, $result['left_out']);
    assertSame(['kept', 'left_out'], array_column($pdo->query("SELECT status FROM rcf_upload_file WHERE batch_id = {$id} ORDER BY position")->fetchAll(), 'status'));
    assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM rcf WHERE generated_by = {$f['users']['adm']}")->fetchColumn());

    // The one left out was never kept, so it reads as new next time.
    $second = ru_upload()->stage(ru_user('adm'), ru_files(['b.xlsx' => $b]));
    assertSame('ready', (string) $pdo->query("SELECT status FROM rcf_upload_file WHERE batch_id = {$second}")->fetchColumn());

    ru_upload()->discard(ru_user('adm'), $second);
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM rcf_upload_batch WHERE id = {$second}")->fetchColumn(), 'the batch is gone');
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM rcf_upload_file WHERE batch_id = {$second}")->fetchColumn(), 'and its files');
    assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM rcf WHERE generated_by = {$f['users']['adm']}")->fetchColumn(), 'and nothing else moved');

    // A batch nobody decided on is swept once it is old enough.
    $third = ru_upload()->stage(ru_user('adm'), ru_files(['b.xlsx' => $b]));
    $pdo->exec("UPDATE rcf_upload_batch SET started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) WHERE id = {$third}");
    assertSame(1, ru_upload()->discardExpired());
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM rcf_upload_batch WHERE id = {$third}")->fetchColumn());
    assertSame(0, ru_upload()->discardExpired(), 'a kept batch is never swept');
});

test('a form that looks like one already kept starts unticked and names it', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    // The Captain generated a form here on 1 April naming m1; the Division
    // Chairman's numbered copy of it arrives by email and is uploaded.
    $generated = (new RcfStore($pdo))->store(ru_user('cap'), $f['year'], ru_form_for_fixture([
        0 => ['type' => 'T', 'member_name' => 'Robert Alpha', 'member_number' => '9000001', 'new_title' => 'Assistant Captain', 'previous_title' => 'Committee Member'],
    ], '2027-04-01'));
    assertSame('generated', (string) $pdo->query("SELECT source FROM rcf WHERE id = {$generated}")->fetchColumn(), 'a generated form says so');

    $cells = ru_template_cells() + [
        'K1' => ['s', '2027-20'], 'G4' => ['s', 'Casey Captain, Captain'], 'D5' => ['s', '4/1/2027'],
        'G5' => ['s', 'RU Logistics Division - RU Bus Ops Team A'],
        'A27' => ['s', '1)'], 'B27' => ['s', 'T'], 'D27' => ['s', 'Robert Alpha'], 'E27' => ['s', '9000001'],
        'F27' => ['s', 'Assistant Captain'], 'G27' => ['s', 'Committee Member'],
    ];
    $id   = ru_upload()->stage(ru_user('adm'), ru_files(['numbered-copy.xlsx' => ru_xlsx('numbered-copy.xlsx', ['Sheet1' => $cells])]));
    $file = $pdo->query("SELECT * FROM rcf_upload_file WHERE batch_id = {$id}")->fetch();
    assertSame('ready', $file['status'], 'it is still a readable form');
    assertSame(0, (int) $file['keep_by_default'], 'but it starts unticked');
    assertSame($generated, (int) $file['like_rcf_id'], 'and names the form it looks like');
    $kinds = array_column(json_decode((string) $file['warnings_json'], true), 'kind');
    assertTrue(in_array(RcfUpload::LIKELY_DUPLICATE, $kinds, true));

    $preview = ru_upload()->preview(ru_user('adm'), $id);
    assertSame(false, $preview['files'][0]['keep_by_default']);
    ru_upload()->discard(ru_user('adm'), $id);
});

// ---------------------------------------------------------------------------
// The derived step, from the form's own date
// ---------------------------------------------------------------------------

/** An applied import that changed one thing about one member number. */
function ru_import(string $number, string $kind, string $field, string $occurredAt, ?string $before = null, ?string $after = null): int
{
    $f   = ru_fixture();
    $pdo = ru_pdo();

    $pdo->prepare(
        'INSERT INTO import_batch (show_year_id, mode, filename, sha256, dry_run, applied_at)'
        . " VALUES (:year, 'update', :file, :sha, 0, :at)"
    )->execute([':year' => $f['year'], ':file' => 'RU-' . uniqid() . '.xls', ':sha' => str_repeat('0', 64), ':at' => $occurredAt]);
    $batch = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO import_change (import_batch_id, member_id, member_number, kind, field, before_value, after_value, occurred_at)'
        . ' VALUES (:batch, NULL, :number, :kind, :field, :before, :after, :at)'
    )->execute([':batch' => $batch, ':number' => $number, ':kind' => $kind, ':field' => $field, ':before' => $before, ':after' => $after, ':at' => $occurredAt]);

    return $batch;
}

test('in the roster is measured from an uploaded form\'s own date, so an import before the upload counts', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    // The Division Chairman's form is dated 10 February 2026 and uploaded
    // today; Rodeo Houston dropped the member on 1 March 2026.
    $id = ru_upload()->stage(ru_user('adm'), ru_files(['dc.xls' => ru_hand_xls('dc.xls')]));
    $files = array_map('intval', array_column($pdo->query("SELECT id FROM rcf_upload_file WHERE batch_id = {$id}")->fetchAll(), 'id'));
    $rcfId = ru_upload()->keep(ru_user('adm'), $id, $files)['forms'][$files[0]];

    $tracking = new RcfTracking($pdo);
    assertSame(null, $tracking->one(ru_user('adm'), $rcfId)['rows'][0]['landed']);

    ru_import('9000002', 'dropped', '', '2026-01-15 15:00:00');
    assertSame(null, $tracking->one(ru_user('adm'), $rcfId)['rows'][0]['landed'], 'an import BEFORE the form\'s date is not its news');

    $batch  = ru_import('9000002', 'dropped', '', '2026-03-01 15:00:00');
    $landed = $tracking->one(ru_user('adm'), $rcfId)['rows'][0]['landed'];
    assertTrue($landed !== null, 'an import after the form\'s date and before the upload counts');
    assertSame('2026-03-01 15:00:00', $landed['at']);
    assertSame($batch, $landed['batch']);
    assertSame('dropped', $landed['kind']);
    assertSame(1, $tracking->one(ru_user('adm'), $rcfId)['form']['landed']);
});

// ---------------------------------------------------------------------------
// The change table
// ---------------------------------------------------------------------------

test('the change table lists every line the caller may see, open first, with was, becomes and the fulfilling import', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    // Two uploaded forms and one generated by the Captain, over three members.
    $gen = ru_generated_xlsx('gen.xlsx', ru_form_for_fixture([
        0 => ['type' => 'T', 'member_name' => 'Robert Alpha', 'member_number' => '9000001', 'new_title' => 'Assistant Captain', 'previous_title' => 'Committee Member'],
        2 => ['type' => 'A', 'member_name' => 'Newcomer Sample', 'rookie' => '1', 'sponsor' => 'Erin Delta'],
    ]));
    $id    = ru_upload()->stage(ru_user('adm'), ru_files(['gen.xlsx' => $gen, 'dc.xls' => ru_hand_xls('dc.xls')]));
    $files = array_map('intval', array_column($pdo->query("SELECT id FROM rcf_upload_file WHERE batch_id = {$id} ORDER BY position")->fetchAll(), 'id'));
    ru_upload()->keep(ru_user('adm'), $id, $files);
    $capForm = (new RcfStore($pdo))->store(ru_user('cap'), $f['year'], ru_form_for_fixture([
        0 => ['type' => 'S', 'member_name' => 'Frank Echo', 'member_number' => 'RU000003', 'new_subcommittee' => 'RU Bus Ops Team A'],
    ], '2027-05-01'));

    // m2's removal was fulfilled; nothing else was.
    ru_import('9000002', 'dropped', '', '2026-03-01 15:00:00');

    $tracking = new RcfTracking($pdo);

    $open = $tracking->changes(ru_user('adm'), 'open');
    assertSame('open', $open['show']);
    assertSame(['open' => 3, 'done' => 1, 'all' => 4], $open['counts']);
    assertSame(3, $open['total']);
    assertSame(1, $open['pages']);
    $names = array_map(static fn (array $l): string => $l['member_name'], $open['lines']);
    assertSame(['Frank Echo', 'Robert Alpha', 'Newcomer Sample'], $names, 'newest form first, then by position');

    $frank = $open['lines'][0];
    assertSame('generated', $frank['source']);
    assertSame($capForm, $frank['rcf_id']);
    assertSame('2027-05-01', $frank['form_date']);
    assertSame('RU Bus Ops Team A', $frank['was']);
    assertSame('RU Bus Ops Team A', $frank['becomes']);
    assertSame(null, $frank['landed']);

    $robert = $open['lines'][1];
    assertSame('uploaded', $robert['source']);
    assertSame('gen.xlsx', $robert['upload_filename']);
    assertSame('Committee Member', $robert['was']);
    assertSame('Assistant Captain', $robert['becomes']);
    assertSame($f['members']['m1']['id'], $robert['member_id']);

    $newcomer = $open['lines'][2];
    assertSame('Not on the roster', $newcomer['was']);
    assertSame('Added to RU Bus Ops Team A', $newcomer['becomes']);
    assertSame('', $newcomer['member_number']);

    $done = $tracking->changes(ru_user('adm'), 'done');
    assertSame(1, count($done['lines']));
    assertSame('Carol Bravo', $done['lines'][0]['member_name']);
    assertSame('On the roster as Committee Member, RU Bus Ops Team B', $done['lines'][0]['was']);
    assertSame('Removed — Member Resigned', $done['lines'][0]['becomes']);
    assertSame('DC-77', $done['lines'][0]['serial']);
    assertSame('dropped', $done['lines'][0]['landed']['kind']);
    assertSame('2026-03-01 15:00:00', $done['lines'][0]['landed']['at']);

    assertSame(4, count($tracking->changes(ru_user('adm'), 'all')['lines']));
    assertSame('open', $tracking->changes(ru_user('adm'), 'nonsense')['show'], 'an unknown choice is the default');

    // The Captain sees only the line on their own form; the Division
    // Chairman sees everything.
    $cap = $tracking->changes(ru_user('cap'), 'all');
    assertSame(['open' => 1, 'done' => 0, 'all' => 1], $cap['counts']);
    assertSame('Frank Echo', $cap['lines'][0]['member_name']);
    assertSame(4, $tracking->changes(ru_user('dc'), 'all')['total']);

    // Paging, at a page size of two.
    $paged = (new RcfTracking($pdo, new DateTimeZone('UTC'), 2))->changes(ru_user('adm'), 'all', 2);
    assertSame(2, $paged['pages']);
    assertSame(2, $paged['page']);
    assertSame(2, count($paged['lines']));
    assertSame(['Robert Alpha', 'Newcomer Sample'], array_map(static fn (array $l): string => $l['member_name'], $paged['lines']),
        'Frank and Carol were page one');
});

// ---------------------------------------------------------------------------
// The screens
// ---------------------------------------------------------------------------

function ru_render(string $view, string $title, array $data): string
{
    $app = ru_app();
    $_SESSION ??= [];
    $wide    = true;
    $notices = [];
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

test('the upload screen renders the form, a preview with a tick box per readable file, and what a kept batch became', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    $limits = ['max_files' => 20, 'file_size' => '2M', 'post_size' => '8M'];
    $upload = ru_upload();

    $blank = ru_render('rcf-upload', 'Upload RCFs', [
        'user' => ru_user('adm'), 'preview' => null, 'recent' => $upload->recent(ru_user('adm')), 'limits' => $limits,
    ]);
    assertTrue(str_contains($blank, '<h1>Upload RCFs</h1>'));
    assertTrue(str_contains($blank, 'name="forms[]"'), 'the file control');
    assertTrue(str_contains($blank, 'Up to 20 files at a time, 2M each'), 'the host\'s ceilings, printed');
    assertSame(0, substr_count($blank, 'name="keep['));

    $gen = ru_generated_xlsx('gen.xlsx', ru_form_for_fixture([
        0 => ['type' => 'T', 'member_name' => 'Robert <Alpha>', 'member_number' => '9000001', 'new_title' => 'Assistant Captain', 'previous_title' => 'Committee Member'],
    ]));
    $id = $upload->stage(ru_user('adm'), ru_files(['gen <evil>.xlsx' => $gen, 'roster.xls' => ru_roster_xls('roster.xls')]));

    $staged = ru_render('rcf-upload', 'Upload RCFs', [
        'user' => ru_user('adm'), 'preview' => $upload->preview(ru_user('adm'), $id), 'recent' => $upload->recent(ru_user('adm')), 'limits' => $limits,
    ]);
    assertTrue(str_contains($staged, 'Nothing kept yet'));
    assertSame(1, substr_count($staged, 'name="keep['), 'one tick box, for the one readable file');
    assertTrue(str_contains($staged, ' checked>'), 'ticked by default');
    assertSame(1, substr_count($staged, 'value="keep"'), 'one Keep form');
    assertSame(1, substr_count($staged, 'value="discard"'));
    assertTrue(str_contains($staged, 'Set aside'), 'the roster export is set aside');
    assertTrue(str_contains($staged, 'not a Roster Change Form'), 'and the sentence says why');
    assertTrue(str_contains($staged, 'gen &lt;evil&gt;.xlsx'), 'the file name is escaped');
    assertTrue(str_contains($staged, 'Robert &lt;Alpha&gt;'), 'and so is a name');
    assertSame(0, substr_count($staged, '<evil>'));
    assertSame(0, substr_count($staged, '<Alpha>'));
    assertSame(0, substr_count($staged, '<script'), 'no JavaScript');
    assertTrue(strlen($staged) < 100 * 1024, 'first paint is ' . strlen($staged) . ' bytes');

    $files = array_map('intval', array_column($pdo->query("SELECT id FROM rcf_upload_file WHERE batch_id = {$id} AND status = 'ready'")->fetchAll(), 'id'));
    $rcfId = $upload->keep(ru_user('adm'), $id, $files)['forms'][$files[0]];

    $kept = ru_render('rcf-upload', 'Upload RCFs', [
        'user' => ru_user('adm'), 'preview' => $upload->preview(ru_user('adm'), $id), 'recent' => $upload->recent(ru_user('adm')), 'limits' => $limits,
    ]);
    assertTrue(str_contains($kept, 'rcf?id=' . $rcfId), 'the kept file links to its form');
    assertSame(0, substr_count($kept, 'name="keep['), 'no tick boxes once kept');
    assertSame(0, substr_count($kept, 'value="discard"'));
    assertTrue(str_contains($kept, 'Upload more'));
});

test('Track RCFs renders the upload card for an Admin, the change table for everyone, and says where a form came from', function (): void {
    $f   = ru_fixture();
    $pdo = ru_pdo();
    ru_reset();

    $id    = ru_upload()->stage(ru_user('adm'), ru_files(['dc.xls' => ru_hand_xls('dc.xls')]));
    $files = array_map('intval', array_column($pdo->query("SELECT id FROM rcf_upload_file WHERE batch_id = {$id}")->fetchAll(), 'id'));
    $rcfId = ru_upload()->keep(ru_user('adm'), $id, $files)['forms'][$files[0]];
    ru_import('9000002', 'dropped', '', '2026-03-01 15:00:00');

    $tracking = new RcfTracking($pdo);
    $limits   = ['max_files' => 20, 'file_size' => '2M', 'post_size' => '8M'];

    $admin = ru_render('rcfs', 'Track RCFs', [
        'user'     => ru_user('adm'),
        'tracking' => [
            'mine'    => $tracking->mine(ru_user('adm')),
            'others'  => $tracking->others(ru_user('adm')),
            'search'  => $tracking->search(ru_user('adm'), ''),
            'changes' => $tracking->changes(ru_user('adm'), 'all'),
            'upload'  => $limits,
        ],
    ]);
    assertTrue(str_contains($admin, 'name="forms[]"'), 'the upload card');
    assertTrue(str_contains($admin, 'Every change requested'));
    assertTrue(str_contains($admin, 'Fulfilled by HLSR'));
    assertTrue(str_contains($admin, 'Carol Bravo'));
    assertTrue(str_contains($admin, 'Removed — Member Resigned'));
    assertTrue(str_contains($admin, 'dropped from the roster'), 'what the import recorded');
    assertTrue(str_contains($admin, 'import-history?batch='), 'an Admin may open the import');
    assertTrue(str_contains($admin, 'from=rcfs'), 'a member on a line links to their card, with the way back');
    assertTrue(str_contains($admin, 'dc.xls'), 'the file a form came from');
    assertTrue(str_contains($admin, 'rcf?id=' . $rcfId));
    assertSame(0, substr_count($admin, '<script'));
    assertTrue(strlen($admin) < 100 * 1024, 'first paint is ' . strlen($admin) . ' bytes');

    $officer = ru_render('rcfs', 'Track RCFs', [
        'user'     => ru_user('cap'),
        'tracking' => [
            'mine'    => $tracking->mine(ru_user('cap')),
            'others'  => null,
            'search'  => $tracking->search(ru_user('cap'), ''),
            'changes' => $tracking->changes(ru_user('cap'), 'open'),
            'upload'  => null,
        ],
    ]);
    assertSame(0, substr_count($officer, 'name="forms[]"'), 'no upload card without the capability');
    assertTrue(str_contains($officer, 'Every change requested'), 'but the table, over their own forms');
    assertSame(0, substr_count($officer, 'Carol Bravo'), 'which does not include the Admin\'s upload');

    $one = ru_render('rcf', 'Roster Change Form', ['user' => ru_user('adm'), 'rcf' => $tracking->one(ru_user('adm'), $rcfId)]);
    assertTrue(str_contains($one, 'Uploaded'), 'the form\'s page says it was uploaded');
    assertTrue(str_contains($one, 'dc.xls'));
    assertTrue(str_contains($one, 'read out of the uploaded file'), 'and that Download again is a rendering, not the file');
    assertTrue(str_contains($one, 'DC-77'), 'with its number on the line');
});

test('rcf upload fixtures are cleaned up', function (): void {
    $pdo = ru_pdo();
    ru_fixture();
    ru_teardown($pdo);

    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'RU%'")->fetchColumn());
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM import_change WHERE member_number IN ('9000001', '9000002')")->fetchColumn());
});
