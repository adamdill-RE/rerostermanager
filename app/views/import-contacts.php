<?php

declare(strict_types=1);

/**
 * Import Contact History (spec 6.7).
 *
 * Two screens in one file with a diff between them, exactly like Import
 * Roster and for the same reason: what this writes goes into `contact_log`,
 * which is never edited and never deleted, so the only chance to catch a
 * wrong column or a misread date is BEFORE the apply.
 *
 * It is a smaller screen than the roster import because it has less to say —
 * every row is an insert or it is not — but the shape is deliberately
 * identical. An Admin who has learned one should not have to learn the other.
 *
 * Two host constraints shape the markup (docs/hosting.md), and they are the
 * roster import's:
 *
 *   * max_input_vars is 1000 with silent truncation, so the row table is a
 *     TABLE and never a form. The apply form carries two inputs: the CSRF
 *     token and a batch id.
 *   * No JavaScript anywhere in this application. The row list expands with
 *     <details>, which needs none.
 *
 * @var Rerm\App                             $app
 * @var ?string                              $blocked  set when the schema is behind the code
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>|null            $preview
 * @var array<int, array<string, mixed>>     $staged
 * @var array<int, array<string, mixed>>     $applied
 * @var array<int, array<string, mixed>>     $teams
 * @var array<int, array<string, mixed>>     $officers
 */

use Rerm\Csrf;
use Rerm\Import\ContactHeaderMap;
use Rerm\Import\ContactImporter;

$chip = static function (string $level, string $word): string {
    $class = match ($level) {
        'ok'    => 'chip-ok',
        'warn'  => 'chip-warn',
        default => 'chip-danger',
    };

    return '<span class="chip ' . $class . '">' . e($word) . '</span>';
};

$number = static fn (int $n): string => number_format($n);

/** A value as the table shows it — blank is a word, not an empty cell. */
$shown = static function (mixed $value): string {
    $text = trim((string) $value);

    return $text === '' ? '(blank)' : $text;
};

/** The headline for one outcome kind, in the words an Admin can act on. */
$headline = static function (string $kind): string {
    return match ($kind) {
        ContactImporter::NO_MEMBER         => 'The row names no member',
        ContactImporter::MEMBER_NOT_FOUND  => 'No such member',
        ContactImporter::AMBIGUOUS_NAME    => 'The name matches more than one member',
        ContactImporter::BAD_DATE          => 'No date, or one that could not be read',
        ContactImporter::FUTURE_DATE       => 'The date is in the future',
        ContactImporter::OFFICER_NOT_FOUND => 'The named officer has no active account',
        ContactImporter::YEAR_CLOSED       => 'That show year is closed',
        ContactImporter::DUPLICATE         => 'Already in the contact log',
        ContactImporter::UNKNOWN_TYPE      => 'An unfamiliar contact type — landed as Other',
        ContactImporter::YEAR_ASSUMED      => 'No show year covers the date — landed in the active year',
        default                            => $kind,
    };
};

/** @param array<string, mixed> $row */
$memberName = static function (array $row) use ($shown): string {
    $first = trim((string) ($row['preferred_name'] ?? '')) !== ''
        ? trim((string) $row['preferred_name'])
        : trim((string) ($row['first_name'] ?? ''));

    $name = trim($first . ' ' . trim((string) ($row['last_name'] ?? '')));

    return $name !== '' ? $name : $shown($row['raw_member'] ?? '');
};

/** @param array<string, mixed> $row */
$officerName = static function (array $row): string {
    $first = trim((string) ($row['officer_preferred'] ?? '')) !== ''
        ? trim((string) $row['officer_preferred'])
        : trim((string) ($row['officer_first'] ?? ''));

    return trim($first . ' ' . trim((string) ($row['officer_last'] ?? '')));
};

$typeWord = static fn (string $type): string => ucfirst(str_replace('_', ' ', $type));
?>
<h1>Import Contact History</h1>
<p class="lede">
    For contacts that happened <em>before</em> this application existed. Every row
    lands in the contact log with the date it really happened and the officer who
    really made it &mdash; which is the whole point, because My Roster Status puts
    the people nobody has contacted at the top of the list.
    <code>.xls</code>, <code>.xlsx</code> and <code>.csv</code> are all read
    directly, whichever you have.
