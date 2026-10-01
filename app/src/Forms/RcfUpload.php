<?php

declare(strict_types=1);

namespace Rerm\Forms;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\User;
use Rerm\Roster\RosterPage;
use Throwable;

/**
 * Upload RCFs (Phase 13, spec-v2 §14) — Roster Change Forms that arrived by
 * email, read into the same record as the ones this application made.
 *
 * Track RCFs (§12) keeps every form made HERE. Most forms in circulation
 * were not: a Vice Chairman fills in the Excel template by hand and emails
 * it, the Division Chairman numbers it and forwards it, the Admin is copied
 * on all of it, and for those forms "was an RCF ever submitted for this
 * member, and did Rodeo Houston process it" is still an email search. This
 * class takes the pile of attachments and puts them in the record.
 *
 * TWO STEPS, BECAUSE A KEPT FORM IS PERMANENT. `rcf` and `rcf_row` are
 * records: nothing deletes a row. A form read wrongly and kept is kept for
 * good, so the only chance to notice a misread column or a file that is not
 * a form at all is BEFORE it is written — the same two steps as the roster
 * import and the contact history load, for the same reason:
 *
 *   1. `stage()` reads every file (`RcfReader`), resolves what the header
 *      and the lines named — members, the team, the show year — notices
 *      duplicates, and writes all of it to `rcf_upload_batch` and
 *      `rcf_upload_file`. Nothing is written to `rcf`.
 *   2. `keep()` writes the ticked files to `rcf` / `rcf_row` through the
 *      same `RcfStore` the generator uses, in one transaction, and logs one
 *      `upload_form` audit row per form. `discard()` throws a batch away;
 *      `discardExpired()` sweeps the ones nobody kept.
 *
 * WHAT IS RESOLVED HERE, ONCE. The keep writes what the preview showed and
 * re-reads nothing: the file is gone (read from PHP's temporary upload and
 * kept nowhere — a filled-in form is personal data), and the thing the Admin
 * ticked has to be the thing that is kept. So the staged row carries the
 * form in exactly the shape `RcfStore::store()` takes, with the names the
 * roster supplied and the previous titles it filled in, and beside it every
 * doubt the reader or this class had about it.
 *
 * WHAT IT NEVER DOES. Nothing here touches `member`, `contact_log` or any
 * table an import owns: an uploaded form is a request, and the next import
 * is the answer, exactly as for a generated one (spec-v2 §11 V2-2). Nothing
 * here deletes a kept form or a line; the only DELETEs are of this class's
 * own staging rows, and only for a batch that was never kept — a kept batch
 * stays, as the record of which file became which form.
 */
final class RcfUpload
{
    /**
     * What the stage has to say about a file beyond what the reader said —
     * in the order the preview lists them, with the reader's own kinds
     * (`RcfReader::KINDS`) before them.
     */
    public const LIKELY_DUPLICATE  = 'likely_duplicate';
    public const NOT_ON_ROSTER     = 'not_on_roster';
    public const NAME_FROM_ROSTER  = 'name_from_roster';
    public const TITLE_FROM_ROSTER = 'title_from_roster';
    public const TEAM_UNMATCHED    = 'team_unmatched';
    public const YEAR_ASSUMED      = 'year_assumed';

    /** @var array<int, string> */
    public const KINDS = [
        self::LIKELY_DUPLICATE, self::NOT_ON_ROSTER, self::NAME_FROM_ROSTER,
        self::TITLE_FROM_ROSTER, self::TEAM_UNMATCHED, self::YEAR_ASSUMED,
    ];

    /** Why a file was refused — the sentence the screen prints, by kind. */
    public const REFUSED_NOT_A_FORM   = 'not_a_form';
    public const REFUSED_KEPT_ALREADY = 'kept_already';
    public const REFUSED_SAME_FILE    = 'same_file';
    public const REFUSED_UPLOAD_ERROR = 'upload_error';

    /**
     * How many files one batch may hold. PHP's own ceiling is
     * `max_file_uploads` and it drops the rest in silence, so the screen
     * states that number; this is the ceiling on what is staged from what
     * arrived, so a batch is always a page somebody can read.
     */
    public const MAX_FILES = 50;

    private readonly int $stageTtlHours;

