<?php

declare(strict_types=1);

namespace Rerm\Export;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Import\HeaderMap;
use Rerm\Roster\MemberReads;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Roster\RosterPage;
use Rerm\Roster\ScopedQuery;

/**
 * Export Full Roster by Show Year (spec 7.5, Phase 8 decided 3).
 *
 * **One export, not two.** Every row comes through `ScopedQuery::forUser()`,
 * exactly like every other roster read, so breadth is decided by who is
 * asking rather than by which button they pressed: an Admin or Executive
 * Officer gets the whole committee, a Senior Officer their division, an
 * Officer their team. There is no separate "full" path to keep in sync with
 * a "scoped" one, which is the entire reason `Capability::ExportRoster` moved
 * to Officer / Scoped.
 *
 * On top of the scope, a **team filter** — `team[]=`, spec 7.2's existing
 * shape, normalised by `RosterPage::teamIds()` and never a second spelling.
 * It INTERSECTS the scope predicate, so it can only ever narrow: an
 * out-of-scope team id yields nothing rather than something. The ordinary use
 * is a Division Chairman over 675 people wanting one team of 82.
 *
 * Two rules that are not negotiable, both with tests:
 *
 *   1. **`(No Division)` writes back as blank**, never as the literal text
 *      (spec 5.1a rule 2). It is our bookkeeping, not Rodeo Houston's data,
 *      and it must not travel back to them as though it were theirs.
 *   2. **The master administrator is never exported.** `is_system = 1` — and
 *      that falls out of `ScopedQuery`, which excludes system rows from every
 *      read in this application, rather than being a special case here.
 *
 * **The file never holds more than one row in memory.** Members are read in
 * pages of CHUNK, each page's metrics, contacts and assignments come from the
 * same batched `MemberReads` every screen uses, and each row is appended to
 * the writer's temp file and dropped. `ZipArchive` writes to a path rather
 * than to `php://output`, so spec 7.5's "streamed, never assembled in memory"
 * holds in spirit and changes in mechanism — see `XlsxWriter`.
 *
 * **An export is PII leaving the building.** It is written outside the
 * document root, it is unlinked as soon as it has been sent, and it is logged
 * with the actor, the scope and the row count. That is why `Action` carries
 * one read.
 */
final class RosterExport
{
    /**
     * Members read per page. Big enough that a 1,954-row export is 8 round
     * trips rather than 40 — the database is on another machine — and small
     * enough that the batched reads for one page stay small.
     */
    private const CHUNK = 250;