</p>

<?php if (($blocked ?? null) !== null) { ?>
    <div class="card">
        <h2><?= $chip('danger', 'Schema is behind') ?> Nothing can be loaded yet</h2>
        <?php foreach (explode("\n\n", $blocked) as $paragraph) { ?>
            <p><?= e($paragraph) ?></p>
        <?php } ?>
        <p>
            Apply them from <code><?= e($app->url('setup')) ?></code> with
            <code>app.setup_key</code> configured, or with
            <code>php bin/migrate.php</code> where there is a shell.
        </p>
    </div>
<?php return; } ?>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <?= $chip($level, $level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<?php if ($preview === null) { ?>
    <div class="card">
        <h2>1 &middot; What the file needs to contain</h2>
        <p>
            One row per contact. Column order does not matter, extra columns are
            ignored, and each of these is matched by any of the spellings listed
            &mdash; so a spreadsheet somebody has already been keeping usually
            needs nothing done to it.
        </p>
        <table>
            <thead>
                <tr>
                    <th scope="col">Column</th>
                    <th scope="col">Spellings it answers to</th>
                    <th scope="col">Needed?</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td data-label="Column">Who it was with</td>
                    <td data-label="Spellings" class="mono"><?=
                        e(implode(', ', array_merge(
                            ContactHeaderMap::ALIASES[ContactHeaderMap::MEMBER_NUMBER],
                            ContactHeaderMap::ALIASES[ContactHeaderMap::MEMBER_NAME]
                        ))) ?></td>
                    <td data-label="Needed?"><strong>Yes</strong> &mdash; a number or a name</td>
                </tr>
                <tr>
                    <td data-label="Column">When</td>
                    <td data-label="Spellings" class="mono"><?=
                        e(implode(', ', ContactHeaderMap::ALIASES[ContactHeaderMap::OCCURRED_AT])) ?></td>
                    <td data-label="Needed?"><strong>Yes</strong> &mdash; rows without one are listed and skipped</td>
                </tr>
                <tr>
                    <td data-label="Column">How</td>
                    <td data-label="Spellings" class="mono"><?=
                        e(implode(', ', ContactHeaderMap::ALIASES[ContactHeaderMap::CONTACT_TYPE])) ?></td>
                    <td data-label="Needed?">No &mdash; a blank one is a call</td>
                </tr>
                <tr>
                    <td data-label="Column">Which officer</td>
                    <td data-label="Spellings" class="mono"><?=
                        e(implode(', ', ContactHeaderMap::ALIASES[ContactHeaderMap::OFFICER])) ?></td>
                    <td data-label="Needed?">No &mdash; blank rows use the officer you choose below</td>
                </tr>
                <tr>
                    <td data-label="Column">What was said</td>
                    <td data-label="Spellings" class="mono"><?=
                        e(implode(', ', ContactHeaderMap::ALIASES[ContactHeaderMap::NOTES])) ?></td>
                    <td data-label="Needed?">No</td>
                </tr>
            </tbody>
        </table>
        <p class="hint">
            Dates are read in US order, so <code>3/4/2026</code> is the fourth of March.
            <code>2026-03-04</code>, <code>4 Mar 2026</code> and a real date cell all work too.
            Contact types understand <code>call</code>, <code>text</code>, <code>email</code>,
            <code>in person</code> and <code>other</code>, along with the words people
            actually write &mdash; <code>vm</code>, <code>left voicemail</code>,
            <code>sms</code>, <code>f2f</code>. Anything else lands as <em>Other</em>
            with its note intact rather than being dropped.
        </p>
        <p class="hint">
            In a <code>.csv</code>, a name written &ldquo;Surname, Given&rdquo; must be in
            quotes &mdash; otherwise the comma splits it into two columns. An
            <code>.xlsx</code> or <code>.xls</code> has no such problem.
        </p>
    </div>

    <div class="card">
        <h2>2 &middot; Choose the file, the officer and the team</h2>
        <form method="post" action="<?= e($app->url('import-contacts')) ?>" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="stage">

            <p>
                <label for="history">The contact history file</label><br>
                <input type="file" id="history" name="history" accept=".xls,.xlsx,.csv" required>
            </p>

            <p>
                <label for="officer_id">Who made these contacts</label><br>
                <select id="officer_id" name="officer_id" required>
                    <option value="">(choose an officer)</option>
                    <?php foreach ($officers as $officer) { ?>
                        <option value="<?= e((string) $officer['id']) ?>">
                            <?= e(trim(
                                (trim((string) $officer['preferred_name']) !== ''
                                    ? (string) $officer['preferred_name']
                                    : (string) $officer['first_name'])
                                . ' ' . (string) $officer['last_name']
                            )) ?>
                            &middot; <?= e((string) $officer['member_number']) ?>
                            <?php if (trim((string) ($officer['team_name'] ?? '')) !== '') { ?>
                                &middot; <?= e((string) $officer['team_name']) ?>
                            <?php } ?>
                        </option>
                    <?php } ?>
                </select>
            </p>
            <p class="hint">
                Every row that does not name its own officer is recorded against this
                one. A row <em>with</em> a &ldquo;Contacted By&rdquo; overrides it &mdash;
                and if that person has no active account the row is listed and skipped,
                rather than being attributed to somebody who did not make the call.
            </p>

            <p>
                <label for="team_id">Which team the file is about</label><br>
                <select id="team_id" name="team_id">
                    <option value="">(the file uses member numbers throughout)</option>
                    <?php foreach ($teams as $team) { ?>
                        <option value="<?= e((string) $team['id']) ?>">
                            <?= e((string) $team['name']) ?> &middot; <?= e($number((int) $team['members'])) ?> members
                        </option>
                    <?php } ?>
                </select>
            </p>
            <p class="hint">
                Names are matched <strong>within this team only</strong>. Committee-wide,
                names are not unique and this application never keys on one; inside a single
                team it is a safe question to ask, and a name still matching two people is
                reported rather than guessed. A file carrying Customer Numbers does not need
                a team at all.
            </p>

            <button type="submit">Read the file</button>
        </form>
        <p class="hint">
            <?= $chip('ok', 'Nothing is written') ?>
            Reading it writes nothing to the contact log. You get the list below first.
        </p>
    </div>
<?php } else {
    $batch  = $preview['batch'];
    $counts = $preview['counts'];
    $done   = $preview['applied'];
?>
    <div class="card">
        <h2><?= $done ? '3 &middot; What was loaded' : '3 &middot; What would be loaded' ?></h2>
        <p class="hint">
            Batch <span class="mono"><?= e((string) $batch['id']) ?></span> &middot;
            <span class="mono"><?= e((string) $batch['filename']) ?></span>
            <?php if (trim((string) ($batch['team_name'] ?? '')) !== '') { ?>
                &middot; names resolved within <?= e((string) $batch['team_name']) ?>
            <?php } ?>
            &middot; unattributed rows go to
            <?= e(trim(
                (trim((string) ($batch['officer_preferred'] ?? '')) !== ''
                    ? (string) $batch['officer_preferred']
                    : (string) ($batch['officer_first'] ?? ''))
                . ' ' . (string) ($batch['officer_last'] ?? '')
            )) ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th scope="col">What</th>
                    <th scope="col" class="num">Rows</th>
                    <th scope="col">Meaning</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td data-label="What">Read</td>
                    <td data-label="Rows" class="num"><?= e($number($counts['read'])) ?></td>
                    <td data-label="Meaning">Rows in the file, excluding the header and any blank ones</td>
                </tr>
                <tr>
                    <td data-label="What"><?= $done ? 'Written' : 'Would be written' ?></td>
                    <td data-label="Rows" class="num"><?= e($number($done ? (int) $batch['rows_inserted'] : $counts['insert'])) ?></td>
                    <td data-label="Meaning">New rows in the contact log, dated as the file says</td>
                </tr>
                <tr>
                    <td data-label="What">Already logged</td>
                    <td data-label="Rows" class="num"><?= e($number($counts['duplicate'])) ?></td>
                    <td data-label="Meaning">Same member, same moment, same type &mdash; not added again</td>
                </tr>
                <tr>
                    <td data-label="What">Not landed</td>
                    <td data-label="Rows" class="num"><?= e($number($counts['skip'])) ?></td>
                    <td data-label="Meaning">The list below says why, row by row</td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if ($preview['kinds'] !== []) { ?>
        <div class="card">
            <h2>What needs a look</h2>
            <p class="hint">
                Grouped by kind, so one unreadable date cannot bury a name that matched
                two people. The last two do <strong>not</strong> cost the row &mdash; it
                lands anyway, and they say what was assumed.
            </p>
            <?php foreach (ContactImporter::KINDS as $kind) {
                if (!isset($preview['kinds'][$kind])) {
                    continue;
                }
                $kept = in_array($kind, ContactImporter::KEPT_KINDS, true);
                // Three outcomes, not two. A duplicate is neither a failure
                // nor a row that landed — it is a row that was already there,
                // which is the import working rather than the import
                // struggling, and calling it "skipped" reads as a problem.
                [$level, $word] = match (true) {
                    $kept                                => ['warn', 'Landed'],
                    $kind === ContactImporter::DUPLICATE => ['warn', 'Already logged'],
                    default                              => ['danger', 'Skipped'],
                };
            ?>
                <details>
                    <summary>
                        <?= $chip($level, $word) ?>
                        <strong><?= e($number((int) $preview['kinds'][$kind])) ?></strong>
                        &middot; <?= e($headline($kind)) ?>
                        <span class="mono">(<?= e($kind) ?>)</span>
                    </summary>
                    <ul class="rows">
                        <?php
                        $shownRows = 0;
                        foreach ($preview['rows'] as $row) {
                            if ((string) $row['outcome_kind'] !== $kind) {
                                continue;
                            }
                            if ($shownRows >= 25) {
                                break;
                            }
                            $shownRows++;
                        ?>
                            <li>
                                row <?= e((string) $row['row_number']) ?>
                                <?php if (trim((string) $row['raw_member']) !== '') { ?>
                                    &middot; <span class="mono"><?= e((string) $row['raw_member']) ?></span>
                                <?php } ?>
                                &mdash; <?= e((string) $row['detail']) ?>
                            </li>
                        <?php } ?>
                        <?php if ((int) $preview['kinds'][$kind] > $shownRows) { ?>
                            <li>&hellip; and <?= e($number((int) $preview['kinds'][$kind] - $shownRows)) ?> more</li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="card">
        <h2>Every row</h2>
        <p class="hint">
            In file order, showing what each row resolved to. This is the last
            chance to notice that a column was read as something it is not.
        </p>
        <table>
            <thead>
                <tr>
                    <th scope="col">Row</th>
                    <th scope="col">Member</th>
                    <th scope="col">When</th>
                    <th scope="col">How</th>
                    <th scope="col">Officer</th>
                    <th scope="col">Year</th>
                    <th scope="col">Outcome</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview['rows'] as $row) {
                $action = (string) $row['action'];
                $level  = match ($action) {
                    'insert'    => 'ok',
                    'duplicate' => 'warn',
                    default     => 'danger',
                };
                $word = match ($action) {
                    'insert'    => $done ? 'Written' : 'Will write',
                    'duplicate' => 'Already logged',
                    default     => 'Skipped',
                };
            ?>
                <tr>
                    <td data-label="Row" class="num"><?= e((string) $row['row_number']) ?></td>
                    <td data-label="Member">
                        <?= e($memberName($row)) ?>
                        <?php if ($row['member_number'] !== null) { ?>
                            <span class="why mono"><?= e((string) $row['member_number']) ?></span>
                        <?php } ?>
                    </td>
                    <td data-label="When">
                        <?php if ($row['occurred_at'] !== null) { ?>
                            <?= e($app->toDisplay((string) $row['occurred_at'])->format('j M Y')) ?>
                            <span class="why"><?= e($shown($row['raw_date'])) ?></span>
                        <?php } else { ?>
                            <?= e($shown($row['raw_date'])) ?>
                        <?php } ?>
                    </td>
                    <td data-label="How">
                        <?= e($typeWord((string) $row['contact_type'])) ?>
                        <?php if (trim((string) $row['raw_type']) !== ''
                            && mb_strtolower((string) $row['raw_type']) !== (string) $row['contact_type']) { ?>
                            <span class="why"><?= e((string) $row['raw_type']) ?></span>
                        <?php } ?>
                    </td>
                    <td data-label="Officer"><?= e($shown($officerName($row))) ?></td>
                    <td data-label="Year"><?= e($shown($row['show_year'])) ?></td>
                    <td data-label="Outcome">
                        <?= $chip($level, $word) ?>
                        <?php if (trim((string) $row['detail']) !== '') { ?>
                            <span class="why"><?= e((string) $row['detail']) ?></span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php if ($preview['truncated']) { ?>
            <p class="hint">Only the first <?= e($number(count($preview['rows']))) ?> rows are shown.</p>
        <?php } ?>
    </div>

    <?php if (!$done) { ?>
        <div class="card">
            <h2>4 &middot; Write it, or throw it away</h2>
            <p>
                <?= $chip('warn', 'Nothing written yet') ?>
                Writing adds <?= e($number($counts['insert'])) ?> row(s) to the contact log.
                The contact log is never edited and never deleted &mdash; a mistake is
                corrected by logging a correcting contact, not by undoing this.
            </p>
            <form method="post" action="<?= e($app->url('import-contacts')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                <button type="submit">Write <?= e($number($counts['insert'])) ?> contact(s) to the log</button>
            </form>
            <form method="post" action="<?= e($app->url('import-contacts')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="discard">
                <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                <button type="submit" class="quiet">Discard this preview</button>
            </form>
        </div>
    <?php } else { ?>
        <div class="card">
            <p>
                <?= $chip('ok', 'Loaded') ?>
                <?= e((string) $batch['applied_at']) ?> UTC
                (<?= e($app->toDisplay((string) $batch['applied_at'])->format('D j M, H:i T')) ?>).
                These contacts now appear in each member's history, and on My Roster Status
                as the date they were last contacted.
            </p>
            <form method="get" action="<?= e($app->url('import-contacts')) ?>">
                <button type="submit" class="quiet">Load another file</button>
            </form>
        </div>
    <?php } ?>
<?php } ?>

<?php if ($staged !== []) { ?>
    <div class="card">
        <h2>Read, not yet written</h2>
        <table>
            <thead>
                <tr>
                    <th scope="col">Batch</th><th scope="col">File</th>
                    <th scope="col" class="num">Read</th><th scope="col" class="num">Ready</th>
                    <th scope="col" class="num">Skipped</th><th scope="col">Read at</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staged as $row) { ?>
                <tr>
                    <td data-label="Batch" class="mono"><?= e((string) $row['id']) ?></td>
                    <td data-label="File"><?= e((string) $row['filename']) ?></td>
                    <td data-label="Read" class="num"><?= e($number((int) $row['rows_read'])) ?></td>
                    <td data-label="Ready" class="num"><?= e($number((int) $row['rows_ready'])) ?></td>
                    <td data-label="Skipped" class="num"><?= e($number((int) $row['rows_skipped'])) ?></td>
                    <td data-label="Read at"><?= e((string) $row['started_at']) ?> UTC</td>
                    <td data-label="">
                        <a href="<?= e($app->url('import-contacts')) ?>?batch=<?= e((string) $row['id']) ?>">Open</a>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php } ?>

<?php if ($applied !== []) { ?>
    <div class="card">
        <h2>Already loaded</h2>
        <p class="hint">
            Kept for good. Eighty rows appearing in somebody's history at once is exactly
            the thing that gets questioned later, and this is the answer to it.
        </p>
        <table>
            <thead>
                <tr>
                    <th scope="col">Batch</th><th scope="col">File</th>
                    <th scope="col" class="num">Written</th><th scope="col">Loaded</th>
                    <th scope="col">By</th><th scope="col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($applied as $row) { ?>
                <tr>
                    <td data-label="Batch" class="mono"><?= e((string) $row['id']) ?></td>
                    <td data-label="File"><?= e((string) $row['filename']) ?></td>
                    <td data-label="Written" class="num"><?= e($number((int) $row['rows_inserted'])) ?></td>
                    <td data-label="Loaded"><?= e((string) $row['applied_at']) ?> UTC</td>
                    <td data-label="By"><?= e($shown(trim(
                        (string) ($row['uploader_first'] ?? '') . ' ' . (string) ($row['uploader_last'] ?? '')
                    ))) ?></td>
                    <td data-label="">
                        <a href="<?= e($app->url('import-contacts')) ?>?batch=<?= e((string) $row['id']) ?>">Open</a>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php } ?>
