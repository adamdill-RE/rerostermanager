<?php

declare(strict_types=1);

namespace Rerm\Forms;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\User;
use RuntimeException;

/**
 * The Roster Change Form (RCF) — the first thing Create Forms creates
 * (spec-v2 §2).
 *
 * An RCF is how a committee officer asks Rodeo Houston to change the roster:
 * add somebody, remove somebody, change a title, move a team. Today it is a
 * spreadsheet passed around by email and filled in by hand, and the whole
 * point of generating it here is that **it must come out looking exactly like
 * the one they already fill in** — same title bar, same instruction panels,
 * same coloured column headers, same twenty-five numbered rows. A Division
 * Chairman receiving one should not be able to tell it was produced by a
 * computer, because a form that looks different is a form that gets
 * questioned instead of processed.
 *
 * So this class is a **transcription**, not a design. Every style id below
 * indexes into `app/templates/rcf/styles.xml`, which is that workbook's own
 * `xl/styles.xml` shipped byte for byte; every merge, width, height and label
 * was read out of its `sheet1.xml`. `FormSheet` turns the two into a file.
 *
 * Three things are ours rather than theirs, and each is deliberate:
 *
 * **The legend and the dropdowns are the same list.** `TYPES` and
 * `REMOVE_REASONS` are printed onto the form (rows 8–12 and 15–20) AND
 * offered on the screen. A form whose printed legend says six remove reasons
 * while the screen offers seven is a form that collects an answer nobody can
 * act on, and this is the ordinary way that happens. `tests/forms_test.php`
 * reads the generated cells back and holds them to the constants.
 *
 * **`(No Division)` never travels.** It is this application's bookkeeping for
 * 72 members who arrive with a blank `Subcommittee 3`, not Rodeo Houston's
 * data (CLAUDE.md; spec §5.1a rule 2). The roster export already writes it
 * back as blank; a form that offered it as a sub-committee would be worse,
 * because a human would read it as a real one. `RcfPage` never offers it and
 * a test asserts that too.
 *
 * **ROOKIE and WAIT LIST are CHECKBOXES, and that had to be discovered.**
 * The blank form carries a numeric `0` in both columns of all twenty-five
 * rows, which reads like a leftover and is not one: cell formats 60 and 61 —
 * which is exactly those fifty cells and nothing else — carry an
 * `xfComplement` that resolves, through the workbook's feature property bag,
 * to `CellControl -> Checkbox`. The `0` is an unchecked box. So this writes
 * a BOOLEAN — `t="b"`, the only thing Excel draws a box for — into both
 * columns of EVERY row, as the source workbook does, and
 * `app/templates/rcf/featurePropertyBag.xml` ships beside the style sheet so
 * the reference resolves. Without it Excel repairs the file on open and the
 * checkboxes are gone — see `FormSheet::BAG_PART`.
 *
 * The form's own older instructions still say `y/n` under ROOKIE and 'Please
 * enter "Yes" or "No"' beside WAIT LIST. Those predate the conversion; the
 * cells are what Rodeo Houston actually processes, so the cells win.
 *
 * A filled-in RCF names members and carries their member numbers, so it is
 * **PII leaving the building** and it is handled the way the roster export is:
 * built outside the document root, unlinked as soon as it has been sent, and
 * logged with the actor, the sub-committee and the row count.
 */
final class RosterChangeForm
{
    /** Entry rows on the form. Twenty-five, because the form has twenty-five. */
    public const ROWS = 25;

    /** The first entry row. Rows 1–26 are the header and the legend. */
    private const FIRST_ENTRY_ROW = 27;

    /**
     * The `*TYPE` codes, exactly as the form spells them — the code is what
     * goes in the cell ("Please Enter The Appropriate Code"), and
     * "{code} = {description}" is what is printed in the legend at rows 8–12.
     *
     * `S & T` is a two-word code with spaces in it. That is the form's
     * spelling and it is kept: a form that says `S&T` is a form somebody has
     * to interpret.
     */
    public const TYPES = [
        'A'     => 'Addition',
        'R'     => 'Remove',
        'T'     => 'Title Change',
        'S'     => 'Sub-Committee Change (Team Change)',
        'S & T' => 'Sub-Committee Change (Team Change) & Title Change',
    ];

