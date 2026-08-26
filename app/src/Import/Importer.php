<?php

declare(strict_types=1);

namespace Rerm\Import;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\TitleMap;
use Rerm\Roster\Spreadsheet;
use Throwable;

/**
 * The roster import (spec 6). Two steps, always, with a diff in between.
 *
 * ONE RULE GOVERNS EVERY LINE BELOW: an import refreshes what Rodeo Houston
 * knows and never overwrites what we know (spec 6.6). The staged payload
 * carries only HLSR-owned fields, so the apply has nothing to write a grant, a
 * scope override, a password, a contact, an assignment or a team's area from
 * — not because it is careful, but because it never had them.
 *
 * There is exactly one exception, it is deliberate, and it is confirmed: when
 * an import flips a metric from N to Y, that metric's `progress` resets to
 * `not_started`, because the thing being chased has happened. The prior value
 * goes to `audit_log` with the batch that cleared it, and `contact_log` is
 * never touched by it — the record of who called whom survives every import
 * unconditionally.
 *
 * Shaped by three measured limits (docs/hosting.md):
 *
 *   * `max_execution_time` is 30s. Parsing 1,954 rows takes 0.07s; applying is
 *     ~2,000 member writes plus ~9,770 metric rows, so everything is batched
 *     at import.batch_rows and every read that would otherwise be per-row is
 *     hoisted into one query.
 *   * `memory_limit` is 128M, shared with whatever the reader is holding.
 *     Warnings flush as they accumulate and rows stage as they are parsed;
 *     the only thing held whole is the existing roster, which is what makes
 *     the diff a memory comparison rather than 1,954 SELECTs. Measured on
 *     this schema: a first import of 1,954 rows peaks at 10MB and takes
 *     1.1s; a second, where the whole roster is held for the diff, peaks at
 *     18MB and takes 0.9s; and 9,770 rows — five times the committee, and
 *     the size a 1.9M workbook at the upload ceiling carries — peaks at
 *     78MB and takes 5.1s. The roster in memory is what grows, so that last
 *     figure is the one to re-measure if this committee ever triples.
 *   * There is no RETURNING — that is MariaDB, and production is MySQL 8.0.
 *     An insert that needs its own ids takes a second statement.
 */
final class Importer
{
    public const MODE_COMPLETE = 'complete';
    public const MODE_UPDATE   = 'update';
    public const MODE_TEAM     = 'team';

    /** @var array<int, string> */
    public const MODES = [self::MODE_COMPLETE, self::MODE_UPDATE, self::MODE_TEAM];

    /**
     * What each mode does (spec 6.2).
     *
     * Phone, phone type and email are updated in EVERY mode including Update,
     * because the brief calls for it and they are the two fields that go stale
     * fastest.
     */
    public static function modeDescription(string $mode): string
    {
        return match ($mode) {
            self::MODE_COMPLETE => 'Every field and every metric on every row. '
                . 'New members are created; members not in the file are flagged absent.',
            self::MODE_UPDATE   => 'Metrics, phone and email only, on members that already exist. '
                . 'A member not already in the roster is reported and ignored. Nobody is flagged absent.',
            self::MODE_TEAM     => 'Every field and every metric, for one team. Rows belonging to any '
                . 'other team are reported and skipped. Members of that team missing from the file are flagged absent.',
            default             => $mode,
        };
    }

    /**
     * Fields Update mode is allowed to write (spec 6.2, 6.6).
     *
     * The metrics are handled separately; these are the member columns.
     *
     * @var array<int, string>
     */
    private const UPDATE_MODE_FIELDS = ['phone', 'phone_e164', 'phone_type', 'email'];

    /**
     * Every HLSR-owned member column, in the order the diff shows them.
     *
     * This list IS spec 6.6's "HLSR owns" table. A column added here starts
     * being overwritten by every import; a column removed stops being. There
     * is no third place to check.
     *
     * @var array<int, string>
     */
    private const OWNED_FIELDS = [
        'title', 'title_level',
        'first_name', 'last_name', 'preferred_name', 'full_name', 'prefix',
        'address', 'city', 'state', 'zip',
        'phone', 'phone_e164', 'phone_type', 'email',
        'legal_name_verified', 'is_rookie', 'in_other_committees', 'badge_pickup_person',
        'badge_released', 'ltc_applied', 'badge_released_date_raw', 'badge_issue_date_raw',
        'eligible_for_service_history_raw', 'eligibility_updated_by_raw',
    ];

    /** The seeded placeholder division a blank `Subcommittee 3` lands in. */
    public const NO_DIVISION = '(No Division)';

    private readonly int $batchRows;

    private readonly int $stageTtlHours;

    /**
     * The initial password hash for accounts this import creates, derived at
     * most once per run. See initialPasswordHash().
     */
    private ?string $initialHash = null;

    public function __construct(
        private readonly PDO $pdo,
        int $batchRows = 500,
        int $stageTtlHours = 24,
        private readonly string $defaultPassword = '1234',
        private readonly string $passwordAlgo = PASSWORD_BCRYPT,
        private readonly int $passwordCost = 11,
    ) {
        $this->batchRows     = max(1, $batchRows);
        $this->stageTtlHours = max(1, $stageTtlHours);
    }

    public static function fromApp(App $app): self
    {
        $config = $app->config();

        return new self(
            $app->db(),
            (int) $config->get('import.batch_rows', 500),
            (int) $config->get('import.stage_ttl_hours', 24),
            (string) $config->get('auth.default_password', '1234'),
            (string) $config->get('auth.password_algo', PASSWORD_BCRYPT),
            (int) $config->get('auth.password_cost', 11),
        );
    }

    /**
     * The hash every account created by ONE import run shares.
     *
     * Derived once and reused, deliberately. bcrypt at cost 11 takes ~50ms;
     * the first complete import of the real roster creates 196 officer
     * accounts, and 196 separate derivations would spend a third of this
     * host's 30-second execution budget salting one publicly documented
     * string. What a shared salt leaks is that those accounts share a
     * password, which is already true, already written down in spec 3.1, and
     * already `1234`. Every one of them carries must_change_password, so the
     * shared value survives exactly until its owner first signs in.
     */
    private function initialPasswordHash(): string
    {
        return $this->initialHash ??= password_hash(
            $this->defaultPassword,
            $this->passwordAlgo,
            ['cost' => $this->passwordCost]
        );
    }

    // -----------------------------------------------------------------------
    // Step one: parse and stage. Nothing in `member` changes.
    // -----------------------------------------------------------------------

