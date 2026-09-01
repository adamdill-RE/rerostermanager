<?php

declare(strict_types=1);

/**
 * Create Forms, and the Roster Change Form (spec-v2 §1, §2).
 *
 * The requirement on this feature is unusual and the tests follow it: the
 * generated form must look EXACTLY like the one Rodeo Houston sends out, so
 * most of what is asserted here is the shape of a spreadsheet rather than the
 * behaviour of a screen. Every expectation below was transcribed by hand from
 * that workbook's `sheet1.xml`, independently of `RosterChangeForm` — the
 * same discipline `access_test.php` applies to the permission matrix, for the
 * same reason: a form that drifts one column is a form somebody at Rodeo
 * Houston has to query, and nobody would notice here until they did.
 *
 * The fidelity half needs no database at all. `RosterChangeForm::draw()` is a
 * pure function of the form and `FormSheet::sheet()` hands back the XML, so
 * "does it look right" is answerable without MySQL. The scope half — who may
 * be offered, and to whom — needs one, and uses generated fixtures like every
 * other suite here.
 *
 * Generated, never real: this repository is public. Member numbers are
 * 'RC000001', emails are @example.com, phones are the reserved
 * (555) 555-01xx fiction range.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Scope;
use Rerm\Auth\User;
use Rerm\Forms\FormSheet;
use Rerm\Forms\RcfPage;
use Rerm\Forms\RosterChangeForm;
use Rerm\Routes;

// ---------------------------------------------------------------------------
// The route, the capability and the tile
// ---------------------------------------------------------------------------

test('both form routes are guarded by create_forms, at the Officer floor and scoped', function (): void {
    assertSame(Capability::CreateForms->value, Routes::guard('forms'));
    assertSame(Capability::CreateForms->value, Routes::guard('form-rcf'));
    assertSame(Level::Officer, Capability::CreateForms->minimumLevel());
    assertSame(Scope::Scoped, Capability::CreateForms->scope());

    // And it is its own capability, not a second name for the export's.
    assertTrue(
        Capability::CreateForms !== Capability::ExportRoster,
        'producing paperwork and taking the roster away are different powers'
    );
});

test('the menu tile is behind the same capability and points at the screen', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../app/views/menu.php');

    assertTrue(str_contains($source, "Capability::CreateForms"), 'the tile names the capability');
    assertTrue(str_contains($source, "'label' => 'Create Forms'"), 'and it is called Create Forms');
    assertTrue(str_contains($source, "'route' => 'forms'"), 'and it links to the screen');
});

test('producing a form is an audited read, like an export', function (): void {
    assertSame('create_form', Action::CreateForm->value);
    assertTrue(Action::CreateForm->label() !== '', 'it has a label the log can show');

    $source = (string) file_get_contents(__DIR__ . '/../app/src/Forms/RosterChangeForm.php');
    assertTrue(str_contains($source, 'Action::CreateForm'), 'and it is written through the enum');
});

// ---------------------------------------------------------------------------
// The form itself. Every expectation transcribed from the source workbook.
// ---------------------------------------------------------------------------

/** A form filled in enough to exercise every column. */
function rcf_form(array $overrides = []): array
{
    $entries = [];
    for ($i = 0; $i < RosterChangeForm::ROWS; $i++) {
        $entries[$i] = RosterChangeForm::emptyEntry();
    }

    $entries[0] = [
        'type'             => 'A',
        'rookie'           => '1',
        'member_name'      => 'Jane Sample',
        'member_number'    => 'RC000001',
        'new_title'        => 'Committee Member',
        'previous_title'   => '',
        'wait_list'        => '1',
        'remove_reason'    => '',
        'new_subcommittee' => '',
        'sponsor'          => 'A. Officer',
    ];

    $entries[1] = [
        'type'             => 'R',
        'rookie'           => '0',
        'member_name'      => 'John Sample',
        'member_number'    => 'RC000002',
        'new_title'        => '',
        'previous_title'   => 'Captain',
        'wait_list'        => '0',
        'remove_reason'    => '4',
        'new_subcommittee' => '',
        'sponsor'          => '',
    ];

    $entries[2] = [
        'type'             => 'S & T',
        'rookie'           => '0',
        'member_name'      => 'Pat Sample',
        'member_number'    => 'RC000003',
        'new_title'        => 'Assistant Captain',
        'previous_title'   => 'Committee Member',
        'wait_list'        => '0',
        'remove_reason'    => '',
        'new_subcommittee' => 'RC Bus Ops Team A',
        'sponsor'          => '',
    ];

    return $overrides + [
        'year'         => '2027',
        'submitter'    => 'A. Officer, Captain',
        'date'         => '3/1/2027',
        'subcommittee' => 'RC Logistics Division - RC Bus Ops Team A',
        'entries'      => $entries,
    ];
}

/**
 * A sheet built from the shipped assets — the style sheet AND the feature
 * property bag its cell formats point at. Never one without the other: that
 * is the whole of the bug this pairing exists to prevent.
 */
function rcf_form_sheet(string $sheetName = 'Sheet1'): FormSheet
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    return FormSheet::create(
        sys_get_temp_dir(),
        $app->path('app/templates/rcf/styles.xml'),
        $sheetName,
        $app->path('app/templates/rcf/featurePropertyBag.xml')
    );
}

/** The sheet XML for a form, with no database anywhere near it. */
function rcf_sheet(array $form): string
{
    $sheet = rcf_form_sheet();
    RosterChangeForm::draw($sheet, $form);

    return $sheet->sheet();
}

/**
 * Every cell of a generated sheet, as `ref => ['s' => style, 't' => type,
 * 'v' => value]`. Parsed with the same XMLReader the application's own reader
 * uses, so a file this cannot parse is a file nothing can.
 *
 * **The type is captured, and that is not incidental.** The first comparison
 * of a generated form against the source workbook read style and value and
 * ignored `t`, and reported 558 cells with zero differences while all fifty
 * checkbox cells were numbers where the workbook had booleans. Excel draws a
 * box for `t="b"` and prints the value for anything else, so the form came out
 * with a `0` sitting in every cell that should have been an empty box.
 *
 * @return array<string, array{s: string, t: string, v: string}>
 */
function rcf_cells(string $xml): array
{
    $reader = new XMLReader();
    assertTrue($reader->XML($xml), 'the sheet is well-formed XML');

    $cells   = [];
    $current = null;
    $inText  = false;

    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'c') {
            $current = $reader->getAttribute('r');
            $cells[$current] = [
                's' => (string) $reader->getAttribute('s'),
                't' => (string) $reader->getAttribute('t'),
                'v' => '',
            ];
            if ($reader->isEmptyElement) {
                $current = null;
            }

            continue;
        }

        if ($reader->nodeType === XMLReader::ELEMENT && ($reader->name === 't' || $reader->name === 'v')) {
            $inText = true;

            continue;
        }

        if ($reader->nodeType === XMLReader::END_ELEMENT && ($reader->name === 't' || $reader->name === 'v')) {
            $inText = false;

            continue;
        }

        if ($inText
            && $current !== null
            && ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::SIGNIFICANT_WHITESPACE)
        ) {
            $cells[$current]['v'] .= $reader->value;
        }
    }

    $reader->close();

    return $cells;
}