    /**
     * The remove reasons, printed at rows 15–20 as "{n}) {reason}".
     *
     * The CELL holds the number alone, which is what the instruction asks for
     * ("Please enter the appropriate REMOVE REASON:" over a numbered list) and
     * what the 26-character column can show without clipping — reason 3 is
     * sixty-two characters long and the column does not wrap.
     */
    public const REMOVE_REASONS = [
        '1' => 'Deceased Member',
        '2' => 'Did Not Meet Requirements',
        '3' => 'Leadership Recommendation (please contact Division Chairman)',
        '4' => 'Member Resigned',
        '5' => 'No Response to Communications',
        '6' => 'No Show for All Assignments',
    ];

    /**
     * The two columns whose cells are Excel checkboxes — see the class
     * comment. Both are written on every row, `1` ticked and `0` not, which
     * is what the blank form Rodeo Houston sends out already holds.
     *
     * Their values are therefore NOT part of whether a row says anything: a
     * tick and nothing else is not a change request, and if it counted, every
     * one of the twenty-five rows would look filled in the moment the form
     * was drawn.
     */
    public const TICKED   = '1';
    public const UNTICKED = '0';

    /**
     * The waitlist notice, whose double spaces are the form's own. It is
     * transcribed rather than rewritten: it is Rodeo Houston's instruction to
     * their own members and not ours to improve.
     */
    private const WAITLIST_NOTICE =
        'Please make sure all new members who are being submitted as an addition are on the '
        . 'Rodeo Express Waitlist.  They can log in online to their HLSR Member Account and '
        . 'select Rodeo Express on the Committee Volunteer Request tab.  Or they can call '
        . 'membership and request to be placed on the Rodeo Express Waitlist.  Rodeo Express '
        . 'cannot  guarantee the addition of anyone who is not on the waitlist and the '
        . 'processing time will increase.';

    /**
     * Column widths, in Excel's character units, and the default cell style
     * each column carries. Straight off the source workbook's `<cols>`.
     *
     * The last run reaches column 1025 because theirs does: it is what stops
     * the sheet changing font at column M.
     *
     * @var array<int, array{0: int, 1: int, 2: string, 3: int}> first, last, width, style
     */
    private const COLUMNS = [
        [1, 1, '4', 1],
        [2, 2, '6', 2],
        [3, 3, '6.5', 2],
        [4, 4, '26.6640625', 2],
        [5, 5, '9.6640625', 2],
        [6, 6, '18.6640625', 3],
        [7, 7, '19', 2],
        [8, 8, '6', 2],
        [9, 9, '26.1640625', 2],
        [10, 10, '22.1640625', 2],
        [11, 11, '8.1640625', 2],
        [12, 12, '28.5', 2],
        [13, 1025, '8.1640625', 2],
    ];

    /**
     * Rows that carry their own height or format. `thickBot` is the heavy
     * rule under the column headers and between the legend and the grid.
     *
     * @var array<int, array{ht?: string, customHeight?: bool, thickBot?: bool, s?: int}>
     */
    private const ROW_ATTRIBUTES = [
        2  => ['ht' => '20'],
        4  => ['ht' => '17'],
        5  => ['s' => 7, 'ht' => '17'],
        7  => ['ht' => '17'],
        9  => ['ht' => '12.75', 'customHeight' => true],
        14 => ['ht' => '17'],
        15 => ['ht' => '17'],
        20 => ['ht' => '16'],
        23 => ['ht' => '15', 'thickBot' => true],
        24 => ['ht' => '15', 'thickBot' => true],
        25 => ['s' => 2, 'ht' => '14.25', 'customHeight' => true, 'thickBot' => true],
        26 => ['s' => 2, 'ht' => '15', 'customHeight' => true],
        30 => ['ht' => '13.5', 'customHeight' => true, 'thickBot' => true],
        31 => ['ht' => '15', 'thickBot' => true],
        32 => ['ht' => '15', 'thickBot' => true],
        33 => ['ht' => '15', 'thickBot' => true],
        34 => ['ht' => '15', 'thickBot' => true],
        35 => ['ht' => '15', 'thickBot' => true],
        36 => ['ht' => '15', 'thickBot' => true],
        41 => ['s' => 8],
        42 => ['s' => 8],
        43 => ['s' => 8],
        44 => ['s' => 8],
        45 => ['s' => 8],
        46 => ['ht' => '15', 'customHeight' => true],
        47 => ['ht' => '15', 'customHeight' => true],
    ];

