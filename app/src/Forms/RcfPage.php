<?php

declare(strict_types=1);

namespace Rerm\Forms;

use DateTimeImmutable;
use PDO;
use Rerm\App;
use Rerm\Auth\User;
use Rerm\Roster\EligibleOfficers;
use Rerm\Roster\RosterPage;
use Rerm\Roster\ScopedQuery;

/**
 * What the Roster Change Form screen offers, and what it makes of what comes
 * back (spec-v2 §2.2).
 *
 * `RosterChangeForm` draws the file; this decides what may go on it. The
 * split matters because the three lists below are the whole permission
 * surface of the feature, and they are three different answers on purpose:
 *
 * **Members are SCOPED, like every other member read in this application.**
 * The picker offers `ScopedQuery::forUser()` intersected with the chosen
 * sub-committee, so an Officer filling in a form sees their own team and a
 * Division Chairman sees their division. Nothing about producing a form is a
 * reason to widen who can enumerate 1,954 people's names and member numbers,
 * and the free-text half of the same control (below) already covers the
 * member who is not in the list.
 *
 * **Sub-committees are the whole committee, because a transfer has to be able
 * to name its destination.** A team list is not personal data — it is 96
 * names Rodeo Houston publishes — and "S = Sub-Committee Change" means moving
 * somebody to a team that is by definition not the one the form is about.
 * Only teams and divisions that actually hold somebody are offered: a list
 * that includes an empty team is a list that invites a move to nowhere.
 *
 * **Officers are the whole committee, deliberately.** The submitter defaults
 * to whoever is signed in and may be changed to any officer, and the sponsor
 * for a new recruit "must be a VC or higher" — which is frequently somebody
 * outside the submitter's own team, so a scoped list could not answer it.
 * What is exposed is a name, a title and a team, and nothing else: no
 * address, no phone number, no email, no compliance status. The title travels
 * with the name so that the "VC or higher" rule can be checked by eye.
 *
 * **`(No Division)` is never offered and never printed.** It is this
 * application's bookkeeping for the 72 members who arrive with a blank
 * `Subcommittee 3`, and the rule that it must not travel back to Rodeo
 * Houston as though it were their data (CLAUDE.md; spec §5.1a rule 2) applies
 * to a form at least as much as to the export. Its TEAMS are real and are
 * offered, under their own names, with nothing prefixed.
 */
final class RcfPage
{
    /**
     * A member reference as one control: `1234567 - Jane Smith`.
     *
     * The form has two columns, HLS&R NO and MEMBER NAME, and the screen has
     * one field, because the job is one decision. Picking somebody out of the
     * list and typing somebody who is not in it are the same gesture — which
     * is the point: an addition is a person "from the ether that is
     * membership", who by definition has no row here yet.
     *
     * Read forgivingly, in both orders, because a person typing a new recruit
     * is not copying a format: number first, name first, or either alone.
     */
    private const NUMBER_FIRST = '/^(\S+)\s*[-\x{2010}-\x{2015}]\s*(.+)$/u';
    private const NAME_FIRST   = '/^(.+?)\s*[-\x{2010}-\x{2015}]\s*(\S+)$/u';

    /**
     * What a member number looks like: unbroken, at least four characters,
     * letters and digits only, and carrying AT LEAST ONE DIGIT.
     *
     * Not `\d{6,7}`, though every real one is. `member_number` is
     * `VARCHAR(32)` because it is an identifier and never arithmetic — the
     * seeded master administrator is `987654321`, and leading zeros have to
     * survive a round trip (CLAUDE.md). The digit requirement is what keeps
     * "Jane Sample-Smith" a name: no part of it is a number, so the whole of
     * it is the name.
     */
    private const NUMBERISH = '/^[A-Za-z0-9]{4,32}$/';

    /**
     * The cap on the member picker, MEASURED rather than guessed: 300 people
     * is a ~16KB datalist, and the largest real division is 675, which is
     * ~36KB — a third of spec §10's whole 100KB first-paint budget for one
     * control. Past the cap the list is dropped and the field stays a text
     * box that still accepts anything, so the feature degrades to "type it
     * in" — which is a thing it can already do — rather than to a page that
     * will not load in a parking lot.
     *
     * A team, which is what an RCF is normally about, is twenty people.
     */
    public const PICKER_LIMIT = 300;