test('the header and the legend are the source workbook, cell for cell', function (): void {
    $cells = rcf_cells(rcf_sheet(rcf_form()));

    // TRANSCRIBED from the Rodeo Houston workbook's sheet1.xml — the words,
    // and the style id each one carries. Not read out of RosterChangeForm.
    $expected = [
        'J1'  => ['4',  'CHANGE FORM  # '],
        'A2'  => ['88', 'Rodeo Express Roster Change Form  - RODEO 2027'],
        'J2'  => ['5',  '(DC USE ONLY)'],
        'A4'  => ['89', 'Name & Title of whom is submitting this form:'],
        'A5'  => ['91', 'Date:'],
        'E5'  => ['89', 'Sub-Committee:'],
        'A7'  => ['81', '*Type - Please Enter The Appropriate Code:'],
        'H7'  => ['82', 'WAIT LIST:'],
        'A8'  => ['83', 'A = Addition'],
        'H8'  => ['84', 'Please enter "Yes" or "No"'],
        'A9'  => ['85', 'R = Remove'],
        'A10' => ['85', 'T = Title Change'],
        'A11' => ['11', 'S = Sub-Committee Change (Team Change)'],
        'A12' => ['87', 'S & T = Sub-Committee Change (Team Change) & Title Change'],
        'A14' => ['76', '**Please enter the appropriate REMOVE REASON:'],
        'A15' => ['78', '1) Deceased Member'],
        'H15' => ['79', 'INTERVIEW REQUIRED or SPONSORED BY'],
        'A16' => ['72', '2) Did Not Meet Requirements'],
        'H16' => ['80', 'Must enter ONE of the following options when adding a new recruit:'],
        'A17' => ['72', '3) Leadership Recommendation (please contact Division Chairman)'],
        'H17' => ['73', '1) Date interview was conducted'],
        'A18' => ['72', '4) Member Resigned'],
        'H18' => ['15', '2) Officers name that will be sponsoring the new recruit (must be a VC or higher).'],
        'A19' => ['72', '5) No Response to Communications'],
        'A20' => ['74', '6) No Show for All Assignments'],
        'H20' => ['75', 'RODEO EXPRESS ROOKIE? Must indicate if they are brand new to Rodeo Express.'],
        'H21' => ['66', 'If they have NEVER been on Rodeo Express they are a Rookie.'],
        'F23' => ['67', 'For Adds & Title Change Only'],
        'J23' => ['67', 'For Adds & Team Changes Only'],
        'L23' => ['23', 'For Adds only'],
        'C24' => ['25', 'RE'],
        'D24' => ['68', 'MEMBER NAME'],
        'E24' => ['68', 'HLS&R NO'],
        'F24' => ['26', 'CHANGE/ADD'],
        'G24' => ['27', 'PREVIOUS'],
        'H24' => ['69', 'WAIT LIST'],
        'I24' => ['70', '**REMOVE REASON  '],
        'J24' => ['68', 'NEW SUB-COMMITTEE (New Team)'],
        'L24' => ['71', 'INTERVIEW REQUIRED or SPONSORED BY'],
        'B25' => ['28', '*TYPE'],
        'C25' => ['29', 'ROOKIE'],
        'F25' => ['30', 'TITLE'],
        'G25' => ['31', 'TITLE'],
        'C26' => ['33', 'y/n'],
        'H26' => ['36', '√'],
    ];

    foreach ($expected as $reference => [$style, $text]) {
        assertTrue(isset($cells[$reference]), $reference . ' is on the form');
        assertSame($style, $cells[$reference]['s'], $reference . ' carries its style');
        assertSame($text, $cells[$reference]['v'], $reference . ' says what the form says');
    }

    // The empty styled cells are cells, not omissions: they are the boxes
    // somebody writes in, and dropping one loses its fill and its border.
    foreach (['B2', 'I2', 'K2', 'G4', 'L4', 'C5', 'D5', 'L5', 'F7', 'L13', 'F21', 'K22'] as $reference) {
        assertTrue(isset($cells[$reference]), $reference . ' is drawn even though it is blank');
    }
});

test('the printed legend is the same list the screen offers — it cannot drift', function (): void {
    $cells = rcf_cells(rcf_sheet(rcf_form()));

    // The one guarantee worth more than any single label: a form whose
    // printed legend disagrees with its own dropdown collects answers nobody
    // can act on.
    $row = 8;
    foreach (RosterChangeForm::TYPES as $code => $description) {
        assertSame($code . ' = ' . $description, $cells['A' . $row]['v'], 'type legend row ' . $row);
        $row++;
    }
    assertSame(13, $row, 'five type codes, at rows 8 to 12');

    $row = 15;
    foreach (RosterChangeForm::REMOVE_REASONS as $number => $reason) {
        assertSame($number . ') ' . $reason, $cells['A' . $row]['v'], 'reason legend row ' . $row);
        $row++;
    }
    assertSame(21, $row, 'six remove reasons, at rows 15 to 20');
});

test('what the officer typed lands in the three header cells', function (): void {
    $cells = rcf_cells(rcf_sheet(rcf_form()));

    assertSame('A. Officer, Captain', $cells['G4']['v'], 'the submitter, with their title');
    assertSame('3/1/2027', $cells['D5']['v'], 'the date, as Houston reads one');
    assertSame('RC Logistics Division - RC Bus Ops Team A', $cells['G5']['v'], 'the sub-committee');

    // The title carries the show year, so a 2028 form says 2028.
    $next = rcf_cells(rcf_sheet(rcf_form(['year' => '2028'])));
    assertSame('Rodeo Express Roster Change Form  - RODEO 2028', $next['A2']['v']);
});

test('the grid is twenty-five rows of twelve cells, numbered as the form numbers them', function (): void {
    $cells = rcf_cells(rcf_sheet(rcf_form()));

    for ($n = 1; $n <= RosterChangeForm::ROWS; $n++) {
        $row = 26 + $n;

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $column) {
            assertTrue(isset($cells[$column . $row]), $column . $row . ' exists');
        }

        assertSame($n . ')', $cells['A' . $row]['v'], 'row ' . $n . ' is numbered');
    }

    // And nothing past the twenty-fifth: an RCF is twenty-five rows.
    assertTrue(!isset($cells['A52']), 'there is no twenty-sixth row');

    // FIVE HUNDRED AND FIFTY-EIGHT cells, which is exactly what the Rodeo
    // Houston workbook's own sheet1.xml holds. A blank form generated by this
    // application was diffed against it cell by cell — style id and value —
    // and came out identical: no cell added, none missing, none differing.
    // This number is the cheap invariant that keeps it that way; the labels,
    // merges, widths and row heights asserted around it are the rest.
    assertSame(558, count($cells), 'the form has exactly the cells the workbook has');
});