    /**
     * Every cell of the header and legend, by RANGE, with the style it
     * carries. An empty styled cell is not nothing: it is the box somebody
     * writes in, and dropping it loses the fill and the border.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const CHROME_STYLES = [
        ['J1', 4],
        ['A2:I2', 88], ['J2', 5], ['K2', 50],
        ['A4:F4', 89], ['G4:L4', 90],
        ['A5:B5', 91], ['C5', 63], ['D5', 6], ['E5:F5', 89], ['G5:L5', 92],
        ['E6', 8], ['F6', 1], ['G6:H6', 8], ['I6:J6', 9], ['K6', 8],
        ['A7:F7', 81], ['H7:L7', 82],
        ['A8:F8', 83], ['H8:L8', 84],
        ['A9:F9', 85], ['H9:L9', 86],
        ['A10:F10', 85], ['G10', 10], ['H10:L10', 86],
        ['A11', 11], ['B11:E11', 12], ['F11', 13], ['G11', 10], ['H11:L11', 86],
        ['A12:F12', 87], ['H12:L12', 86],
        ['H13:L13', 86],
        ['A14:F14', 76], ['G14', 14], ['H14:L14', 77],
        ['A15:F15', 78], ['H15:L15', 79],
        ['A16:F16', 72], ['H16:L16', 80],
        ['A17:F17', 72], ['H17:L17', 73],
        ['A18:F18', 72], ['H18', 15], ['I18:K18', 16], ['L18', 17],
        ['A19:F19', 72], ['H19:K19', 18], ['L19', 19],
        ['A20:F20', 74], ['H20:L20', 75],
        ['A21:F21', 20], ['H21:L21', 66],
        ['A22:F22', 21], ['H22', 22], ['I22:K22', 1],
        ['B23:C23', 8], ['F23:G23', 67], ['H23', 8], ['J23:K23', 67], ['L23', 23],
        ['B24', 24], ['C24', 25], ['D24:E24', 68], ['F24', 26], ['G24', 27],
        ['H24', 69], ['I24', 70], ['J24:K24', 68], ['L24', 71],
        ['A25', 1], ['B25', 28], ['C25', 29], ['D25:E25', 68], ['F25', 30], ['G25', 31],
        ['H25', 69], ['I25', 70], ['J25:K25', 68], ['L25', 71],
        ['A26', 1], ['B26', 32], ['C26', 33], ['D26:E26', 68], ['F26', 34], ['G26', 35],
        ['H26', 36], ['I26', 37], ['J26:K26', 68], ['L26', 38],
    ];

    /**
     * The words printed on a blank form, at the cell that carries them.
     *
     * The type legend (A8–A12) and the remove-reason legend (A15–A20) are
     * NOT here: they are generated from `TYPES` and `REMOVE_REASONS` so that
     * the paper and the dropdown cannot drift apart. Neither is the title at
     * A2, which carries the show year.
     *
     * @var array<string, string>
     */
    private const CHROME_TEXT = [
        'J1'  => 'CHANGE FORM  # ',
        'J2'  => '(DC USE ONLY)',
        'A4'  => 'Name & Title of whom is submitting this form:',
        'A5'  => 'Date:',
        'E5'  => 'Sub-Committee:',
        'A7'  => '*Type - Please Enter The Appropriate Code:',
        'H7'  => 'WAIT LIST:',
        'H8'  => 'Please enter "Yes" or "No"',
        'H9'  => self::WAITLIST_NOTICE,
        'A14' => '**Please enter the appropriate REMOVE REASON:',
        'H15' => 'INTERVIEW REQUIRED or SPONSORED BY',
        'H16' => 'Must enter ONE of the following options when adding a new recruit:',
        'H17' => '1) Date interview was conducted',
        'H18' => '2) Officers name that will be sponsoring the new recruit (must be a VC or higher).',
        'H20' => 'RODEO EXPRESS ROOKIE? Must indicate if they are brand new to Rodeo Express.',
        'H21' => 'If they have NEVER been on Rodeo Express they are a Rookie.',
        'F23' => 'For Adds & Title Change Only',
        'J23' => 'For Adds & Team Changes Only',
        'L23' => 'For Adds only',
        'C24' => 'RE',
        'D24' => 'MEMBER NAME',
        'E24' => 'HLS&R NO',
        'F24' => 'CHANGE/ADD',
        'G24' => 'PREVIOUS',
        'H24' => 'WAIT LIST',
        'I24' => '**REMOVE REASON  ',
        'J24' => 'NEW SUB-COMMITTEE (New Team)',
        'L24' => 'INTERVIEW REQUIRED or SPONSORED BY',
        'B25' => '*TYPE',
        'C25' => 'ROOKIE',
        'F25' => 'TITLE',
        'G25' => 'TITLE',
        'C26' => 'y/n',
        'H26' => '√',
    ];