    /**
     * How many of the form's twenty-five rows the screen draws to begin with,
     * and how many more each press of the button adds.
     *
     * The paper form has twenty-five rows and so does the file; the SCREEN
     * does not have to, and drawing all of them costs ~45KB of controls
     * against a 100KB budget for a page that usually carries three names. A
     * row that was never drawn submits nothing and prints blank, which is
     * exactly what an untouched row on the paper form does.
     *
     * The count never shrinks below what is already filled in: a row with
     * somebody on it cannot be hidden by a button, or their change would be
     * on the file with no way to see it.
     */
    public const VISIBLE_ROWS = 5;
    public const VISIBLE_STEP = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * Everything the screen needs.
     *
     * @param array<string, mixed> $input the raw query string or POST, untrusted
     * @return array<string, mixed>
     */
    public function page(User $user, array $input): array
    {
        $subcommittees = $this->subcommittees();
        $selected      = self::pick($subcommittees, (string) ($input['subcommittee'] ?? ''));

        $officers = $this->officers();
        $entries  = self::entries($input);

        return [
            'year'          => $this->showYearLabel(),
            'officers'      => $officers,
            'submitter'     => self::pickOfficer($officers, (string) ($input['submitter'] ?? ''), $user),
            'date'          => self::date((string) ($input['date'] ?? '')),
            'subcommittees' => $subcommittees,
            'subcommittee'  => $selected,
            'members'       => $selected === null ? [] : $this->membersFor($user, $selected),
            'types'         => RosterChangeForm::TYPES,
            'reasons'       => RosterChangeForm::REMOVE_REASONS,
            'rows'          => RosterChangeForm::ROWS,
            'visible_rows'  => self::visibleRows($input, $entries),
            'entries'       => $entries,
        ];
    }

    /**
     * The submitted screen as the arguments `RosterChangeForm::build()`
     * takes.
     *
     * Nothing a person typed reaches the file unresolved: the sub-committee
     * and the submitter are looked up by key in the lists this class built,
     * so a POST naming a team that is not offered produces no sub-committee
     * rather than a sub-committee of the caller's choosing.
     *
     * @param array<string, mixed> $input
     * @return array{year: string, submitter: string, date: string, subcommittee: string,
     *     entries: array<int, array<string, string>>}
     */
    public function formFromInput(User $user, array $input): array
    {
        $page = $this->page($user, $input);

        /** @var array<string, mixed>|null $subcommittee */
        $subcommittee = $page['subcommittee'];

        $entries = [];
        foreach ($page['entries'] as $entry) {
            $entries[] = $this->entry($user, $entry, $page['subcommittees']);
        }

        return [
            'year'         => (string) $page['year'],
            'submitter'    => $page['submitter'] === null
                ? ''
                : (string) $page['submitter']['form_name'],
            'date'         => self::american((string) $page['date']),
            'subcommittee' => $subcommittee === null ? '' : (string) $subcommittee['form_label'],
            'entries'      => $entries,
        ];
    }

