<?php

declare(strict_types=1);

namespace Rerm\Roster;

use DateTimeImmutable;
use PDO;
use Rerm\App;
use Rerm\Auth\User;
use Rerm\Forms\RcfTracking;

/**
 * The people a team is waiting for (Phase 14.8, spec-v2 §22.2): everyone
 * asked for on a Roster Change Form as an ADDITION to a team, whom Rodeo
 * Houston's roster does not list yet — drawn on View My Roster and My
 * Roster Status as "ghost" rows, beside the team they are joining, so an
 * officer looking at their team sees who is on the way as well as who is
 * on it.
 *
 * Two places a pending addition can be, and both are read:
 *
 *   - a LINE of a kept form (`rcf_row`, type A) — made here, uploaded, or
 *     consolidated — on a form that was not voided and that the roster has
 *     not yet answered, by RcfTracking's own rule (`LANDED_EXISTS`: the
 *     first `import_change` after the form that says the member was
 *     created or returned). One rule, so a form's page and a team's roster
 *     cannot disagree about whether somebody has arrived;
 *   - a REQUEST (`rcf_entry`, type A) still in the chain — pending or
 *     approved and not yet on a form. A generated request IS a line above
 *     and is not read twice; a returned or withdrawn one is nobody's.
 *
 * The team is the one the line names: NEW SUB-COMMITTEE first (an
 * addition's destination since Phase 14.7, matched to a team by name) and
 * the form's own team heading second — the same order `RcfTracking::
 * teamFor()` prints. A line that names no team is nobody's ghost.
 *
 * Four rules:
 *
 *   - **Scope is the roster's.** The teams asked about are intersected
 *     with `ScopedQuery::forUser()` before anything is read, so a team id
 *     typed into the URL from outside the caller's scope yields nothing —
 *     the roster's own rule for `team[]`, applied to the ghosts beside it.
 *   - **A ghost is nobody who is already there.** A number the roster
 *     already shows on that team, present and not dropped, is a member
 *     and is drawn as one; the line about them is still open on Track
 *     RCFs, where that is the right question.
 *   - **A ghost is counted nowhere.** No metric row, no contact, no
 *     assignment: not on the four cards, not in "Showing 1–50 of 212",
 *     not in the Committee Dashboard's figures. They are not on the HLSR
 *     roster, and the figures say what the HLSR roster says.
 *   - **Nothing here writes.** A read over two record tables; a form
 *     still never writes the roster, and a roster screen never writes a
 *     form.
 */
