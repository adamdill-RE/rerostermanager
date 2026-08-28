<?php

declare(strict_types=1);

namespace Rerm\Roster;

use PDO;
use Rerm\Auth\User;

/**
 * "Which teams am I looking at?" — asked and answered once, for every screen
 * that offers the choice.
 *
 * Three screens narrow a roster by team and they used to answer three
 * questions apiece by hand: which teams may this caller pick from, which did
 * they pick, and what does the screen show when they have not picked yet.
 * The first two were already duplicated (View My Roster and Export Roster
 * each had a private `teamsInScope`), and the third is new — Phase 10 gives
 * My Roster Status and Export Roster a DEFAULT, and a default is exactly the
 * kind of rule that drifts when it is written down twice.
 *
 *
 * THE OPTIONS ARE READ THROUGH THE SCOPE, NOT OFF THE TEAM TABLE
 *
 * `inScope()` reads teams that actually HOLD MEMBERS this caller can see,
 * through `ScopedQuery::forUser()` like every other roster read. So the
 * picker cannot offer a team the caller could not have seen anyway, and a
 * team that exists but is empty inside their scope is not a choice that
 * silently yields nothing.
 *
 *
 * A CHOICE NARROWS. IT CAN NEVER WIDEN
 *
 * Whatever comes back from `choose()` is ANDed onto the scope predicate by
 * the caller, never substituted for it. That is what makes it safe to take
 * team ids straight from a query string without checking them against the
 * options: an id outside the caller's scope intersects nothing and yields an
 * empty roster, which is the honest answer. Filtering the ids against the
 * options first would be worse, not better — a list of out-of-scope ids would
 * filter down to nothing, and nothing means "every team" one line later.
 *
 *
 * THREE STATES, AND THE URL SAYS WHICH
 *
 *   team[]=12&team[]=13   those teams
 *   team=all              every team in scope — the ALL token below
 *   (absent)              the caller has not chosen, so the default applies
 *
 * The token exists because the absence of a value cannot mean two things.
 * Before the default, an empty team filter meant "everything"; with a default
 * it means "I have not said", and somebody who wants everything back needs a
 * way to say so that survives a link, a page turn and a bookmark.
 */
final class TeamFilter
{
    /** The value that means "every team in this caller's scope". */
    public const ALL = 'all';

    /**
     * Teams holding members inside this caller's scope, with the member count
     * the picker shows beside each name.
     *
     * @return array<int, array<string, mixed>> id, name, members — by name
     */
    public static function inScope(PDO $pdo, User $user): array
    {
        $scoped = ScopedQuery::forUser($user);

        $read = $pdo->prepare(
            'SELECT t.id, t.name, COUNT(*) AS members FROM member m'
            . ' INNER JOIN team t ON t.id = m.team_id'
            . ' WHERE ' . $scoped->predicate()
            . ' GROUP BY t.id, t.name ORDER BY t.name'
        );
        $read->execute($scoped->bindings());

        return $read->fetchAll();
    }

    /**
     * Resolves the team selection, and reports enough for a screen to draw
     * the control AND say in words what it is showing.
     *
     * @param mixed                            $input      the raw `team` value from the query string
     * @param array<int, array<string, mixed>> $options    inScope(), above
     * @param ?int                             $ownTeamId  the caller's own team
     * @param bool                             $mayDefault false where an explicit
     *        narrowing is already in force — a drill-down link from the
     *        Committee Dashboard must reproduce the figure that made it, and
     *        a default quietly ANDed onto it would not
     *
     * @return array{
     *     may_choose: bool,
     *     options: array<int, array<string, mixed>>,
     *     own: ?int,
     *     own_name: string,
     *     selected: array<int, int>,
     *     all: bool,
     *     defaulted: bool
     * }
     */
    public static function choose(
        mixed $input,
        array $options,
        ?int $ownTeamId,
        bool $mayDefault = true,
    ): array {
        $own     = null;
        $ownName = '';
        foreach ($options as $option) {
            if ($ownTeamId !== null && (int) $option['id'] === $ownTeamId) {
                $own     = $ownTeamId;
                $ownName = (string) $option['name'];
            }
        }

        // One team in scope is not a choice. An Officer's team IS their scope,
        // so a picker there would offer them the one thing they already have,
        // and a "showing your team" sentence would be describing the scope
        // rather than a narrowing somebody made.
        $mayChoose = count($options) > 1;

        $answer = [
            'may_choose' => $mayChoose,
            'options'    => $options,
            'own'        => $own,
            'own_name'   => $ownName,
            'selected'   => [],
            'all'        => true,
            'defaulted'  => false,
        ];

        if (self::isAll($input)) {
            return $answer;
        }

        if (!self::said($input)) {
            // Nothing in the URL, so this is a first visit rather than a
            // choice. Start on the caller's own team when they have one here
            // and there is anything else it could have been.
            if ($mayChoose && $mayDefault && $own !== null) {
                return ['selected' => [$own], 'all' => false, 'defaulted' => true] + $answer;
            }

            return $answer;
        }

        $selected = RosterPage::teamIds($input);

        return ['selected' => $selected, 'all' => $selected === []] + $answer;
    }

    /**
     * Does this input carry the ALL token, in either shape a query string can
     * spell it — `team=all` from a select, `team[]=all` from a checkbox?
     */
    public static function isAll(mixed $input): bool
    {
        if (is_string($input)) {
            return $input === self::ALL;
        }

        if (is_array($input)) {
            foreach ($input as $value) {
                if (is_string($value) && $value === self::ALL) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Did the URL say anything at all about teams?
     *
     * An empty string reads as silence rather than as "none": `?team=` is
     * what a browser sends for a control nobody touched, and treating it as a
     * choice would make the default depend on which form last posted.
     */
    public static function said(mixed $input): bool
    {
        if ($input === null || $input === '' || $input === []) {
            return false;
        }

        return is_string($input) || is_int($input) || is_array($input);
    }

    /**
     * The `team` parameter that reproduces a resolved selection in a link.
     *
     * Explicit in every state, including the default one: a link that leaves
     * the selection out is a link that re-derives it at the other end, and
     * "turn the page and get somebody else's roster back" is the failure this
     * whole mechanism exists to prevent.
     *
     * @param array{selected: array<int, int>, all: bool} $choice
     *
     * @return string|array<int, int>
     */
    public static function param(array $choice): string|array
    {
        return $choice['all'] ? self::ALL : $choice['selected'];
    }
}
