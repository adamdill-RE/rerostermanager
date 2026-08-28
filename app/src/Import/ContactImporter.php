<?php

declare(strict_types=1);

namespace Rerm\Import;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Roster\Spreadsheet;
use Throwable;

/**
 * A bulk load of contacts that happened before this application existed
 * (docs/spec-v1.md §6.7).
 *
 * Officers chased people for months before there was anywhere to record it.
 * Those calls are in a spreadsheet, and without this they are retyped one
 * screen at a time or lost — and lost is much the worse outcome, because My
 * Roster Status sorts by "never contacted, then oldest first", so a member
 * called in October reads as never called and goes to the top of somebody's
 * list in November.
 *
 * FOUR THINGS MAKE IT DIFFERENT FROM `Rerm\Roster\LogContact`, WHICH IS THE
 * SCREEN THIS EXISTS BESIDE RATHER THAN REPLACES:
 *
 *   1. It BACK-DATES. LogContact writes UTC_TIMESTAMP() and says in its own
 *      comment that a contact is never back-dated — correctly, for a contact
 *      somebody is logging as they make it. This one's entire purpose is a
 *      date in the past, and a load that stamped all eighty rows "today"
 *      would record a fiction and destroy the ordering it was meant to fix.
 *   2. It ATTRIBUTES TO SOMEBODY ELSE. A per-row officer, defaulting to one
 *      chosen for the batch. That is a claim about another person's work, and
 *      it is why this is Admin-only and audited.
 *   3. It is TWO STEPS. Eighty permanent rows written by one button press is
 *      the same shape of risk as 1,954, and gets the same answer: parse,
 *      resolve, show a diff, and write only on a second explicit POST.
 *   4. It resolves members BY NAME, which nothing else in this application
 *      does. "Never key on a name" (CLAUDE.md) is a rule about identity, and
 *      it is not broken here: the name is resolved to a member number ONCE,
 *      at stage time, inside a single team, and an ambiguous name is refused
 *      rather than guessed. What gets stored is the id.
 *
 * WHAT IT MUST NEVER DO, and none of these are hypothetical:
 *
 *   * Write anything but `contact_log`. Not a metric, not a progress status,
 *     not an assignment. A history load says a conversation happened; it does
 *     not say what the member promised, and inferring "in progress" from an
 *     old note would put a status nobody set in front of an officer.
 *   * Update or delete a `contact_log` row. The table is append-only forever
 *     (spec 5.5). Re-applying a file it has already applied inserts nothing,
 *     because the rows are recognised as duplicates — not because anything is
 *     overwritten.
 *   * Write into a closed show year. Closing FREEZES (spec 5.1), and a row
 *     whose date lands in a closed year is skipped and listed, exactly as
 *     LogContact refuses a live contact into one.
 */
final class ContactImporter
{
    // -----------------------------------------------------------------------
    // Why a row did not land. Every one of these appears on the preview with a
    // count and the rows behind it; tests/contact_import_test.php fires each.
    // -----------------------------------------------------------------------

    /** The row names no member at all — every identifying cell is blank. */
    public const NO_MEMBER = 'no_member';

    /** A member number that is not on the roster, or a name nobody matches. */
    public const MEMBER_NOT_FOUND = 'member_not_found';

    /**
     * A name matching more than one member of the team. Refused, never
     * guessed: a contact filed against the wrong Smith is worse than a
     * contact filed against nobody, because nobody re-reads it.
     */
    public const AMBIGUOUS_NAME = 'ambiguous_name';

    /** No date, or a cell no date format in ContactRow recognises. */
    public const BAD_DATE = 'bad_date';

    /** A date after today. A contact has happened; a future one has not. */
    public const FUTURE_DATE = 'future_date';

    /** The named officer is not on the roster, or has no active account. */
    public const OFFICER_NOT_FOUND = 'officer_not_found';

    /** The date falls in a show year that is closed. Closing freezes. */
    public const YEAR_CLOSED = 'year_closed';

    /** Already in contact_log: same member, same moment, same type. */
    public const DUPLICATE = 'duplicate';

    /** Landed, but the type cell was a word this application does not model. */
    public const UNKNOWN_TYPE = 'unknown_type';

    /**
     * Landed, but no show year covers the date, so it went to the active one.
     * Most show years carry no dates at all (`starts_on` is nullable), which
     * makes this the ordinary case rather than an unusual one.
     */
    public const YEAR_ASSUMED = 'year_assumed';

    /**
     * Every reason, in the order the preview lists them: the ones that lose a
     * row first, then the two that keep it.
     *
     * @var array<int, string>
     */
    public const KINDS = [
        self::NO_MEMBER, self::MEMBER_NOT_FOUND, self::AMBIGUOUS_NAME,
        self::BAD_DATE, self::FUTURE_DATE, self::OFFICER_NOT_FOUND,
        self::YEAR_CLOSED, self::DUPLICATE,
        self::UNKNOWN_TYPE, self::YEAR_ASSUMED,
    ];

    /** The two that do NOT cost the row — they annotate one that landed. */
    public const KEPT_KINDS = [self::UNKNOWN_TYPE, self::YEAR_ASSUMED];

    /**
     * A file this size is not a history load, it is a mistake — most likely
     * the roster export, which has 1,954 rows and none of these columns.
     * Refused before anything is staged, naming what it probably is.
     */
    private const MAX_ROWS = 2000;

    private readonly int $batchRows;