final class PendingAdditions
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * The pending additions to these teams, newest first, as the screens
     * draw them — or [] when no team is in view. $search is the screen's
     * own search term, already past the floor: a ghost has to match it the
     * way a member would, by number or by name, so a search for the new
     * recruit finds them as a ghost rather than as nobody.
     *
     * @param array<int, int> $teamIds the teams whose roster is on the screen
     * @return array<int, array<string, mixed>>
     */
    public function forTeams(User $user, array $teamIds, string $search = ''): array
    {
        $teams = $this->teamsInScope($user, $teamIds);
        if ($teams === []) {
            return [];
        }

        [$places, $bind] = MemberReads::idList(array_keys($teams), 'pending_team');

        // A line about a number the roster already shows on that team is a
        // member, not a ghost — written once, used by both reads.
        $already = static fn (string $alias): string =>
            "NOT EXISTS (SELECT 1 FROM member pm WHERE pm.member_number = {$alias}.member_number"
            . " AND {$alias}.member_number <> '' AND pm.team_id = t.id AND " . ScopedQuery::visible('pm') . ')';

        // 1. Lines of kept forms. The team join is the destination first
        //    and the heading second; the type is tested before the join so
        //    a removal's empty NEW SUB-COMMITTEE never matches a team.
        $lines = $this->pdo->prepare(
            'SELECT r.id, r.member_number, r.member_name, r.new_title, r.rookie, r.serial,'
            . ' r.sent_to_dc_on, r.sent_to_rosters_on,'
            . ' f.id AS rcf_id, f.generated_by, f.generated_at, f.source, f.form_date, f.submitter,'
            . ' t.id AS team_id, t.name AS team_name'
            . ' FROM rcf_row r'
            . ' INNER JOIN rcf f ON f.id = r.rcf_id AND f.voided_at IS NULL'
            . " INNER JOIN team t ON (r.new_subcommittee <> '' AND t.name = r.new_subcommittee)"
            . "   OR (r.new_subcommittee = '' AND f.team_id = t.id)"
            . " WHERE r.type = 'A' AND t.id IN ({$places})"
            . ' AND NOT ' . RcfTracking::LANDED_EXISTS
            . ' AND ' . $already('r')
            . ' ORDER BY f.generated_at DESC, r.position'
        );
        $lines->execute($bind);

        // 2. Requests still in the chain. The same team rule; `generated`
        //    is excluded because that request is a line above.
        $entries = $this->pdo->prepare(
            'SELECT e.id, e.member_number, e.member_name, e.new_title, e.rookie, e.status,'
            . ' e.requested_at, e.requested_by, e.current_approver_id,'
            . ' t.id AS team_id, t.name AS team_name,'
            . ' rm.preferred_name AS r_preferred, rm.first_name AS r_first, rm.last_name AS r_last,'
            . ' rm.member_number AS r_number,'
            . ' cm.preferred_name AS c_preferred, cm.first_name AS c_first, cm.last_name AS c_last,'
            . ' cm.member_number AS c_number'
            . ' FROM rcf_entry e'
            . " INNER JOIN team t ON (e.new_subcommittee <> '' AND t.name = e.new_subcommittee)"
            . "   OR (e.new_subcommittee = '' AND e.team_id = t.id)"
            . ' INNER JOIN app_user ru ON ru.id = e.requested_by INNER JOIN member rm ON rm.id = ru.member_id'
            . ' LEFT JOIN app_user cu ON cu.id = e.current_approver_id LEFT JOIN member cm ON cm.id = cu.member_id'
            . " WHERE e.type = 'A' AND e.status IN ('pending', 'approved') AND e.voided_at IS NULL"
            . " AND t.id IN ({$places})"
            . ' AND ' . $already('e')
            . ' ORDER BY e.requested_at DESC, e.id DESC'
        );
        $entries->execute($bind);

        $name = static fn (array $r, string $p): string => $r[$p . '_number'] === null ? '' : RosterPage::displayName(
            (string) $r[$p . '_preferred'],
            (string) $r[$p . '_first'],
            (string) $r[$p . '_last'],
            (string) $r[$p . '_number']
        );

        $ghosts = [];
        foreach ($lines->fetchAll() as $row) {
            $ghosts[] = [
                'key'           => 'line:' . (int) $row['id'],
                'source'        => 'form',
                'member_number' => (string) $row['member_number'],
                'name'          => self::rosterName((string) $row['member_name']),
                'title'         => trim((string) $row['new_title']),
                'rookie'        => (int) $row['rookie'] === 1,
                'team_id'       => (int) $row['team_id'],
                'team_name'     => (string) $row['team_name'],
                'asked_at'      => (string) $row['generated_at'],
                'where'         => self::lineWhereabouts($row),
                'rcf_id'        => (int) $row['rcf_id'],
                // Open the form, for whoever may see it — RcfTracking's one
                // rule; the route 404s anybody else the same way.
                'link'          => RcfTracking::mayView($user, (int) $row['generated_by']) ? 'rcf' : null,
            ];
        }
        foreach ($entries->fetchAll() as $row) {
            $requester = $name($row, 'r');
            $approver  = $name($row, 'c');
            $ghosts[]  = [
                'key'           => 'entry:' . (int) $row['id'],
                'source'        => 'request',
                'member_number' => (string) $row['member_number'],
                'name'          => self::rosterName((string) $row['member_name']),
                'title'         => trim((string) $row['new_title']),
                'rookie'        => (int) $row['rookie'] === 1,
                'team_id'       => (int) $row['team_id'],
                'team_name'     => (string) $row['team_name'],
                'asked_at'      => (string) $row['requested_at'],
                'where'         => (string) $row['status'] === 'approved'
                    ? 'RCF request by ' . $requester . ', approved and waiting to go on a consolidated form'
                    : 'RCF request by ' . $requester . ($approver !== '' ? ', waiting on ' . $approver : ''),
                'rcf_id'        => null,
                // The requester's own list, or the approver's: the two
                // screens the request is on for the people it is between.
                'link'          => (int) $row['requested_by'] === $user->id ? 'rcf-entries'
                    : ((int) ($row['current_approver_id'] ?? 0) === $user->id ? 'rcf-approvals' : null),
            ];
        }

        return self::searched(self::distinct($ghosts), $search);
    }

    /**
     * The teams in view, as id => name, through the roster's own scope:
     * only teams holding a member the caller may see, and only the ones
     * asked for. An id from outside the scope is dropped here, before any
     * form is read.
     *
     * @param array<int, int> $teamIds
     * @return array<int, string>
     */
    private function teamsInScope(User $user, array $teamIds): array
    {
        $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds), static fn (int $id): bool => $id > 0)));
        if ($teamIds === []) {
            return [];
        }

        $scoped           = ScopedQuery::forUser($user);
        [$places, $bind]  = MemberReads::idList($teamIds, 'view_team');

        $read = $this->pdo->prepare(
            'SELECT DISTINCT t.id, t.name FROM team t INNER JOIN member m ON m.team_id = t.id'
            . ' WHERE ' . $scoped->predicate() . " AND t.id IN ({$places})"
        );
        $read->execute($scoped->bindings() + $bind);

        $teams = [];
        foreach ($read->fetchAll() as $row) {
            $teams[(int) $row['id']] = (string) $row['name'];
        }

        return $teams;
    }

    /**
     * One ghost per person: a number asked for on two forms, or on a form
     * and in a request, is one person on the way. The first seen wins —
     * lines before requests, newest first — because a line is further
     * along. A newcomer with no number yet is one per name per team.
     *
     * @param array<int, array<string, mixed>> $ghosts
     * @return array<int, array<string, mixed>>
     */
    public static function distinct(array $ghosts): array
    {
        $seen = [];
        $out  = [];
        foreach ($ghosts as $ghost) {
            $key = $ghost['member_number'] !== ''
                ? 'n:' . $ghost['member_number']
                : 'name:' . mb_strtolower(trim((string) $ghost['name'])) . '|' . (int) $ghost['team_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $ghost;
        }

        return $out;
    }

    /**
     * The ghosts a search term keeps: every word of it has to land on the
     * number or the name, RosterPage::searchClause()'s rule in PHP, so the
     * same word finds the same people whether they are on the roster or on
     * the way to it. An empty term keeps everyone.
     *
     * @param array<int, array<string, mixed>> $ghosts
     * @return array<int, array<string, mixed>>
     */
    public static function searched(array $ghosts, string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return $ghosts;
        }

        $tokens = RosterPage::searchTokens($search);

        return array_values(array_filter($ghosts, static function (array $ghost) use ($tokens): bool {
            $haystacks = [(string) $ghost['member_number'], (string) $ghost['name']];
            foreach ($tokens as $token) {
                $hit = false;
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && mb_stripos($haystack, $token) !== false) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * A form's `Last, First` (Phase 14.6) read the way the roster prints a
     * name — `First Last` — so a ghost sits in the list the way its
     * neighbours do. A name with no comma, or more than one, is kept as
     * typed: it is the form's word and the roster has none yet.
     */
    public static function rosterName(string $formName): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $formName));
        if (substr_count($name, ',') !== 1) {
            return $name;
        }

        [$last, $first] = array_map('trim', explode(',', $name, 2));

        return trim($first . ' ' . $last);
    }

    /**
     * Where a kept form's line has got to, in one sentence: whose form,
     * which RCF number when the Division Chairman has given it one, and
     * the last step it was marked sent to. PLAIN text; the view escapes.
     *
     * @param array<string, mixed> $row a line with its form's header
     */
    public static function lineWhereabouts(array $row): string
    {
        $serial = trim((string) ($row['serial'] ?? ''));
        $who    = trim((string) ($row['submitter'] ?? ''));
        $source = (string) ($row['source'] ?? 'generated');

        $sentence = match ($source) {
            'uploaded'     => 'On an uploaded RCF' . ($serial !== '' ? ' #' . $serial : '')
                . (($row['form_date'] ?? null) !== null ? ' dated ' . self::day((string) $row['form_date']) : ''),
            'consolidated' => 'On consolidated RCF' . ($serial !== '' ? ' #' . $serial : '')
                . ($who !== '' ? ' by ' . $who : ''),
            default        => 'On an RCF' . ($serial !== '' ? ' #' . $serial : '')
                . ($who !== '' ? ' by ' . $who : ''),
        };

        if (($row['sent_to_rosters_on'] ?? null) !== null) {
            $sentence .= ', sent to Rosters ' . self::day((string) $row['sent_to_rosters_on']);
        } elseif (($row['sent_to_dc_on'] ?? null) !== null) {
            $sentence .= ', sent to the Division Chairman ' . self::day((string) $row['sent_to_dc_on']);
        }

        return $sentence . ' — not yet in the roster';
    }

    /** A DATE column as a person reads a day: `3 Oct 2026`. */
    private static function day(string $date): string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        return $day === false ? $date : $day->format('j M Y');
    }
}