    /**
     * Merged ranges in the header and legend. The twenty-five `J{n}:K{n}` of
     * the grid are added row by row in `draw()`, so the count comes out at
     * the source workbook's sixty.
     *
     * @var array<int, string>
     */
    private const CHROME_MERGES = [
        'A2:I2', 'A4:F4', 'G4:L4', 'A5:B5', 'E5:F5', 'G5:L5',
        'A7:F7', 'H7:L7', 'A8:F8', 'H8:L8', 'A9:F9', 'H9:L13', 'A10:F10',
        'A12:F12', 'A14:F14', 'H14:L14', 'A15:F15', 'H15:L15', 'A16:F16', 'H16:L16',
        'A17:F17', 'H17:L17', 'A18:F18', 'A19:F19', 'A20:F20', 'H20:L20', 'H21:L21',
        'F23:G23', 'J23:K23',
        'D24:D26', 'E24:E26', 'H24:H25', 'I24:I25', 'J24:K26', 'L24:L25',
    ];

    /**
     * The style of every cell of every entry row, A through L.
     *
     * Twenty-five rows that look identical and are not: Excel accreted a
     * dozen near-duplicate `cellXfs` over the form's life, and the borders
     * genuinely differ at the top row, at the two internal rules and at the
     * last row. Transcribed rather than generalised, because a rule in the
     * wrong place is exactly the kind of difference somebody notices without
     * being able to name it.
     *
     * @var array<int, array<int, int>> sheet row => [A, B, C, D, E, F, G, H, I, J, K, L]
     */
    private const ENTRY_ROW_STYLES = [
        27 => [39, 59, 60, 40, 40, 40, 43, 61, 51, 65, 65, 52],
        28 => [41, 59, 60, 40, 40, 40, 62, 61, 53, 65, 65, 52],
        29 => [41, 59, 60, 40, 40, 40, 62, 61, 53, 65, 65, 52],
        30 => [41, 59, 60, 40, 40, 40, 62, 61, 53, 65, 65, 52],
        31 => [41, 42, 60, 40, 40, 40, 62, 61, 53, 65, 65, 52],
        32 => [41, 42, 60, 40, 40, 43, 62, 61, 55, 65, 65, 52],
        33 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        34 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        35 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        36 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        37 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        38 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        39 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        40 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        41 => [41, 42, 60, 40, 40, 43, 62, 61, 53, 65, 65, 52],
        42 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        43 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        44 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        45 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        46 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        47 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        48 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        49 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        50 => [41, 42, 60, 43, 48, 43, 62, 61, 44, 65, 65, 54],
        51 => [45, 46, 60, 56, 56, 49, 49, 61, 57, 64, 64, 58],
    ];

    /**
     * Which entry field each column carries. Column K holds nothing: it is
     * the right half of the merged NEW SUB-COMMITTEE cell, whose value lives
     * in J.
     *
     * @var array<string, string>
     */
    private const ENTRY_COLUMNS = [
        'B' => 'type',
        'C' => 'rookie',
        'D' => 'member_name',
        'E' => 'member_number',
        'F' => 'new_title',
        'G' => 'previous_title',
        'H' => 'wait_list',
        'I' => 'remove_reason',
        'J' => 'new_subcommittee',
        'L' => 'sponsor',
    ];