    /**
     * The columns this application generated, after HeaderMap::EXPORTED's.
     * Spelled here and nowhere else.
     *
     * The four SCORED metrics get four columns each. Harassment training gets
     * only its status: it is not one of the four, it enters no percentage,
     * and it has no progress workflow to report (spec 5.4) — offering an
     * empty "Harassment Training Progress By" column would suggest one
     * exists.
     */
    private const GENERATED_SUFFIXES = [' Status', ' Progress', ' Progress By', ' Progress At'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $exportDirectory,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db(), $app->path('var/exports'));
    }

    /**
     * The header row, in file order. Public so the screen can say exactly
     * what the file will contain BEFORE it is built, and so a test can hold
     * it to HeaderMap.
     *
     * @return array<int, string>
     */
    public static function headers(): array
    {
        $headers = HeaderMap::EXPORTED;

        foreach (Metric::scored() as $metric) {
            foreach (self::GENERATED_SUFFIXES as $suffix) {
                $headers[] = $metric->label() . $suffix;
            }
        }

        $headers[] = Metric::HarassmentTraining->label() . ' Status';

        $headers[] = 'Division';
        $headers[] = 'Area';
        $headers[] = 'Assigned Officers';
        $headers[] = 'Contacts This Year';
        $headers[] = 'Last Contact Date';
        $headers[] = 'Last Contact Type';
        $headers[] = 'Last Contact Officer';

        return $headers;
    }

    /**
     * How many rows a given caller, year and filter would produce — the
     * number the screen shows before anybody presses the button, and the
     * number the audit row records afterwards.
     *
     * @param array<int, int> $teamIds
     */
    public function countRows(User $user, array $teamIds): int
    {
        [$where, $bind] = $this->predicate($user, $teamIds);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM member m WHERE {$where}");
        $count->execute($bind);

        return (int) $count->fetchColumn();
    }

    /**
     * Builds the file and returns its path, the row count and the filename to
     * send it under. The caller sends it, then calls `XlsxWriter::close()`
     * through `discard()`.
     *
     * @param array<int, int> $teamIds
     * @return array{path: string, rows: int, filename: string, writer: XlsxWriter}
     */
    public function build(User $user, int $showYearId, string $yearLabel, array $teamIds): array
    {
        [$where, $bind] = $this->predicate($user, $teamIds);

        $writer = XlsxWriter::create($this->exportDirectory, 'Roster ' . $yearLabel);
        $writer->addRow(self::headers());

        $reads = new MemberReads($this->pdo);
        $rows  = 0;

        // Keyset pagination on the primary key rather than LIMIT/OFFSET: the
        // offset form re-scans everything before it on every page, and the
        // roster is not changing under us mid-export anyway.
        $lastId = 0;

        while (true) {
            $read = $this->pdo->prepare(
                'SELECT m.*, t.name AS team_name, t.area AS team_area,'
                . ' d.name AS division_name, d.is_placeholder AS division_is_placeholder'
                . ' FROM member m'
                . ' LEFT JOIN team t ON t.id = m.team_id'
                . ' INNER JOIN division d ON d.id = m.division_id'
                . " WHERE {$where} AND m.id > :export_after"
                . ' ORDER BY m.id ASC LIMIT ' . self::CHUNK
            );
            $read->execute($bind + [':export_after' => $lastId]);
            $members = $read->fetchAll();

            if ($members === []) {
                break;
            }

            $ids = array_map(static fn (array $m): int => (int) $m['id'], $members);

            $metrics     = $reads->metricsFor($ids, $showYearId);
            $progress    = $reads->metricProgressFor($ids, $showYearId);
            $contacts    = $reads->contactsFor($ids, $showYearId);
            $assignments = $reads->assignmentsFor($ids, $showYearId);

            foreach ($members as $member) {
                $writer->addRow($this->row($member, $metrics, $progress, $contacts, $assignments));
                $rows++;
                $lastId = (int) $member['id'];
            }

            // Nothing is kept between pages: the three lookups go out of
            // scope with the page they were read for.
            unset($members, $metrics, $progress, $contacts, $assignments);
        }

        return [
            'path'     => $writer->finish(),
            'rows'     => $rows,
            'filename' => self::filename($yearLabel, $user, $teamIds),
            'writer'   => $writer,
        ];
    }

    /**
     * The record (spec 10, Phase 8 "encode these"): who exported what, how
     * wide their scope was, and how many people were in the file. Written
     * whether or not the download completes — the rows were read and the file
     * was built, and that is the fact worth keeping.
     *
     * @param array<int, int> $teamIds
     */
    public function audit(User $user, int $showYearId, string $yearLabel, array $teamIds, int $rows): void
    {
        (new AuditLog($this->pdo))->record(
            $user,
            Action::ExportRoster,
            'show_year',
            (string) $showYearId,
            null,
            [
                'show_year'      => $yearLabel,
                'rows'           => $rows,
                // The breadth, in the caller's own terms, so the log answers
                // "how much did they take" without re-deriving their scope
                // from an account that may since have been demoted.
                'scope_level'    => $user->level->value,
                'scope_division' => $user->scopeDivisionId,
                'scope_team'     => $user->scopeTeamId,
                'team_filter'    => $teamIds === [] ? null : array_values($teamIds),
            ]
        );
    }

    /** Removes the built file. Always called, and called even on a failure. */
    public function discard(XlsxWriter $writer, ?string $path = null): void
    {
        $writer->close($path);
    }

    /**
     * The scope predicate plus the optional team filter, as one WHERE.
     *
     * @param array<int, int> $teamIds
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function predicate(User $user, array $teamIds): array
    {
        $scoped = ScopedQuery::forUser($user);
        $where  = $scoped->predicate();
        $bind   = $scoped->bindings();

        if ($teamIds !== []) {
            $places = [];
            foreach (array_values($teamIds) as $i => $teamId) {
                $places[]                    = ":export_team_{$i}";
                $bind[":export_team_{$i}"]   = $teamId;
            }
            $where .= ' AND m.team_id IN (' . implode(', ', $places) . ')';
        }

        return [$where, $bind];
    }

    /**
     * One member as one row of cells, in `headers()` order. Every value is a
     * string: `XlsxWriter` has no numeric cell type, which is what keeps
     * Customer Number 1234567 from becoming 1234567.0.
     *
     * @param array<string, mixed> $member
     * @param array<int, array<string, array{imported_value: string, progress: string}>> $metrics
     * @param array<int, array<string, array{by: string, at: string, note: string}>> $progress
     * @param array<int, array<int, array<string, mixed>>> $contacts
     * @param array<int, array<int, array<string, mixed>>> $assignments
     * @return array<int, string>
     */
    private function row(
        array $member,
        array $metrics,
        array $progress,
        array $contacts,
        array $assignments
    ): array
    {
        $id        = (int) $member['id'];
        $history   = $contacts[$id] ?? [];
        $contacted = $history !== [];
        $last      = $history[0] ?? null;

        $flag = static fn (mixed $value): string => (int) $value === 1 ? 'Y' : 'N';

        // Y / N / '' — the tri-state as the file spells it. 'unknown' is a
        // BLANK cell, never the word: 1,716 of 1,954 harassment-training rows
        // arrived blank and they have to go back blank (spec 5.4).
        $imported = function (string $metric) use ($metrics, $id): string {
            $value = $metrics[$id][$metric]['imported_value'] ?? 'unknown';

            return $value === 'Y' || $value === 'N' ? $value : '';
        };

        $cells = [];

        foreach (HeaderMap::EXPORTED as $column) {
            $cells[] = match ($column) {
                HeaderMap::TITLE               => (string) $member['title'],
                HeaderMap::CUSTOMER_NUMBER     => (string) $member['member_number'],
                HeaderMap::FULL_NAME           => (string) $member['full_name'],
                HeaderMap::PREFIX              => (string) $member['prefix'],
                HeaderMap::FIRST_NAME          => (string) $member['first_name'],
                HeaderMap::LAST_NAME           => (string) $member['last_name'],
                HeaderMap::PREFERRED_NAME      => (string) $member['preferred_name'],
                HeaderMap::LEGAL_NAME_VERIFIED => $flag($member['legal_name_verified']),
                HeaderMap::SUBCOMMITTEE_1      => (string) ($member['team_name'] ?? ''),

                // Spec 5.1a rule 2: the placeholder division writes back as
                // BLANK, never as "(No Division)". The flag is read off the
                // division row rather than the name being compared, so
                // renaming the placeholder cannot break the rule.
                HeaderMap::SUBCOMMITTEE_3      => (int) $member['division_is_placeholder'] === 1
                    ? ''
                    : (string) $member['division_name'],

                HeaderMap::ADDRESS             => (string) $member['address'],
                HeaderMap::CITY                => (string) $member['city'],
                HeaderMap::STATE               => (string) $member['state'],
                HeaderMap::ZIP                 => (string) $member['zip'],
                HeaderMap::PHONE               => (string) $member['phone'],
                HeaderMap::PHONE_TYPE          => (string) $member['phone_type'],
                HeaderMap::EMAIL               => (string) ($member['email'] ?? ''),

                HeaderMap::SHOW_DUES           => $imported(Metric::HlsrDues->value),
                HeaderMap::COMMITTEE_DUES      => $imported(Metric::CommitteeDues->value),
                HeaderMap::INDEMNITY           => $imported(Metric::Indemnity->value),
                HeaderMap::BACKGROUND_CHECK    => $imported(Metric::BackgroundCheck->value),
                HeaderMap::HARASSMENT_TRAINING => $imported(Metric::HarassmentTraining->value),

                HeaderMap::ROOKIE              => $flag($member['is_rookie']),
                HeaderMap::BADGE_RELEASED      => $flag($member['badge_released']),
                HeaderMap::BADGE_RELEASED_DATE => (string) $member['badge_released_date_raw'],
                HeaderMap::BADGE_ISSUE_DATE    => (string) $member['badge_issue_date_raw'],
                HeaderMap::BADGE_PICKUP_PERSON => (string) $member['badge_pickup_person'],
                HeaderMap::ELIGIBLE_SERVICE    => (string) $member['eligible_for_service_history_raw'],
                HeaderMap::ELIGIBILITY_UPDATED => (string) $member['eligibility_updated_by_raw'],
                HeaderMap::LTC_APPLIED         => $flag($member['ltc_applied']),
                HeaderMap::IN_OTHER_COMMITTEES => $flag($member['in_other_committees']),
            };
        }

        // The four scored metrics: the effective status through
        // MetricStatus::derive(), which is the ONLY place spec 5.4 exists,
        // and the progress this application tracks beside it.
        foreach (Metric::scored() as $metric) {
            $values = $metrics[$id][$metric->value] ?? null;

            $cells[] = MetricStatus::derive(
                $values['imported_value'] ?? 'unknown',
                $values['progress'] ?? 'not_started',
                $contacted
            )->label();

            $set = $progress[$id][$metric->value] ?? null;

            $cells[] = self::progressWord($values['progress'] ?? 'not_started');
            $cells[] = (string) ($set['by'] ?? '');
            $cells[] = (string) ($set['at'] ?? '');
        }

        // Harassment training: status only. Not one of the four.
        $harassment = $metrics[$id][Metric::HarassmentTraining->value] ?? null;
        $cells[] = MetricStatus::derive(
            $harassment['imported_value'] ?? 'unknown',
            $harassment['progress'] ?? 'not_started',
            $contacted
        )->label();

        // The division as WE hold it — placeholder name included, because
        // this column is ours and the honest answer to "where is this member
        // filed" is "(No Division)". The Rodeo Houston column above is the
        // one that must go back blank.
        $cells[] = (string) $member['division_name'];
        $cells[] = (string) ($member['team_area'] ?? '');

        $officers = array_map(
            static fn (array $a): string => (string) $a['officer_name'],
            $assignments[$id] ?? []
        );
        $cells[] = implode('; ', $officers);

        $cells[] = (string) count($history);
        $cells[] = $last !== null ? (string) $last['occurred_at'] : '';
        $cells[] = $last !== null ? self::contactWord((string) $last['contact_type']) : '';
        $cells[] = $last !== null ? (string) $last['officer_name'] : '';

        return $cells;
    }

    /** The progress state as a person reads it, not as the ENUM spells it. */
    private static function progressWord(string $progress): string
    {
        return match ($progress) {
            'in_progress'      => 'In progress',
            'claimed_complete' => 'Claimed complete',
            default            => 'Not started',
        };
    }

    /** The contact type as a person reads it. */
    private static function contactWord(string $type): string
    {
        return match ($type) {
            'call'      => 'Call',
            'text'      => 'Text',
            'email'     => 'Email',
            'in_person' => 'In person',
            default     => 'Other',
        };
    }

    /**
     * What the browser saves it as. The show year and the date, so two
     * exports a week apart do not overwrite each other in a downloads folder,
     * and a marker for a narrowed one so a partial file is never mistaken for
     * the committee.
     *
     * @param array<int, int> $teamIds
     */
    private static function filename(string $yearLabel, User $user, array $teamIds): string
    {
        $parts = ['rerm-roster', $yearLabel];

        if ($teamIds !== []) {
            $parts[] = count($teamIds) === 1 ? 'one-team' : count($teamIds) . '-teams';
        } elseif (!$user->level->atLeast(Level::ExecutiveOfficer)) {
            $parts[] = 'my-scope';
        }

        $parts[] = date('Y-m-d');

        $name = implode('-', $parts);

        // Held to characters that survive a Content-Disposition header, a
        // Windows filename and a shell without quoting.
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '.xlsx';
    }
}