    /**
     * Reads a roster into `import_batch` + `import_staged_row` and returns the
     * batch id. `member` is untouched, and so is every table in spec 6.6's
     * "we own" list.
     *
     * @param string  $path         a readable file — .xls, .xlsx or .csv, decided by content
     * @param string  $filename     what to call it in the record; the upload's own name
     * @param ?int    $teamId       required in team mode, refused in the others
     * @param ?int    $uploadedBy   app_user.id, or null for a run with no signed-in user
     *
     * @throws ImportException when the file or the request cannot be imported at all
     */
    public function stage(
        string $path,
        string $filename,
        string $mode = self::MODE_COMPLETE,
        ?int $teamId = null,
        ?int $uploadedBy = null,
    ): int {
        if (!in_array($mode, self::MODES, true)) {
            throw new ImportException("Unknown import mode '{$mode}'.");
        }
        if ($mode === self::MODE_TEAM && $teamId === null) {
            throw new ImportException(
                'Team mode needs a team. The Admin chooses it, and the import verifies every '
                . "row's Subcommittee 1 against it rather than trusting the file (spec 6.2)."
            );
        }
        if ($mode !== self::MODE_TEAM && $teamId !== null) {
            throw new ImportException('Only a team import takes a team.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportException("Cannot read {$path}.");
        }

        $showYear = $this->activeShowYear();
        $teamName = $teamId === null ? null : $this->teamName($teamId);

        // The header is read in its own pass, before a batch exists, so that a
        // file this import cannot read leaves no record of an import that
        // never started. It also sidesteps a real trap: the readers return
        // generators, and a generator broken out of after one row cannot be
        // re-entered without either rewinding it — which throws — or handing
        // the header back a second time as though it were a member.
        $headers = HeaderMap::fromHeaderRow($this->readHeaderRow($path));

        $batchId = $this->createBatch($showYear['id'], $mode, $teamId, $filename, $path, $uploadedBy);

        try {
            return $this->stageRows(
                $batchId,
                $headers,
                Spreadsheet::open($path)->rows(),
                $mode,
                $teamId,
                $teamName,
                $showYear['id']
            );
        } catch (Throwable $e) {
            // A batch that failed mid-parse is not a record of anything, and
            // leaving it staged would offer the Admin an apply button for half
            // a roster.
            $this->discard($batchId);

            throw $e;
        }
    }

    /**
     * The first row of the file, or a refusal naming why there is not one.
     *
     * @return array<int, string>
     */
    private function readHeaderRow(string $path): array
    {
        foreach (Spreadsheet::open($path)->rows() as $row) {
            return $row;
        }

        throw new ImportException(
            'The file has no rows at all — not even a header. If it came out of Excel, check '
            . 'that the roster is on the first sheet.'
        );
    }

    /**
     * @param iterable<int, array<int, string>> $rows every row of the file, header included
     */
    private function stageRows(
        int $batchId,
        HeaderMap $headers,
        iterable $rows,
        string $mode,
        ?int $teamId,
        ?string $teamName,
        int $showYearId,
    ): int {
        $warnings = new Warnings($this->pdo, $batchId, $this->batchRows);

        // Everything the diff needs, read once. 1,954 members is a few
        // megabytes against a 128M limit, and the alternative is 1,954
        // SELECTs against a database on another machine.
        $existing      = $this->loadExistingMembers();
        $systemNumbers = $this->loadSystemMemberNumbers();
        $emailOwners   = $this->loadEmailOwners();
        $existingTeams = $this->loadTeamDivisions();
        $metricsNow    = $this->loadMetrics($showYearId);

        $counts  = ['read' => 0, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'skip' => 0];
        $flips   = [];
        $seen    = [];
        $teamsInFile = [];
        $staged    = [];
        $rowNumber = 0;

        foreach ($rows as $row) {
            $rowNumber++;

            // Row 1 is the header, already parsed into $headers by the caller.
            // Row numbers count it, so a number reported in a warning is the
            // number the Admin sees when they open the file to look.
            if ($rowNumber === 1) {
                continue;
            }

            $counts['read']++;

            $values  = RowNormaliser::normalise($headers, $row);
            $number  = $values['member_number'];

            if ($number === '') {
                $counts['skip']++;
                $warnings->add(Warnings::MISSING_MEMBER_NUMBER, $rowNumber, null, $this->describeRow($values));
                $staged[] = $this->stagedRow($batchId, $rowNumber, '', 'skip', null, null, null);
                $staged   = $this->flushStaged($staged);
                continue;
            }

            if (mb_strlen($number) > RowNormaliser::MEMBER_NUMBER_WIDTH) {
                $counts['skip']++;
                $warnings->add(
                    Warnings::MISSING_MEMBER_NUMBER,
                    $rowNumber,
                    mb_substr($number, 0, RowNormaliser::MEMBER_NUMBER_WIDTH),
                    'Customer Number is ' . mb_strlen($number) . ' characters; the column holds '
                    . RowNormaliser::MEMBER_NUMBER_WIDTH . '. Shortening it would match a different member.'
                );
                $staged[] = $this->stagedRow($batchId, $rowNumber, '', 'skip', null, null, null);
                $staged   = $this->flushStaged($staged);
                continue;
            }

            if (isset($systemNumbers[$number])) {
                $counts['skip']++;
                $warnings->add(
                    Warnings::SYSTEM_MEMBER_NUMBER,
                    $rowNumber,
                    $number,
                    'This number belongs to an account this application created, not to a committee member.'
                );
                $staged[] = $this->stagedRow($batchId, $rowNumber, $number, 'skip', null, null, null);
                $staged   = $this->flushStaged($staged);
                continue;
            }

            if (isset($seen[$number])) {
                $counts['skip']++;
                $warnings->add(
                    Warnings::DUPLICATE_MEMBER_NUMBER,
                    $rowNumber,
                    $number,
                    "Already imported from row {$seen[$number]}. This row was skipped; the first one wins."
                );
                $staged[] = $this->stagedRow($batchId, $rowNumber, $number, 'skip', null, null, null);
                $staged   = $this->flushStaged($staged);
                continue;
            }

            // Team mode verifies rather than retargets: a row that names
            // another team is reported and skipped, never quietly moved into
            // the team the Admin chose.
            if ($mode === self::MODE_TEAM && !$this->sameTeam($values['team_name'], (string) $teamName)) {
                $counts['skip']++;
                $warnings->add(
                    Warnings::WRONG_TEAM,
                    $rowNumber,
                    $number,
                    sprintf(
                        'Row is on %s; this import is for %s.',
                        $values['team_name'] === '' ? '(no team)' : $values['team_name'],
                        (string) $teamName
                    )
                );
                $staged[] = $this->stagedRow($batchId, $rowNumber, $number, 'skip', null, null, null);
                $staged   = $this->flushStaged($staged);
                continue;
            }

            $seen[$number] = $rowNumber;
            $current       = $existing[$number] ?? null;

            $this->warnAboutRow(
                $warnings,
                $rowNumber,
                $number,
                $values,
                $headers,
                $row,
                $emailOwners,
                $existingTeams,
                $teamsInFile,
                $mode
            );

            // Update mode touches nobody it has not already got.
            if ($current === null && $mode === self::MODE_UPDATE) {
                $counts['skip']++;
                $warnings->add(
                    Warnings::NOT_IN_ROSTER,
                    $rowNumber,
                    $number,
                    'Not in the roster. An update import never creates a member — run a complete '
                    . 'or team import to add them.'
                );
                $staged[] = $this->stagedRow($batchId, $rowNumber, $number, 'skip', null, null, null);
                $staged   = $this->flushStaged($staged);
                continue;
            }

            $metrics = RowNormaliser::metrics($headers, $row);
            $values['metrics'] = $metrics;
            $values['mode']    = $mode;

            $memberId = $current === null ? null : (int) $current['id'];
            $changes  = $current === null
                ? []
                : $this->diff($current, $values, $mode, $metricsNow[$memberId] ?? []);

            $this->recordFlips($flips, $current, $metrics, $metricsNow[$memberId] ?? []);

            if ($current === null) {
                $counts['create']++;
                $action = 'create';
            } elseif ($changes !== []) {
                $counts['update']++;
                $action = 'update';
            } else {
                $counts['unchanged']++;
                $action = 'unchanged';
            }

            $staged[] = $this->stagedRow($batchId, $rowNumber, $number, $action, $memberId, $values, $changes);
            $staged   = $this->flushStaged($staged);
        }

        $this->flushStaged($staged, true);

        // Absence: flag, never delete (spec 6.5). Update mode flags nobody —
        // it is not a statement about who is on the committee.
        $absent = $mode === self::MODE_UPDATE
            ? []
            : $this->absentMembers($existing, $seen, $mode, $teamId);

        $this->stageAbsent($batchId, $absent);

        $warnings->flush();

        $summary = [
            'metric_flips'    => $this->summariseFlips($flips),
            'new_teams'       => $this->newTeamNames($teamsInFile, $existingTeams),
            'warning_counts'  => $warnings->counts(),
            'absent_examples' => array_slice(array_column($absent, 'member_number'), 0, 20),
        ];

        $this->finishBatch($batchId, $counts, count($absent), $warnings->total(), $summary);

        return $batchId;
    }

    // -----------------------------------------------------------------------
    // Warnings for one row
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>       $values
     * @param array<int, string>         $row
     * @param array<string, string> $emailOwners   lowercased email => member number.
     *                                             BY REFERENCE: two rows of the same
     *                                             file sharing an address is the case
     *                                             this warning exists for, and it can
     *                                             only be seen by carrying what has
     *                                             been read so far into the next row.
     * @param array<string, string> $existingTeams team key => division name
     * @param array<string, array{name: string, division: string}> $teamsInFile this file's teams
     */
    private function warnAboutRow(
        Warnings $warnings,
        int $rowNumber,
        string $number,
        array $values,
        HeaderMap $headers,
        array $row,
        array &$emailOwners,
        array $existingTeams,
        array &$teamsInFile,
        string $mode,
    ): void {
        if (!TitleMap::knows((string) $values['title'])) {
            $warnings->add(
                Warnings::UNKNOWN_TITLE,
                $rowNumber,
                $number,
                sprintf(
                    'Title %s is not in the map — imported as Member, with no login.',
                    $values['title'] === '' ? '(blank)' : '"' . $values['title'] . '"'
                )
            );
        }

        // The bucket makes those 72 members reachable; it does not make them
        // correctly placed, so the warning fires whether or not they land
        // somewhere (spec 5.1a).
        if ($values['division_name'] === '') {
            $warnings->add(
                Warnings::NO_DIVISION,
                $rowNumber,
                $number,
                'Subcommittee 3 is blank — placed in ' . self::NO_DIVISION . '.'
            );
        }

        if ($values['email'] === null) {
            $warnings->add(
                Warnings::NO_EMAIL,
                $rowNumber,
                $number,
                'No address on file, so password recovery cannot reach this member.'
            );
        } else {
            $key = mb_strtolower((string) $values['email'], 'UTF-8');
            if (isset($emailOwners[$key]) && $emailOwners[$key] !== $number) {
                $warnings->add(
                    Warnings::SHARED_EMAIL,
                    $rowNumber,
                    $number,
                    'Also on file for member ' . $emailOwners[$key]
                    . '. A recovery email has to name the member number it applies to.'
                );
            } else {
                $emailOwners[$key] = $number;
            }
        }

        if ($values['phone'] !== '' && !RowNormaliser::isCellPhone((string) $values['phone_type'])) {
            $warnings->add(
                Warnings::NON_CELL_PHONE,
                $rowNumber,
                $number,
                sprintf(
                    'Phone type is %s — the text link is suppressed rather than failing silently.',
                    $values['phone_type'] === '' ? '(blank)' : $values['phone_type']
                )
            );
        }

        if ($values['phone'] !== '' && $values['phone_e164'] === null) {
            $warnings->add(
                Warnings::UNPARSEABLE_PHONE,
                $rowNumber,
                $number,
                'Imported as text; no call or text link will be offered.'
            );
        }

        foreach (RowNormaliser::METRICS as $metric => $column) {
            $raw = $headers->value($row, $column);
            if (RowNormaliser::metricIsUnexpected($raw)) {
                $warnings->add(
                    Warnings::UNEXPECTED_METRIC_VALUE,
                    $rowNumber,
                    $number,
                    sprintf('%s is "%s" — neither Y nor N. Imported as Not reported.', $column, $raw)
                );
            }
        }

        $team = (string) $values['team_name'];
        if ($team === '') {
            return;
        }

        $division = $values['division_name'] === '' ? self::NO_DIVISION : (string) $values['division_name'];
        $teamKey  = $this->teamKey($team);

        // Not in update mode: it writes neither team nor division, so a
        // warning promising that the team "will be created" would be a
        // promise the apply does not keep.
        if ($mode !== self::MODE_UPDATE
            && !isset($existingTeams[$teamKey])
            && !isset($teamsInFile[$teamKey])
        ) {
            $warnings->add(
                Warnings::NEW_TEAM,
                $rowNumber,
                $number,
                sprintf('Team "%s" has not been seen before — it will be created under %s.', $team, $division)
            );
        }

        // Seven teams in the sample genuinely span two divisions, so this is
        // information rather than an error: division is a property of the
        // MEMBER, and team.division_id is only the modal value for display.
        $known = $teamsInFile[$teamKey]['division'] ?? $existingTeams[$teamKey] ?? null;
        if ($known !== null && $known !== $division) {
            $warnings->add(
                Warnings::TEAM_DIVISION_CONFLICT,
                $rowNumber,
                $number,
                sprintf('Team "%s" also appears under %s. Division follows the member, not the team.', $team, $known)
            );
        }

        // Keyed by the folded name so a stray capital is not a second team,
        // but the file's own spelling is what the preview shows.
        $teamsInFile[$teamKey] ??= ['name' => $team, 'division' => $division];
    }

    /**
     * Teams this file introduces, in the file's own spelling.
     *
     * @param array<string, array{name: string, division: string}> $inFile
     * @param array<string, string>                                $existing
     *
     * @return array<int, string>
     */
    private function newTeamNames(array $inFile, array $existing): array
    {
        $names = [];
        foreach ($inFile as $key => $team) {
            if (!isset($existing[$key])) {
                $names[] = $team['name'];
            }
        }

        return $names;
    }

    // -----------------------------------------------------------------------
    // The diff
    // -----------------------------------------------------------------------

    /**
     * What would change on an existing member. Empty means unchanged.
     *
     * @param array<string, mixed> $current the member row as it stands
     * @param array<string, mixed> $values  the normalised file row
     * @param array<string, string> $metricsNow metric => imported_value
     *
     * @return array<string, array{0: mixed, 1: mixed}> field => [before, after]
     */
    private function diff(array $current, array $values, string $mode, array $metricsNow): array
    {
        $fields = $mode === self::MODE_UPDATE ? self::UPDATE_MODE_FIELDS : self::OWNED_FIELDS;
        $changes = [];

        foreach ($fields as $field) {
            $before = $current[$field] ?? null;
            $after  = $values[$field] ?? null;

            if (is_int($before) || is_bool($before)) {
                $before = (int) $before;
                $after  = (int) $after;
            }

            if ((string) $before !== (string) $after || ($before === null) !== ($after === null)) {
                $changes[$field] = [$before, $after];
            }
        }

        // Team and division are compared by name — the ids may not exist yet.
        if ($mode !== self::MODE_UPDATE) {
            $team = (string) $values['team_name'];
            if ($this->teamKey((string) ($current['team_name'] ?? '')) !== $this->teamKey($team)) {
                $changes['team'] = [(string) ($current['team_name'] ?? ''), $team];
            }

            // Every import re-evaluates the placeholder: a populated
            // Subcommittee 3 moves the member out, a blank one moves them in.
            // Never sticky (spec 5.1a).
            $division = $values['division_name'] === '' ? self::NO_DIVISION : (string) $values['division_name'];
            if ((string) ($current['division_name'] ?? '') !== $division) {
                $changes['division'] = [(string) ($current['division_name'] ?? ''), $division];
            }
        }

        foreach ($values['metrics'] as $metric => $after) {
            $before = $metricsNow[$metric] ?? 'unknown';
            if ($before !== $after) {
                $changes['metric:' . $metric] = [$before, $after];
            }
        }

        // A member flagged absent by an earlier import who is back in the file
        // is a change, even when every field matches: the flag is coming off.
        if (($current['absent_since_import_id'] ?? null) !== null) {
            $changes['absent_since_import_id'] = ['flagged', 'seen again'];
        }

        return $changes;
    }

    /**
     * @param array<string, array<string, int>> $flips
     * @param ?array<string, mixed>             $current
     * @param array<string, string>             $metrics
     * @param array<string, string>             $metricsNow
     */
    private function recordFlips(array &$flips, ?array $current, array $metrics, array $metricsNow): void
    {
        foreach ($metrics as $metric => $after) {
            $before = $current === null ? 'new' : ($metricsNow[$metric] ?? 'unknown');
            if ($before === $after) {
                continue;
            }
            $flips[$metric][$before . '->' . $after] = ($flips[$metric][$before . '->' . $after] ?? 0) + 1;
        }
    }

    /**
     * "412 members would move to Committee Dues = Y", in a shape a view can
     * render without re-deriving anything.
     *
     * @param array<string, array<string, int>> $flips
     *
     * @return array<int, array{metric: string, from: string, to: string, members: int}>
     */
    private function summariseFlips(array $flips): array
    {
        $summary = [];
        foreach ($flips as $metric => $transitions) {
            foreach ($transitions as $transition => $count) {
                [$from, $to] = explode('->', $transition, 2);
                $summary[] = ['metric' => $metric, 'from' => $from, 'to' => $to, 'members' => $count];
            }
        }

        usort($summary, static fn (array $a, array $b): int => $b['members'] <=> $a['members']);

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Absence
    // -----------------------------------------------------------------------

    /**
     * Members the file did not mention. Flagged, never deleted (spec 6.5).
     *
     * Purged members are already out of every roster and are not re-flagged.
     * System rows are not here at all — loadExistingMembers() never returns
     * one, which is what keeps the first complete import from flagging the
     * only account that can sign in.
     *
     * @param array<string, array<string, mixed>> $existing
     * @param array<string, int>                  $seen
     *
     * @return array<int, array{id: int, member_number: string}>
     */
    private function absentMembers(array $existing, array $seen, string $mode, ?int $teamId): array
    {
        $absent = [];

        foreach ($existing as $number => $member) {
            if (isset($seen[$number])) {
                continue;
            }
            if ($member['purged_at'] !== null) {
                continue;
            }
            // A team import is a statement about one team only. Flagging
            // everybody else absent because they were not in a 40-row file is
            // how a whole committee lands on the purge screen.
            if ($mode === self::MODE_TEAM && (int) ($member['team_id'] ?? 0) !== (int) $teamId) {
                continue;
            }
            if ($member['absent_since_import_id'] !== null) {
                // Already flagged by an earlier import; re-flagging would
                // rewrite which batch first noticed, which is the one fact the
                // purge screen needs.
                continue;
            }

            $absent[] = ['id' => (int) $member['id'], 'member_number' => (string) $number];
        }

        return $absent;
    }

    /** @param array<int, array{id: int, member_number: string}> $absent */
    private function stageAbsent(int $batchId, array $absent): void
    {
        $rows = [];
        foreach ($absent as $member) {
            $rows[] = $this->stagedRow($batchId, 0, $member['member_number'], 'absent', $member['id'], null, null);
            $rows   = $this->flushStaged($rows);
        }
        $this->flushStaged($rows, true);
    }

    // -----------------------------------------------------------------------
    // Step two: the preview
    // -----------------------------------------------------------------------

    /**
     * Everything the Admin reads before deciding (spec 6.3).
     *
     * @return array<string, mixed>
     *
     * @throws ImportException when the batch is gone or has already been applied
     */
    public function preview(int $batchId, int $sampleSize = 20): array
    {
        $batch = $this->batch($batchId);

        return [
            'batch'          => $batch,
            'counts'         => [
                'read'      => (int) $batch['rows_read'],
                'create'    => (int) $batch['rows_created'],
                'update'    => (int) $batch['rows_updated'],
                'unchanged' => (int) $batch['rows_unchanged'],
                'absent'    => (int) $batch['rows_absent'],
                'skipped'   => $this->countAction($batchId, 'skip'),
            ],
            'metric_flips'   => $batch['summary']['metric_flips'] ?? [],
            'new_teams'      => $batch['summary']['new_teams'] ?? [],
            'warnings'       => Warnings::countsFor($this->pdo, $batchId),
            // The 20 largest changes, row by row. Not 1,954 form fields:
            // max_input_vars is 1000 and PHP truncates past it in silence.
            'largest'        => $this->largestChanges($batchId, $sampleSize),
            'sample_creates' => $this->sampleByAction($batchId, 'create', $sampleSize),
            'sample_absent'  => $this->sampleByAction($batchId, 'absent', $sampleSize),
            'expires_at'     => $this->expiryOf($batch),
            'applied'        => $batch['applied_at'] !== null,
        ];
    }

    /**
     * The rows whose diff touches the most fields, which is where a mistake
     * in a file shows up first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function largestChanges(int $batchId, int $limit): array
    {
        $read = $this->pdo->prepare(
            'SELECT s.`row_number`, s.member_number, s.changes, m.first_name, m.last_name, m.preferred_name '
            . 'FROM import_staged_row s LEFT JOIN member m ON m.id = s.member_id '
            . "WHERE s.import_batch_id = :batch AND s.action = 'update' ORDER BY s.id"
        );
        $read->execute([':batch' => $batchId]);

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $changes = json_decode((string) $row['changes'], true);
            $rows[] = [
                'row_number'    => (int) $row['row_number'],
                'member_number' => (string) $row['member_number'],
                'name'          => $this->displayName($row),
                'changes'       => is_array($changes) ? $changes : [],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => count($b['changes']) <=> count($a['changes']));

        return array_slice($rows, 0, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    private function sampleByAction(int $batchId, string $action, int $limit): array
    {
        $read = $this->pdo->prepare(
            'SELECT s.`row_number`, s.member_number, s.payload, m.first_name, m.last_name, m.preferred_name '
            . 'FROM import_staged_row s LEFT JOIN member m ON m.id = s.member_id '
            . 'WHERE s.import_batch_id = :batch AND s.action = :action ORDER BY s.id LIMIT ' . max(1, $limit)
        );
        $read->execute([':batch' => $batchId, ':action' => $action]);

        $rows = [];
        foreach ($read->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $payload = is_array($payload) ? $payload : [];

            $name = $this->displayName($row);

            $rows[] = [
                'row_number'    => (int) $row['row_number'],
                'member_number' => (string) $row['member_number'],
                // A create has no member row to join to, so the name comes
                // from what was parsed rather than from what is stored.
                'name'          => $name !== '' ? $name : $this->displayName($payload),
                'team'          => (string) ($payload['team_name'] ?? ''),
                'title'         => (string) ($payload['title'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function displayName(array $row): string
    {
        $preferred = trim((string) ($row['preferred_name'] ?? ''));
        $first     = $preferred !== '' ? $preferred : trim((string) ($row['first_name'] ?? ''));

        return trim($first . ' ' . trim((string) ($row['last_name'] ?? '')));
    }

    private function countAction(int $batchId, string $action): int
    {
        $read = $this->pdo->prepare(
            'SELECT COUNT(*) FROM import_staged_row WHERE import_batch_id = :batch AND action = :action'
        );
        $read->execute([':batch' => $batchId, ':action' => $action]);

        return (int) $read->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Step three: apply
    // -----------------------------------------------------------------------

    /**
     * Writes the staged batch to `member`, `member_metric` and `app_user`.
     *
     * The second, explicit act. Everything before this point is reversible by
     * doing nothing.
     *
     * @return array<string, int>
     *
     * @throws ImportException
     */
    public function apply(int $batchId, ?int $actorUserId = null): array
    {
        $batch = $this->batch($batchId);

        if ($batch['applied_at'] !== null) {
            throw new ImportException(
                "Batch {$batchId} was already applied at {$batch['applied_at']} UTC. "
                . 'Staging a file again is how to re-import it — re-applying a batch would '
                . 'write a diff computed against a roster that has since changed.'
            );
        }

        $showYear = $this->showYear((int) $batch['show_year_id']);
        if ((int) $showYear['is_open'] !== 1) {
            throw new ImportException(
                "Show year {$showYear['label']} is closed. A closed year is read-only and exportable; "
                . 'open it, or make another year active, before importing into it.'
            );
        }

        $mode       = (string) $batch['mode'];
        $showYearId = (int) $batch['show_year_id'];
        $now        = App::now();

        // Reference data first, outside the row loop: a team created per row
        // would be 1,954 round trips to a database on another machine.
        //
        // Update mode resolves nothing, because it writes neither team nor
        // division (spec 6.2). Creating them anyway would let a file that is
        // not allowed to move anybody still add 96 empty teams to the
        // dashboard.
        $divisions = $mode === self::MODE_UPDATE ? [] : $this->resolveDivisions($batchId);
        $teams     = $mode === self::MODE_UPDATE ? [] : $this->resolveTeams($batchId, $divisions);

        $applied = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'absent' => 0, 'accounts' => 0, 'progress_reset' => 0];

        foreach (['create', 'update', 'unchanged'] as $action) {
            foreach ($this->stagedChunks($batchId, $action) as $chunk) {
                $this->pdo->beginTransaction();
                try {
                    $result = $this->applyChunk($chunk, $action, $mode, $batchId, $showYearId, $divisions, $teams, $actorUserId, $now);
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    $this->pdo->rollBack();

                    throw $e;
                }

                foreach ($result as $key => $value) {
                    $applied[$key] = ($applied[$key] ?? 0) + $value;
                }
            }
        }

        // Absence last: a member seen in this file has already had their flag
        // cleared above, so nothing here can flag somebody the file contained.
        foreach ($this->stagedChunks($batchId, 'absent') as $chunk) {
            $this->pdo->beginTransaction();
            try {
                $applied['absent'] += $this->applyAbsent($chunk, $batchId);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();

                throw $e;
            }
        }

        $this->markApplied($batchId, $actorUserId, $applied);

        return $applied;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @param array<string, int>               $divisions name => id
     * @param array<string, int>               $teams     key  => id
     *
     * @return array<string, int>
     */
    private function applyChunk(
        array $chunk,
        string $action,
        string $mode,
        int $batchId,
        int $showYearId,
        array $divisions,
        array $teams,
        ?int $actorUserId,
        string $now,
    ): array {
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'accounts' => 0, 'progress_reset' => 0];

        $payloads = [];
        foreach ($chunk as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            $payload['__staged_id'] = (int) $row['id'];
            $payload['__member_id'] = $row['member_id'] === null ? null : (int) $row['member_id'];
            $payloads[] = $payload;
        }

        if ($payloads === []) {
            return $result;
        }

        if ($action === 'create') {
            $result['created'] = $this->createMembers($payloads, $divisions, $teams, $batchId, $now);
        } elseif ($action === 'update') {
            $result['updated'] = $this->updateMembers($payloads, $mode, $divisions, $teams, $batchId, $now);
        } else {
            // Unchanged still records that this batch saw them, and clears an
            // absence flag if one was standing.
            $result['unchanged'] = $this->touchMembers($payloads, $batchId);
        }

        // Ids for the rows just created; there is no RETURNING here.
        $ids = $this->memberIds(array_column($payloads, 'member_number'));

        $metricResult = $this->applyMetrics($payloads, $ids, $showYearId, $batchId, $actorUserId, $now);
        $result['progress_reset'] = $metricResult['progress_reset'];

        // Update mode leaves member.title and member.title_level alone, so it
        // must leave the account alone too. Moving a level from a title the
        // roster row does not carry would deactivate an officer's login on the
        // strength of a field this import declined to import.
        $result['accounts'] = $mode === self::MODE_UPDATE
            ? 0
            : $this->applyAccounts($payloads, $ids, $actorUserId);

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @param array<string, int>               $divisions
     * @param array<string, int>               $teams
     */
    private function createMembers(array $payloads, array $divisions, array $teams, int $batchId, string $now): int
    {
        $columns = array_merge(['member_number'], self::OWNED_FIELDS, [
            'division_id', 'team_id', 'first_imported_at', 'last_seen_import_id', 'is_active',
        ]);

        $rows = [];
        foreach ($payloads as $payload) {
            $row = ['member_number' => $payload['member_number']];
            foreach (self::OWNED_FIELDS as $field) {
                $row[$field] = self::field($payload, $field);
            }
            $row['division_id']         = $this->divisionIdFor($payload, $divisions);
            $row['team_id']             = $this->teamIdFor($payload, $teams);
            $row['first_imported_at']   = $now;
            $row['last_seen_import_id'] = $batchId;
            $row['is_active']           = 1;

            $rows[] = $row;
        }

        return $this->insertRows('member', $columns, $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @param array<string, int>               $divisions
     * @param array<string, int>               $teams
     */
    private function updateMembers(array $payloads, string $mode, array $divisions, array $teams, int $batchId, string $now): int
    {
        // Update mode writes metrics, phone and email and nothing else — the
        // brief asks for the contact fields in every mode because they are
        // what goes stale fastest (spec 6.6).
        $fields = $mode === self::MODE_UPDATE ? self::UPDATE_MODE_FIELDS : self::OWNED_FIELDS;

        $assignments = [];
        foreach ($fields as $field) {
            $assignments[] = "`{$field}` = :{$field}";
        }
        $assignments[] = '`last_seen_import_id` = :batch';
        // A member who reappears is un-flagged automatically (spec 6.5).
        $assignments[] = '`absent_since_import_id` = NULL';

        if ($mode !== self::MODE_UPDATE) {
            $assignments[] = '`division_id` = :division_id';
            $assignments[] = '`team_id` = :team_id';
        }

        $statement = $this->pdo->prepare(
            'UPDATE `member` SET ' . implode(', ', $assignments) . ' WHERE `id` = :id AND `is_system` = 0'
        );

        $updated = 0;
        foreach ($payloads as $payload) {
            $bind = [':id' => $payload['__member_id'], ':batch' => $batchId];
            foreach ($fields as $field) {
                $bind[':' . $field] = self::field($payload, $field);
            }
            if ($mode !== self::MODE_UPDATE) {
                $bind[':division_id'] = $this->divisionIdFor($payload, $divisions);
                $bind[':team_id']     = $this->teamIdFor($payload, $teams);
            }

            $statement->execute($bind);
            $updated++;
        }

        return $updated;
    }

    /** @param array<int, array<string, mixed>> $payloads */
    private function touchMembers(array $payloads, int $batchId): int
    {
        $ids = array_values(array_filter(array_column($payloads, '__member_id')));
        if ($ids === []) {
            return 0;
        }

        [$placeholders, $bind] = $this->inList($ids, 'id');
        $bind[':batch'] = $batchId;

        $this->pdo->prepare(
            'UPDATE `member` SET `last_seen_import_id` = :batch, `absent_since_import_id` = NULL '
            . "WHERE `id` IN ({$placeholders}) AND `is_system` = 0"
        )->execute($bind);

        return count($ids);
    }

    /**
     * Metrics, and the one exception to the ownership rule.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @param array<string, int>               $ids member_number => member id
     *
     * @return array<string, int>
     */
    private function applyMetrics(array $payloads, array $ids, int $showYearId, int $batchId, ?int $actorUserId, string $now): array
    {
        $memberIds = [];
        foreach ($payloads as $payload) {
            $id = $ids[(string) $payload['member_number']] ?? null;
            if ($id !== null) {
                $memberIds[] = $id;
            }
        }

        if ($memberIds === []) {
            return ['progress_reset' => 0];
        }

        $existing = $this->loadMetricsFor($memberIds, $showYearId);

        $rows   = [];
        $resets = [];

        foreach ($payloads as $payload) {
            $memberId = $ids[(string) $payload['member_number']] ?? null;
            if ($memberId === null) {
                continue;
            }

            foreach (($payload['metrics'] ?? []) as $metric => $value) {
                $rows[] = [
                    'member_id'         => $memberId,
                    'show_year_id'      => $showYearId,
                    'metric'            => $metric,
                    'imported_value'    => $value,
                    'imported_at'       => $now,
                    'imported_batch_id' => $batchId,
                ];

                $before = $existing[$memberId][$metric] ?? null;
                if ($before === null) {
                    continue;
                }

                // THE ONE EXCEPTION (spec 6.6). N -> Y means the thing being
                // chased has happened, so "in progress" is now false. An
                // import that leaves it N preserves progress untouched, so a
                // roster refresh never erases work still in flight.
                if ($before['imported_value'] === 'N'
                    && $value === 'Y'
                    && $before['progress'] !== 'not_started'
                ) {
                    $resets[] = [
                        'member_id' => $memberId,
                        'metric'    => $metric,
                        'before'    => $before,
                    ];
                }
            }
        }

        $this->insertRows(
            'member_metric',
            ['member_id', 'show_year_id', 'metric', 'imported_value', 'imported_at', 'imported_batch_id'],
            $rows,
            // VALUES() rather than the row alias MySQL 8.0.19 introduced:
            // MariaDB 10.11 does not accept `AS new`, CI runs both engines,
            // and production's 8.0.41 still supports VALUES() (deprecated, not
            // removed). It is the only form both understand. The alternative
            // — one UPDATE per row — is 9,770 round trips to a database on
            // another machine, inside a 30-second ceiling.
            'ON DUPLICATE KEY UPDATE `imported_value` = VALUES(`imported_value`), '
            . '`imported_at` = VALUES(`imported_at`), `imported_batch_id` = VALUES(`imported_batch_id`)'
        );

        if ($resets === []) {
            return ['progress_reset' => 0];
        }

        // Recorded, never silent: the prior progress, its author and its note
        // go to audit_log with the batch that cleared them. contact_log is not
        // touched — the record of who called whom survives every import
        // unconditionally, and that is what keeps "why did this flip back to
        // N" answerable.
        $reset = $this->pdo->prepare(
            'UPDATE `member_metric` SET `progress` = :progress, `progress_by` = NULL, '
            . '`progress_at` = NULL, `progress_note` = :note '
            . 'WHERE `member_id` = :member AND `show_year_id` = :year AND `metric` = :metric'
        );

        $audit = $this->pdo->prepare(
            'INSERT INTO `audit_log` (`actor_user_id`, `action`, `entity`, `entity_id`, `before_json`, `after_json`, `ip`) '
            . 'VALUES (:actor, :action, :entity, :entity_id, :before_json, :after_json, :ip)'
        );

        foreach ($resets as $item) {
            $reset->execute([
                ':progress' => 'not_started',
                ':note'     => '',
                ':member'   => $item['member_id'],
                ':year'     => $showYearId,
                ':metric'   => $item['metric'],
            ]);

            $audit->execute([
                ':actor'       => $actorUserId,
                ':action'      => 'import_reset_progress',
                ':entity'      => 'member_metric',
                ':entity_id'   => $item['member_id'] . ':' . $showYearId . ':' . $item['metric'],
                ':before_json' => self::json([
                    'imported_value' => $item['before']['imported_value'],
                    'progress'       => $item['before']['progress'],
                    'progress_by'    => $item['before']['progress_by'],
                    'progress_at'    => $item['before']['progress_at'],
                    'progress_note'  => $item['before']['progress_note'],
                ]),
                ':after_json'  => self::json([
                    'imported_value'  => 'Y',
                    'progress'        => 'not_started',
                    'import_batch_id' => $batchId,
                    'why'             => 'imported_value moved N to Y, so the tracked work is done',
                ]),
                ':ip'          => '',
            ]);
        }

        return ['progress_reset' => count($resets)];
    }

    /**
     * Accounts follow the title level, and never the other way round.
     *
     * A demotion deactivates, it never deletes: the audit trail outlives the
     * account and a re-promotion reactivates the same row (spec 6.6). A
     * granted_level holds an account open through any demotion — that is the
     * entire point of designation, and this method never writes one.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @param array<string, int>               $ids
     */
    private function applyAccounts(array $payloads, array $ids, ?int $actorUserId): int
    {
        $wanted = [];
        foreach ($payloads as $payload) {
            $memberId = $ids[(string) $payload['member_number']] ?? null;
            if ($memberId !== null) {
                $wanted[$memberId] = (string) $payload['title_level'];
            }
        }

        if ($wanted === []) {
            return 0;
        }

        [$placeholders, $bind] = $this->inList(array_keys($wanted), 'm');
        $read = $this->pdo->prepare(
            'SELECT `id`, `member_id`, `level`, `granted_level`, `is_active` FROM `app_user` '
            . "WHERE `member_id` IN ({$placeholders})"
        );
        $read->execute($bind);

        $accounts = [];
        foreach ($read->fetchAll() as $row) {
            $accounts[(int) $row['member_id']] = $row;
        }

        $touched = 0;

        $update = $this->pdo->prepare(
            'UPDATE `app_user` SET `level` = :level, `is_active` = :active WHERE `id` = :id'
        );

        $create = $this->pdo->prepare(
            'INSERT INTO `app_user` (`member_id`, `level`, `password_hash`, `must_change_password`, `is_active`) '
            . 'VALUES (:member, :level, :hash, 1, 1)'
        );

        foreach ($wanted as $memberId => $level) {
            $account = $accounts[$memberId] ?? null;
            $grants  = Level::from($level)->grantsLogin();

            if ($account === null) {
                // Member is data, not a user: 1,758 of 1,954 have no row here
                // at all, and creating disabled accounts for them would put a
                // password hash beside every home address in the database.
                if (!$grants) {
                    continue;
                }

                $create->execute([
                    ':member' => $memberId,
                    ':level'  => $level,
                    // Spec 3.1: the initial password is 1234 with
                    // must_change_password set. It is hashed like any other,
                    // because a column that sometimes holds plaintext is a
                    // column something will one day compare as plaintext.
                    ':hash'   => $this->initialPasswordHash(),
                ]);
                $touched++;
                continue;
            }

            // A granted level holds the account open whatever the roster now
            // calls this person. effective_level is granted_level ?? level and
            // the schema computes it; this only ever writes `level`.
            $holdOpen = $account['granted_level'] !== null;
            $active   = ($grants || $holdOpen) ? 1 : 0;

            if ((string) $account['level'] === $level && (int) $account['is_active'] === $active) {
                continue;
            }

            $update->execute([':level' => $level, ':active' => $active, ':id' => (int) $account['id']]);
            $touched++;
        }

        return $touched;
    }

    /** @param array<int, array<string, mixed>> $chunk */
    private function applyAbsent(array $chunk, int $batchId): int
    {
        $ids = [];
        foreach ($chunk as $row) {
            if ($row['member_id'] !== null) {
                $ids[] = (int) $row['member_id'];
            }
        }

        if ($ids === []) {
            return 0;
        }

        [$placeholders, $bind] = $this->inList($ids, 'id');
        $bind[':batch'] = $batchId;

        // Flag, never delete. An Admin confirms the purge as a separate,
        // logged action, and a member who reappears is un-flagged
        // automatically. is_system = 0 is belt and braces: a system row is
        // never staged as absent in the first place.
        $flag = $this->pdo->prepare(
            'UPDATE `member` SET `absent_since_import_id` = :batch '
            . "WHERE `id` IN ({$placeholders}) AND `is_system` = 0 AND `purged_at` IS NULL "
            . 'AND `absent_since_import_id` IS NULL'
        );
        $flag->execute($bind);

        // What was flagged, not what was staged: a member purged between the
        // preview and the apply is skipped by the WHERE clause above, and
        // reporting them as newly flagged would be a count nothing supports.
        return $flag->rowCount();
    }

    // -----------------------------------------------------------------------
    // Reference data
    // -----------------------------------------------------------------------

    /**
     * Division ids for every division named in the batch, creating any that
     * are new. Blank always resolves to the seeded placeholder.
     *
     * @return array<string, int> name => id
     */
    private function resolveDivisions(int $batchId): array
    {
        $names = $this->distinctStagedValue($batchId, 'division_name');
        $names[] = self::NO_DIVISION;

        $existing = [];
        foreach ($this->pdo->query('SELECT id, name FROM division')->fetchAll() as $row) {
            $existing[mb_strtolower((string) $row['name'], 'UTF-8')] = (int) $row['id'];
        }

        $insert = $this->pdo->prepare('INSERT INTO division (name, is_placeholder, is_active) VALUES (:name, 0, 1)');

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '' || isset($existing[mb_strtolower($name, 'UTF-8')])) {
                continue;
            }
            $insert->execute([':name' => mb_substr($name, 0, 128)]);
            $existing[mb_strtolower($name, 'UTF-8')] = (int) $this->pdo->lastInsertId();
        }

        return $existing;
    }

    /**
     * Team ids, creating any the file introduces.
     *
     * `team.division_id` is the modal division for display only — seven teams
     * genuinely span two — and `team.area` is never touched here: it is
     * Admin-editable display grouping and no import writes it (spec 6.6).
     *
     * @param array<string, int> $divisions
     *
     * @return array<string, int> team key => id
     */
    private function resolveTeams(int $batchId, array $divisions): array
    {
        $existing = [];
        foreach ($this->pdo->query('SELECT id, name FROM team')->fetchAll() as $row) {
            $existing[$this->teamKey((string) $row['name'])] = (int) $row['id'];
        }

        $read = $this->pdo->prepare(
            'SELECT payload FROM import_staged_row '
            . "WHERE import_batch_id = :batch AND action IN ('create', 'update', 'unchanged')"
        );
        $read->execute([':batch' => $batchId]);

        $wanted = [];
        foreach ($read->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            $name = trim((string) ($payload['team_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $division = trim((string) ($payload['division_name'] ?? ''));
            $wanted[$this->teamKey($name)] ??= ['name' => $name, 'division' => $division === '' ? self::NO_DIVISION : $division];
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO team (name, division_id, area, is_active) VALUES (:name, :division, NULL, 1)'
        );

        foreach ($wanted as $key => $team) {
            if (isset($existing[$key])) {
                continue;
            }
            $insert->execute([
                ':name'     => mb_substr($team['name'], 0, 128),
                ':division' => $divisions[mb_strtolower($team['division'], 'UTF-8')] ?? null,
            ]);
            $existing[$key] = (int) $this->pdo->lastInsertId();
        }

        return $existing;
    }

    /**
     * One staged field, preserving a deliberate NULL.
     *
     * `?? ''` would have done, and it silently would not: `email` and
     * `phone_e164` are NULL when there is no address and no normalisable
     * number, and the coalescing operator cannot tell that NULL apart from a
     * key that is not there. The cost is invisible — the member is stored with
     * an empty string instead, and every subsequent import reports them as
     * changed forever, because '' and NULL never compare equal.
     *
     * @param array<string, mixed> $payload
     */
    private static function field(array $payload, string $field): mixed
    {
        return array_key_exists($field, $payload) ? $payload[$field] : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int>   $divisions
     */
    private function divisionIdFor(array $payload, array $divisions): int
    {
        $name = trim((string) ($payload['division_name'] ?? ''));
        // Every import re-evaluates it: populated moves out, blank moves in.
        $key  = mb_strtolower($name === '' ? self::NO_DIVISION : $name, 'UTF-8');

        return $divisions[$key] ?? $divisions[mb_strtolower(self::NO_DIVISION, 'UTF-8')];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int>   $teams
     */
    private function teamIdFor(array $payload, array $teams): ?int
    {
        $name = trim((string) ($payload['team_name'] ?? ''));

        return $name === '' ? null : ($teams[$this->teamKey($name)] ?? null);
    }

    // -----------------------------------------------------------------------
    // Batches
    // -----------------------------------------------------------------------

    private function createBatch(
        int $showYearId,
        string $mode,
        ?int $teamId,
        string $filename,
        string $path,
        ?int $uploadedBy,
    ): int {
        $insert = $this->pdo->prepare(
            'INSERT INTO import_batch (show_year_id, mode, team_id, filename, sha256, uploaded_by, dry_run) '
            . 'VALUES (:year, :mode, :team, :filename, :sha, :by, 1)'
        );
        $insert->execute([
            ':year'     => $showYearId,
            ':mode'     => $mode,
            ':team'     => $teamId,
            ':filename' => mb_substr($filename, 0, 255),
            // Identifies the file itself, so "did we already import this one"
            // is answerable without keeping the file — and nothing keeps the
            // file, because it is ~1,950 people's addresses.
            ':sha'      => hash_file('sha256', $path),
            ':by'       => $uploadedBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, int>   $counts
     * @param array<string, mixed> $summary
     */
    private function finishBatch(int $batchId, array $counts, int $absent, int $warnings, array $summary): void
    {
        $this->pdo->prepare(
            'UPDATE import_batch SET rows_read = :read, rows_created = :created, rows_updated = :updated, '
            . 'rows_unchanged = :unchanged, rows_absent = :absent, warnings_count = :warnings, '
            . 'summary_json = :summary WHERE id = :id'
        )->execute([
            ':read'      => $counts['read'],
            ':created'   => $counts['create'],
            ':updated'   => $counts['update'],
            ':unchanged' => $counts['unchanged'],
            ':absent'    => $absent,
            ':warnings'  => $warnings,
            ':summary'   => self::json($summary),
            ':id'        => $batchId,
        ]);
    }

    /** @param array<string, int> $applied */
    private function markApplied(int $batchId, ?int $actorUserId, array $applied): void
    {
        $this->pdo->prepare(
            'UPDATE import_batch SET applied_at = :now, dry_run = 0 WHERE id = :id'
        )->execute([':now' => App::now(), ':id' => $batchId]);

        // Append-only in its record: every batch keeps its counts, its
        // warnings and the user who ran it, forever. "Why did this member's
        // dues flip back to N" has to stay answerable years from now.
        $this->pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip) '
            . 'VALUES (:actor, :action, :entity, :entity_id, :after_json, :ip)'
        )->execute([
            ':actor'      => $actorUserId,
            ':action'     => 'import_applied',
            ':entity'     => 'import_batch',
            ':entity_id'  => (string) $batchId,
            ':after_json' => self::json($applied),
            ':ip'         => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    }

    /**
     * One batch, with its summary decoded.
     *
     * @return array<string, mixed>
     *
     * @throws ImportException
     */
    public function batch(int $batchId): array
    {
        $read = $this->pdo->prepare(
            'SELECT b.*, y.label AS show_year_label, t.name AS team_name '
            . 'FROM import_batch b '
            . 'INNER JOIN show_year y ON y.id = b.show_year_id '
            . 'LEFT JOIN team t ON t.id = b.team_id '
            . 'WHERE b.id = :id'
        );
        $read->execute([':id' => $batchId]);
        $row = $read->fetch();

        if (!is_array($row)) {
            throw new ImportException(
                "There is no import batch {$batchId}. Staged batches are discarded after "
                . "{$this->stageTtlHours} hours (spec 6.3) — upload the file again."
            );
        }

        $summary = json_decode((string) ($row['summary_json'] ?? ''), true);
        $row['summary'] = is_array($summary) ? $summary : [];

        return $row;
    }

    /**
     * Staged batches waiting to be applied, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stagedBatches(int $limit = 20): array
    {
        $read = $this->pdo->query(
            'SELECT b.*, y.label AS show_year_label, t.name AS team_name '
            . 'FROM import_batch b '
            . 'INNER JOIN show_year y ON y.id = b.show_year_id '
            . 'LEFT JOIN team t ON t.id = b.team_id '
            . 'WHERE b.applied_at IS NULL ORDER BY b.id DESC LIMIT ' . max(1, $limit)
        );

        return $read->fetchAll();
    }

    /**
     * Batches already applied, newest first — the record that answers "why did
     * this member's dues flip back to N".
     *
     * @return array<int, array<string, mixed>>
     */
    public function appliedBatches(int $limit = 10): array
    {
        $read = $this->pdo->query(
            'SELECT b.*, y.label AS show_year_label, t.name AS team_name '
            . 'FROM import_batch b '
            . 'INNER JOIN show_year y ON y.id = b.show_year_id '
            . 'LEFT JOIN team t ON t.id = b.team_id '
            . 'WHERE b.applied_at IS NOT NULL ORDER BY b.id DESC LIMIT ' . max(1, $limit)
        );

        return $read->fetchAll();
    }

    /** Drops a staged batch and everything parsed into it. */
    public function discard(int $batchId): void
    {
        // import_warning has no CASCADE — it is a record, and the only thing
        // that removes one is removing the batch it belongs to, here.
        $this->pdo->prepare('DELETE FROM import_warning WHERE import_batch_id = :id')
            ->execute([':id' => $batchId]);
        $this->pdo->prepare('DELETE FROM import_staged_row WHERE import_batch_id = :id')
            ->execute([':id' => $batchId]);
        $this->pdo->prepare('DELETE FROM import_batch WHERE id = :id AND applied_at IS NULL')
            ->execute([':id' => $batchId]);
    }

    /**
     * Discards staged batches older than import.stage_ttl_hours (spec 6.3).
     *
     * A stale preview is worse than no preview: it was computed against a
     * roster that has since changed, and applying it would write a diff
     * nobody has read.
     *
     * @return int batches discarded
     */
    public function discardExpired(): int
    {
        // The cutoff is computed here rather than bound into an INTERVAL: a
        // placeholder inside `INTERVAL ? HOUR` is one more thing that has to
        // behave identically on MySQL 8.0 and MariaDB 10.11, and a UTC
        // timestamp string is a value both simply compare. Every DATETIME in
        // this schema is UTC and the connection pins it, so the two sides of
        // this comparison are in the same zone.
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $this->stageTtlHours . ' hours')
            ->format('Y-m-d H:i:s');

        $read = $this->pdo->prepare(
            'SELECT id FROM import_batch WHERE applied_at IS NULL AND started_at < :cutoff'
        );
        $read->execute([':cutoff' => $cutoff]);

        $ids = $read->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->discard((int) $id);
        }

        return count($ids);
    }

    /** @param array<string, mixed> $batch */
    private function expiryOf(array $batch): string
    {
        return (string) (new DateTimeImmutable((string) $batch['started_at'], new DateTimeZone('UTC')))
            ->modify('+' . $this->stageTtlHours . ' hours')
            ->format('Y-m-d H:i:s');
    }

    // -----------------------------------------------------------------------
    // Reads, hoisted out of the row loop
    // -----------------------------------------------------------------------

    /**
     * The roster as it stands, keyed by member number.
     *
     * `is_system = 1` rows are NOT returned, and that absence is what makes
     * them invisible to the import: never matched, never updated, never
     * flagged absent. Without it the first complete import would flag the
     * master administrator for not appearing in a file they will never appear
     * in, and invite an Admin to purge the only account that can sign in.
     *
     * This, with loadMetrics() below, is what the import's memory footprint
     * actually is: ~8MB for the 1,954-member roster and ~50MB at five times
     * that. It buys the diff — 1,954 comparisons in memory instead of 1,954
     * SELECTs against a database on another machine, which is the difference
     * between a second and a timeout.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadExistingMembers(): array
    {
        $columns = implode(', ', array_map(static fn (string $f): string => 'm.`' . $f . '`', self::OWNED_FIELDS));

        $rows = $this->pdo->query(
            "SELECT m.id, m.member_number, {$columns}, m.division_id, m.team_id, "
            . 'm.purged_at, m.absent_since_import_id, '
            . 'd.name AS division_name, t.name AS team_name '
            . 'FROM member m '
            . 'INNER JOIN division d ON d.id = m.division_id '
            . 'LEFT JOIN team t ON t.id = m.team_id '
            . 'WHERE m.is_system = 0'
        )->fetchAll();

        $existing = [];
        foreach ($rows as $row) {
            $existing[(string) $row['member_number']] = $row;
        }

        return $existing;
    }

    /** @return array<string, true> */
    private function loadSystemMemberNumbers(): array
    {
        $numbers = [];
        foreach ($this->pdo->query('SELECT member_number FROM member WHERE is_system = 1')->fetchAll() as $row) {
            $numbers[(string) $row['member_number']] = true;
        }

        return $numbers;
    }

    /** @return array<string, string> lowercased email => member number */
    private function loadEmailOwners(): array
    {
        $owners = [];
        $rows = $this->pdo->query(
            "SELECT member_number, email FROM member WHERE is_system = 0 AND email IS NOT NULL AND email <> ''"
        )->fetchAll();

        foreach ($rows as $row) {
            $owners[mb_strtolower((string) $row['email'], 'UTF-8')] = (string) $row['member_number'];
        }

        return $owners;
    }

    /** @return array<string, string> team key => division name */
    private function loadTeamDivisions(): array
    {
        $teams = [];
        $rows = $this->pdo->query(
            'SELECT t.name, d.name AS division_name FROM team t LEFT JOIN division d ON d.id = t.division_id'
        )->fetchAll();

        foreach ($rows as $row) {
            $teams[$this->teamKey((string) $row['name'])] = (string) ($row['division_name'] ?? self::NO_DIVISION);
        }

        return $teams;
    }

    /**
     * Imported metric values for the whole roster, for the diff.
     *
     * @return array<int, array<string, string>> member id => metric => imported_value
     */
    private function loadMetrics(int $showYearId): array
    {
        $read = $this->pdo->prepare(
            'SELECT member_id, metric, imported_value FROM member_metric WHERE show_year_id = :year'
        );
        $read->execute([':year' => $showYearId]);

        $metrics = [];
        foreach ($read->fetchAll() as $row) {
            $metrics[(int) $row['member_id']][(string) $row['metric']] = (string) $row['imported_value'];
        }

        return $metrics;
    }

    /**
     * Full metric rows for one chunk of members, including progress — which
     * the apply reads and, in exactly one case, resets.
     *
     * @param array<int, int> $memberIds
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function loadMetricsFor(array $memberIds, int $showYearId): array
    {
        [$placeholders, $bind] = $this->inList($memberIds, 'm');
        $bind[':year'] = $showYearId;

        $read = $this->pdo->prepare(
            'SELECT member_id, metric, imported_value, progress, progress_by, progress_at, progress_note '
            . "FROM member_metric WHERE show_year_id = :year AND member_id IN ({$placeholders})"
        );
        $read->execute($bind);

        $metrics = [];
        foreach ($read->fetchAll() as $row) {
            $metrics[(int) $row['member_id']][(string) $row['metric']] = $row;
        }

        return $metrics;
    }

    /**
     * @param array<int, string> $memberNumbers
     *
     * @return array<string, int>
     */
    private function memberIds(array $memberNumbers): array
    {
        $memberNumbers = array_values(array_filter(array_unique($memberNumbers), static fn ($n): bool => (string) $n !== ''));
        if ($memberNumbers === []) {
            return [];
        }

        [$placeholders, $bind] = $this->inList($memberNumbers, 'n');

        $read = $this->pdo->prepare(
            "SELECT id, member_number FROM member WHERE member_number IN ({$placeholders})"
        );
        $read->execute($bind);

        $ids = [];
        foreach ($read->fetchAll() as $row) {
            $ids[(string) $row['member_number']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    private function distinctStagedValue(int $batchId, string $key): array
    {
        $read = $this->pdo->prepare(
            'SELECT payload FROM import_staged_row '
            . "WHERE import_batch_id = :batch AND action IN ('create', 'update', 'unchanged')"
        );
        $read->execute([':batch' => $batchId]);

        $values = [];
        foreach ($read->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (is_array($payload) && isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                $values[trim((string) $payload[$key])] = true;
            }
        }

        return array_keys($values);
    }

    /**
     * Staged rows in chunks of import.batch_rows, one transaction each.
     *
     * @return iterable<int, array<int, array<string, mixed>>>
     */
    private function stagedChunks(int $batchId, string $action): iterable
    {
        $lastId = 0;

        while (true) {
            $read = $this->pdo->prepare(
                'SELECT id, member_number, member_id, payload FROM import_staged_row '
                . 'WHERE import_batch_id = :batch AND action = :action AND id > :after '
                . 'ORDER BY id LIMIT ' . $this->batchRows
            );
            $read->execute([':batch' => $batchId, ':action' => $action, ':after' => $lastId]);

            $chunk = $read->fetchAll();
            if ($chunk === []) {
                return;
            }

            $lastId = (int) $chunk[count($chunk) - 1]['id'];

            yield $chunk;
        }
    }

    // -----------------------------------------------------------------------
    // Small shared machinery
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>|null $changes
     *
     * @return array<string, mixed>
     */
    private function stagedRow(
        int $batchId,
        int $rowNumber,
        string $memberNumber,
        string $action,
        ?int $memberId,
        ?array $payload,
        ?array $changes,
    ): array {
        return [
            'import_batch_id' => $batchId,
            'row_number'      => $rowNumber,
            'member_number'   => $memberNumber,
            'action'          => $action,
            'member_id'       => $memberId,
            'payload'         => $payload === null ? null : self::json($payload),
            'changes'         => $changes === null ? null : self::json($changes),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>> what is still buffered
     */
    private function flushStaged(array $rows, bool $force = false): array
    {
        if ($rows === [] || (!$force && count($rows) < $this->batchRows)) {
            return $rows;
        }

        $this->insertRows(
            'import_staged_row',
            ['import_batch_id', 'row_number', 'member_number', 'action', 'member_id', 'payload', 'changes'],
            $rows
        );

        return [];
    }

    /**
     * A multi-row INSERT, chunked so no single statement carries an
     * unreasonable number of placeholders.
     *
     * @param array<int, string>               $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertRows(string $table, array $columns, array $rows, string $onDuplicate = ''): int
    {
        if ($rows === []) {
            return 0;
        }

        // 2,000 placeholders a statement: comfortably inside every driver
        // limit, and large enough that a 9,770-row metric write is twenty
        // statements rather than nine thousand.
        $perStatement = max(1, (int) floor(2000 / max(1, count($columns))));
        $quoted       = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        $written      = 0;

        foreach (array_chunk($rows, $perStatement) as $chunk) {
            $tuples = [];
            $bind   = [];

            foreach ($chunk as $i => $row) {
                $names = [];
                foreach ($columns as $column) {
                    // A named placeholder cannot be reused within one
                    // statement, so every column of every row gets its own.
                    $name    = ':' . $column . '_' . $i;
                    $names[] = $name;
                    $bind[$name] = $row[$column] ?? null;
                }
                $tuples[] = '(' . implode(', ', $names) . ')';
            }

            $sql = "INSERT INTO `{$table}` ({$quoted}) VALUES " . implode(', ', $tuples);
            if ($onDuplicate !== '') {
                $sql .= ' ' . $onDuplicate;
            }

            $statement = $this->pdo->prepare($sql);
            $statement->execute($bind);
            $written += count($chunk);
        }

        return $written;
    }

    /**
     * An IN list with one placeholder per value.
     *
     * @param array<int, int|string> $values
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function inList(array $values, string $prefix): array
    {
        $placeholders = [];
        $bind         = [];

        foreach (array_values($values) as $i => $value) {
            $placeholders[] = ":{$prefix}{$i}";
            $bind[":{$prefix}{$i}"] = $value;
        }

        return [implode(', ', $placeholders), $bind];
    }

    /**
     * JSON for a column MySQL will validate.
     *
     * `payload` and `changes` are real JSON on MySQL 8.0, so an invalid string
     * is refused rather than stored — and json_encode() returns FALSE on a
     * byte sequence that is not valid UTF-8, which would then be inserted as
     * '' and take a 1,954-row import down over one cell. SUBSTITUTE turns that
     * byte into U+FFFD instead, which is what e() does with the same problem
     * on the way out.
     *
     * INVALID_UTF8_SUBSTITUTE rather than PARTIAL_OUTPUT_ON_ERROR: a partial
     * payload is a member imported with some of their fields.
     *
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    /** Teams are matched the way headers are: trimmed, collapsed, case-folded. */
    private function teamKey(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name));

        return mb_strtolower($collapsed ?? trim($name), 'UTF-8');
    }

    private function sameTeam(string $a, string $b): bool
    {
        return $this->teamKey($a) === $this->teamKey($b);
    }

    /** @param array<string, mixed> $values */
    private function describeRow(array $values): string
    {
        $name = trim(((string) $values['first_name']) . ' ' . ((string) $values['last_name']));

        return $name === '' ? 'The row is empty.' : "Row reads \"{$name}\".";
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ImportException
     */
    private function activeShowYear(): array
    {
        $row = $this->pdo->query('SELECT * FROM show_year WHERE is_active = 1')->fetch();

        if (!is_array($row)) {
            throw new ImportException(
                'No show year is active. Everything a roster carries — metrics, contacts, '
                . 'assignments — is keyed to one, so there is nowhere to import to. Create '
                . 'one and set it active first.'
            );
        }

        if ((int) $row['is_open'] !== 1) {
            throw new ImportException(
                "Show year {$row['label']} is active but closed. A closed year is read-only "
                . 'and exportable; open it, or make another year active, before importing.'
            );
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function showYear(int $id): array
    {
        $read = $this->pdo->prepare('SELECT * FROM show_year WHERE id = :id');
        $read->execute([':id' => $id]);
        $row = $read->fetch();

        if (!is_array($row)) {
            throw new ImportException("Show year {$id} no longer exists.");
        }

        return $row;
    }

    private function teamName(int $teamId): string
    {
        $read = $this->pdo->prepare('SELECT name FROM team WHERE id = :id');
        $read->execute([':id' => $teamId]);
        $name = $read->fetchColumn();

        if (!is_string($name)) {
            throw new ImportException("There is no team {$teamId}.");
        }

        return $name;
    }
}