    /**
     * One submitted row, resolved.
     *
     * Two things are filled in FOR the officer rather than asked of them, and
     * both are things this application already knows:
     *
     *   * the member's name, when they gave only a number that is in scope;
     *   * their PREVIOUS TITLE, when they left it blank — a title change is a
     *     request to replace a title we are already holding, and making
     *     somebody retype it is how it ends up disagreeing with the roster.
     *
     * Anything typed always wins. The lookup is through the caller's own
     * scope, so it can only ever fill in a member they could already read.
     *
     * @param array<string, string> $entry
     * @param array<int, array<string, mixed>> $subcommittees
     * @return array<string, string>
     */
    private function entry(User $user, array $entry, array $subcommittees): array
    {
        $resolved = RosterChangeForm::emptyEntry();

        [$number, $name] = self::parseMember((string) ($entry['member'] ?? ''));

        $known = $number === '' ? null : $this->memberInScope($user, $number);

        $resolved['type']          = self::oneOf((string) ($entry['type'] ?? ''), array_keys(RosterChangeForm::TYPES));
        $resolved['rookie']        = self::oneOf((string) ($entry['rookie'] ?? ''), RosterChangeForm::ROOKIE);
        $resolved['member_number'] = $number;
        $resolved['member_name']   = $name !== '' ? $name : (string) ($known['form_name'] ?? '');
        $resolved['new_title']     = self::text((string) ($entry['new_title'] ?? ''));
        $resolved['previous_title'] = self::text((string) ($entry['previous_title'] ?? ''));
        $resolved['remove_reason'] = self::oneOf(
            (string) ($entry['remove_reason'] ?? ''),
            array_keys(RosterChangeForm::REMOVE_REASONS)
        );

        if ($resolved['previous_title'] === '' && $known !== null) {
            $resolved['previous_title'] = (string) $known['title'];
        }

        // The destination team, matched against the same list the screen
        // offered. A row's NEW SUB-COMMITTEE column is thirty characters wide
        // and does not wrap, so it carries the team's OWN name — which is
        // also exactly what Rodeo Houston's `Subcommittee 1` column holds. The
        // division belongs on the heading at the top of the form, where there
        // is room for it.
        //
        // Matched by LABEL rather than by key because this is one control
        // repeated twenty-five times: a <select> of a hundred teams, drawn
        // twenty-five times over, is 150KB of options on a phone with a
        // 100KB budget (spec §10). A <datalist> is emitted once and shared.
        // A value that matches nothing is kept as typed rather than dropped:
        // a datalist is a text box wearing a list, an older browser shows
        // only the text box, and silently discarding what somebody wrote into
        // it is how a member gets left off a form.
        $resolved['new_subcommittee'] = self::matchLabel(
            $subcommittees,
            (string) ($entry['new_subcommittee'] ?? '')
        );

        $resolved['sponsor'] = self::text((string) ($entry['sponsor'] ?? ''));

        // WAIT LIST is a tick box on the screen and Yes/No on the paper, and
        // an unticked box submits NOTHING — so an untouched row would collect
        // a "No" and stop being untouched. The answer is only written once
        // the row says something else too: a blank row prints blank, exactly
        // as it does on the form Rodeo Houston sends out.
        $resolved['wait_list'] = '';

        if (!RosterChangeForm::entryIsBlank($resolved)) {
            $resolved['wait_list'] = (string) ($entry['wait_list'] ?? '') === 'Yes' ? 'Yes' : 'No';
        }

        return $resolved;
    }

