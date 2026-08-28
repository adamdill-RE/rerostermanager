<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Export\RosterExport;
use Rerm\Roster\RosterPage;
use Rerm\Roster\ScopedQuery;

/**
 * What the Export screen shows BEFORE anybody presses the button (spec 7.5,
 * Phase 8 "encode these").
 *
 * "Say on the screen what the file will contain before it is built." So this
 * decides three things and hands them to the view: which show years can be
 * exported, which teams the caller may narrow to, and — exactly — how many
 * people the current selection covers. A download that turns out to hold
 * 1,954 home addresses when somebody expected 82 is the failure this screen
 * exists to prevent.
 *
 * The team list is the same one View My Roster offers: teams that actually
 * hold members in this caller's scope, through the same predicate. An Officer
 * gets no team filter at all — their team IS their scope, so the control
 * would offer them the one thing they already have.
 */
final class ExportPage
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RosterExport $export,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db(), RosterExport::fromApp($app));
    }

    /**
     * @param array<string, mixed> $input the raw query string, untrusted
     * @return array<string, mixed>
     */
    public function page(User $user, array $input): array
    {
        $years = $this->showYears();

        // The year to export. Defaults to the active one — the year an
        // officer is working — and an unknown id falls back to it rather than
        // erroring: this screen is reached by link, and a stale bookmark
        // should show the current year, not a stack trace.
        $requested = (int) ($input['year'] ?? 0);
        $selected  = null;
        foreach ($years as $year) {
            if ((int) $year['id'] === $requested) {
                $selected = $year;
            }
        }
        if ($selected === null) {
            foreach ($years as $year) {
                if ((int) $year['is_active'] === 1) {
                    $selected = $year;
                }
            }
        }
        $selected ??= $years[0] ?? null;

        // The team filter is for Senior Officer and above only, exactly as on
        // View My Roster: an Officer's team is their scope.
        $canFilterTeams = $user->level->atLeast(Level::SeniorOfficer);
        $selectedTeams  = $canFilterTeams ? RosterPage::teamIds($input['team'] ?? []) : [];

        return [
            'years'            => $years,
            'year'             => $selected,
            'can_filter_teams' => $canFilterTeams,
            'teams'            => $canFilterTeams ? $this->teamsInScope($user) : [],
            'selected_teams'   => $selectedTeams,

            // The exact number, counted now, through the same predicate the
            // build will use. Not an estimate and not a cap.
            'rows'             => $this->export->countRows($user, $selectedTeams),

            // What the file will contain, in the file's own column order, so
            // the promise on the screen is the header row itself rather than
            // a description of it.
            'columns'          => RosterExport::headers(),

            // How wide this caller's scope is, in their own words — an
            // Officer reading "your team" should not have to work out
            // whether that means the committee.
            'scope_word'       => self::scopeWord($user),
        ];
    }

    /** How this caller's breadth reads on the screen. */
    public static function scopeWord(User $user): string
    {
        if ($user->level->atLeast(Level::ExecutiveOfficer)) {
            return 'the whole committee';
        }

        if ($user->level === Level::SeniorOfficer) {
            return 'your division';
        }

        if ($user->level === Level::Officer) {
            return 'your team';
        }

        return 'nobody';
    }

    /**
     * Every show year, newest first. Spec 7.5 is "by show year", and a closed
     * year is explicitly exportable (spec 5.1) — closing freezes a year, it
     * does not seal it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function showYears(): array
    {
        return $this->pdo
            ->query('SELECT id, label, is_open, is_active FROM show_year ORDER BY is_active DESC, label DESC')
            ->fetchAll();
    }

    /**
     * The teams the filter can offer: those that hold members in this user's
     * scope, through the roster's own predicate.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamsInScope(User $user): array
    {
        $scoped = ScopedQuery::forUser($user);

        $read = $this->pdo->prepare(
            'SELECT t.id, t.name, COUNT(*) AS members FROM member m'
            . ' INNER JOIN team t ON t.id = m.team_id'
            . ' WHERE ' . $scoped->predicate()
            . ' GROUP BY t.id, t.name ORDER BY t.name'
        );
        $read->execute($scoped->bindings());

        return $read->fetchAll();
    }
}
