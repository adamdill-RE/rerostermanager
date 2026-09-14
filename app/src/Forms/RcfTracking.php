<?php

declare(strict_types=1);

namespace Rerm\Forms;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\User;
use Rerm\Roster\RosterPage;

/**
 * Track RCFs (Phase 11, spec-v2 §12) — every Roster Change Form this
 * application produced, who made it, and where each line of it has got to.
 *
 * The question that brought this screen into being, in the words of the
 * first officer to ask it: a member rings to say they have not been asked to
 * pay their dues, and finding out whether an RCF was ever submitted for them
 * — and whether it stopped with the Vice Chairman, the Division Chairman or
 * Rodeo Houston — means sorting through months of RCF emails. So:
 *
 *   * **the list** opens on the caller's own forms, newest first, each with
 *     how many of its lines have gone where; an Executive Officer sees
 *     everybody else's below, because the Chairman is copied on every form
 *     and a Division Chairman forwards them;
 *   * **one form** is its lines, each with the three things this
 *     application never knew before — the Division Chairman's RCF NUMBER,
 *     the day the line went to the Division Chairman, the day it went to
 *     Rosters — and a fourth it derives rather than asks: whether the
 *     roster now shows the change;
 *   * **a member** is findable by name or number across every form, and the
 *     member card lists the forms about them.
 *
 * WHO MAY SEE WHAT. A form is visible to the account that generated it and
 * to anyone holding `view_all_forms` (Executive Officer and above,
 * Everywhere). Nobody else, and the answer for nobody else is null, which
 * the route turns into the 404 an out-of-scope member gets: this
 * application does not discuss what exists with people who cannot see it.
 * Whoever may see a form may track it — a Vice Chairman marks their own
 * form sent, a Division Chairman marks anybody's forwarded and numbered.
 *
 * WHAT IS DERIVED AND NEVER STORED. "The roster shows it" comes from
 * `import_change` at read time: an addition landed when the member's number
 * appears as created or returned after the form was made, a removal when it
 * appears as dropped, a title or team change when that field changed. A
 * stored flag would be somebody's opinion of what the roster says; the
 * roster says it itself, and it says it on exactly the day the import ran.
 *
 * WHAT IT NEVER WRITES. Nothing here touches `member`, `contact_log` or any
 * table an import owns, and nothing deletes a form or a line: a kept form is
 * a record. The only writes are the three tracking columns on `rcf_row`, and
 * every one of them goes to the audit log with before and after.
 */
final class RcfTracking
{
    /** The RCF number is free text — the Division Chairman's numbering is theirs. */
    public const SERIAL_MAX = 32;

    /** A search shares the roster's floor: three characters (spec 7.2). */
    public const SEARCH_MIN_CHARS = RosterPage::SEARCH_MIN_CHARS;

    /**
     * The two tracked steps, as the POST names them and the screen labels
     * them. Generated is the third step and is the form's own timestamp.
     *
     * @var array<string, array{0: string, 1: string}> key => [column, label]
     */
    public const STEPS = [
        'dc'      => ['sent_to_dc_on', 'Sent to the Division Chairman'],
        'rosters' => ['sent_to_rosters_on', 'Sent to Rosters'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly DateTimeZone $zone = new DateTimeZone('UTC'),
        private readonly int $pageSize = 100,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            $app->displayTimezone(),
            (int) $app->config()->get('roster.page_size_desktop', 100)
        );
    }

    /**
     * May this user see (and therefore track) a form this account made?
     * The whole visibility rule, in one place, so the list, the form, the
     * search and the member card cannot disagree about it.
     */
    public static function mayView(User $user, int $generatedBy): bool
    {
        return $generatedBy === $user->id || Access::mayUse($user, Capability::ViewAllForms);
    }

    // -----------------------------------------------------------------------
    // The list
    // -----------------------------------------------------------------------