    /**
     * Every officer on the committee: name, title and team, and nothing else.
     *
     * "Officer" is `EligibleOfficers`' own definition — a visible member
     * whose EFFECTIVE level (`granted_level ?? title_level`) is Officer or
     * above — read through that class so this list and the assignment picker
     * cannot disagree about who is one. The rank comparison happens in PHP:
     * the level column is an ENUM declared low to high, so `>= 'officer'` in
     * SQL would sort 'admin' below it and the Chairman would stop being an
     * officer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function officers(): array
    {
        [$in, $bind] = EligibleOfficers::levelIn('rcf');

        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.full_name, m.title, t.name AS team_name'
            . ' FROM member m'
            . ' LEFT JOIN app_user u ON u.member_id = m.id'
            . ' LEFT JOIN team t ON t.id = m.team_id'
            . ' WHERE ' . ScopedQuery::visible('m')
            . ' AND ' . EligibleOfficers::effectiveLevel('m', 'u') . " IN {$in}"
            . ' ORDER BY m.last_name, m.first_name, m.member_number'
        );
        $read->execute($bind);

        $officers = [];
        foreach ($read->fetchAll() as $row) {
            $officers[] = [
                'member_number' => (string) $row['member_number'],
                'name'          => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'title'     => (string) $row['title'],
                'team_name' => (string) ($row['team_name'] ?? ''),

                // What goes on the paper: "Name & Title of whom is submitting
                // this form" asks for both, so both are written.
                'form_name' => trim(self::formalName($row) . ', ' . (string) $row['title'], ', '),
            ];
        }

        return $officers;
    }

    /**
     * Every division and team that actually holds somebody, as one list the
     * screen groups and the writer prints.
     *
     * `is_placeholder` divisions are not offered as sub-committees — see the
     * class comment — but their teams are, ungrouped and unprefixed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function subcommittees(): array
    {
        $visible = ScopedQuery::visible('m');

        $divisions = $this->pdo->query(
            'SELECT d.id, d.name, d.is_placeholder, COUNT(m.id) AS members'
            . ' FROM division d'
            . " INNER JOIN member m ON m.division_id = d.id AND {$visible}"
            . ' GROUP BY d.id, d.name, d.is_placeholder'
            . ' ORDER BY d.name'
        )->fetchAll();

        $teams = $this->pdo->query(
            'SELECT t.id, t.name, d.name AS division_name, d.is_placeholder, COUNT(m.id) AS members'
            . ' FROM team t'
            . " INNER JOIN member m ON m.team_id = t.id AND {$visible}"
            . ' LEFT JOIN division d ON d.id = t.division_id'
            . ' GROUP BY t.id, t.name, d.name, d.is_placeholder'
            . ' ORDER BY t.name'
        )->fetchAll();

        $options = [];

        foreach ($divisions as $division) {
            if ((int) $division['is_placeholder'] === 1) {
                continue;
            }

            $options[] = [
                'key'        => 'd:' . (int) $division['id'],
                'group'      => (string) $division['name'],
                'name'       => (string) $division['name'],
                'label'      => (string) $division['name'],
                'form_label' => (string) $division['name'],
                'members'    => (int) $division['members'],
                'division_id' => (int) $division['id'],
                'team_id'    => null,
            ];
        }

        foreach ($teams as $team) {
            $division = (int) $team['is_placeholder'] === 1 ? '' : (string) ($team['division_name'] ?? '');

            $options[] = [
                'key'   => 't:' . (int) $team['id'],
                'group' => $division !== '' ? $division : (string) ($team['division_name'] ?? ''),
                'name'  => (string) $team['name'],

                // The team, prefixed by its division, which is how a list of
                // 96 becomes findable. A team filed under the placeholder
                // carries no prefix at all: that name is ours, and a form
                // that showed it would be showing Rodeo Houston their own
                // roster with our bookkeeping written into it.
                'label' => $division !== ''
                    ? $division . ' - ' . (string) $team['name']
                    : (string) $team['name'],
                'form_label' => $division !== ''
                    ? $division . ' - ' . (string) $team['name']
                    : (string) $team['name'],
                'members'     => (int) $team['members'],
                'division_id' => null,
                'team_id'     => (int) $team['id'],
            ];
        }

        return $options;
    }

    /**
     * The members the picker offers: this caller's scope, narrowed to the
     * sub-committee the form is about.
     *
     * @param array<string, mixed> $subcommittee
     * @return array<int, array<string, string>>
     */
    public function membersFor(User $user, array $subcommittee): array
    {
        $scoped = ScopedQuery::forUser($user);
        $where  = $scoped->predicate();
        $bind   = $scoped->bindings();

        if ($subcommittee['team_id'] !== null) {
            $where .= ' AND m.team_id = :rcf_team';
            $bind[':rcf_team'] = (int) $subcommittee['team_id'];
        } else {
            $where .= ' AND m.division_id = :rcf_division';
            $bind[':rcf_division'] = (int) $subcommittee['division_id'];
        }

        $read = $this->pdo->prepare(
            'SELECT m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.full_name, m.title'
            . " FROM member m WHERE {$where}"
            . ' ORDER BY m.last_name, m.first_name, m.member_number'
            . ' LIMIT ' . (self::PICKER_LIMIT + 1)
        );
        $read->execute($bind);

        $rows = $read->fetchAll();

        // Past the cap the control degrades to a plain text box rather than
        // to a page that will not load. See PICKER_LIMIT.
        if (count($rows) > self::PICKER_LIMIT) {
            return [];
        }

        $members = [];
        foreach ($rows as $row) {
            $members[] = [
                'member_number' => (string) $row['member_number'],
                'form_name'     => self::formalName($row),
                'known_as'      => RosterPage::displayName(
                    (string) $row['preferred_name'],
                    (string) $row['first_name'],
                    (string) $row['last_name'],
                    (string) $row['member_number']
                ),
                'title'         => (string) $row['title'],
            ];
        }

        return $members;
    }