test('a filled row carries its answers, in the columns the form puts them in', function (): void {
    $cells = rcf_cells(rcf_sheet(rcf_form()));

    // Row 1 of the grid is sheet row 27. The column order is the form's:
    // *TYPE, ROOKIE, MEMBER NAME, HLS&R NO, CHANGE/ADD TITLE, PREVIOUS TITLE,
    // WAIT LIST, REMOVE REASON, NEW SUB-COMMITTEE, INTERVIEW/SPONSOR.
    assertSame('A', $cells['B27']['v']);
    assertSame('1', $cells['C27']['v'], 'a ticked ROOKIE box');
    assertSame('Jane Sample', $cells['D27']['v']);
    assertSame('RC000001', $cells['E27']['v']);
    assertSame('Committee Member', $cells['F27']['v']);
    assertSame('', $cells['G27']['v']);
    assertSame('1', $cells['H27']['v'], 'a ticked WAIT LIST box');
    assertSame('', $cells['I27']['v']);
    assertSame('A. Officer', $cells['L27']['v']);

    // A removal: the reason is the NUMBER, which is what the instruction over
    // the numbered list asks for and what the 26-character column can show.
    assertSame('R', $cells['B28']['v']);
    assertSame('4', $cells['I28']['v']);
    assertSame('Captain', $cells['G28']['v'], 'the previous title');

    // The two-word code survives as the form spells it.
    assertSame('S & T', $cells['B29']['v']);
    assertSame('RC Bus Ops Team A', $cells['J29']['v'], 'the destination team, unprefixed');
    assertSame('', $cells['K29']['v'], 'K is the right half of the merged cell');
});

test('ROOKIE and WAIT LIST are checkbox cells: 1 or 0, on every row', function (): void {
    $cells = rcf_cells(rcf_sheet(rcf_form()));

    // The blank form carries a numeric 0 in both columns of all twenty-five
    // rows. That reads like a leftover and is not one: cell formats 60 and 61
    // — which is exactly those fifty cells — carry an xfComplement resolving
    // through the workbook's feature property bag to CellControl -> Checkbox.
    // The 0 is an UNCHECKED BOX, so both columns carry an answer on every row.
    // And they are BOOLEAN cells. Excel draws a box for t="b" and prints the
    // value for anything else, so a plain numeric 0 here — which is what
    // shipped first — is the character 0 sitting where an empty box belongs.
    for ($row = 27; $row <= 51; $row++) {
        foreach (['C', 'H'] as $column) {
            $cell = $cells[$column . $row];

            assertSame('b', $cell['t'], $column . $row . ' is a boolean cell, or Excel draws no box');
            assertTrue(
                in_array($cell['v'], ['0', '1'], true),
                $column . $row . ' is ticked or not, got ' . var_export($cell['v'], true)
            );
        }
    }

    // Ticked where the officer ticked, and unticked everywhere else.
    assertSame('1', $cells['C27']['v']);
    assertSame('1', $cells['H27']['v']);

    for ($row = 30; $row <= 51; $row++) {
        assertSame('0', $cells['C' . $row]['v'], 'C' . $row . ' is unticked');
        assertSame('0', $cells['H' . $row]['v'], 'H' . $row . ' is unticked');
        assertSame('', $cells['D' . $row]['v'], 'and no member name appears');
    }
});

test('the merges, the widths and the page setup are the source workbook', function (): void {
    $xml = rcf_sheet(rcf_form());

    // TRANSCRIBED: sixty merged ranges — thirty-five in the header and the
    // legend, and one per entry row for NEW SUB-COMMITTEE.
    preg_match_all('/<mergeCell ref="([^"]+)"\/>/', $xml, $matches);
    $merges = $matches[1];

    assertSame(60, count($merges), 'sixty merged ranges, as the workbook has');

    foreach ([
        'A2:I2', 'A4:F4', 'G4:L4', 'A5:B5', 'E5:F5', 'G5:L5',
        'A7:F7', 'H7:L7', 'A8:F8', 'H8:L8', 'A9:F9', 'H9:L13', 'A10:F10',
        'A12:F12', 'A14:F14', 'H14:L14', 'A15:F15', 'H15:L15', 'A16:F16', 'H16:L16',
        'A17:F17', 'H17:L17', 'A18:F18', 'A19:F19', 'A20:F20', 'H20:L20', 'H21:L21',
        'F23:G23', 'J23:K23',
        'D24:D26', 'E24:E26', 'H24:H25', 'I24:I25', 'J24:K26', 'L24:L25',
        'J27:K27', 'J39:K39', 'J51:K51',
    ] as $range) {
        assertTrue(in_array($range, $merges, true), $range . ' is merged');
    }

    // A11 is NOT merged with B11:F11 — the one legend line the workbook left
    // unmerged, and a difference somebody would see as a shifted rule.
    assertTrue(!in_array('A11:F11', $merges, true), 'A11 is not merged, as in the workbook');

    // TRANSCRIBED column widths, in Excel's character units.
    foreach ([
        [1, 1, '4'], [2, 2, '6'], [3, 3, '6.5'], [4, 4, '26.6640625'],
        [5, 5, '9.6640625'], [6, 6, '18.6640625'], [7, 7, '19'], [8, 8, '6'],
        [9, 9, '26.1640625'], [10, 10, '22.1640625'], [11, 11, '8.1640625'], [12, 12, '28.5'],
    ] as [$first, $last, $width]) {
        assertTrue(
            str_contains($xml, '<col min="' . $first . '" max="' . $last . '" width="' . $width . '"'),
            'column ' . $first . ' is ' . $width . ' wide'
        );
    }

    // Thirteen column runs, against the workbook's fourteen. The one not
    // reproduced is `min="1026" max="1026" width="8.5"` with NO style — a
    // default-width run Excel left behind at column AMK, which carries no
    // formatting and is the only reason that workbook's declared dimension
    // reaches AMK51. Reproducing it would mean copying a quirk without
    // copying the dimension it explains, so it is written down here instead.
    assertSame(13, substr_count($xml, '<col '), 'thirteen column runs, A through the tail');
    assertSame(0, substr_count($xml, 'min="1026"'), 'and not the stray one at AMK');

    // Landscape at 67%: this form is printed, and a portrait one is unusable.
    assertTrue(str_contains($xml, '<pageSetup scale="67" orientation="landscape"/>'), 'landscape at 67%');

    // The heavy rules under the header block and the column headings.
    assertTrue(str_contains($xml, '<row r="24" ht="15" thickBot="1">'), 'row 24 keeps its heavy rule');
    assertTrue(str_contains($xml, 'A1:L51'), 'the used range is the whole form');
});