    /**
     * The caller's own forms, newest first, each with its progress. Not
     * paged: an officer makes a handful a year, and a page of one's own
     * forms with a "next" on it is a page that hides the one being looked
     * for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mine(User $user): array
    {
        $read = $this->pdo->prepare(
            $this->formSelect() . ' WHERE f.generated_by = :me ORDER BY f.id DESC'
        );
        $read->execute([':me' => $user->id]);

        return $this->summarise($read->fetchAll());
    }

    /**
     * Everybody ELSE's forms, newest first, paged — for a holder of
     * `view_all_forms`, and null for anyone else. Own forms are not
     * repeated here: they are the group above.
     *
     * @return ?array{forms: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function others(User $user, int $page = 1): ?array
    {
        if (!Access::mayUse($user, Capability::ViewAllForms)) {
            return null;
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM rcf f WHERE f.generated_by <> :me');
        $count->execute([':me' => $user->id]);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $this->pageSize));
        $page  = min(max(1, $page), $pages);

        $read = $this->pdo->prepare(
            $this->formSelect() . ' WHERE f.generated_by <> :me ORDER BY f.id DESC'
            . ' LIMIT ' . $this->pageSize . ' OFFSET ' . (($page - 1) * $this->pageSize)
        );
        $read->execute([':me' => $user->id]);

        return [
            'forms' => $this->summarise($read->fetchAll()),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Lines naming a member, by name or number, across every form the
     * caller may see. A term is words and every word has to land (Phase
     * 10.3), over the printed name and the number — the same rule as the
     * roster's search, on the columns this table has.
     *
     * @return array{q: string, too_short: bool, matches: array<int, array<string, mixed>>}
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) < self::SEARCH_MIN_CHARS) {
            return ['q' => $term, 'too_short' => $term !== '', 'matches' => []];
        }

        $parts = [];
        $bind  = [
            ':me'  => $user->id,
            ':all' => Access::mayUse($user, Capability::ViewAllForms) ? 1 : 0,
        ];
        foreach (RosterPage::searchTokens($term) as $i => $token) {
            $like            = '%' . RosterPage::escapeLike($token) . '%';
            $parts[]         = "(r.member_name LIKE :w{$i}n ESCAPE '\\\\' OR r.member_number LIKE :w{$i}m ESCAPE '\\\\')";
            $bind[":w{$i}n"] = $like;
            $bind[":w{$i}m"] = $like;
        }

        $read = $this->pdo->prepare(
            $this->rowSelect()
            . ' WHERE (f.generated_by = :me OR :all = 1) AND ' . implode(' AND ', $parts)
            . ' ORDER BY f.id DESC, r.position LIMIT 200'
        );
        $read->execute($bind);

        return ['q' => $term, 'too_short' => false, 'matches' => $this->lines($read->fetchAll(), $user)];
    }

    /**
     * Every form this member is on, for the member card, newest first:
     * matched by the id the number resolved to at generation, or by the
     * number itself for a line kept before they had a row. The lines are
     * shown to anyone who can see the member — a Captain reading that the
     * Vice Chairman sent a form about one of their people is the point —
     * and each carries whether the CALLER may open the form it is on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forMember(User $user, int $memberId, string $memberNumber): array
    {
        $read = $this->pdo->prepare(
            $this->rowSelect()
            . ' WHERE r.member_id = :id OR (r.member_number <> \'\' AND r.member_number = :number)'
            . ' ORDER BY f.id DESC, r.position'
        );
        $read->execute([':id' => $memberId, ':number' => $memberNumber]);

        return $this->lines($read->fetchAll(), $user);
    }

    // -----------------------------------------------------------------------
    // One form
    // -----------------------------------------------------------------------

    /**
     * One form and its lines, or null when the caller may not see it — or
     * there is no such form, which is the same answer on purpose.
     *
     * @return ?array{form: array<string, mixed>, rows: array<int, array<string, mixed>>}
     */
    public function one(User $user, int $rcfId): ?array
    {
        if ($rcfId <= 0) {
            return null;
        }

        $read = $this->pdo->prepare($this->formSelect() . ' WHERE f.id = :id');
        $read->execute([':id' => $rcfId]);
        $form = $read->fetch();
        if (!is_array($form) || !self::mayView($user, (int) $form['generated_by'])) {
            return null;
        }

        $rows = $this->pdo->prepare($this->rowSelect() . ' WHERE r.rcf_id = :id ORDER BY r.position');
        $rows->execute([':id' => $rcfId]);

        return [
            'form' => $this->summarise([$form])[0],
            'rows' => $this->lines($rows->fetchAll(), $user),
        ];
    }

