<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Export\RosterExport;
use Rerm\Roster\TeamFilter;

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
 *
 * PHASE 10 GAVE THE FILTER A DEFAULT, AND THAT CHANGED WHAT AN EMPTY ONE MEANS
 *
 * The export now starts on the caller's own team rather than on everything
 * they can see. The reason is the paragraph above, read the other way round:
 * the screen exists to stop a file turning out to hold 1,954 home addresses
 * when somebody expected 82, and starting narrow makes the safe answer the
 * one nobody has to choose. Whoever wants the whole of their scope still
 * gets it in one click, and the count on the screen says which they are
 * about to download either way.
 *
 * That is why the ALL token exists (`Rerm\Roster\TeamFilter`). With a
 * default, "no teams ticked" can no longer mean "every team" — it means "I
 * have not said" — so wanting everything needs a way to say so that survives
 * the GET form, the hidden fields on the POST, and a bookmark. Both verbs
 * resolve the same input through the same call, which is what keeps the file
 * equal to the count that was on the screen when the button was pressed.
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

        // Which teams, resolved exactly as My Roster Status resolves them, by
        // the same call. The control appears for a caller whose scope holds
        // more than one team; below that an Officer's team IS their scope and
        // the filter would offer them the one thing they already have.
        $teamChoice    = TeamFilter::choose(
            $input['team'] ?? null,
            TeamFilter::inScope($this->pdo, $user),
            $user->scopeTeamId,
        );
        $selectedTeams = $teamChoice['selected'];

        return [
            'years'            => $years,
            'year'             => $selected,
            'can_filter_teams' => $teamChoice['may_choose'],
            'teams'            => $teamChoice['options'],
            'selected_teams'   => $selectedTeams,

            // The picker's full state, so the screen can say whether this
            // selection was chosen or is where it started, and offer the one
            // click to everything in scope.
            'team_choice'      => $teamChoice,

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
}