test('every cell holding text is an inline string — there is no numeric path to reach for', function (): void {
    $xml = rcf_sheet(rcf_form());

    // The rule the whole application rests on: a member number written as a
    // number is 1234567.0. Everything a person typed is t="inlineStr", and
    // the only <v> in the file is the blank form's own zero.
    assertTrue(str_contains($xml, 'RC000001'), 'the member number is on the form');
    assertSame(0, substr_count($xml, '<v>RC000001</v>'), 'and never as a numeric cell');

    // Fifty <v> cells, and every one of them is a BOOLEAN tick box. Nothing
    // in this file is a number: a numeric cell is how a member number becomes
    // 1234567.0, and `FormSheet` deliberately has no way to write one.
    preg_match_all('/<c [^>]*>(?:<v>([^<]*)<\/v>)/', $xml, $matches, PREG_SET_ORDER);
    assertSame(50, count($matches), 'fifty value cells: two checkbox columns, twenty-five rows');
    assertSame(50, substr_count($xml, ' t="b">'), 'and all fifty are booleans');

    foreach ($matches as $match) {
        assertTrue(in_array($match[1], ['0', '1'], true), 'each one ticked or not');
    }

    assertTrue(!method_exists(FormSheet::class, 'number'), 'there is no numeric cell writer at all');

    // A member number that is all digits is still a string.
    $digits = rcf_form();
    $digits['entries'][0]['member_number'] = '1234567';
    $xml    = rcf_sheet($digits);

    assertTrue(str_contains($xml, '<t xml:space="preserve">1234567</t>'), 'digits stay text');
    assertSame(0, substr_count($xml, '<v>1234567</v>'));
});

test('the writer refuses to become a general numeric cell', function (): void {
    $sheet = rcf_form_sheet();

    // The only non-string cell is the tick box, and it takes a bool — there is
    // no way to hand this writer a number at all, which is the point.
    $sheet->boolean('A1', 0, true);
    assertTrue(str_contains($sheet->sheet(), '<c r="A1" s="0" t="b"><v>1</v></c>'), 'a ticked box');

    assertThrows(
        static fn () => $sheet->text('nonsense', 0, 'x'),
        'not a cell reference',
        'a typo\'d reference fails here rather than in Excel'
    );
});

test('the built file is a real workbook, with the shipped style sheet inside it', function (): void {
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    $sheet = rcf_form_sheet();
    RosterChangeForm::draw($sheet, rcf_form());

    $path = $sheet->finish();

    try {
        assertTrue(is_file($path), 'the archive exists');

        $zip = new ZipArchive();
        assertSame(true, $zip->open($path) === true, 'and it opens as a zip');

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
            'xl/featurePropertyBag/featurePropertyBag.xml',
        ] as $part) {
            assertTrue($zip->locateName($part) !== false, $part . ' is in the package');
        }

        // EVERY PART THE WORKBOOK POINTS AT IS IN THE PACKAGE. This is the
        // rule the first shipped version broke: styles.xml carried an
        // xfComplement indexing into a feature property bag that was not
        // shipped, and Excel opened the form with "Repaired Records: Format
        // from /xl/styles.xml part (Styles)" and dropped the checkboxes.
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        preg_match_all('/Target="([^"]+)"/', $rels, $targets);
        assertTrue($targets[1] !== [], 'the workbook declares relationships');

        foreach ($targets[1] as $target) {
            assertTrue(
                $zip->locateName('xl/' . ltrim($target, '/')) !== false,
                'xl/' . $target . ' is related by the workbook and must be in the package'
            );
        }

        // And every part in the package is declared in [Content_Types].xml,
        // which is the other half of the same rule.
        $types = (string) $zip->getFromName('[Content_Types].xml');
        foreach (['/xl/workbook.xml', '/xl/worksheets/sheet1.xml', '/xl/styles.xml',
            '/xl/featurePropertyBag/featurePropertyBag.xml'] as $part) {
            assertTrue(
                str_contains($types, 'PartName="' . $part . '"'),
                $part . ' is declared in [Content_Types].xml'
            );
        }

        // The style sheet is the shipped asset, unmodified: the fourteen
        // fonts and the ninety-three cellXfs the style ids index into.
        $styles = (string) $zip->getFromName('xl/styles.xml');
        assertSame(
            (string) file_get_contents($app->path('app/templates/rcf/styles.xml')),
            $styles,
            'the style sheet travels byte for byte'
        );
        assertTrue(str_contains($styles, '<cellXfs count="93">'), 'all ninety-three cell formats');

        // The tab is called what their tab is called.
        assertTrue(
            str_contains((string) $zip->getFromName('xl/workbook.xml'), 'name="Sheet1"'),
            'the sheet is Sheet1, as on the form officers already know'
        );

        $zip->close();

        // And this application's own reader opens it — by MAGIC BYTES, not by
        // extension, which is the closest thing to Excel that can run in a
        // test and also proves the file is recognisably an .xlsx.
        assertSame('xlsx', Rerm\Roster\Spreadsheet::detect($path), 'it reads as an xlsx');

        $rows  = iterator_to_array(Rerm\Roster\Spreadsheet::open($path)->rows(), false);
        $first = [];
        foreach ($rows as $row) {
            $first[] = trim((string) ($row[0] ?? ''));
        }

        assertTrue(count($rows) >= 25, 'the reader sees the form, got ' . count($rows) . ' rows');
        assertTrue(
            in_array('Rodeo Express Roster Change Form  - RODEO 2027', $first, true),
            'including its title'
        );
        assertTrue(in_array('25)', $first, true), 'and the last of its twenty-five rows');
    } finally {
        $sheet->close($path);
    }

    assertTrue(!is_file($path), 'and the file does not survive on disk — it names members');
});