    /**
     * The tracking form's save: the RCF number and the two dates, per line,
     * with a whole-form row that wins over the lines below it.
     *
     * THE RULE, stated once here and once on the screen: a value in the
     * "every row" row is written to every line; otherwise each line's own
     * field is written as it came back, and a date box left empty clears
     * the date. That is what lets one save mark a whole form sent — the
     * ordinary case, a Vice Chairman emailing the form to the Division
     * Chairman — and still let one line be the exception when the Division
     * Chairman split a form across two numbered ones of their own.
     *
     * Each line is looked up by ITS OWN id among the form's lines, so a POST
     * naming a line on another form writes nothing. Returns how many lines
     * changed, or null when the caller may not see the form.
     *
     * @param array<string, mixed> $input the raw POST, untrusted
     */
    public function track(User $user, int $rcfId, array $input): ?int
    {
        $rows = $this->rowsForWrite($user, $rcfId);
        if ($rows === null) {
            return null;
        }

        $typed = is_array($input['track'] ?? null) ? $input['track'] : [];
        $all   = is_array($typed['all'] ?? null) ? $typed['all'] : [];

        $allSerial  = self::serial((string) ($all['serial'] ?? ''));
        $allDates   = [];
        foreach (self::STEPS as $key => [$column]) {
            $allDates[$column] = self::isoDate((string) ($all[$key] ?? ''));
        }

        $changes = [];
        foreach ($rows as $id => $row) {
            $own = is_array($typed[$id] ?? null) ? $typed[$id] : [];
            $new = [];

            $new['serial'] = $allSerial !== ''
                ? $allSerial
                : (array_key_exists('serial', $own) ? self::serial((string) $own['serial']) : (string) $row['serial']);

            foreach (self::STEPS as $key => [$column]) {
                if ($allDates[$column] !== null) {
                    $new[$column] = $allDates[$column];
                } elseif (array_key_exists($key, $own)) {
                    // Empty clears; a value that is not a date keeps what
                    // was there, because a browser's date box submits a
                    // date or nothing and anything else is not a request.
                    $value         = trim((string) $own[$key]);
                    $new[$column] = $value === '' ? null : (self::isoDate($value) ?? $row[$column]);
                } else {
                    $new[$column] = $row[$column];
                }
            }

            $diff = [];
            foreach ($new as $column => $value) {
                if ($value !== $row[$column]) {
                    $diff[$column] = $value;
                }
            }
            if ($diff !== []) {
                $changes[$id] = $diff;
            }
        }

        return $this->write($user, $rcfId, $rows, $changes);
    }

    /**
     * The one-tap case: every line that has not yet been marked as sent on
     * this step is marked sent TODAY, in Houston. Lines already carrying a
     * date keep it — a button that overwrote the day something really
     * happened would be a button nobody could press twice safely. Returns
     * how many lines changed, or null when the caller may not see the form.
     */
    public function markToday(User $user, int $rcfId, string $step): ?int
    {
        if (!isset(self::STEPS[$step])) {
            return null;
        }
        [$column] = self::STEPS[$step];

        $rows = $this->rowsForWrite($user, $rcfId);
        if ($rows === null) {
            return null;
        }

        $today   = (new DateTimeImmutable('today', $this->zone))->format('Y-m-d');
        $changes = [];
        foreach ($rows as $id => $row) {
            if ($row[$column] === null) {
                $changes[$id] = [$column => $today];
            }
        }

        return $this->write($user, $rcfId, $rows, $changes);
    }