    public function __construct(
        private readonly PDO $pdo,
        private readonly RcfStore $store,
        int $stageTtlHours = 24,
    ) {
        $this->stageTtlHours = max(1, $stageTtlHours);
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            RcfStore::fromApp($app),
            (int) $app->config()->get('import.stage_ttl_hours', 24)
        );
    }

    /**
     * May this user open (and therefore keep or discard) this upload? The
     * person who made it, and whoever may see every form (§12.3) — the same
     * shape as `RcfTracking::mayView()`, so an upload is visible to exactly
     * the people its kept forms will be.
     */
    public static function mayOpen(User $user, int $uploadedBy): bool
    {
        return $uploadedBy === $user->id || Access::mayUse($user, Capability::ViewAllForms);
    }

    // -----------------------------------------------------------------------
    // Step one: read, resolve, stage. `rcf` is untouched.
    // -----------------------------------------------------------------------

    /**
     * Reads a set of uploaded files into one staged batch and returns its id.
     *
     * Every file that arrived is a row, whatever became of it: one that is
     * not a form, one PHP could not receive, one already kept, one that is
     * the same file as an earlier one in the batch — each is `refused` with
     * the sentence that says why, so nothing a person chose is dropped in
     * silence. A readable form is `ready`, with a tick box on the screen.
     *
     * @param array<int, array{path: ?string, name: string, size?: int, error?: string}> $files
     *        in the order the browser sent them; `error` is PHP's refusal
     *        for a file that did not arrive, and `path` is then null
     *
     * @throws RcfUploadException when nothing can be staged at all
     */
    public function stage(User $actor, array $files): int
    {
        $files = array_values($files);
        if ($files === []) {
            throw new RcfUploadException('Choose at least one Roster Change Form to upload.');
        }
        if (count($files) > self::MAX_FILES) {
            throw new RcfUploadException(sprintf(
                'That is %d files; up to %d are read at a time. Upload them in smaller batches.',
                count($files),
                self::MAX_FILES
            ));
        }

        $years = $this->showYears();
        if ($years['active'] === null) {
            throw new RcfUploadException(
                'No show year is active, so there is nowhere to file an uploaded form. '
                . 'An Admin makes a show year active on Show Year.'
            );
        }

        $teams     = $this->teams();
        $divisions = $this->divisions();

        $this->pdo->prepare('INSERT INTO rcf_upload_batch (uploaded_by) VALUES (:by)')
            ->execute([':by' => $actor->id]);
        $batchId = (int) $this->pdo->lastInsertId();

        $counts = ['read' => 0, 'ready' => 0, 'refused' => 0];
        $seen   = [];

        try {
            foreach ($files as $index => $file) {
                $position = $index + 1;
                $name     = mb_substr(basename((string) ($file['name'] ?? '')), 0, 255);
                $path     = $file['path'] ?? null;
                $counts['read']++;

                if ($path === null || !is_file($path)) {
                    $this->stageRefused($batchId, $position, $name, '', 0, 'upload', self::REFUSED_UPLOAD_ERROR,
                        (string) ($file['error'] ?? 'The file did not arrive.'));
                    $counts['refused']++;
                    continue;
                }

                $sha  = (string) hash_file('sha256', $path);
                $size = (int) (filesize($path) ?: 0);

                // The same bytes twice in one upload: the second is the
                // first again, whatever it is called.
                if (isset($seen[$sha])) {
                    $this->stageRefused($batchId, $position, $name, $sha, $size, '', self::REFUSED_SAME_FILE, sprintf(
                        'The same file as #%d (%s) in this upload, under another name.',
                        $seen[$sha]['position'],
                        $seen[$sha]['name']
                    ));
                    $counts['refused']++;
                    continue;
                }
                $seen[$sha] = ['position' => $position, 'name' => $name];

                // The same bytes as a form already kept: it is in the record.
                $kept = $this->keptWithSameContents($sha);
                if ($kept !== null) {
                    $this->stageRefused($batchId, $position, $name, $sha, $size, '', self::REFUSED_KEPT_ALREADY, sprintf(
                        'This exact file was already kept as form #%d (uploaded %s UTC as %s). It is in the record.',
                        (int) $kept['id'],
                        (string) $kept['generated_at'],
                        (string) $kept['upload_filename']
                    ));
                    $counts['refused']++;
                    continue;
                }

                try {
                    $read = RcfReader::read($path);
                } catch (RcfReadException $e) {
                    $this->stageRefused($batchId, $position, $name, $sha, $size, '', self::REFUSED_NOT_A_FORM, $e->getMessage());
                    $counts['refused']++;
                    continue;
                }

                $staged = $this->resolve($read, $years, $teams, $divisions);
                $this->stageReady($batchId, $position, $name, $sha, $size, $read, $staged);
                $counts['ready']++;
            }
        } catch (Throwable $e) {
            // A batch that failed mid-way is not a preview of anything.
            $this->deleteBatch($batchId);

            throw $e;
        }

        $this->pdo->prepare(
            'UPDATE rcf_upload_batch SET files_read = :read, files_ready = :ready, files_refused = :refused'
            . ' WHERE id = :id'
        )->execute([
            ':read'    => $counts['read'],
            ':ready'   => $counts['ready'],
            ':refused' => $counts['refused'],
            ':id'      => $batchId,
        ]);

        return $batchId;
    }

    /**
     * What the header and the lines named, resolved once, and the staged
     * form in `RcfStore::store()`'s shape with the roster's names and
     * previous titles filled in where the form left them blank — the same
     * two courtesies the generator extends (`RcfPage::entry()`).
     *
     * @param array<string, mixed> $read     RcfReader::read()'s answer
     * @param array<string, mixed> $years    showYears()
     * @param array<string, array<string, mixed>> $teams     teams(), keyed by lower-cased name
     * @param array<string, array<string, mixed>> $divisions divisions(), keyed by lower-cased name
     * @return array<string, mixed> form, resolved, warnings, like_rcf_id, keep_by_default
     */
    private function resolve(array $read, array $years, array $teams, array $divisions): array
    {
        $form     = $read['form'];
        $warnings = $read['warnings'];
        $resolved = ['lines' => [], 'team_name' => '', 'division_name' => '', 'show_year' => '', 'year_assumed' => false];

        // ----- The members ----------------------------------------------------
        $numbers = [];
        foreach ($form['entries'] as $entry) {
            if (!RosterChangeForm::entryIsBlank($entry) && (string) $entry['member_number'] !== '') {
                $numbers[] = (string) $entry['member_number'];
            }
        }
        $members = $this->membersByNumber($numbers);

        foreach ($form['entries'] as $index => $entry) {
            if (RosterChangeForm::entryIsBlank($entry)) {
                continue;
            }
            $position = $index + 1;
            $number   = (string) $entry['member_number'];
            $member   = $number === '' ? null : ($members[strtoupper($number)] ?? null);

            $resolved['lines'][$position] = [
                'member_id'    => $member === null ? null : (int) $member['id'],
                'on_roster'    => $member !== null && $member['purged_at'] === null && $member['dropped_since_import_id'] === null,
                'roster_name'  => $member === null ? '' : (string) $member['name'],
                'roster_title' => $member === null ? '' : (string) $member['title'],
            ];

            if ($member === null) {
                if ($number !== '' && in_array((string) $entry['type'], ['R', 'T', 'S', 'S & T'], true)) {
                    // An addition names somebody the roster does not hold
                    // yet, which is what an addition is; any other change
                    // names somebody it should.
                    $warnings[] = ['row' => $position, 'kind' => self::NOT_ON_ROSTER, 'detail' => sprintf(
                        'Member number %s is not on the roster, so the line is kept as typed and watched by number.',
                        $number
                    )];
                }
                continue;
            }

            if ((string) $entry['member_name'] === '' && $member['name'] !== '') {
                $form['entries'][$index]['member_name'] = $member['name'];
                $warnings[] = ['row' => $position, 'kind' => self::NAME_FROM_ROSTER, 'detail' => sprintf(
                    'No name on the line; the roster\'s name for %s, %s, is used.',
                    $number,
                    $member['name']
                )];
            }

            if ((string) $entry['previous_title'] === ''
                && in_array((string) $entry['type'], ['T', 'S & T', 'R'], true)
                && (string) $member['title'] !== ''
            ) {
                $form['entries'][$index]['previous_title'] = mb_substr((string) $member['title'], 0, 160);
                $warnings[] = ['row' => $position, 'kind' => self::TITLE_FROM_ROSTER, 'detail' => sprintf(
                    'No previous title on the line; the roster\'s, %s, is filled in.',
                    (string) $member['title']
                )];
            }
        }

        // ----- The sub-committee --------------------------------------------
        [$teamId, $divisionId, $teamName, $divisionName] = self::subcommittee((string) $form['subcommittee'], $teams, $divisions);
        $form['team_id']           = $teamId;
        $form['division_id']       = $divisionId;
        $resolved['team_name']     = $teamName;
        $resolved['division_name'] = $divisionName;
        if ((string) $form['subcommittee'] !== '' && $teamId === null && $divisionId === null) {
            $warnings[] = ['row' => null, 'kind' => self::TEAM_UNMATCHED, 'detail' => sprintf(
                'The sub-committee "%s" matches no team or division by name; the label is kept as printed and the form is filed under none.',
                (string) $form['subcommittee']
            )];
        }

        // ----- The show year ------------------------------------------------
        $yearLabel = (string) $form['year'];
        if ($yearLabel !== '' && isset($years['by_label'][$yearLabel])) {
            $showYearId = (int) $years['by_label'][$yearLabel];
            $resolved['show_year'] = $yearLabel;
        } else {
            $showYearId = (int) $years['active']['id'];
            $resolved['show_year']    = (string) $years['active']['label'];
            $resolved['year_assumed'] = true;
            $form['year']             = (string) $years['active']['label'];
            $warnings[] = ['row' => null, 'kind' => self::YEAR_ASSUMED, 'detail' => $yearLabel === ''
                ? sprintf('The title names no show year, so the active one, %s, is assumed.', (string) $years['active']['label'])
                : sprintf('The title says RODEO %s and no show year carries that label, so the active one, %s, is assumed.', $yearLabel, (string) $years['active']['label'])];
        }

        // ----- A form already kept that this looks like ----------------------
        $like = $this->likelyDuplicate((string) $form['form_date'], $numbers);
        if ($like !== null) {
            $warnings[] = ['row' => null, 'kind' => self::LIKELY_DUPLICATE, 'detail' => sprintf(
                'Form #%d, %s on %s, carries the same date and the same member numbers. '
                . 'This file is left unticked; tick it if it really is a second form.',
                (int) $like['id'],
                $like['source'] === 'uploaded' ? 'uploaded' : 'made here',
                (string) $like['generated_at'] . ' UTC'
            )];
        }

        return [
            'form'            => $form,
            'resolved'        => $resolved,
            'show_year_id'    => $showYearId,
            'warnings'        => $warnings,
            'like_rcf_id'     => $like === null ? null : (int) $like['id'],
            'keep_by_default' => $like === null,
        ];
    }

    /**
     * The team and division a sub-committee label names: `Division - Team`
     * as the generator prints it, or a team's name alone, or a division's.
     * Matched by name, case-insensitively; nothing matched is null and the
     * label is kept as printed.
     *
     * @param array<string, array<string, mixed>> $teams
     * @param array<string, array<string, mixed>> $divisions
     * @return array{0: ?int, 1: ?int, 2: string, 3: string} team id, division id, team name, division name
     */
    public static function subcommittee(string $label, array $teams, array $divisions): array
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        if ($label === '') {
            return [null, null, '', ''];
        }

        $key = static fn (string $s): string => mb_strtolower(trim($s));

        // The whole label as a team, then as a division.
        if (isset($teams[$key($label)])) {
            $team = $teams[$key($label)];

            return [(int) $team['id'], null, (string) $team['name'], (string) $team['division_name']];
        }
        if (isset($divisions[$key($label)])) {
            $division = $divisions[$key($label)];

            return [null, (int) $division['id'], '', (string) $division['name']];
        }

        // "Division - Team", "Division – Team", "Division / Team": the last
        // part is the team, and if it is not one, the first part may be a
        // division on its own.
        $parts = preg_split('/\s+[-–—\/]\s+/u', $label) ?: [];
        if (count($parts) >= 2) {
            $last = $key((string) end($parts));
            if (isset($teams[$last])) {
                $team = $teams[$last];

                return [(int) $team['id'], null, (string) $team['name'], (string) $team['division_name']];
            }
            $first = $key((string) $parts[0]);
            if (isset($divisions[$first])) {
                $division = $divisions[$first];

                return [null, (int) $division['id'], '', (string) $division['name']];
            }
        }

        return [null, null, '', ''];
    }

    // -----------------------------------------------------------------------
    // The preview
    // -----------------------------------------------------------------------

    /**
     * One batch and its files, for the screen — or null when there is no
     * such batch or the caller may not open it, which is the same answer on
     * purpose.
     *
     * @return ?array{batch: array<string, mixed>, files: array<int, array<string, mixed>>}
     */
    public function preview(User $user, int $batchId): ?array
    {
        $batch = $this->batch($batchId);
        if ($batch === null || !self::mayOpen($user, (int) $batch['uploaded_by'])) {
            return null;
        }

        $read = $this->pdo->prepare(
            'SELECT f.*, r.generated_at AS kept_at FROM rcf_upload_file f'
            . ' LEFT JOIN rcf r ON r.id = f.rcf_id'
            . ' WHERE f.batch_id = :id ORDER BY f.position'
        );
        $read->execute([':id' => $batchId]);

        $files = [];
        foreach ($read->fetchAll() as $row) {
            $files[] = $this->fileForScreen($row);
        }

        return ['batch' => $batch, 'files' => $files];
    }

    /**
     * Uploads the caller may open, newest first: the ones still waiting to
     * be kept, and the ones kept, with who made each and what became of it.
     *
     * @return array{staged: array<int, array<string, mixed>>, kept: array<int, array<string, mixed>>}
     */
    public function recent(User $user, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $all   = Access::mayUse($user, Capability::ViewAllForms) ? 1 : 0;

        $list = function (string $where) use ($user, $all, $limit): array {
            $read = $this->pdo->prepare(
                $this->batchSelect() . " WHERE (b.uploaded_by = :me OR :all = 1) AND {$where}"
                . " ORDER BY b.id DESC LIMIT {$limit}"
            );
            $read->execute([':me' => $user->id, ':all' => $all]);

            return array_map([$this, 'batchForScreen'], $read->fetchAll());
        };

        return [
            'staged' => $list('b.applied_at IS NULL'),
            'kept'   => $list('b.applied_at IS NOT NULL'),
        ];
    }

    // -----------------------------------------------------------------------
    // Step two: keep. The only method here that writes rcf.
    // -----------------------------------------------------------------------

    /**
     * Keeps the ticked files of a staged batch as forms, in one transaction,
     * and marks the batch kept. A ready file whose box was not ticked is
     * `left_out` and stays readable on the batch's page as the record of
     * what was decided; a refused file stays refused.
     *
     * @param array<int, int|string> $keepFileIds the ids of the files whose boxes were ticked
     * @return array{kept: int, left_out: int, lines: int, forms: array<int, int>} file id => rcf id
     *
     * @throws RcfUploadException when the batch does not exist, is not the caller's to keep, or was kept already
     */
    public function keep(User $actor, int $batchId, array $keepFileIds): array
    {
        $batch = $this->batch($batchId);
        if ($batch === null || !self::mayOpen($actor, (int) $batch['uploaded_by'])) {
            throw new RcfUploadException("There is no upload {$batchId} to keep.");
        }
        if ($batch['applied_at'] !== null) {
            throw new RcfUploadException(sprintf(
                'Upload %d was already kept, on %s UTC. Keeping it twice is what this check exists to stop; '
                . 'upload the files again if there is more to keep.',
                $batchId,
                (string) $batch['applied_at']
            ));
        }

        $keep = [];
        foreach ($keepFileIds as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $keep[(int) $id] = true;
            }
        }

        $read = $this->pdo->prepare(
            "SELECT * FROM rcf_upload_file WHERE batch_id = :id AND status = 'ready' ORDER BY position"
        );
        $read->execute([':id' => $batchId]);
        $files = $read->fetchAll();

        $kept    = 0;
        $leftOut = 0;
        $lines   = 0;
        $forms   = [];

        $this->pdo->beginTransaction();

        try {
            $mark = $this->pdo->prepare(
                'UPDATE rcf_upload_file SET status = :status, rcf_id = :rcf WHERE id = :id AND batch_id = :batch'
            );
            $audit = new AuditLog($this->pdo);

            foreach ($files as $file) {
                $fileId = (int) $file['id'];

                if (!isset($keep[$fileId])) {
                    $mark->execute([':status' => 'left_out', ':rcf' => null, ':id' => $fileId, ':batch' => $batchId]);
                    $leftOut++;
                    continue;
                }

                $stored = json_decode((string) $file['form_json'], true);
                $form   = is_array($stored['form'] ?? null) ? $stored['form'] : null;
                if ($form === null) {
                    throw new RcfUploadException(sprintf(
                        'File #%d (%s) was staged without a readable form. Discard this upload and read the files again.',
                        (int) $file['position'],
                        (string) $file['filename']
                    ));
                }

                $rcfId = $this->store->store($actor, (int) $file['show_year_id'], $form, [
                    'source'   => 'uploaded',
                    'filename' => (string) $file['filename'],
                    'sha256'   => (string) $file['sha256'],
                    'serial'   => (string) $file['serial'],
                ]);

                $mark->execute([':status' => 'kept', ':rcf' => $rcfId, ':id' => $fileId, ':batch' => $batchId]);

                $audit->record($actor, Action::UploadForm, 'rcf', (string) $rcfId, null, [
                    'form'          => 'roster_change_form',
                    'filename'      => (string) $file['filename'],
                    'sha256'        => (string) $file['sha256'],
                    'show_year'     => (string) $form['year'],
                    'sub_committee' => (string) $form['subcommittee'],
                    'submitted_by'  => (string) $form['submitter'],
                    'form_date'     => (string) ($form['form_date'] ?? ''),
                    'serial'        => (string) $file['serial'],
                    'rows'          => (int) $file['row_count'],
                    'rcf_id'        => $rcfId,
                    'upload_batch'  => $batchId,
                ]);

                $forms[$fileId] = $rcfId;
                $kept++;
                $lines += (int) $file['row_count'];
            }

            $this->pdo->prepare(
                'UPDATE rcf_upload_batch SET applied_at = UTC_TIMESTAMP(), files_kept = :kept, lines_kept = :lines'
                . ' WHERE id = :id'
            )->execute([':kept' => $kept, ':lines' => $lines, ':id' => $batchId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['kept' => $kept, 'left_out' => $leftOut, 'lines' => $lines, 'forms' => $forms];
    }

    // -----------------------------------------------------------------------
    // Housekeeping
    // -----------------------------------------------------------------------

    /**
     * Throws a staged batch away. A KEPT batch is refused: it is the record
     * of which file became which form, and `rcf_upload_file.rcf_id` points
     * at forms that cannot be deleted either.
     *
     * @throws RcfUploadException
     */
    public function discard(User $user, int $batchId): void
    {
        $batch = $this->batch($batchId);
        if ($batch === null || !self::mayOpen($user, (int) $batch['uploaded_by'])) {
            return;
        }
        if ($batch['applied_at'] !== null) {
            throw new RcfUploadException(
                "Upload {$batchId} was kept. A kept upload is the record of what was kept, and it stays."
            );
        }

        $this->deleteBatch($batchId);
    }

    /**
     * Drops staged batches older than the TTL, and returns how many. A
     * preview read last week was read against a roster and a record that
     * have both moved since.
     */
    public function discardExpired(): int
    {
        $read = $this->pdo->prepare(
            'SELECT id FROM rcf_upload_batch WHERE applied_at IS NULL'
            . ' AND started_at < (UTC_TIMESTAMP() - INTERVAL :hours HOUR)'
        );
        $read->execute([':hours' => $this->stageTtlHours]);

        $dropped = 0;
        foreach ($read->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->deleteBatch((int) $id);
            $dropped++;
        }

        return $dropped;
    }

    /**
     * A kept form read from these exact bytes, or null — the cheapest answer
     * to "have I uploaded this one already", asked before a byte is parsed.
     *
     * @return ?array<string, mixed>
     */
    public function keptWithSameContents(string $sha256): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return null;
        }

        $read = $this->pdo->prepare(
            'SELECT id, generated_at, upload_filename FROM rcf WHERE upload_sha256 = :sha ORDER BY id LIMIT 1'
        );
        $read->execute([':sha' => $sha256]);
        $row = $read->fetch();

        return is_array($row) ? $row : null;
    }

    // -----------------------------------------------------------------------
    // Writes to staging
    // -----------------------------------------------------------------------

    private function stageRefused(
        int $batchId,
        int $position,
        string $name,
        string $sha,
        int $size,
        string $format,
        string $kind,
        string $why,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO rcf_upload_file (batch_id, position, filename, sha256, format, size_bytes, status,'
            . ' refusal, keep_by_default, form_json, warnings_json)'
            . " VALUES (:batch, :position, :name, :sha, :format, :size, 'refused', :refusal, 0, NULL, :warnings)"
        )->execute([
            ':batch'    => $batchId,
            ':position' => $position,
            ':name'     => $name,
            // A file that never arrived has no bytes to hash; the column is
            // NOT NULL so the record is one row per file, so it carries the
            // sentinel every reader can recognise as "no file".
            ':sha'      => $sha === '' ? str_repeat('0', 64) : $sha,
            ':format'   => mb_substr($format, 0, 8),
            ':size'     => $size,
            ':refusal'  => mb_substr($why, 0, 500),
            ':warnings' => self::json([['row' => null, 'kind' => $kind, 'detail' => mb_substr($why, 0, 500)]]),
        ]);
    }

    /**
     * @param array<string, mixed> $read   RcfReader::read()'s answer
     * @param array<string, mixed> $staged resolve()'s answer
     */
    private function stageReady(
        int $batchId,
        int $position,
        string $name,
        string $sha,
        int $size,
        array $read,
        array $staged,
    ): void {
        $form = $staged['form'];

        $this->pdo->prepare(
            'INSERT INTO rcf_upload_file (batch_id, position, filename, sha256, format, size_bytes, status,'
            . ' refusal, keep_by_default, like_rcf_id, show_year_id, division_id, team_id, serial, form_date,'
            . ' row_count, warnings_count, form_json, warnings_json)'
            . " VALUES (:batch, :position, :name, :sha, :format, :size, 'ready', '', :keep, :like, :year,"
            . ' :division, :team, :serial, :date, :rows, :warnings_count, :form, :warnings)'
        )->execute([
            ':batch'          => $batchId,
            ':position'       => $position,
            ':name'           => $name,
            ':sha'            => $sha,
            ':format'         => mb_substr((string) $read['format'], 0, 8),
            ':size'           => $size,
            ':keep'           => $staged['keep_by_default'] ? 1 : 0,
            ':like'           => $staged['like_rcf_id'],
            ':year'           => $staged['show_year_id'],
            ':division'       => $form['division_id'],
            ':team'           => $form['team_id'],
            ':serial'         => mb_substr((string) $read['serial'], 0, RcfTracking::SERIAL_MAX),
            ':date'           => (string) $form['form_date'] === '' ? null : (string) $form['form_date'],
            ':rows'           => (int) $read['rows'],
            ':warnings_count' => count($staged['warnings']),
            ':form'           => self::json([
                'form'     => $form,
                'resolved' => $staged['resolved'],
                'read'     => [
                    'format'   => (string) $read['format'],
                    'sheet'    => (string) $read['sheet'],
                    'title'    => (string) $read['title'],
                    'raw_date' => (string) $read['raw_date'],
                    'columns'  => $read['columns'],
                ],
            ]),
            ':warnings'       => self::json(array_values($staged['warnings'])),
        ]);
    }

    /**
     * The staged form as JSON. A byte that is not UTF-8 — a name pasted
     * from somewhere old — is substituted rather than refusing the whole
     * file: the JSON column would refuse it outright, and a file the Admin
     * cannot see on the preview is a file that cannot be decided about.
     *
     * @param array<mixed> $payload
     */
    private static function json(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /** The files first, then the batch — RESTRICT means the order matters. */
    private function deleteBatch(int $batchId): void
    {
        $this->pdo->prepare('DELETE FROM rcf_upload_file WHERE batch_id = :id')->execute([':id' => $batchId]);
        $this->pdo->prepare('DELETE FROM rcf_upload_batch WHERE id = :id AND applied_at IS NULL')->execute([':id' => $batchId]);
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /** @return ?array<string, mixed> */
    private function batch(int $batchId): ?array
    {
        if ($batchId <= 0) {
            return null;
        }

        $read = $this->pdo->prepare($this->batchSelect() . ' WHERE b.id = :id');
        $read->execute([':id' => $batchId]);
        $row = $read->fetch();

        return is_array($row) ? $this->batchForScreen($row) : null;
    }

    private function batchSelect(): string
    {
        return 'SELECT b.*, m.preferred_name AS u_preferred, m.first_name AS u_first, m.last_name AS u_last,'
            . ' m.member_number AS u_number'
            . ' FROM rcf_upload_batch b'
            . ' INNER JOIN app_user u ON u.id = b.uploaded_by'
            . ' INNER JOIN member m ON m.id = u.member_id';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function batchForScreen(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'uploaded_by'   => (int) $row['uploaded_by'],
            'uploader_name' => RosterPage::displayName(
                (string) $row['u_preferred'],
                (string) $row['u_first'],
                (string) $row['u_last'],
                (string) $row['u_number']
            ),
            'started_at'    => (string) $row['started_at'],
            'applied_at'    => $row['applied_at'] === null ? null : (string) $row['applied_at'],
            'files_read'    => (int) $row['files_read'],
            'files_ready'   => (int) $row['files_ready'],
            'files_refused' => (int) $row['files_refused'],
            'files_kept'    => (int) $row['files_kept'],
            'lines_kept'    => (int) $row['lines_kept'],
        ];
    }

    /**
     * One staged file as the screen shows it: what was read, resolved and
     * doubted, with the filled lines in order and the warnings about each
     * beside it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function fileForScreen(array $row): array
    {
        $stored   = $row['form_json'] === null ? null : json_decode((string) $row['form_json'], true);
        $form     = is_array($stored['form'] ?? null) ? $stored['form'] : null;
        $resolved = is_array($stored['resolved'] ?? null) ? $stored['resolved'] : ['lines' => []];
        $read     = is_array($stored['read'] ?? null) ? $stored['read'] : [];
        $warnings = $row['warnings_json'] === null ? [] : (json_decode((string) $row['warnings_json'], true) ?: []);

        $byRow   = [];
        $general = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            if (($warning['row'] ?? null) === null) {
                $general[] = $warning;
            } else {
                $byRow[(int) $warning['row']][] = $warning;
            }
        }

        $lines = [];
        if ($form !== null) {
            foreach ((array) $form['entries'] as $index => $entry) {
                if (!is_array($entry) || RosterChangeForm::entryIsBlank($entry)) {
                    continue;
                }
                $position = (int) $index + 1;
                $fact     = $resolved['lines'][$position] ?? $resolved['lines'][(string) $position] ?? [];

                $lines[] = [
                    'position'         => $position,
                    'type'             => (string) ($entry['type'] ?? ''),
                    'type_label'       => RosterChangeForm::TYPES[(string) ($entry['type'] ?? '')] ?? (string) ($entry['type'] ?? ''),
                    'member_name'      => (string) ($entry['member_name'] ?? ''),
                    'member_number'    => (string) ($entry['member_number'] ?? ''),
                    'member_id'        => isset($fact['member_id']) && $fact['member_id'] !== null ? (int) $fact['member_id'] : null,
                    'on_roster'        => (bool) ($fact['on_roster'] ?? false),
                    'rookie'           => (string) ($entry['rookie'] ?? '') === RosterChangeForm::TICKED,
                    'wait_list'        => (string) ($entry['wait_list'] ?? '') === RosterChangeForm::TICKED,
                    'new_title'        => (string) ($entry['new_title'] ?? ''),
                    'previous_title'   => (string) ($entry['previous_title'] ?? ''),
                    'remove_reason'    => (string) ($entry['remove_reason'] ?? ''),
                    'new_subcommittee' => (string) ($entry['new_subcommittee'] ?? ''),
                    'sponsor'          => (string) ($entry['sponsor'] ?? ''),
                    'change'           => RcfTracking::changeSummary($entry),
                    'warnings'         => $byRow[$position] ?? [],
                ];
            }
        }

        return [
            'id'              => (int) $row['id'],
            'position'        => (int) $row['position'],
            'filename'        => (string) $row['filename'],
            'sha256'          => (string) $row['sha256'],
            'format'          => (string) $row['format'],
            'size_bytes'      => (int) $row['size_bytes'],
            'status'          => (string) $row['status'],
            'refusal'         => (string) $row['refusal'],
            'keep_by_default' => (int) $row['keep_by_default'] === 1,
            'like_rcf_id'     => $row['like_rcf_id'] === null ? null : (int) $row['like_rcf_id'],
            'serial'          => (string) $row['serial'],
            'form_date'       => $row['form_date'] === null ? '' : (string) $row['form_date'],
            'row_count'       => (int) $row['row_count'],
            'warnings_count'  => (int) $row['warnings_count'],
            'rcf_id'          => $row['rcf_id'] === null ? null : (int) $row['rcf_id'],
            'kept_at'         => ($row['kept_at'] ?? null) === null ? null : (string) $row['kept_at'],
            'form'            => $form,
            'resolved'        => $resolved,
            'read'            => $read,
            'lines'           => $lines,
            'warnings'        => $general,
        ];
    }

    /**
     * @param array<int, string> $numbers
     * @return array<string, array<string, mixed>> upper-cased number => id, name, title, purged_at, dropped_since_import_id
     */
    private function membersByNumber(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter($numbers, static fn (string $n): bool => $n !== '')));
        if ($numbers === []) {
            return [];
        }

        $places = [];
        $bind   = [];
        foreach ($numbers as $i => $number) {
            $places[]       = ":n{$i}";
            $bind[":n{$i}"] = $number;
        }

        $read = $this->pdo->prepare(
            'SELECT id, member_number, full_name, first_name, last_name, title, purged_at, dropped_since_import_id'
            . ' FROM member WHERE is_system = 0 AND member_number IN (' . implode(', ', $places) . ')'
        );
        $read->execute($bind);

        $members = [];
        foreach ($read->fetchAll() as $row) {
            // The name Rodeo Houston spells — full_name, else first and
            // last — never the preferred name, exactly as the generator
            // prints it (RcfPage::formalName).
            $full = trim((string) $row['full_name']);
            $name = $full !== '' ? $full : trim(trim((string) $row['first_name']) . ' ' . trim((string) $row['last_name']));

            $members[strtoupper((string) $row['member_number'])] = [
                'id'                      => (int) $row['id'],
                'name'                    => $name,
                'title'                   => (string) $row['title'],
                'purged_at'               => $row['purged_at'],
                'dropped_since_import_id' => $row['dropped_since_import_id'],
            ];
        }

        return $members;
    }

    /**
     * A kept form carrying the same date and the same member numbers as
     * this one — the Division Chairman forwarding the numbered copy of a
     * form a Vice Chairman generated here, most often. Null when the form
     * has no date or no numbers to compare, or nothing matches.
     *
     * @param array<int, string> $numbers
     * @return ?array<string, mixed>
     */
    private function likelyDuplicate(string $formDate, array $numbers): ?array
    {
        $numbers = array_values(array_unique(array_map('strtoupper', array_filter($numbers, static fn (string $n): bool => $n !== ''))));
        sort($numbers);
        if ($formDate === '' || $numbers === []) {
            return null;
        }

        $read = $this->pdo->prepare(
            'SELECT r.rcf_id, r.member_number FROM rcf_row r'
            . ' INNER JOIN rcf f ON f.id = r.rcf_id'
            . " WHERE f.form_date = :date AND r.member_number <> ''"
            . ' ORDER BY r.rcf_id, r.position'
        );
        $read->execute([':date' => $formDate]);

        $sets = [];
        foreach ($read->fetchAll() as $row) {
            $sets[(int) $row['rcf_id']][] = strtoupper((string) $row['member_number']);
        }

        foreach ($sets as $rcfId => $set) {
            $set = array_values(array_unique($set));
            sort($set);
            if ($set === $numbers) {
                $form = $this->pdo->prepare('SELECT id, generated_at, source FROM rcf WHERE id = :id');
                $form->execute([':id' => $rcfId]);
                $row = $form->fetch();

                return is_array($row) ? $row : null;
            }
        }

        return null;
    }

    /** @return array{active: ?array<string, mixed>, by_label: array<string, int>} */
    private function showYears(): array
    {
        $active  = null;
        $byLabel = [];
        foreach ($this->pdo->query('SELECT id, label, is_active FROM show_year')->fetchAll() as $row) {
            $byLabel[(string) $row['label']] = (int) $row['id'];
            if ((int) $row['is_active'] === 1) {
                $active = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
            }
        }

        return ['active' => $active, 'by_label' => $byLabel];
    }

    /** @return array<string, array<string, mixed>> lower-cased name => id, name, division_id, division_name */
    private function teams(): array
    {
        $teams = [];
        foreach ($this->pdo->query(
            'SELECT t.id, t.name, t.division_id, d.name AS division_name FROM team t'
            . ' LEFT JOIN division d ON d.id = t.division_id'
        )->fetchAll() as $row) {
            $teams[mb_strtolower(trim((string) $row['name']))] = [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['name'],
                'division_id'   => $row['division_id'] === null ? null : (int) $row['division_id'],
                'division_name' => (string) ($row['division_name'] ?? ''),
            ];
        }

        return $teams;
    }

    /**
     * Every REAL division by name. `(No Division)` is this application's
     * bookkeeping and is never printed on a form (§2.2), so a form cannot
     * name it and it is not offered as a match.
     *
     * @return array<string, array<string, mixed>>
     */
    private function divisions(): array
    {
        $divisions = [];
        foreach ($this->pdo->query('SELECT id, name FROM division WHERE is_placeholder = 0')->fetchAll() as $row) {
            $divisions[mb_strtolower(trim((string) $row['name']))] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $divisions;
    }
}