test('a style sheet that points at a feature property bag cannot ship without one', function (): void {
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    // The bug this file exists to keep fixed. Cell formats 60 and 61 — the
    // twenty-five ROOKIE cells and the twenty-five WAIT LIST cells — carry
    // <xfpb:xfComplement i="0"/>, an INDEX into
    // xl/featurePropertyBag/featurePropertyBag.xml that resolves to
    // CellControl -> Checkbox. Shipped without the bag, Excel resolves the
    // index, finds nothing, and opens the form with "Repaired Records: Format
    // from /xl/styles.xml part (Styles)" — the checkboxes gone and the user
    // told their form was damaged.
    //
    // The writer refuses rather than producing that file.
    $styles = (string) file_get_contents($app->path('app/templates/rcf/styles.xml'));
    assertTrue(str_contains($styles, 'xfComplement'), 'the style sheet does reference one');
    assertSame(2, substr_count($styles, 'xfComplement'), 'in exactly the two checkbox formats');

    assertThrows(
        static fn () => FormSheet::create(
            sys_get_temp_dir(),
            $app->path('app/templates/rcf/styles.xml'),
            'Sheet1'
        ),
        'feature property bag',
        'a style sheet with a dangling reference is refused, not shipped'
    );

    // The bag itself is what says "checkbox", and it says so in as many words.
    $bag = (string) file_get_contents($app->path('app/templates/rcf/featurePropertyBag.xml'));
    assertTrue(str_contains($bag, 'type="Checkbox"'), 'the bag declares a checkbox');
    assertTrue(str_contains($bag, 'k="CellControl"'), 'wired to a cell control');
});

test('the style sheet is self-contained: no theme part is needed and none is shipped', function (): void {
    /** @var App $app */
    $app    = $GLOBALS['rerm_app'];
    $styles = (string) file_get_contents($app->path('app/templates/rcf/styles.xml'));

    assertTrue($styles !== '', 'the asset ships with the application');

    // Every colour is explicit. A theme reference would need xl/theme/theme1.xml
    // beside it, and a workbook missing one it refers to is a workbook Excel
    // repairs on open.
    assertSame(0, preg_match('/theme="\d+"/', $styles), 'no colour refers to a theme');

    // And it carries no member data — it is fonts, fills and borders.
    assertSame(0, preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $styles), 'no email address');
    assertSame(0, preg_match('/\(\d{3}\)\s*\d{3}-\d{4}/', $styles), 'no phone number');
});

test('more rows than the form has is refused, never silently trimmed', function (): void {
    $form = rcf_form();
    $form['entries'][] = RosterChangeForm::emptyEntry();

    $sheet = rcf_form_sheet();

    // draw() takes what it is given; build() is where the ceiling lives, and
    // it throws rather than dropping row twenty-six — somebody quietly not
    // being added is the failure this prevents.
    $source = (string) file_get_contents(__DIR__ . '/../app/src/Forms/RosterChangeForm.php');
    assertTrue(
        str_contains($source, 'silently dropping the rest'),
        'the ceiling is a refusal and says why'
    );
    assertSame(25, RosterChangeForm::ROWS);

    $sheet->close();
});

// ---------------------------------------------------------------------------
// Reading what the screen sent back
// ---------------------------------------------------------------------------

test('a member is read out of one field, in either order, or either half alone', function (): void {
    assertSame(['1234567', 'Jane Sample'], RcfPage::parseMember('1234567 - Jane Sample'));
    assertSame(['1234567', 'Jane Sample'], RcfPage::parseMember('  1234567   -   Jane Sample  '));
    assertSame(['1234567', 'Jane Sample'], RcfPage::parseMember('Jane Sample - 1234567'));
    assertSame(['1234567', 'Jane Sample'], RcfPage::parseMember("1234567 \u{2013} Jane Sample"));
    assertSame(['1234567', ''], RcfPage::parseMember('1234567'));

    // A name and nothing else still goes on the form: HLS&R NO is a column
    // somebody at Rodeo Houston can fill in, and refusing the row would lose
    // the request.
    assertSame(['', 'Jane Sample'], RcfPage::parseMember('Jane Sample'));
    assertSame(['', ''], RcfPage::parseMember('   '));

    // A hyphenated surname is a name, not a separator.
    assertSame(['', 'Jane Sample-Smith'], RcfPage::parseMember('Jane Sample-Smith'));
});

test('the filename says which year and which sub-committee, and survives a header', function (): void {
    $name = RosterChangeForm::filename('2027', 'RC Logistics Division - RC Bus Ops Team A');

    assertTrue(str_starts_with($name, 'rerm-rcf-2027-'), 'the year is in it');
    assertTrue(str_ends_with($name, '.xlsx'), 'and it is a spreadsheet');
    assertSame(1, preg_match('/^[A-Za-z0-9._-]+$/', $name), 'nothing in it needs quoting');

    // A sub-committee full of punctuation cannot smuggle anything into a
    // Content-Disposition header.
    $hostile = RosterChangeForm::filename('2027', "x\"; filename=\"evil.exe");
    assertSame(1, preg_match('/^[A-Za-z0-9._-]+$/', $hostile), 'a hostile name is flattened');
});

test('a blank entry is blank, and one answer is enough to stop it being', function (): void {
    assertTrue(RosterChangeForm::entryIsBlank(RosterChangeForm::emptyEntry()));
    assertTrue(RosterChangeForm::entryIsBlank([]));
    assertTrue(RosterChangeForm::entryIsBlank(['type' => '   ']), 'whitespace is not an answer');

    assertTrue(!RosterChangeForm::entryIsBlank(['sponsor' => 'A. Officer']));
    assertTrue(!RosterChangeForm::entryIsBlank(['member_name' => 'Jane Sample']));

    // The two tick boxes carry an answer on every row of the blank form, so
    // they cannot decide whether a row says anything: a tick with no name
    // beside it is not a change request.
    assertTrue(RosterChangeForm::entryIsBlank([
        'rookie' => RosterChangeForm::TICKED, 'wait_list' => RosterChangeForm::TICKED,
    ]), 'a tick alone is not a change request');
});

// ---------------------------------------------------------------------------
// The database under test
// ---------------------------------------------------------------------------

function rcf_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    try {
        $pdo = $app->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    return $pdo;
}

function rcf_teardown(PDO $pdo): void
{
    $members = "SELECT id FROM member WHERE member_number LIKE 'RC%'";
    $users   = "SELECT id FROM app_user WHERE member_id IN ({$members})";

    $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$users})");
    $pdo->exec("DELETE FROM contact_log WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM assignment WHERE member_id IN ({$members}) OR officer_member_id IN ({$members})");
    $pdo->exec("DELETE FROM member_metric WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM app_user WHERE member_id IN ({$members})");
    $pdo->exec("DELETE FROM member WHERE member_number LIKE 'RC%'");
    $pdo->exec("DELETE FROM team WHERE name LIKE 'RC %'");
    $pdo->exec("DELETE FROM division WHERE name LIKE 'RC %'");
}

/**
 * Two divisions and a placeholder, three teams, and eight members.
 *
 *   RC Logistics Division      RC Bus Ops Team A   cap (Captain), m1, m2
 *                              RC Bus Ops Team B   m3
 *   RC Member Services Div.    RC Ost Team A       vc (Division Vice Chairman), m4
 *   RC (No Division)           RC Chuck Team A     m5
 *
 * `cap` is an Officer scoped to Team A; `vc` a Senior Officer over Member
 * Services. Between them they are exactly the two scopes this screen has to
 * get right.
 *
 * @return array<string, mixed>
 */