    // -----------------------------------------------------------------------
    // The derived fourth step: the roster shows it
    // -----------------------------------------------------------------------

    /**
     * Which of these lines the roster has since shown, and when: line id =>
     * the earliest `import_change` row after the form was generated that
     * says what the line asked for. One query for however many lines.
     *
     *   A       created or returned — they are on the roster now
     *   R       dropped — the file stopped listing them
     *   T       the title changed
     *   S       the team changed
     *   S & T   either of those two
     *
     * A line with no member number cannot be matched and is never "landed":
     * an addition typed in by name alone has nothing for the import to be
     * filed under yet.
     *
     * @param array<int, int> $rowIds
     * @return array<int, array{at: string, batch: int}>
     */
    public function landed(array $rowIds): array
    {
        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));
        if ($rowIds === []) {
            return [];
        }

        $places = [];
        $bind   = [];
        foreach ($rowIds as $i => $id) {
            $places[]        = ":r{$i}";
            $bind[":r{$i}"] = $id;
        }

        $read = $this->pdo->prepare(
            'SELECT r.id AS row_id, c.occurred_at, c.import_batch_id'
            . ' FROM rcf_row r'
            . ' INNER JOIN rcf f ON f.id = r.rcf_id'
            . ' INNER JOIN import_change c ON c.member_number = r.member_number'
            . '   AND c.occurred_at >= f.generated_at'
            . '   AND ('
            . "        (r.type = 'A' AND c.kind IN ('created', 'returned'))"
            . "     OR (r.type = 'R' AND c.kind = 'dropped')"
            . "     OR (r.type = 'T' AND c.kind = 'updated' AND c.field = 'title')"
            . "     OR (r.type = 'S' AND c.kind = 'updated' AND c.field = 'team')"
            . "     OR (r.type = 'S & T' AND c.kind = 'updated' AND c.field IN ('team', 'title'))"
            . '   )'
            . ' WHERE r.id IN (' . implode(', ', $places) . ") AND r.member_number <> ''"
            . ' ORDER BY r.id, c.occurred_at, c.id'
        );
        $read->execute($bind);

        $landed = [];
        foreach ($read->fetchAll() as $row) {
            $id = (int) $row['row_id'];
            // The FIRST match per line is the day it landed; later ones are
            // the roster continuing to change, which is not this line's news.
            $landed[$id] ??= ['at' => (string) $row['occurred_at'], 'batch' => (int) $row['import_batch_id']];
        }

        return $landed;
    }

    // -----------------------------------------------------------------------
    // Shapes
    // -----------------------------------------------------------------------

    /** The form columns every list reads, with who generated it. */
    private function formSelect(): string
    {
        return 'SELECT f.id, f.show_year_id, f.generated_by, f.generated_at, f.year_label, f.submitter,'
            . ' f.submitter_number, f.form_date, f.subcommittee, f.division_id, f.team_id, f.row_count,'
            . ' f.regenerated_count, f.last_regenerated_at,'
            . ' gm.preferred_name AS g_preferred, gm.first_name AS g_first, gm.last_name AS g_last,'
            . ' gm.member_number AS g_number'
            . ' FROM rcf f'
            . ' INNER JOIN app_user gu ON gu.id = f.generated_by'
            . ' INNER JOIN member gm ON gm.id = gu.member_id';
    }

    /** The line columns, with their form's header and who last tracked them. */
    private function rowSelect(): string
    {
        return 'SELECT r.id, r.rcf_id, r.position, r.member_id, r.member_number, r.member_name, r.type,'
            . ' r.rookie, r.new_title, r.previous_title, r.wait_list, r.remove_reason,'
            . ' r.new_subcommittee, r.sponsor, r.serial, r.sent_to_dc_on, r.sent_to_rosters_on,'
            . ' r.tracked_at,'
            . ' f.generated_by, f.generated_at, f.subcommittee, f.year_label, f.submitter,'
            . ' gm.preferred_name AS g_preferred, gm.first_name AS g_first, gm.last_name AS g_last,'
            . ' gm.member_number AS g_number,'
            . ' tm.preferred_name AS t_preferred, tm.first_name AS t_first, tm.last_name AS t_last,'
            . ' tm.member_number AS t_number'
            . ' FROM rcf_row r'
            . ' INNER JOIN rcf f ON f.id = r.rcf_id'
            . ' INNER JOIN app_user gu ON gu.id = f.generated_by'
            . ' INNER JOIN member gm ON gm.id = gu.member_id'
            . ' LEFT JOIN app_user tu ON tu.id = r.tracked_by'
            . ' LEFT JOIN member tm ON tm.id = tu.member_id';
    }

    /**
     * Forms as the list shows them: the header, the generator's name, and
     * how many lines have gone where — counted from the lines and from
     * `landed()`, in two queries for the whole page rather than two per form.
     *
     * @param array<int, array<string, mixed>> $forms raw formSelect() rows
     * @return array<int, array<string, mixed>>
     */
    private function summarise(array $forms): array
    {
        if ($forms === []) {
            return [];
        }

        $ids    = array_map(static fn (array $f): int => (int) $f['id'], $forms);
        $places = [];
        $bind   = [];
        foreach ($ids as $i => $id) {
            $places[]        = ":f{$i}";
            $bind[":f{$i}"] = $id;
        }
        $in = '(' . implode(', ', $places) . ')';

        $read = $this->pdo->prepare(
            'SELECT rcf_id, id, serial, sent_to_dc_on, sent_to_rosters_on'
            . " FROM rcf_row WHERE rcf_id IN {$in} ORDER BY rcf_id, position"
        );
        $read->execute($bind);
        $lines = $read->fetchAll();

        $landed = $this->landed(array_map(static fn (array $l): int => (int) $l['id'], $lines));

        $progress = [];
        foreach ($lines as $line) {
            $rcfId = (int) $line['rcf_id'];
            $p     = &$progress[$rcfId];
            $p ??= ['rows' => 0, 'numbered' => 0, 'dc' => 0, 'rosters' => 0, 'landed' => 0, 'serials' => []];
            $p['rows']++;
            if ((string) $line['serial'] !== '') {
                $p['numbered']++;
                $p['serials'][(string) $line['serial']] = true;
            }
            if ($line['sent_to_dc_on'] !== null) {
                $p['dc']++;
            }
            if ($line['sent_to_rosters_on'] !== null) {
                $p['rosters']++;
            }
            if (isset($landed[(int) $line['id']])) {
                $p['landed']++;
            }
            unset($p);
        }

        $out = [];
        foreach ($forms as $form) {
            $id = (int) $form['id'];
            $p  = $progress[$id] ?? ['rows' => 0, 'numbered' => 0, 'dc' => 0, 'rosters' => 0, 'landed' => 0, 'serials' => []];

            $out[] = [
                'id'                  => $id,
                'show_year_id'        => (int) $form['show_year_id'],
                'generated_by'        => (int) $form['generated_by'],
                'generated_at'        => (string) $form['generated_at'],
                'generator_name'      => RosterPage::displayName(
                    (string) $form['g_preferred'],
                    (string) $form['g_first'],
                    (string) $form['g_last'],
                    (string) $form['g_number']
                ),
                'year_label'          => (string) $form['year_label'],
                'submitter'           => (string) $form['submitter'],
                'submitter_number'    => (string) $form['submitter_number'],
                'form_date'           => $form['form_date'] === null ? '' : (string) $form['form_date'],
                'subcommittee'        => (string) $form['subcommittee'],
                'division_id'         => $form['division_id'] === null ? null : (int) $form['division_id'],
                'team_id'             => $form['team_id'] === null ? null : (int) $form['team_id'],
                'row_count'           => (int) $form['row_count'],
                'regenerated_count'   => (int) $form['regenerated_count'],
                'last_regenerated_at' => $form['last_regenerated_at'] === null ? null : (string) $form['last_regenerated_at'],
                'rows'                => $p['rows'],
                'numbered'            => $p['numbered'],
                'dc'                  => $p['dc'],
                'rosters'             => $p['rosters'],
                'landed'              => $p['landed'],
                'serials'             => array_keys($p['serials']),
            ];
        }

        return $out;
    }

    /**
     * Lines as the screens show them: the cells, the codes spelled out, a
     * one-line summary of the change, the tracking and whether the roster
     * shows it — plus whether THIS caller may open the form the line is on.
     *
     * @param array<int, array<string, mixed>> $rows raw rowSelect() rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(array $rows, User $user): array
    {
        if ($rows === []) {
            return [];
        }

        $landed = $this->landed(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $out = [];
        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $type = (string) $row['type'];

            $out[] = [
                'id'                 => $id,
                'rcf_id'             => (int) $row['rcf_id'],
                'position'           => (int) $row['position'],
                'member_id'          => $row['member_id'] === null ? null : (int) $row['member_id'],
                'member_number'      => (string) $row['member_number'],
                'member_name'        => (string) $row['member_name'],
                'type'               => $type,
                'type_label'         => RosterChangeForm::TYPES[$type] ?? $type,
                'rookie'             => (int) $row['rookie'] === 1,
                'new_title'          => (string) $row['new_title'],
                'previous_title'     => (string) $row['previous_title'],
                'wait_list'          => (int) $row['wait_list'] === 1,
                'remove_reason'      => (string) $row['remove_reason'],
                'reason_label'       => RosterChangeForm::REMOVE_REASONS[(string) $row['remove_reason']] ?? '',
                'new_subcommittee'   => (string) $row['new_subcommittee'],
                'sponsor'            => (string) $row['sponsor'],
                'change'             => self::changeSummary($row),
                'serial'             => (string) $row['serial'],
                'sent_to_dc_on'      => $row['sent_to_dc_on'] === null ? null : (string) $row['sent_to_dc_on'],
                'sent_to_rosters_on' => $row['sent_to_rosters_on'] === null ? null : (string) $row['sent_to_rosters_on'],
                'tracked_at'         => $row['tracked_at'] === null ? null : (string) $row['tracked_at'],
                'tracked_by_name'    => $row['t_number'] === null ? '' : RosterPage::displayName(
                    (string) $row['t_preferred'],
                    (string) $row['t_first'],
                    (string) $row['t_last'],
                    (string) $row['t_number']
                ),
                'landed'             => $landed[$id] ?? null,
                // The form the line is on, for the search and the member card.
                'generated_by'       => (int) $row['generated_by'],
                'generated_at'       => (string) $row['generated_at'],
                'generator_name'     => RosterPage::displayName(
                    (string) $row['g_preferred'],
                    (string) $row['g_first'],
                    (string) $row['g_last'],
                    (string) $row['g_number']
                ),
                'subcommittee'       => (string) $row['subcommittee'],
                'year_label'         => (string) $row['year_label'],
                'viewable'           => self::mayView($user, (int) $row['generated_by']),
            ];
        }

        return $out;
    }

    /**
     * What a line asks for, in one clause the list can print: the code's
     * meaning and the cell that qualifies it. PLAIN text; the view escapes.
     *
     * @param array<string, mixed> $row
     */
    public static function changeSummary(array $row): string
    {
        $type   = (string) $row['type'];
        $word   = RosterChangeForm::TYPES[$type] ?? ($type === '' ? 'No type' : $type);
        $detail = match ($type) {
            'A'     => trim((string) $row['new_title']),
            'R'     => (string) $row['remove_reason'] === ''
                ? ''
                : (RosterChangeForm::REMOVE_REASONS[(string) $row['remove_reason']] ?? (string) $row['remove_reason']),
            'T'     => trim(trim((string) $row['previous_title']) . ' → ' . trim((string) $row['new_title']), ' →'),
            'S'     => trim((string) $row['new_subcommittee']),
            'S & T' => trim(
                trim(trim((string) $row['new_subcommittee']) . ', ' . trim((string) $row['new_title']), ', ')
            ),
            default => '',
        };

        return $detail === '' ? $word : $word . ' — ' . $detail;
    }

    // -----------------------------------------------------------------------
    // The write
    // -----------------------------------------------------------------------

    /**
     * The form's lines keyed by their own id, with the three tracking
     * columns as stored, for a caller who may see the form — else null.
     *
     * @return ?array<int, array{position: int, serial: string, sent_to_dc_on: ?string, sent_to_rosters_on: ?string}>
     */
    private function rowsForWrite(User $user, int $rcfId): ?array
    {
        if ($rcfId <= 0) {
            return null;
        }

        $read = $this->pdo->prepare('SELECT generated_by FROM rcf WHERE id = :id');
        $read->execute([':id' => $rcfId]);
        $by = $read->fetchColumn();
        if ($by === false || !self::mayView($user, (int) $by)) {
            return null;
        }

        $lines = $this->pdo->prepare(
            'SELECT id, position, serial, sent_to_dc_on, sent_to_rosters_on FROM rcf_row'
            . ' WHERE rcf_id = :id ORDER BY position'
        );
        $lines->execute([':id' => $rcfId]);

        $rows = [];
        foreach ($lines->fetchAll() as $line) {
            $rows[(int) $line['id']] = [
                'position'           => (int) $line['position'],
                'serial'             => (string) $line['serial'],
                'sent_to_dc_on'      => $line['sent_to_dc_on'] === null ? null : (string) $line['sent_to_dc_on'],
                'sent_to_rosters_on' => $line['sent_to_rosters_on'] === null ? null : (string) $line['sent_to_rosters_on'],
            ];
        }

        return $rows;
    }

    /**
     * Writes the changed lines and one audit row saying what changed on
     * which, before and after. Nothing changed writes nothing — not even
     * the audit row — so a Save pressed twice is one record.
     *
     * @param array<int, array<string, mixed>> $rows    rowsForWrite()'s answer
     * @param array<int, array<string, mixed>> $changes line id => column => new value
     */
    private function write(User $user, int $rcfId, array $rows, array $changes): int
    {
        if ($changes === []) {
            return 0;
        }

        $before = [];
        $after  = [];

        $this->pdo->beginTransaction();

        try {
            $update = $this->pdo->prepare(
                'UPDATE rcf_row SET serial = :serial, sent_to_dc_on = :dc, sent_to_rosters_on = :rosters,'
                . ' tracked_by = :by, tracked_at = UTC_TIMESTAMP()'
                . ' WHERE id = :id AND rcf_id = :rcf'
            );

            foreach ($changes as $id => $diff) {
                $row = $rows[$id];
                $new = $diff + $row;

                $update->execute([
                    ':serial'  => $new['serial'],
                    ':dc'      => $new['sent_to_dc_on'],
                    ':rosters' => $new['sent_to_rosters_on'],
                    ':by'      => $user->id,
                    ':id'      => $id,
                    ':rcf'     => $rcfId,
                ]);

                $position = (string) $row['position'];
                foreach ($diff as $column => $value) {
                    $before[$position][$column] = $row[$column];
                    $after[$position][$column]  = $value;
                }
            }

            (new AuditLog($this->pdo))->record(
                $user,
                Action::TrackForm,
                'rcf',
                (string) $rcfId,
                ['rows' => $before],
                ['rows' => $after]
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return count($changes);
    }

    /** An RCF number: collapsed, trimmed, bounded. Free text otherwise. */
    private static function serial(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > self::SERIAL_MAX ? mb_substr($value, 0, self::SERIAL_MAX) : $value;
    }

    /** `YYYY-MM-DD` when it is one and a real day, else null. */
    private static function isoDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