    private readonly int $stageTtlHours;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $timezone = 'America/Chicago',
        int $batchRows = 200,
        int $stageTtlHours = 24,
    ) {
        $this->batchRows     = max(1, $batchRows);
        $this->stageTtlHours = max(1, $stageTtlHours);
    }

    public static function fromApp(App $app): self
    {
        $config = $app->config();

        return new self(
            $app->db(),
            (string) $config->get('app.timezone', 'America/Chicago'),
            (int) $config->get('import.batch_rows', 500),
            (int) $config->get('import.stage_ttl_hours', 24),
        );
    }

    // -----------------------------------------------------------------------
    // Step one: parse, resolve and stage. `contact_log` is untouched.
    // -----------------------------------------------------------------------

    /**
     * Reads a contact history file into `contact_import_batch` +
     * `contact_import_row` and returns the batch id. Nothing is written to
     * `contact_log`, and nothing else in the database changes at all.
     *
     * @param string $path             a readable .xls, .xlsx or .csv, decided by content
     * @param string $filename         what to call it in the record
     * @param int    $defaultOfficerId app_user.id every unattributed row lands against
     * @param ?int   $teamId           the team names are resolved within, or null for
     *                                 a file that carries member numbers throughout
     * @param ?int   $uploadedBy       app_user.id of whoever is doing this
     *
     * @throws ImportException when the file cannot be read as a contact history at all
     */
    public function stage(
        string $path,
        string $filename,
        int $defaultOfficerId,
        ?int $teamId = null,
        ?int $uploadedBy = null,
    ): int {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImportException("Cannot read {$path}.");
        }

        $showYear = $this->activeShowYear();
        $this->requireOfficer($defaultOfficerId);
        if ($teamId !== null) {
            $this->requireTeam($teamId);
        }

        // The header is read in its own pass, before a batch exists, so a file
        // this import cannot read leaves no record of an import that never
        // started — and because the readers return generators, which cannot be
        // broken out of and re-entered without handing back the header row as
        // though it were data.
        $headers = ContactHeaderMap::fromHeaderRow($this->readHeaderRow($path));

        $batchId = $this->createBatch(
            (int) $showYear['id'],
            $teamId,
            $defaultOfficerId,
            $filename,
            $path,
            $uploadedBy
        );

        try {
            return $this->stageRows($batchId, $headers, $path, $defaultOfficerId, $teamId);
        } catch (Throwable $e) {
            // A batch that failed mid-parse is not a record of anything, and
            // leaving it staged would offer an apply button for half a file.
            $this->discard($batchId);

            throw $e;
        }
    }

    /**
     * @return array<int, string>
     *
     * @throws ImportException
     */
    private function readHeaderRow(string $path): array
    {
        foreach (Spreadsheet::open($path)->rows() as $row) {
            return $row;
        }

        throw new ImportException(
            'The file has no rows at all — not even a header. If it came out of Excel, check '
            . 'that the contacts are on the first sheet.'
        );
    }

    /**
     * Resolves every row and writes it to staging.
     *
     * The roster lookups are loaded ONCE, up front, rather than queried per
     * row: an eighty-row file against an eighty-member team is 160 queries
     * that two are enough for, and this host gives a request 30 seconds.
     */
    private function stageRows(
        int $batchId,
        ContactHeaderMap $headers,
        string $path,
        int $defaultOfficerId,
        ?int $teamId,
    ): int {
        $byNumber = $this->membersByNumber();
        $byName   = $teamId === null ? [] : $this->teamMembersByName($teamId);
        $officers = $this->officersByKey();
        $years    = $this->showYears();
        $active   = $this->activeShowYear();

        $counts  = ['read' => 0, 'insert' => 0, 'duplicate' => 0, 'skip' => 0];
        $kinds   = [];
        $pending = [];
        $seen    = [];
        $first   = true;
        $number  = 0;

        foreach (Spreadsheet::open($path)->rows() as $row) {
            $number++;

            if ($first) {
                $first = false;
                continue;
            }

            // A trailing empty row is what a spreadsheet leaves behind, not a
            // contact somebody failed to describe. Silently ignored — every
            // other blank field is a warning, this one is punctuation.
            if ($this->isBlank($row)) {
                continue;
            }

            $counts['read']++;
            if ($counts['read'] > self::MAX_ROWS) {
                throw new ImportException(sprintf(
                    'This file has more than %s rows, which is far more contacts than a '
                    . "history load should carry.\n\nIf this is the ROSTER export, it goes "
                    . 'to Import Roster instead — that one expects 1,954 rows and columns '
                    . 'like Customer Number and Subcommittee 1.',
                    number_format(self::MAX_ROWS)
                ));
            }

            $staged = $this->resolveRow(
                $row,
                $number,
                $headers,
                $byNumber,
                $byName,
                $officers,
                $years,
                $active,
                $defaultOfficerId,
                $teamId
            );

            // A file listing the same contact twice is one contact. Caught
            // here as well as against contact_log, because the rows the apply
            // is about to insert are not in contact_log yet and would not
            // catch each other.
            if ($staged['action'] === 'insert') {
                $key = $this->contactKey(
                    (int) $staged['member_id'],
                    (string) $staged['occurred_at'],
                    (string) $staged['contact_type']
                );
                if (isset($seen[$key])) {
                    $staged['action']    = 'duplicate';
                    $staged['outcome_kind'] = self::DUPLICATE;
                    $staged['detail']    = 'The same contact appears earlier in this file, on row '
                        . $seen[$key] . '.';
                } else {
                    $seen[$key] = $number;
                }
            }

            if ($staged['action'] === 'insert') {
                $counts['insert']++;
            } elseif ($staged['action'] === 'duplicate') {
                $counts['duplicate']++;
            } else {
                $counts['skip']++;
            }

            if ($staged['outcome_kind'] !== '') {
                $kinds[$staged['outcome_kind']] = ($kinds[$staged['outcome_kind']] ?? 0) + 1;
            }

            $pending[] = $staged;
            if (count($pending) >= $this->batchRows) {
                $this->flush($batchId, $pending);
                $pending = [];
            }
        }

        $this->flush($batchId, $pending);
        $this->finishBatch($batchId, $counts, array_sum($kinds));

        return $batchId;
    }

    /**
     * One file row to one staged row, fully resolved.
     *
     * Order matters and is not arbitrary: the member first, because a row
     * naming nobody has nothing else worth reporting; then the date, because
     * it decides the show year; then the officer; then the duplicate check,
     * which needs all three. Each step that fails stops the row there, so an
     * unreadable row reports the FIRST thing wrong with it rather than five
     * consequences of it.
     *
     * @param array<int, string>              $row
     * @param array<string, array{id:int,name:string}>       $byNumber
     * @param array<string, array<int, array{id:int,name:string}>> $byName
     * @param array<string, int>              $officers
     * @param array<int, array<string, mixed>> $years
     * @param array<string, mixed>            $active
     *
     * @return array<string, mixed>
     */
    private function resolveRow(
        array $row,
        int $number,
        ContactHeaderMap $headers,
        array $byNumber,
        array $byName,
        array $officers,
        array $years,
        array $active,
        int $defaultOfficerId,
        ?int $teamId,
    ): array {
        $rawNumber  = $headers->value($row, ContactHeaderMap::MEMBER_NUMBER);
        $rawName    = $headers->memberName($row);
        $rawOfficer = $headers->value($row, ContactHeaderMap::OFFICER);
        $rawDate    = $headers->value($row, ContactHeaderMap::OCCURRED_AT);
        $rawType    = $headers->value($row, ContactHeaderMap::CONTACT_TYPE);
        $notes      = mb_substr($headers->value($row, ContactHeaderMap::NOTES), 0, 1000);

        $staged = [
            'row_number'   => $number,
            'action'       => 'skip',
            'outcome_kind'    => '',
            'detail'       => '',
            'raw_member'   => mb_substr($rawNumber !== '' ? $rawNumber : $rawName, 0, 255),
            'raw_officer'  => mb_substr($rawOfficer, 0, 255),
            'raw_date'     => mb_substr($rawDate, 0, 64),
            'raw_type'     => mb_substr($rawType, 0, 64),
            'member_id'    => null,
            'contacted_by' => null,
            'show_year_id' => null,
            'contact_type' => 'call',
            'occurred_at'  => null,
            'notes'        => $notes,
        ];

        // ---- the member -------------------------------------------------
        if ($rawNumber === '' && $rawName === '') {
            return ['outcome_kind' => self::NO_MEMBER, 'detail' => 'The row names no member.'] + $staged;
        }

        if ($rawNumber !== '') {
            // Trimmed of the decimal point a spreadsheet adds when it decides
            // a seven-digit identifier is a number: 1234567.0 is 1234567, and
            // refusing it would be refusing the commonest shape of the file.
            $key = $this->memberNumberKey($rawNumber);
            if (!isset($byNumber[$key])) {
                return [
                    'outcome_kind' => self::MEMBER_NOT_FOUND,
                    'detail'    => "No member has number {$rawNumber}.",
                ] + $staged;
            }
            $staged['member_id'] = $byNumber[$key]['id'];
        } elseif (isset($byNumber[$this->memberNumberKey($rawName)])
            && preg_match('/^\d+(\.0+)?$/', trim($rawName)) === 1
        ) {
            // A file with ONE `Member` column and member numbers typed into
            // it. A member number is six or seven digits and a name is not, so
            // there is nothing to be ambiguous about — and the alternative is
            // telling somebody that no member of the team is called 1234567.
            $staged['member_id'] = $byNumber[$this->memberNumberKey($rawName)]['id'];
        } else {
            if ($teamId === null) {
                return [
                    'outcome_kind' => self::MEMBER_NOT_FOUND,
                    'detail'    => 'This row identifies the member by name, and no team was chosen '
                        . 'to resolve names within.',
                ] + $staged;
            }

            $matches = $byName[$this->nameKey($rawName)] ?? [];
            if ($matches === []) {
                return [
                    'outcome_kind' => self::MEMBER_NOT_FOUND,
                    'detail'    => preg_match('/^\d+(\.0+)?$/', trim($rawName)) === 1
                        // Told that no member of the team is CALLED 1000005,
                        // somebody reasonably concludes the import cannot read
                        // numbers. It can; this one is not on the roster.
                        ? "No member has number {$rawName}, and no member of this team is called "
                            . 'that either.'
                        : "No member of this team is called \"{$rawName}\".",
                ] + $staged;
            }
            if (count($matches) > 1) {
                return [
                    'outcome_kind' => self::AMBIGUOUS_NAME,
                    'detail'    => sprintf(
                        '"%s" matches %d members of this team (%s). Put their Customer Number '
                        . 'in the file instead.',
                        $rawName,
                        count($matches),
                        implode(', ', array_column($matches, 'name'))
                    ),
                ] + $staged;
            }
            $staged['member_id'] = $matches[0]['id'];
        }

        // ---- when it happened -------------------------------------------
        $occurred = ContactRow::parseDate($rawDate, $this->timezone);
        if ($occurred === null) {
            return [
                'outcome_kind' => self::BAD_DATE,
                'detail'    => $rawDate === ''
                    ? 'The row has no date, and a contact with no date cannot be placed in a '
                        . 'show year or ordered against the others.'
                    : "\"{$rawDate}\" is not a date this import recognises. Dates are read in "
                        . 'US order, so 3/4/2026 is the fourth of March.',
            ] + $staged;
        }
        if ($occurred > gmdate('Y-m-d H:i:s')) {
            return [
                'outcome_kind' => self::FUTURE_DATE,
                'detail'    => "\"{$rawDate}\" is in the future. A contact history records what "
                    . 'has already happened.',
            ] + $staged;
        }
        $staged['occurred_at'] = $occurred;

        // ---- which show year --------------------------------------------
        [$yearId, $assumed] = $this->yearFor($occurred, $years, $active);
        if ($yearId === null) {
            return [
                'outcome_kind' => self::YEAR_CLOSED,
                'detail'    => 'The show year covering this date is closed, and closing a year '
                    . 'freezes it. Re-open it to load history into it.',
            ] + $staged;
        }
        $staged['show_year_id'] = $yearId;

        // ---- who made it -------------------------------------------------
        if ($rawOfficer === '') {
            $staged['contacted_by'] = $defaultOfficerId;
        } else {
            $officerId = $officers[$this->memberNumberKey($rawOfficer)]
                ?? $officers[$this->nameKey($rawOfficer)]
                ?? null;
            if ($officerId === null) {
                return [
                    'outcome_kind' => self::OFFICER_NOT_FOUND,
                    'detail'    => "\"{$rawOfficer}\" is not an active account. Attributing their "
                        . 'work to somebody else would be a false record; grant them a login, or '
                        . 'clear the column so the row lands against the batch default.',
                ] + $staged;
            }
            $staged['contacted_by'] = $officerId;
        }

        // ---- how ---------------------------------------------------------
        $type = ContactRow::parseType($rawType);
        if ($type === null && $rawType !== '') {
            $staged['contact_type'] = 'other';
            $staged['action']       = 'insert';
            $staged['outcome_kind']    = self::UNKNOWN_TYPE;
            $staged['detail']       = "\"{$rawType}\" is not a contact type this application "
                . 'models, so the row lands as Other. The note is kept in full.';
        } else {
            // A blank type column is a call. Every one of the 1,838 cell
            // numbers on the roster says what officers actually do, and a
            // history kept without a type column is a list of calls.
            $staged['contact_type'] = $type ?? 'call';
            $staged['action']       = 'insert';
        }

        if ($assumed && $staged['outcome_kind'] === '') {
            $staged['outcome_kind'] = self::YEAR_ASSUMED;
            $staged['detail']    = sprintf(
                'No show year covers %s, so it lands in %s, the active year.',
                substr($occurred, 0, 10),
                (string) $active['label']
            );
        }

        // ---- already there? ----------------------------------------------
        if ($this->alreadyLogged(
            (int) $staged['member_id'],
            (string) $staged['occurred_at'],
            (string) $staged['contact_type']
        )) {
            $staged['action']    = 'duplicate';
            $staged['outcome_kind'] = self::DUPLICATE;
            $staged['detail']    = 'This contact is already in the log — same member, same time, '
                . 'same type. Applying this batch will not add it again.';
        }

        return $staged;
    }

    // -----------------------------------------------------------------------
    // Step two: the preview. Reads staging; writes nothing.
    // -----------------------------------------------------------------------

    /**
     * Everything the preview screen shows, for one staged or applied batch.
     *
     * @return array<string, mixed>
     *
     * @throws ImportException when the batch does not exist
     */
    public function preview(int $batchId, int $sampleSize = 200): array
    {
        $batch = $this->batch($batchId);

        $counts = [];
        $read   = $this->pdo->prepare(
            'SELECT action, COUNT(*) AS n FROM contact_import_row WHERE batch_id = :id GROUP BY action'
        );
        $read->execute([':id' => $batchId]);
        foreach ($read->fetchAll() as $row) {
            $counts[(string) $row['action']] = (int) $row['n'];
        }

        $kinds = [];
        $read  = $this->pdo->prepare(
            'SELECT outcome_kind, COUNT(*) AS n FROM contact_import_row'
            . " WHERE batch_id = :id AND outcome_kind <> '' GROUP BY outcome_kind"
        );
        $read->execute([':id' => $batchId]);
        foreach ($read->fetchAll() as $row) {
            $kinds[(string) $row['outcome_kind']] = (int) $row['n'];
        }

        $size = max(1, min(1000, $sampleSize));
        $read = $this->pdo->prepare(
            'SELECT r.*, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' om.first_name AS officer_first, om.last_name AS officer_last,'
            . ' om.preferred_name AS officer_preferred, y.label AS show_year'
            . ' FROM contact_import_row r'
            . ' LEFT JOIN member m ON m.id = r.member_id'
            . ' LEFT JOIN app_user u ON u.id = r.contacted_by'
            . ' LEFT JOIN member om ON om.id = u.member_id'
            . ' LEFT JOIN show_year y ON y.id = r.show_year_id'
            . " WHERE r.batch_id = :id ORDER BY r.`row_number` LIMIT {$size}"
        );
        $read->execute([':id' => $batchId]);

        return [
            'batch'  => $batch,
            'counts' => [
                'read'      => (int) $batch['rows_read'],
                'insert'    => $counts['insert'] ?? 0,
                'duplicate' => $counts['duplicate'] ?? 0,
                'skip'      => $counts['skip'] ?? 0,
            ],
            'kinds'     => $kinds,
            'rows'      => $read->fetchAll(),
            'truncated' => (int) $batch['rows_read'] > $size,
            'applied'   => $batch['applied_at'] !== null,
        ];
    }

    // -----------------------------------------------------------------------
    // Step three: the apply. The only method here that writes contact_log.
    // -----------------------------------------------------------------------

    /**
     * Inserts every staged row whose action is `insert`, in one transaction,
     * and records the batch against every row it wrote.
     *
     * The duplicate check runs AGAIN here, against the live table, and that is
     * not belt and braces — a preview staged this morning can be applied this
     * afternoon, and somebody may have logged one of these contacts by hand in
     * between. Staging says what the file wants; this says what is still true.
     *
     * @return array{inserted: int, duplicate: int, skipped: int}
     *
     * @throws ImportException
     */
    public function apply(int $batchId, ?int $actorUserId = null): array
    {
        $batch = $this->batch($batchId);

        if ($batch['applied_at'] !== null) {
            throw new ImportException(
                "Batch {$batchId} has already been applied, on {$batch['applied_at']} UTC. "
                . 'Applying it twice is what the check exists to stop; upload the file again '
                . 'if there is more to load.'
            );
        }

        $read = $this->pdo->prepare(
            'SELECT id, `row_number`, member_id, contacted_by, show_year_id, contact_type,'
            . ' occurred_at, notes FROM contact_import_row'
            . " WHERE batch_id = :id AND action = 'insert' ORDER BY `row_number`"
        );
        $read->execute([':id' => $batchId]);
        $rows = $read->fetchAll();

        $inserted = 0;
        $late     = 0;

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO contact_log'
                . ' (member_id, show_year_id, contacted_by, contact_type, occurred_at, notes,'
                . ' contact_import_batch_id)'
                . ' VALUES (:member, :year, :by, :type, :at, :notes, :batch)'
            );

            foreach ($rows as $row) {
                $memberId = (int) $row['member_id'];
                $at       = (string) $row['occurred_at'];
                $type     = (string) $row['contact_type'];

                if ($this->alreadyLogged($memberId, $at, $type)) {
                    // Logged by hand between the preview and now. Recorded as
                    // the duplicate it is rather than inserted, and the staged
                    // row is corrected so the batch's own record matches what
                    // it actually did.
                    $this->pdo->prepare(
                        "UPDATE contact_import_row SET action = 'duplicate', outcome_kind = :kind,"
                        . ' detail = :detail WHERE id = :id'
                    )->execute([
                        ':kind'   => self::DUPLICATE,
                        ':detail' => 'Logged by somebody else between the preview and the apply, '
                            . 'so this batch did not add it again.',
                        ':id'     => (int) $row['id'],
                    ]);
                    $late++;
                    continue;
                }

                $insert->execute([
                    ':member' => $memberId,
                    ':year'   => (int) $row['show_year_id'],
                    ':by'     => (int) $row['contacted_by'],
                    ':type'   => $type,
                    ':at'     => $at,
                    ':notes'  => (string) $row['notes'],
                    ':batch'  => $batchId,
                ]);
                $inserted++;
            }

            $this->pdo->prepare(
                'UPDATE contact_import_batch SET rows_inserted = :inserted, rows_ready = :ready,'
                . ' rows_duplicate = rows_duplicate + :late, applied_at = UTC_TIMESTAMP(),'
                . ' dry_run = 0 WHERE id = :id'
            )->execute([
                ':inserted' => $inserted,
                ':ready'    => $inserted,
                ':late'     => $late,
                ':id'       => $batchId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        // Outside the transaction, and shaped like the roster import's own
        // audit write rather than going through Rerm\Audit\AuditLog: that
        // class takes a User and this method takes an id, because the CLI
        // (bin/import-contacts.php) has an account and no session.
        //
        // Append-only in its record. Eighty rows appearing in a member's
        // history at once, months after the fact, is exactly the thing
        // somebody will question later, and this is the answer: who loaded
        // them, from which file, when, and how many.
        $this->pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, action, entity, entity_id, after_json, ip)'
            . ' VALUES (:actor, :action, :entity, :entity_id, :after_json, :ip)'
        )->execute([
            ':actor'      => $actorUserId,
            ':action'     => Action::ImportContactHistory->value,
            ':entity'     => 'contact_import_batch',
            ':entity_id'  => (string) $batchId,
            ':after_json' => AuditLog::json([
                'filename'  => (string) $batch['filename'],
                'rows_read' => (int) $batch['rows_read'],
                'inserted'  => $inserted,
                'duplicate' => (int) $batch['rows_duplicate'] + $late,
                'skipped'   => (int) $batch['rows_skipped'],
                'team_id'   => $batch['team_id'] === null ? null : (int) $batch['team_id'],
            ]),
            ':ip'         => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);

        return [
            'inserted'  => $inserted,
            'duplicate' => (int) $batch['rows_duplicate'] + $late,
            'skipped'   => (int) $batch['rows_skipped'],
        ];
    }

    // -----------------------------------------------------------------------
    // Housekeeping
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     *
     * @throws ImportException
     */
    public function batch(int $batchId): array
    {
        $read = $this->pdo->prepare(
            'SELECT b.*, t.name AS team_name, y.label AS show_year,'
            . ' m.first_name AS officer_first, m.last_name AS officer_last,'
            . ' m.preferred_name AS officer_preferred'
            . ' FROM contact_import_batch b'
            . ' LEFT JOIN team t ON t.id = b.team_id'
            . ' LEFT JOIN show_year y ON y.id = b.show_year_id'
            . ' LEFT JOIN app_user u ON u.id = b.default_officer_user_id'
            . ' LEFT JOIN member m ON m.id = u.member_id'
            . ' WHERE b.id = :id'
        );
        $read->execute([':id' => $batchId]);
        $batch = $read->fetch();

        if (!is_array($batch)) {
            throw new ImportException("There is no contact history batch {$batchId}.");
        }

        return $batch;
    }

    /**
     * Batches waiting for an apply, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stagedBatches(int $limit = 10): array
    {
        return $this->batchList('b.dry_run = 1 AND b.applied_at IS NULL', $limit);
    }

    /**
     * Batches that were applied, newest first — the record of what was loaded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function appliedBatches(int $limit = 10): array
    {
        return $this->batchList('b.applied_at IS NOT NULL', $limit);
    }

    /**
     * Throws away a staged batch and its rows.
     *
     * Staging is not a record: nothing here has ever been in `contact_log`,
     * and an abandoned preview that stayed forever would offer an apply button
     * for a file somebody decided against. An APPLIED batch is refused — its
     * rows are cited by `contact_log.contact_import_batch_id`, and the
     * foreign key would refuse anyway.
     *
     * @throws ImportException
     */
    public function discard(int $batchId): void
    {
        $read = $this->pdo->prepare('SELECT applied_at FROM contact_import_batch WHERE id = :id');
        $read->execute([':id' => $batchId]);
        $batch = $read->fetch();

        if (!is_array($batch)) {
            return;
        }
        if ($batch['applied_at'] !== null) {
            throw new ImportException(
                "Batch {$batchId} has been applied. An applied batch is the record of what was "
                . 'loaded and the contacts themselves point at it; it stays.'
            );
        }

        $this->pdo->prepare('DELETE FROM contact_import_row WHERE batch_id = :id')
            ->execute([':id' => $batchId]);
        $this->pdo->prepare('DELETE FROM contact_import_batch WHERE id = :id AND applied_at IS NULL')
            ->execute([':id' => $batchId]);
    }

    /**
     * Drops staged batches older than the TTL, and returns how many.
     *
     * A preview computed a week ago was computed against a roster and a
     * contact log that have both moved since, so applying it would write a
     * diff nobody has read.
     */
    public function discardExpired(): int
    {
        $read = $this->pdo->prepare(
            'SELECT id FROM contact_import_batch WHERE dry_run = 1 AND applied_at IS NULL'
            . ' AND started_at < (UTC_TIMESTAMP() - INTERVAL :hours HOUR)'
        );
        $read->execute([':hours' => $this->stageTtlHours]);

        $dropped = 0;
        foreach ($read->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->discard((int) $id);
            $dropped++;
        }

        return $dropped;
    }

    /**
     * Whether an identical file has already been applied — the cheapest
     * possible answer to "have I done this already", asked before a single
     * row is read.
     *
     * @return array<string, mixed>|null the applied batch, or null
     */
    public function appliedWithSameContents(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $read = $this->pdo->prepare(
            'SELECT id, filename, applied_at, rows_inserted FROM contact_import_batch'
            . ' WHERE sha256 = :sha AND applied_at IS NOT NULL ORDER BY applied_at DESC LIMIT 1'
        );
        $read->execute([':sha' => hash_file('sha256', $path)]);
        $batch = $read->fetch();

        return is_array($batch) ? $batch : null;
    }

    // -----------------------------------------------------------------------
    // The lookups, loaded once per stage
    // -----------------------------------------------------------------------

    /**
     * Every visible member by number. ~1,954 rows of three small columns is
     * well under a megabyte and turns 80 lookups into one query.
     *
     * @return array<string, array{id:int, name:string}>
     */
    private function membersByNumber(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, member_number, first_name, last_name, preferred_name FROM member'
            . ' WHERE purged_at IS NULL AND dropped_since_import_id IS NULL AND is_system = 0'
        )->fetchAll();

        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[$this->memberNumberKey((string) $row['member_number'])] = [
                'id'   => (int) $row['id'],
                'name' => $this->displayName($row),
            ];
        }

        return $byNumber;
    }

    /**
     * One team's members, indexed by every spelling of their name this import
     * will accept: "Last, First", "First Last", the preferred name in both
     * positions, and the full name as imported.
     *
     * A key holds a LIST, not a member, because two people on one team may
     * share a spelling — and when they do the row is refused rather than
     * resolved. That refusal is the reason this returns lists.
     *
     * @return array<string, array<int, array{id:int, name:string}>>
     */
    private function teamMembersByName(int $teamId): array
    {
        $read = $this->pdo->prepare(
            'SELECT id, member_number, first_name, last_name, preferred_name, full_name FROM member'
            . ' WHERE team_id = :team AND purged_at IS NULL AND dropped_since_import_id IS NULL'
            . ' AND is_system = 0'
        );
        $read->execute([':team' => $teamId]);

        $byName = [];
        foreach ($read->fetchAll() as $row) {
            $entry = ['id' => (int) $row['id'], 'name' => $this->displayName($row)];

            $first     = trim((string) $row['first_name']);
            $last      = trim((string) $row['last_name']);
            $preferred = trim((string) $row['preferred_name']);
            $full      = trim((string) $row['full_name']);

            $spellings = [];
            if ($last !== '') {
                if ($first !== '') {
                    $spellings[] = $first . ' ' . $last;
                    $spellings[] = $last . ', ' . $first;
                }
                if ($preferred !== '') {
                    $spellings[] = $preferred . ' ' . $last;
                    $spellings[] = $last . ', ' . $preferred;
                }
            }
            if ($full !== '') {
                $spellings[] = $full;
            }

            foreach ($spellings as $spelling) {
                $key = $this->nameKey($spelling);
                if ($key === '') {
                    continue;
                }
                // The same member reached by two spellings is one match, not
                // two: a list of ids, deduplicated, so `full_name` agreeing
                // with `First Last` never makes somebody ambiguous with
                // themselves.
                foreach ($byName[$key] ?? [] as $existing) {
                    if ($existing['id'] === $entry['id']) {
                        continue 2;
                    }
                }
                $byName[$key][] = $entry;
            }
        }

        return $byName;
    }

    /**
     * Every ACTIVE account, by member number and by every spelling of the
     * holder's name — the same keys `teamMembersByName` builds, so a row can
     * name its officer either way.
     *
     * Committee-wide rather than team-scoped, because an officer who helped
     * with a team is not necessarily on it. A name matching two accounts is
     * dropped from the index entirely: the row it would resolve is refused as
     * `officer_not_found`, which is honest — this import will not guess whose
     * work it is recording.
     *
     * @return array<string, int>
     */
    private function officersByKey(): array
    {
        $rows = $this->pdo->query(
            'SELECT u.id, m.member_number, m.first_name, m.last_name, m.preferred_name, m.full_name'
            . ' FROM app_user u JOIN member m ON m.id = u.member_id'
            . ' WHERE u.is_active = 1 AND m.purged_at IS NULL'
        )->fetchAll();

        $counts = [];
        $byKey  = [];

        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $keys = [$this->memberNumberKey((string) $row['member_number'])];

            $first     = trim((string) $row['first_name']);
            $last      = trim((string) $row['last_name']);
            $preferred = trim((string) $row['preferred_name']);
            $full      = trim((string) $row['full_name']);

            if ($last !== '') {
                if ($first !== '') {
                    $keys[] = $this->nameKey($first . ' ' . $last);
                    $keys[] = $this->nameKey($last . ', ' . $first);
                }
                if ($preferred !== '') {
                    $keys[] = $this->nameKey($preferred . ' ' . $last);
                    $keys[] = $this->nameKey($last . ', ' . $preferred);
                }
            }
            if ($full !== '') {
                $keys[] = $this->nameKey($full);
            }

            foreach (array_unique(array_filter($keys)) as $key) {
                if (($byKey[$key] ?? $id) !== $id) {
                    $counts[$key] = 2;
                    continue;
                }
                $byKey[$key]  = $id;
                $counts[$key] = 1;
            }
        }

        foreach ($counts as $key => $count) {
            if ($count > 1) {
                unset($byKey[$key]);
            }
        }

        return $byKey;
    }

    /** @return array<int, array<string, mixed>> */
    private function showYears(): array
    {
        return $this->pdo->query(
            'SELECT id, label, starts_on, ends_on, is_open, is_active FROM show_year'
            . ' ORDER BY starts_on IS NULL, starts_on'
        )->fetchAll();
    }

    /**
     * Which show year a contact belongs to, and whether that was a guess.
     *
     * A year whose dates contain the contact wins. Failing that — and most
     * show years carry no dates at all, `starts_on` being nullable — it goes
     * to the active year, flagged, because a contact must be keyed to a year
     * for "contacted this year" to be answerable at all.
     *
     * A CLOSED year returns null, whichever way it was chosen. Closing
     * freezes, and this import does not get an exemption the log-a-contact
     * screen does not have.
     *
     * @param array<int, array<string, mixed>> $years
     * @param array<string, mixed>             $active
     *
     * @return array{0: ?int, 1: bool}
     */
    private function yearFor(string $occurredUtc, array $years, array $active): array
    {
        $day = substr($occurredUtc, 0, 10);

        foreach ($years as $year) {
            $from = $year['starts_on'] === null ? null : (string) $year['starts_on'];
            $to   = $year['ends_on'] === null ? null : (string) $year['ends_on'];

            if ($from === null && $to === null) {
                continue;
            }
            if ($from !== null && $day < $from) {
                continue;
            }
            if ($to !== null && $day > $to) {
                continue;
            }

            return [(int) $year['is_open'] === 1 ? (int) $year['id'] : null, false];
        }

        return [(int) $active['is_open'] === 1 ? (int) $active['id'] : null, true];
    }

    // -----------------------------------------------------------------------
    // Small shared pieces
    // -----------------------------------------------------------------------

    /**
     * Is this exact contact already in the log?
     *
     * Member, moment and type together. Deliberately exact rather than
     * same-day: two calls to one member on one afternoon is ordinary, and
     * collapsing them would lose a real contact to protect against a
     * hypothetical one. What this catches is the case that actually happens —
     * the same file applied twice.
     */
    private function alreadyLogged(int $memberId, string $occurredAt, string $type): bool
    {
        $read = $this->pdo->prepare(
            'SELECT 1 FROM contact_log WHERE member_id = :member AND occurred_at = :at'
            . ' AND contact_type = :type LIMIT 1'
        );
        $read->execute([':member' => $memberId, ':at' => $occurredAt, ':type' => $type]);

        return $read->fetchColumn() !== false;
    }

    private function contactKey(int $memberId, string $occurredAt, string $type): string
    {
        return $memberId . '|' . $occurredAt . '|' . $type;
    }

    /**
     * The member-number matching key.
     *
     * `1234567.0` is what a spreadsheet writes when it decides a seven-digit
     * identifier is a number, and it is the same member as `1234567`. Leading
     * zeros are preserved — `0012345` is not `12345`, and CLAUDE.md says a
     * leading zero must survive a round trip — so nothing is stripped from the
     * front.
     */
    private function memberNumberKey(string $raw): string
    {
        $value = trim($raw);
        if (preg_match('/^(\d+)\.0+$/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return $value;
    }

    /** Case, punctuation and spacing insensitive; "Smith, John" = "John Smith". */
    private function nameKey(string $raw): string
    {
        $value = mb_strtolower(trim($raw), 'UTF-8');
        // A trailing suffix or an initial's full stop should not decide
        // whether two spellings of one person match.
        $value = str_replace(["\u{2019}", "'", '.', ','], ['', '', '', ' '], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** @param array<string, mixed> $row */
    private function displayName(array $row): string
    {
        $first = trim((string) ($row['preferred_name'] ?? '')) !== ''
            ? trim((string) $row['preferred_name'])
            : trim((string) ($row['first_name'] ?? ''));

        $name = trim($first . ' ' . trim((string) ($row['last_name'] ?? '')));

        return $name !== '' ? $name : (string) ($row['member_number'] ?? '');
    }

    /** @param array<int, string> $row */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Writes to staging
    // -----------------------------------------------------------------------

    private function createBatch(
        int $showYearId,
        ?int $teamId,
        int $defaultOfficerId,
        string $filename,
        string $path,
        ?int $uploadedBy,
    ): int {
        $insert = $this->pdo->prepare(
            'INSERT INTO contact_import_batch'
            . ' (show_year_id, team_id, default_officer_user_id, filename, sha256, uploaded_by, dry_run)'
            . ' VALUES (:year, :team, :officer, :filename, :sha, :by, 1)'
        );
        $insert->execute([
            ':year'     => $showYearId,
            ':team'     => $teamId,
            ':officer'  => $defaultOfficerId,
            ':filename' => mb_substr($filename, 0, 255),
            // Identifies the file itself, so "did we already load this one" is
            // answerable without keeping the file — and nothing keeps the
            // file, because it is a list of who called whom.
            ':sha'      => hash_file('sha256', $path),
            ':by'       => $uploadedBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function flush(int $batchId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO contact_import_row'
            . ' (batch_id, `row_number`, action, outcome_kind, detail, raw_member, raw_officer,'
            . ' raw_date, raw_type, member_id, contacted_by, show_year_id, contact_type,'
            . ' occurred_at, notes)'
            . ' VALUES (:batch, :number, :action, :kind, :detail, :member_raw, :officer_raw,'
            . ' :date_raw, :type_raw, :member, :by, :year, :type, :at, :notes)'
        );

        foreach ($rows as $row) {
            $insert->execute([
                ':batch'       => $batchId,
                ':number'      => (int) $row['row_number'],
                ':action'      => (string) $row['action'],
                ':kind'        => (string) $row['outcome_kind'],
                ':detail'      => mb_substr((string) $row['detail'], 0, 500),
                ':member_raw'  => (string) $row['raw_member'],
                ':officer_raw' => (string) $row['raw_officer'],
                ':date_raw'    => (string) $row['raw_date'],
                ':type_raw'    => (string) $row['raw_type'],
                ':member'      => $row['member_id'],
                ':by'          => $row['contacted_by'],
                ':year'        => $row['show_year_id'],
                ':type'        => (string) $row['contact_type'],
                ':at'          => $row['occurred_at'],
                ':notes'       => (string) $row['notes'],
            ]);
        }
    }

    /** @param array<string, int> $counts */
    private function finishBatch(int $batchId, array $counts, int $warnings): void
    {
        $this->pdo->prepare(
            'UPDATE contact_import_batch SET rows_read = :read, rows_ready = :ready,'
            . ' rows_duplicate = :duplicate, rows_skipped = :skipped, warnings_count = :warnings'
            . ' WHERE id = :id'
        )->execute([
            ':read'      => $counts['read'],
            ':ready'     => $counts['insert'],
            ':duplicate' => $counts['duplicate'],
            ':skipped'   => $counts['skip'],
            ':warnings'  => $warnings,
            ':id'        => $batchId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function batchList(string $where, int $limit): array
    {
        $limit = max(1, min(100, $limit));

        return $this->pdo->query(
            'SELECT b.*, t.name AS team_name, y.label AS show_year, m.member_number AS uploader_number,'
            . ' m.first_name AS uploader_first, m.last_name AS uploader_last'
            . ' FROM contact_import_batch b'
            . ' LEFT JOIN team t ON t.id = b.team_id'
            . ' LEFT JOIN show_year y ON y.id = b.show_year_id'
            . ' LEFT JOIN app_user u ON u.id = b.uploaded_by'
            . ' LEFT JOIN member m ON m.id = u.member_id'
            . " WHERE {$where} ORDER BY b.started_at DESC, b.id DESC LIMIT {$limit}"
        )->fetchAll();
    }

    // -----------------------------------------------------------------------
    // Preconditions
    // -----------------------------------------------------------------------

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
                'No show year is active. Every contact is keyed to one, so there is nowhere to '
                . 'load history to. Create a show year and set it active first.'
            );
        }

        return $row;
    }

    /** @throws ImportException */
    private function requireOfficer(int $userId): void
    {
        $read = $this->pdo->prepare(
            'SELECT 1 FROM app_user WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $read->execute([':id' => $userId]);

        if ($read->fetchColumn() === false) {
            throw new ImportException(
                'Every contact belongs to the officer who made it, so a history load needs an '
                . 'account to attribute unlabelled rows to. Choose an active one.'
            );
        }
    }

    /** @throws ImportException */
    private function requireTeam(int $teamId): void
    {
        $read = $this->pdo->prepare('SELECT 1 FROM team WHERE id = :id LIMIT 1');
        $read->execute([':id' => $teamId]);

        if ($read->fetchColumn() === false) {
            throw new ImportException("There is no team {$teamId}.");
        }
    }
}