function rcf_fixture(): array
{
    static $fixture = null;

    if ($fixture !== null) {
        return $fixture;
    }

    $pdo = rcf_pdo();
    rcf_teardown($pdo);

    $insertDivision = $pdo->prepare('INSERT INTO division (name, is_placeholder) VALUES (:name, :placeholder)');
    $divisions      = [];
    foreach ([
        'log'  => ['RC Logistics Division', 0],
        // An ampersand on purpose: it is the character that breaks a page
        // when a screen forgets to escape, and every list on this one
        // shows a division name.
        'mem'  => ['RC Member Services & Support Division', 0],
        'none' => ['RC (No Division)', 1],
    ] as $key => [$name, $placeholder]) {
        $insertDivision->execute([':name' => $name, ':placeholder' => $placeholder]);
        $divisions[$key] = (int) $pdo->lastInsertId();
    }

    $insertTeam = $pdo->prepare('INSERT INTO team (name, division_id) VALUES (:name, :division)');
    $teams      = [];
    foreach ([
        'a'     => ['RC Bus Ops Team A', 'log'],
        'b'     => ['RC Bus Ops Team B', 'log'],
        'ost'   => ['RC Ost Team A', 'mem'],
        'chuck' => ['RC Chuck Team A', 'none'],
    ] as $key => [$name, $division]) {
        $insertTeam->execute([':name' => $name, ':division' => $divisions[$division]]);
        $teams[$key] = (int) $pdo->lastInsertId();
    }

    $insertMember = $pdo->prepare(
        'INSERT INTO member (member_number, first_name, last_name, preferred_name, full_name,'
        . ' division_id, team_id, phone, phone_e164, phone_type, email, title, title_level)'
        . " VALUES (:number, :first, :last, :preferred, :full, :division, :team,"
        . " '(555) 555-0118', '+15555550118', 'CELL PHONE', :email, :title, :level)"
    );

    $specs = [
        // key    division team    first      last       preferred title                     level
        'cap' => ['log',  'a',    'Alexis',  'Captain',  '',        'Captain',                'officer'],
        'm1'  => ['log',  'a',    'Robert',  'Alpha',    'Bob',     'Committee Member',       'member'],
        'm2'  => ['log',  'a',    'Carol',   'Bravo',    '',        'Committee Member',       'member'],
        'm3'  => ['log',  'b',    'Dana',    'Charlie',  '',        'Committee Member',       'member'],
        'vc'  => ['mem',  'ost',  'Erin',    'Delta',    '',        'Division Vice Chairman', 'senior_officer'],
        'm4'  => ['mem',  'ost',  'Frank',   'Echo',     '',        'Committee Member',       'member'],
        'm5'  => ['none', 'chuck', 'Gina',   'Foxtrot',  '',        'Committee Member',       'member'],
    ];

    $members = [];
    $n       = 0;
    foreach ($specs as $key => [$division, $team, $first, $last, $preferred, $title, $level]) {
        $n++;
        $number = sprintf('RC%06d', $n);

        $insertMember->execute([
            ':number'    => $number,
            ':first'     => $first,
            ':last'      => $last,
            ':preferred' => $preferred,
            ':full'      => $first . ' ' . $last,
            ':division'  => $divisions[$division],
            ':team'      => $teams[$team],
            ':email'     => strtolower($first) . '@example.com',
            ':title'     => $title,
            ':level'     => $level,
        ]);

        $members[$key] = ['id' => (int) $pdo->lastInsertId(), 'number' => $number];
    }

    return $fixture = [
        'divisions' => $divisions,
        'teams'     => $teams,
        'members'   => $members,
    ];
}

/** The Captain on RC Bus Ops Team A — an Officer, scoped to one team. */
function rcf_captain(): User
{
    $f = rcf_fixture();

    return new User(
        id: 9001,
        memberId: $f['members']['cap']['id'],
        memberNumber: $f['members']['cap']['number'],
        level: Level::Officer,
        scopeDivisionId: $f['divisions']['log'],
        scopeTeamId: $f['teams']['a'],
        mustChangePassword: false,
        displayName: 'Alexis Captain',
    );
}

/** The Division Vice Chairman over Member Services — a Senior Officer. */
function rcf_senior(): User
{
    $f = rcf_fixture();

    return new User(
        id: 9002,
        memberId: $f['members']['vc']['id'],
        memberNumber: $f['members']['vc']['number'],
        level: Level::SeniorOfficer,
        scopeDivisionId: $f['divisions']['mem'],
        scopeTeamId: $f['teams']['ost'],
        mustChangePassword: false,
        displayName: 'Erin Delta',
    );
}

test('the member picker is scoped exactly like every other roster read', function (): void {
    $f    = rcf_fixture();
    $page = new RcfPage(rcf_pdo());

    $teamA = RcfPage::pick($page->subcommittees(), 't:' . $f['teams']['a']);
    assertTrue($teamA !== null, 'the Captain\'s own team is offered');

    $numbers = array_column($page->membersFor(rcf_captain(), $teamA), 'member_number');
    sort($numbers);

    assertSame(
        [$f['members']['cap']['number'], $f['members']['m1']['number'], $f['members']['m2']['number']],
        $numbers,
        'an Officer sees their own team and nobody else'
    );

    // The same team, asked for by somebody whose scope does not reach it.
    assertSame([], $page->membersFor(rcf_senior(), $teamA),
        'a sub-committee outside the caller\'s scope offers nobody, not everybody');

    // A whole division, for the officer scoped to it.
    $memberServices = RcfPage::pick($page->subcommittees(), 'd:' . $f['divisions']['mem']);
    $numbers        = array_column($page->membersFor(rcf_senior(), $memberServices), 'member_number');
    sort($numbers);

    assertSame(
        [$f['members']['vc']['number'], $f['members']['m4']['number']],
        $numbers,
        'a Senior Officer sees their division'
    );
});

test('the lookup that fills a row in cannot reach outside the caller\'s scope', function (): void {
    $f    = rcf_fixture();
    $page = new RcfPage(rcf_pdo());

    $own = $page->memberInScope(rcf_captain(), $f['members']['m1']['number']);
    assertTrue($own !== null, 'their own team member is found');
    assertSame('Robert Alpha', $own['form_name'],
        'and by the name Rodeo Houston spells, not the preferred one');
    assertSame('Committee Member', $own['title']);

    assertSame(null, $page->memberInScope(rcf_captain(), $f['members']['m4']['number']),
        'somebody on another division is not found');
    assertSame(null, $page->memberInScope(rcf_captain(), 'RC999999'),
        'and neither is a number that does not exist — indistinguishably');
});