    /**
     * One member, by number, INSIDE the caller's scope — the lookup behind
     * the two fields that fill themselves in.
     *
     * Scoped rather than global on purpose. An officer typing a number
     * belonging to somebody they cannot see gets nothing filled in, which is
     * the same answer every other screen gives them, and the row still goes
     * on the form with what they typed.
     *
     * @return array<string, string>|null
     */
    public function memberInScope(User $user, string $number): ?array
    {
        $scoped = ScopedQuery::forUser($user);

        $read = $this->pdo->prepare(
            'SELECT m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.full_name, m.title'
            . ' FROM member m WHERE ' . $scoped->predicate()
            . ' AND m.member_number = :rcf_number LIMIT 1'
        );
        $read->execute($scoped->bindings() + [':rcf_number' => $number]);

        $row = $read->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'member_number' => (string) $row['member_number'],
            'form_name'     => self::formalName($row),
            'title'         => (string) $row['title'],
        ];
    }

    /**
     * `1234567 - Jane Smith`, in either order, or either half alone.
     *
     * @return array{0: string, 1: string} member number, member name
     */
    public static function parseMember(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

        if ($raw === '') {
            return ['', ''];
        }

        if (preg_match(self::NUMBER_FIRST, $raw, $match) === 1 && self::isNumberish($match[1])) {
            return [$match[1], self::text($match[2])];
        }

        if (preg_match(self::NAME_FIRST, $raw, $match) === 1 && self::isNumberish($match[2])) {
            return [$match[2], self::text($match[1])];
        }

        if (self::isNumberish($raw)) {
            return [$raw, ''];
        }

        // A name and nothing else. The form still takes it: HLS&R NO is a
        // column somebody at Rodeo Houston can fill in, and refusing the row
        // would lose the request.
        return ['', self::text($raw)];
    }

    /** Could this token be a member number? See NUMBERISH. */
    private static function isNumberish(string $token): bool
    {
        return preg_match(self::NUMBERISH, $token) === 1 && preg_match('/\d/', $token) === 1;
    }

    /**
     * The name Rodeo Houston spells: `full_name` as it arrived, else first
     * and last. NOT the preferred name — "Bud" is what an officer calls him
     * and not what is on the membership record this form asks them to change.
     *
     * @param array<string, mixed> $row
     */
    private static function formalName(array $row): string
    {
        $full = trim((string) ($row['full_name'] ?? ''));
        if ($full !== '') {
            return $full;
        }

        $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));

        return $name !== '' ? $name : (string) ($row['member_number'] ?? '');
    }

    /**
     * The option a key names, or null. Never the key itself: a submitted
     * value is only ever a lookup into a list this application built.
     *
     * @param array<int, array<string, mixed>> $options
     * @return array<string, mixed>|null
     */
    public static function pick(array $options, string $key): ?array
    {
        foreach ($options as $option) {
            if ((string) $option['key'] === $key) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The canonical name of the option whose LABEL was typed, or the text
     * itself when nothing matches. Case- and space-insensitive, because a
     * person retyping "bus ops team a" means the team.
     *
     * @param array<int, array<string, mixed>> $options
     */
    public static function matchLabel(array $options, string $typed): string
    {
        $typed = self::text($typed);

        if ($typed === '') {
            return '';
        }

        $wanted = mb_strtolower($typed);

        foreach ($options as $option) {
            if (mb_strtolower((string) $option['label']) === $wanted
                || mb_strtolower((string) $option['name']) === $wanted
            ) {
                return (string) $option['name'];
            }
        }

        return $typed;
    }

    /**
     * The officer a member number names, defaulting to whoever is signed in.
     *
     * The default is the point: the person filling the form in is nearly
     * always the person submitting it, and the dropdown exists for the times
     * they are filling it in for somebody else.
     *
     * @param array<int, array<string, mixed>> $officers
     * @return array<string, mixed>|null
     */
    public static function pickOfficer(array $officers, string $number, User $user): ?array
    {
        foreach ($officers as $officer) {
            if ((string) $officer['member_number'] === $number) {
                return $officer;
            }
        }

        foreach ($officers as $officer) {
            if ((string) $officer['member_number'] === $user->memberNumber) {
                return $officer;
            }
        }

        return null;
    }

    /**
     * The twenty-five rows as they were submitted, always exactly
     * RosterChangeForm::ROWS of them however many arrived.
     *
     * `max_input_vars` is 1000 on this host and PHP truncates past it in
     * SILENCE (CLAUDE.md). Twenty-five rows of ten fields is 250, plus the
     * header and the token — comfortably inside it, and the reason the form
     * is one page rather than a growing list.
     *
     * @param array<string, mixed> $input
     * @return array<int, array<string, string>>
     */
    private static function entries(array $input): array
    {
        $submitted = is_array($input['row'] ?? null) ? $input['row'] : [];
        $entries   = [];

        for ($i = 0; $i < RosterChangeForm::ROWS; $i++) {
            $row = is_array($submitted[$i] ?? null) ? $submitted[$i] : [];

            $entries[$i] = [
                'type'             => self::text((string) ($row['type'] ?? '')),
                'rookie'           => self::text((string) ($row['rookie'] ?? '')),
                'member'           => self::text((string) ($row['member'] ?? '')),
                'new_title'        => self::text((string) ($row['new_title'] ?? '')),
                'previous_title'   => self::text((string) ($row['previous_title'] ?? '')),
                'wait_list'        => self::text((string) ($row['wait_list'] ?? '')),
                'remove_reason'    => self::text((string) ($row['remove_reason'] ?? '')),
                'new_subcommittee' => self::text((string) ($row['new_subcommittee'] ?? '')),
                'sponsor'          => self::text((string) ($row['sponsor'] ?? '')),
            ];
        }

        return $entries;
    }

    /**
     * How many rows to draw: five, or as many as are already filled in, or
     * five more than last time if the button was pressed — never more than
     * the form has, and never fewer than five.
     *
     * @param array<string, mixed> $input
     * @param array<int, array<string, string>> $entries
     */
    private static function visibleRows(array $input, array $entries): int
    {
        $filled = 0;
        foreach ($entries as $index => $entry) {
            foreach ($entry as $value) {
                if ($value !== '') {
                    $filled = $index + 1;

                    break;
                }
            }
        }

        $visible = max(self::VISIBLE_ROWS, $filled, (int) ($input['visible'] ?? 0));

        if (($input['action'] ?? '') === 'rows') {
            $visible += self::VISIBLE_STEP;
        }

        return (int) min(RosterChangeForm::ROWS, $visible);
    }

    /** A value that must be one of a fixed list, or nothing at all. */
    private static function oneOf(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Free text on its way to a cell: collapsed, trimmed and bounded.
     *
     * The bound is the narrowest of the columns it can land in rather than
     * the format's 32,767: a title is a title, and a paragraph pasted into
     * one produces a form that prints as a grey smear.
     */
    private static function text(string $value, int $limit = 120): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    /** The submitted date as `YYYY-MM-DD`, defaulting to today in Houston. */
    private static function date(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $value) {
                return $value;
            }
        }

        return (new DateTimeImmutable('now', new \DateTimeZone('America/Chicago')))->format('Y-m-d');
    }

    /**
     * `YYYY-MM-DD` as `M/D/YYYY`, which is what the cell's own number format
     * would render and what everybody in Houston reads.
     *
     * Written as a STRING rather than as a date serial, deliberately: this
     * application has no numeric cell type (`FormSheet`), because that is
     * what keeps a member number from becoming 1234567.0. A string that
     * already reads the way the format would render it costs nothing and
     * keeps that rule whole.
     */
    private static function american(string $isoDate): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('n/j/Y') : '';
    }

    /**
     * The show year the form is for — the active one, which is the year an
     * officer is working. It goes in the title bar, so a form produced in
     * 2028 says RODEO 2028.
     */
    private function showYearLabel(): string
    {
        $label = $this->pdo
            ->query('SELECT label FROM show_year ORDER BY is_active DESC, label DESC LIMIT 1')
            ->fetchColumn();

        return is_string($label) ? $label : '';
    }
}