    /**
     * The checkbox columns, by the letter they sit in: ROOKIE and WAIT LIST.
     *
     * @var array<string, string> column => the entry field it carries
     */
    private const CHECKBOX_COLUMNS = ['C' => 'rookie', 'H' => 'wait_list'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $exportDirectory,
        private readonly string $stylesPath,
        private readonly string $featurePropertyBagPath,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            $app->path('var/exports'),
            $app->path('app/templates/rcf/styles.xml'),
            $app->path('app/templates/rcf/featurePropertyBag.xml')
        );
    }

    /**
     * An empty entry row. The screen starts from twenty-five of these and the
     * writer accepts nothing else, so a field that is added has to be added
     * in one place.
     *
     * @return array<string, string>
     */
    public static function emptyEntry(): array
    {
        return array_fill_keys(array_values(self::ENTRY_COLUMNS), '');
    }

    /**
     * Is there anything on this row at all? A blank one prints as blank.
     *
     * The two CHECKBOX fields are not consulted, deliberately: they carry an
     * answer on every row of the blank form, so counting them would make all
     * twenty-five rows look filled in before anybody had typed a thing. A
     * tick with no name beside it is not a change request.
     */
    public static function entryIsBlank(array $entry): bool
    {
        $ticks = array_values(self::CHECKBOX_COLUMNS);

        foreach (self::emptyEntry() as $field => $unused) {
            if (in_array($field, $ticks, true)) {
                continue;
            }

            if (trim((string) ($entry[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds the file and returns its path, the filename to send it under and
     * how many rows carry anything. The caller sends it, then calls
     * `discard()` — always, including on a failure.
     *
     * @param array{year: string, submitter: string, date: string, subcommittee: string,
     *     entries: array<int, array<string, string>>} $form
     * @return array{path: string, filename: string, rows: int, sheet: FormSheet}
     */
    public function build(array $form): array
    {
        $entries = array_values($form['entries']);

        if (count($entries) > self::ROWS) {
            throw new RuntimeException(
                'A Roster Change Form has ' . self::ROWS . ' rows. '
                . count($entries) . ' were given, and silently dropping the rest is how '
                . 'somebody stops being added without anybody noticing.'
            );
        }

        // 'Sheet1', because that is what the tab on their form is called. A
        // better name would be a visible difference from the form officers
        // already know, which is the one thing this file may not be.
        $sheet = FormSheet::create(
            $this->exportDirectory,
            $this->stylesPath,
            'Sheet1',
            $this->featurePropertyBagPath
        );

        self::draw($sheet, $form);

        $filled = 0;
        foreach ($entries as $entry) {
            if (!self::entryIsBlank($entry)) {
                $filled++;
            }
        }

        return [
            'path'     => $sheet->finish(),
            'filename' => self::filename($form['year'], $form['subcommittee']),
            'rows'     => $filled,
            'sheet'    => $sheet,
        ];
    }

    /**
     * The record: who produced a form, for which sub-committee, naming how
     * many people (spec §10). An RCF carries member names and numbers, so it
     * is logged for the reason the export is — it is the same data leaving by
     * a different door.
     */
    public function audit(User $actor, array $form, int $rows): void
    {
        (new AuditLog($this->pdo))->record(
            $actor,
            Action::CreateForm,
            'form',
            'rcf',
            null,
            [
                'form'          => 'roster_change_form',
                'show_year'     => $form['year'],
                'sub_committee' => $form['subcommittee'],
                'submitted_by'  => $form['submitter'],
                'rows'          => $rows,
            ]
        );
    }

    /** Removes the built file. Always called, and called even on a failure. */
    public function discard(FormSheet $sheet, ?string $path = null): void
    {
        $sheet->close($path);
    }

    /**
     * Everything on the sheet: the widths, the chrome, the legend generated
     * from the two vocabularies, and the twenty-five entry rows.
     *
     * Static and public because it is a pure function of the form, and
     * because that is what lets `tests/forms_test.php` assert the shape of
     * every cell without a database in front of it. The database is only ever
     * needed by `audit()`.
     *
     * @param array{year: string, submitter: string, date: string, subcommittee: string,
     *     entries: array<int, array<string, string>>} $form
     */
    public static function draw(FormSheet $sheet, array $form): void
    {
        $entries = array_values($form['entries']);

        foreach (self::COLUMNS as [$first, $last, $width, $style]) {
            $sheet->column($first, $last, $width, $style);
        }

        foreach (self::ROW_ATTRIBUTES as $number => $attributes) {
            $sheet->row(
                $number,
                $attributes['ht'] ?? null,
                $attributes['customHeight'] ?? false,
                $attributes['thickBot'] ?? false,
                $attributes['s'] ?? null
            );
        }

        // The empty styled cells first, then the words over the top of them:
        // a label and its box are the same cell, and the style has to survive
        // the value being written into it.
        foreach (self::CHROME_STYLES as [$range, $style]) {
            foreach (self::expand($range) as $reference) {
                $sheet->text($reference, $style);
            }
        }

        $styleOf = self::styleIndex();

        foreach (self::CHROME_TEXT as $reference => $text) {
            $sheet->text($reference, $styleOf[$reference], $text);
        }

        // The title carries the show year, so a 2028 form says 2028. The
        // double space after "Form" is theirs.
        $sheet->text('A2', $styleOf['A2'], 'Rodeo Express Roster Change Form  - RODEO ' . $form['year']);

        // The legend, from the same lists the screen offers.
        $row = 8;
        foreach (self::TYPES as $code => $description) {
            $sheet->text('A' . $row, $styleOf['A' . $row], $code . ' = ' . $description);
            $row++;
        }

        $row = 15;
        foreach (self::REMOVE_REASONS as $number => $reason) {
            $sheet->text('A' . $row, $styleOf['A' . $row], $number . ') ' . $reason);
            $row++;
        }

        // What the officer filled in at the top.
        $sheet->text('G4', $styleOf['G4'], $form['submitter']);
        $sheet->text('D5', $styleOf['D5'], $form['date']);
        $sheet->text('G5', $styleOf['G5'], $form['subcommittee']);

        foreach (self::ENTRY_ROW_STYLES as $number => $styles) {
            $index = $number - self::FIRST_ENTRY_ROW;
            $entry = $entries[$index] ?? [];

            $sheet->merge('J' . $number . ':K' . $number);

            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $offset => $column) {
                $reference = $column . $number;
                $style     = $styles[$offset];

                if ($column === 'A') {
                    // The row's own number, as the form writes it: "1)".
                    $sheet->text($reference, $style, ($index + 1) . ')');

                    continue;
                }

                // A checkbox cell, on every row. It has to be a BOOLEAN and
                // not a number: Excel draws a box for `t="b"` and prints the
                // value for anything else, so a plain `0` here is the
                // character 0 sitting where an empty box belongs.
                if (isset(self::CHECKBOX_COLUMNS[$column])) {
                    $sheet->boolean(
                        $reference,
                        $style,
                        (string) ($entry[self::CHECKBOX_COLUMNS[$column]] ?? '') === self::TICKED
                    );

                    continue;
                }

                $field = self::ENTRY_COLUMNS[$column] ?? null;

                $sheet->text(
                    $reference,
                    $style,
                    $field === null ? '' : trim((string) ($entry[$field] ?? ''))
                );
            }
        }

        foreach (self::CHROME_MERGES as $range) {
            $sheet->merge($range);
        }
    }

    /**
     * Every cell reference in a range like `A2:I2` or `D24:D26`, and the one
     * reference in `J1`. Rectangular ranges only, which is all the form has.
     *
     * @return array<int, string>
     */
    public static function expand(string $range): array
    {
        if (!str_contains($range, ':')) {
            return [$range];
        }

        [$from, $to] = explode(':', $range, 2);

        if (preg_match('/^([A-Z]{1,3})([0-9]+)$/', $from, $a) !== 1
            || preg_match('/^([A-Z]{1,3})([0-9]+)$/', $to, $b) !== 1
        ) {
            throw new RuntimeException("'{$range}' is not a cell range.");
        }

        $references = [];

        for ($row = (int) $a[2]; $row <= (int) $b[2]; $row++) {
            for ($column = self::columnIndex($a[1]); $column <= self::columnIndex($b[1]); $column++) {
                $references[] = self::columnLetters($column) . $row;
            }
        }

        return $references;
    }

    /**
     * Cell reference => style, over the whole of CHROME_STYLES. Built rather
     * than written down a second time: the style a label carries is the style
     * of the box it sits in, and stating it twice is how they come apart.
     *
     * @return array<string, int>
     */
    private static function styleIndex(): array
    {
        $index = [];

        foreach (self::CHROME_STYLES as [$range, $style]) {
            foreach (self::expand($range) as $reference) {
                $index[$reference] = $style;
            }
        }

        return $index;
    }

    /** `A` -> 1, `AA` -> 27. One-based, matching `<col min=>`. */
    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    /** The inverse. */
    private static function columnLetters(int $index): string
    {
        $letters = '';
        for ($n = $index - 1; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letters = chr(65 + $n % 26) . $letters;
        }

        return $letters;
    }

    /**
     * What the browser saves it as. The show year and the sub-committee, so
     * three forms for three teams do not overwrite each other in a downloads
     * folder, and the date so today's does not overwrite last week's.
     */
    public static function filename(string $year, string $subcommittee): string
    {
        $name = implode('-', array_filter([
            'rerm-rcf',
            $year,
            $subcommittee,
            date('Y-m-d'),
        ], static fn (string $part): bool => $part !== ''));

        // Held to characters that survive a Content-Disposition header, a
        // Windows filename and a shell without quoting.
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        return trim((string) preg_replace('/-+/', '-', $name), '-') . '.xlsx';
    }
}