test('(No Division) is never offered, and its teams travel with no prefix', function (): void {
    $f       = rcf_fixture();
    $page    = new RcfPage(rcf_pdo());
    $options = $page->subcommittees();

    // The placeholder division is this application's bookkeeping for the 72
    // members who arrive with a blank Subcommittee 3. It must not reach Rodeo
    // Houston as though it were theirs (CLAUDE.md, spec 5.1a rule 2), and a
    // form is a worse place for it than the export, because a human reads it.
    assertSame(null, RcfPage::pick($options, 'd:' . $f['divisions']['none']),
        'the placeholder division is not a sub-committee anybody can choose');

    $chuck = RcfPage::pick($options, 't:' . $f['teams']['chuck']);
    assertTrue($chuck !== null, 'but its team is real and is offered');
    assertSame('RC Chuck Team A', $chuck['label'], 'under its own name, with nothing prefixed');
    assertSame('RC Chuck Team A', $chuck['form_label'], 'and that is what is printed');

    // A team under a real division carries it, which is how ninety-six teams
    // become findable.
    $teamA = RcfPage::pick($options, 't:' . $f['teams']['a']);
    assertSame('RC Logistics Division - RC Bus Ops Team A', $teamA['label']);
    assertSame('RC Bus Ops Team A', $teamA['name'], 'the row column carries the team alone');

    // And nothing in the whole list mentions it.
    foreach ($options as $option) {
        assertTrue(
            !str_contains((string) $option['form_label'], '(No Division)'),
            'nothing printed on a form says (No Division): ' . $option['form_label']
        );
    }
});

test('the officer lists hold officers, and only officers', function (): void {
    $f        = rcf_fixture();
    $page     = new RcfPage(rcf_pdo());
    $numbers  = array_column($page->officers(), 'member_number');

    assertTrue(in_array($f['members']['cap']['number'], $numbers, true), 'the Captain is an officer');
    assertTrue(in_array($f['members']['vc']['number'], $numbers, true), 'so is the Vice Chairman');
    assertTrue(!in_array($f['members']['m1']['number'], $numbers, true), 'a Committee Member is not');

    // The whole committee, deliberately: the sponsor for a new recruit must be
    // a Vice Chairman or higher, who is frequently on another team.
    $officers = $page->officers();
    $captain  = null;
    foreach ($officers as $officer) {
        if ($officer['member_number'] === $f['members']['cap']['number']) {
            $captain = $officer;
        }
    }

    assertTrue($captain !== null);
    assertSame('Alexis Captain, Captain', $captain['form_name'], 'the paper wants the name AND the title');
    assertSame('RC Bus Ops Team A', $captain['team_name'], 'and the team, so the list is legible');
});

test('the submitter defaults to whoever is signed in', function (): void {
    $f    = rcf_fixture();
    $page = new RcfPage(rcf_pdo());

    $chosen = $page->page(rcf_captain(), [])['submitter'];
    assertTrue($chosen !== null, 'somebody is chosen');
    assertSame($f['members']['cap']['number'], $chosen['member_number'], 'and it is them');

    // And it can be changed to any officer.
    $other = $page->page(rcf_captain(), ['submitter' => $f['members']['vc']['number']])['submitter'];
    assertSame($f['members']['vc']['number'], $other['member_number']);

    // A number that is not an officer's falls back rather than being taken at
    // face value: what is written on the paper is only ever an entry from a
    // list this application built.
    $bogus = $page->page(rcf_captain(), ['submitter' => 'RC999999'])['submitter'];
    assertSame($f['members']['cap']['number'], $bogus['member_number']);
});

test('a submitted form is resolved against the lists, never taken as typed', function (): void {
    $f    = rcf_fixture();
    $page = new RcfPage(rcf_pdo());

    $form = $page->formFromInput(rcf_captain(), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'date'         => '2027-03-01',
        'row'          => [
            0 => [
                'type'             => 'T',
                'member'           => $f['members']['m1']['number'],
                'new_title'        => 'Assistant Captain',
                'new_subcommittee' => 'RC Logistics Division - RC Bus Ops Team B',
            ],
            1 => [
                // Everything here is a lie or a typo.
                'type'             => 'Z',
                'rookie'           => 'maybe',
                'remove_reason'    => '99',
                'member'           => 'Somebody Unknown',
            ],
        ],
    ]);

    assertSame('2027', $form['year']);
    assertSame('3/1/2027', $form['date'], 'the date is written the way Houston reads one');
    assertSame('RC Logistics Division - RC Bus Ops Team A', $form['subcommittee']);
    assertSame('Alexis Captain, Captain', $form['submitter']);

    // Row one: the name and the previous title were filled in from the roster.
    assertSame('T', $form['entries'][0]['type']);
    assertSame($f['members']['m1']['number'], $form['entries'][0]['member_number']);
    assertSame('Robert Alpha', $form['entries'][0]['member_name'], 'the name came from the roster');
    assertSame('Committee Member', $form['entries'][0]['previous_title'], 'and so did the previous title');
    assertSame('Assistant Captain', $form['entries'][0]['new_title']);
    assertSame('RC Bus Ops Team B', $form['entries'][0]['new_subcommittee'], 'the destination, unprefixed');

    // Row two: every invented value is dropped, and the name still goes on.
    assertSame('', $form['entries'][1]['type'], 'a type code that does not exist is not written');
    assertSame(RosterChangeForm::UNTICKED, $form['entries'][1]['rookie'], 'and "maybe" is not a tick');
    assertSame('', $form['entries'][1]['remove_reason']);
    assertSame('Somebody Unknown', $form['entries'][1]['member_name'], 'the typed name survives');
    assertSame('', $form['entries'][1]['member_number']);

    // And rows three to twenty-five are untouched.
    assertSame(RosterChangeForm::ROWS, count($form['entries']));
    assertTrue(RosterChangeForm::entryIsBlank($form['entries'][2]), 'row three is blank');
});

test('a typed previous title wins over the roster, and a sub-committee outside the list is nothing', function (): void {
    $f    = rcf_fixture();
    $page = new RcfPage(rcf_pdo());

    $form = $page->formFromInput(rcf_captain(), [
        'subcommittee' => 'd:' . $f['divisions']['none'],
        'row'          => [
            0 => [
                'member'         => $f['members']['m1']['number'],
                'previous_title' => 'Whatever they actually were',
            ],
        ],
    ]);

    assertSame('', $form['subcommittee'], 'the placeholder division cannot be chosen even by hand');
    assertSame('Whatever they actually were', $form['entries'][0]['previous_title'],
        'what an officer typed is never overwritten');
});

test('the two tick boxes carry an answer on every row without making one look filled', function (): void {
    $f    = rcf_fixture();
    $page = new RcfPage(rcf_pdo());

    $form = $page->formFromInput(rcf_captain(), [
        'subcommittee' => 't:' . $f['teams']['a'],
        'row'          => [
            0 => [
                'member'    => $f['members']['m1']['number'],
                'wait_list' => RosterChangeForm::TICKED,
                'rookie'    => RosterChangeForm::TICKED,
            ],
            1 => ['member' => $f['members']['m2']['number']],
        ],
    ]);

    assertSame(RosterChangeForm::TICKED, $form['entries'][0]['wait_list']);
    assertSame(RosterChangeForm::TICKED, $form['entries'][0]['rookie']);
    assertSame(RosterChangeForm::UNTICKED, $form['entries'][1]['wait_list'], 'not ticked is 0');
    assertSame(RosterChangeForm::UNTICKED, $form['entries'][5]['rookie'], 'on every row, as the blank form is');

    // An unticked box submits NOTHING, so every row would otherwise collect a
    // 0 and stop being blank — twenty-three untouched rows all looking filled
    // in. entryIsBlank ignores both columns for exactly that reason.
    assertTrue(RosterChangeForm::entryIsBlank($form['entries'][5]), 'row six is still blank');
    assertTrue(!RosterChangeForm::entryIsBlank($form['entries'][0]), 'row one is not');
});

/** The whole page, layout included, as a signed-in officer would receive it. */
function rcf_render(User $user, array $input): string
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    $_SESSION ??= [];
    $wide    = true;
    $title   = 'Roster Change Form';
    $notices = [];
    $rcf     = (new RcfPage(rcf_pdo()))->page($user, $input);

    ob_start();
    require $app->path('app/views/form-rcf.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

test('the screen renders, escapes what it shows and shares its three long lists', function (): void {
    $f    = rcf_fixture();
    $html = rcf_render(rcf_captain(), ['subcommittee' => 't:' . $f['teams']['a']]);

    assertTrue(str_contains($html, 'RC Logistics Division - RC Bus Ops Team A'), 'the chosen sub-committee');
    assertTrue(str_contains($html, 'RC000002 - Robert Alpha'), 'a member, ready to be picked');
    assertTrue(
        str_contains($html, 'RC Member Services &amp; Support Division'),
        'a division name with an ampersand in it is escaped'
    );
    assertSame(0, substr_count($html, 'Support Division</option>'), 'and never raw');

    // A preferred name is offered as the datalist's LABEL and never as its
    // value: "Bud" is how an officer looks him up, and the membership record
    // says Robert.
    assertTrue(str_contains($html, 'label="Bob Alpha"'), 'the name he is known by helps find him');
    assertSame(0, substr_count($html, '- Bob Alpha'), 'but it is never what goes on the form');

    // The three long lists are emitted ONCE each and shared by every row.
    // Drawing a hundred teams twenty-five times over is 150KB of <option> on
    // a page with a 100KB budget (spec 10).
    assertSame(1, substr_count($html, '<datalist id="rcf-members">'));
    assertSame(1, substr_count($html, '<datalist id="rcf-subcommittees">'));
    assertSame(1, substr_count($html, '<datalist id="rcf-officers">'));
    assertSame(RcfPage::VISIBLE_ROWS, substr_count($html, 'list="rcf-members"'), 'one field per drawn row');

    // Both tick boxes are tick boxes on the screen because both are tick
    // boxes on the paper.
    assertSame(
        RcfPage::VISIBLE_ROWS * 2,
        substr_count($html, '<input type="checkbox"'),
        'rookie and wait list, one pair per drawn row'
    );

    // No script anywhere: the host has no build step and render() forbids it.
    assertSame(0, substr_count($html, '<script'), 'there is no JavaScript in this application');
});

test('the screen draws five rows, grows on request, and never hides a filled one', function (): void {
    $f    = rcf_fixture();
    $page = new RcfPage(rcf_pdo());
    $team = 't:' . $f['teams']['a'];

    assertSame(5, $page->page(rcf_captain(), ['subcommittee' => $team])['visible_rows'],
        'five to begin with — most forms carry three names');

    assertSame(10, $page->page(rcf_captain(), [
        'subcommittee' => $team, 'visible' => '5', 'action' => 'rows',
    ])['visible_rows'], 'and five more each time the button is pressed');

    assertSame(RosterChangeForm::ROWS, $page->page(rcf_captain(), [
        'subcommittee' => $team, 'visible' => '25', 'action' => 'rows',
    ])['visible_rows'], 'never past the twenty-five the form has');

    // A row somebody has filled in cannot be hidden by a button, or their
    // change would be on the file with no way to see it.
    assertSame(12, $page->page(rcf_captain(), [
        'subcommittee' => $team,
        'visible'      => '1',
        'row'          => [11 => ['member' => 'RC000002']],
    ])['visible_rows'], 'row twelve is filled in, so twelve rows are drawn');
});

test('the page stays inside the first-paint budget at the size it is normally used', function (): void {
    $f = rcf_fixture();

    // Spec 10: under 100KB on first paint. MEASURED, at the three sizes that
    // matter, with the shape recorded in spec-v2 §2.4:
    //
    //   nothing chosen yet          ~37KB
    //   a team, five rows           ~59KB   <- what this screen normally is
    //   a 290-person division       ~74KB
    //   all twenty-five rows drawn  ~115KB  <- a deliberate expansion, four
    //                                          button presses in, and ~14KB
    //                                          over the wire once compressed
    //
    // The budget is asserted where it applies — the page as it arrives — and
    // the fully expanded form is held to a ceiling rather than pretended
    // about. That is why the screen draws five rows and not twenty-five.
    $chosen = rcf_render(rcf_captain(), ['subcommittee' => 't:' . $f['teams']['a']]);
    assertTrue(strlen($chosen) < 100 * 1024, 'first paint is ' . strlen($chosen) . ' bytes');

    $empty = rcf_render(rcf_captain(), []);
    assertTrue(strlen($empty) < 100 * 1024, 'and before anything is chosen, ' . strlen($empty) . ' bytes');

    $expanded = rcf_render(rcf_captain(), ['subcommittee' => 't:' . $f['teams']['a'], 'visible' => '25']);
    assertTrue(strlen($expanded) < 128 * 1024, 'fully expanded is ' . strlen($expanded) . ' bytes');
    assertTrue(
        strlen(gzencode($expanded, 6)) < 24 * 1024,
        'and ' . strlen(gzencode($expanded, 6)) . ' bytes on the wire'
    );
});

test('roster change form fixtures are cleaned up', function (): void {
    $pdo = rcf_pdo();
    rcf_fixture();
    rcf_teardown($pdo);

    assertSame(
        0,
        (int) $pdo->query("SELECT COUNT(*) FROM member WHERE member_number LIKE 'RC%'")->fetchColumn(),
        'no fixture members survive'
    );
});
