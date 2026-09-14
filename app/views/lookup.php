<?php

declare(strict_types=1);

/**
 * Look Up Members (Phase 12, spec-v2 §13) — a pasted list of member
 * numbers, and where each one stands today.
 *
 * Two parts, and the second only once something was typed:
 *
 *   * the box, which takes the list however it arrived — a column from a
 *     spreadsheet, a sentence from an email, commas with the spaces in the
 *     wrong places — and keeps what was typed so a mistake is fixed in
 *     place rather than pasted again;
 *   * the answer: a report of how the list was read (what was found, what
 *     the roster does not hold, what was set aside and why — nothing
 *     silently dropped), and then one row per member IN THE ORDER GIVEN,
 *     so the table reads across against the list it came from.
 *
 * The list is POSTed, because three hundred numbers is longer than a query
 * string the server will carry; and a GET with `numbers` looks them up too,
 * so a short list is a link and the way back from a member card keeps the
 * list. Nothing is written by either verb, so the POST re-renders rather
 * than redirecting: there is no state change to get past with a 303, and
 * a 303 would have to carry the whole list to draw the same page.
 *
 * Every RCF is a fold on its row, because "was a form ever made for them"
 * is answered by the count and "by whom, and when" by opening it. Every
 * rendered value goes through e().
 *
 * @var Rerm\App                             $app
 * @var Rerm\Auth\User                       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var string                               $typed   what was typed, as typed
 * @var ?array<string, mixed>                $result  LookupPage::lookUp()'s answer, or null before a lookup
 */

use Rerm\Admin\MemberNumbers;
use Rerm\Csrf;
use Rerm\View;

$number = static fn (int $n): string => number_format($n);

$chip = static function (string $level, string $word): string {
    $class = match ($level) {
        'ok'    => 'chip-ok',
        'warn'  => 'chip-warn',
        'info'  => 'chip-info',
        'muted' => 'chip-muted',
        default => 'chip-danger',
    };

    return '<span class="chip ' . $class . '">' . e($word) . '</span>';
};

/** A stored value as Import History shows it: absence and blank are different. */
$value = static function (?string $text): string {
    if ($text === null) {
        return '<span class="chip chip-muted">not set</span>';
    }
    if (trim($text) === '') {
        return '<span class="chip chip-muted">blank</span>';
    }

    return '<span class="mono">' . e($text) . '</span>';
};

/** A stored DATE as the day it names, or the words for none. PLAIN; the caller escapes. */
$day = static function (?string $iso): string {
    $parsed = $iso === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

    return $parsed instanceof DateTimeImmutable ? $parsed->format('j M Y') : 'not yet';
};

$plural = static fn (int $n, string $one, string $many): string => $number($n) . ' ' . ($n === 1 ? $one : $many);
?>
<h1>Look Up Members</h1>
<p class="lede">
    Paste or type member numbers, and see where each one stands today: their
    title, team and division, whether they are on the roster, when they first
    appeared, what the last import changed about them, and every Roster
    Change Form that named them.
</p>

<form method="post" action="<?= e($app->url('lookup')) ?>" class="lookup">
    <?= Csrf::field() ?>
    <label for="numbers">Member numbers</label>
    <textarea id="numbers" name="numbers" rows="4" autocomplete="off" spellcheck="false"
              placeholder="1234567, 2345678, 3456789"<?= $result === null ? ' autofocus' : '' ?>><?= e($typed) ?></textarea>
    <p class="hint">
        Comma-separated, or one per line &mdash; a column pasted from a
        spreadsheet works as it is. Extra spaces, semicolons, quotes and a
        stray <span class="mono">.0</span> from Excel are read past.
        Up to <?= e((string) MemberNumbers::MAX) ?> at a time; anything the
        roster does not hold is listed rather than dropped.
    </p>
    <button type="submit">Look up</button>
</form>

<?php if ($result !== null) { ?>

<?php /* How the list was read. Everything that was not looked up as a
         member is named here, above the table, so the table can be
         trusted: a header pasted along with the column, a number the
         roster does not hold, a repeat, a number Excel reshaped. */ ?>
<div class="card">
    <?php if ($result['given'] === 0) { ?>
        <p>
            <strong>No member numbers were read.</strong>
            <?php if ($result['ignored'] !== []) { ?>
                Everything typed was words, and a member number has digits in it.
            <?php } else { ?>
                Paste or type at least one, then press Look up.
            <?php } ?>
        </p>
    <?php } else { ?>
        <p>
            <strong><?= e($plural((int) $result['given'], 'number', 'numbers')) ?></strong> read
            &middot; <?= e($plural((int) $result['found'], 'member found', 'members found')) ?>
            <?php if ($result['unknown'] !== []) { ?>
                &middot; <?= e($plural(count($result['unknown']), 'not on the roster', 'not on the roster')) ?>
            <?php } ?>
            <?php if ($result['duplicates'] !== []) { ?>
                &middot; <?= e($plural(array_sum($result['duplicates']), 'repeat', 'repeats')) ?> set aside
            <?php } ?>
            <?php if ($result['ignored'] !== []) { ?>
                &middot; <?= e($plural(count($result['ignored']) + (int) $result['ignored_more'], 'word', 'words')) ?> ignored
            <?php } ?>
        </p>
    <?php } ?>

    <?php if ($result['unknown'] !== []) { ?>
        <p>
            <?= $chip('danger', 'Not on the roster') ?>
            <?php foreach ($result['unknown'] as $i => $miss) { ?>
                <?= $i > 0 ? '&middot;' : '' ?>
                <span class="mono"><?= e((string) $miss['number']) ?></span><?php
                    if ((string) $miss['hint'] !== '') { ?> <span class="why"><?= e((string) $miss['hint']) ?></span><?php } ?>
            <?php } ?>
        </p>
        <p class="hint">
            Never imported under that number, or a system row. A number read
            off a screen that dropped its leading zeros is matched to the
            member who has them, and the row says so.
        </p>
    <?php } ?>

    <?php if ($result['read_as'] !== []) { ?>
        <p>
            <?= $chip('info', 'Read as') ?>
            <?php $first = true; foreach ($result['read_as'] as $was => $is) { ?>
                <?= $first ? '' : '&middot;' ?>
                <span class="mono"><?= e((string) $was) ?></span> &rarr; <span class="mono"><?= e((string) $is) ?></span>
                <?php $first = false; ?>
            <?php } ?>
        </p>
    <?php } ?>

    <?php if ($result['duplicates'] !== []) { ?>
        <p>
            <?= $chip('muted', 'Listed more than once') ?>
            <?php $first = true; foreach ($result['duplicates'] as $dup => $extra) { ?>
                <?= $first ? '' : '&middot;' ?>
                <span class="mono"><?= e((string) $dup) ?></span> <span class="why">&times;<?= e((string) ((int) $extra + 1)) ?></span>
                <?php $first = false; ?>
            <?php } ?>
        </p>
    <?php } ?>

    <?php if ($result['ignored'] !== []) { ?>
        <p>
            <?= $chip('muted', 'Ignored') ?>
            <?php foreach ($result['ignored'] as $i => $word) { ?>
                <?= $i > 0 ? '&middot;' : '' ?>
                <span class="mono"><?= e((string) $word) ?></span>
            <?php } ?>
            <?php if ((int) $result['ignored_more'] > 0) { ?>
                <span class="why">and <?= e($number((int) $result['ignored_more'])) ?> more</span>
            <?php } ?>
        </p>
        <p class="hint">Words with no digit in them &mdash; a column heading, an "and", a note.</p>
    <?php } ?>

    <?php if ($result['over'] !== []) { ?>
        <p>
            <?= $chip('warn', 'Not looked up') ?>
            The first <?= e((string) MemberNumbers::MAX) ?> were; these
            <?= e($number(count($result['over']))) ?> were past the limit. Paste them on their own:
            <span class="mono"><?= e(implode(', ', $result['over'])) ?></span>
        </p>
    <?php } ?>

    <?php if ($result['truncated']) { ?>
        <p><?= $chip('warn', 'Cut short') ?> That was more text than this box reads; only the start of it was.</p>
    <?php } ?>
</div>

<?php if ($result['rows'] !== []) {
    // The way back to this list from a member card: the numbers, as a GET
    // this route answers, bounded as every `back` is (member_back()).
    $backQuery = 'numbers=' . rawurlencode(mb_substr(implode(',', $result['numbers']), 0, 380));
?>
<table class="lookup">
    <thead>
        <tr>
            <th scope="col">Number</th>
            <th scope="col">Name</th>
            <th scope="col">Title</th>
            <th scope="col">Team</th>
            <th scope="col">Division</th>
            <th scope="col">Roster</th>
            <th scope="col">First seen</th>
            <th scope="col">Last change</th>
            <th scope="col">RCFs</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($result['rows'] as $row) {
        $card = $app->url('member?id=' . (int) $row['id'] . '&from=lookup&back=' . rawurlencode($backQuery));
        $last = $row['last_change'];
    ?>
        <tr id="m<?= e((string) $row['id']) ?>">
            <td data-label="Number" class="mono"><div class="in">
                <?= e((string) $row['member_number']) ?>
                <?php if ($row['typed'] !== $row['member_number']) { ?>
                    <span class="why">typed <?= e((string) $row['typed']) ?></span>
                <?php } ?>
            </div></td>
            <td data-label="Name"><a href="<?= e($card) ?>"><?= e((string) $row['name']) ?></a></td>
            <td data-label="Title"><?= (string) $row['title'] === '' ? $chip('muted', 'blank') : e((string) $row['title']) ?></td>
            <td data-label="Team"><?= (string) $row['team_name'] === '' ? $chip('muted', 'none') : e((string) $row['team_name']) ?></td>
            <td data-label="Division"><?= e((string) $row['division_name']) ?></td>
            <td data-label="Roster"><div class="in">
                <?php if ($row['state'] === 'purged') { ?>
                    <?= $chip('danger', 'Purged') ?>
                    <span class="why"><?= $row['purged_at'] === null ? '' : View::time($app, (string) $row['purged_at']) ?></span>
                <?php } elseif ($row['state'] === 'dropped') { ?>
                    <?= $chip('warn', 'Dropped') ?>
                    <span class="why">
                        <?php if ($row['dropped_at'] !== null) { ?>
                            by the import of <?= View::time($app, (string) $row['dropped_at']) ?>
                        <?php } ?>
                        <?php if ($row['dropped_batch'] !== null) { ?>
                            (<a href="<?= e($app->url('import-history?batch=' . (int) $row['dropped_batch'])) ?>">import <?= e((string) $row['dropped_batch']) ?></a>)
                        <?php } ?>
                    </span>
                <?php } else { ?>
                    <?= $chip('ok', 'On the roster') ?>
                <?php } ?>
            </div></td>
            <td data-label="First seen"><div class="in">
                <?php $firstSeen = $row['first_seen'] ?? $row['first_at']; ?>
                <?php if ($firstSeen === null) { ?>
                    <?= $chip('muted', 'not recorded') ?>
                <?php } else { ?>
                    <?= View::time($app, (string) $firstSeen) ?>
                    <?php if ($row['first_batch'] !== null) { ?>
                        <span class="why"><a href="<?= e($app->url('import-history?batch=' . (int) $row['first_batch'])) ?>">import <?= e((string) $row['first_batch']) ?></a></span>
                    <?php } ?>
                <?php } ?>
            </div></td>
            <td data-label="Last change"><div class="in">
                <?php if ($last === null) { ?>
                    <?= $chip('muted', 'None recorded') ?>
                    <span class="why">the record began after they appeared</span>
                <?php } elseif ($last['items'] === []) { ?>
                    <?= $chip('info', 'None since they appeared') ?>
                <?php } else { ?>
                    <?= View::time($app, (string) $last['at']) ?>
                    <span class="why"><a href="<?= e($app->url('import-history?member=' . rawurlencode((string) $row['member_number']))) ?>">import <?= e((string) $last['batch']) ?></a>
                        <?php if ((int) $row['changes_total'] > count($last['items']) + ((int) ($row['first_batch'] !== null))) { ?>
                            &middot; <?= e($number((int) $row['changes_total'])) ?> in all
                        <?php } ?>
                    </span>
                    <ul class="rows">
                        <?php foreach ($last['items'] as $item) { ?>
                            <li>
                                <?php if ($item['kind'] === 'updated') { ?>
                                    <?= e((string) $item['field_label']) ?>:
                                    <?= $value($item['before']) ?> &rarr; <?= $value($item['after']) ?>
                                <?php } else { ?>
                                    <?= $chip($item['kind'] === 'dropped' ? 'danger' : 'ok', (string) $item['kind_label']) ?>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div></td>
            <td data-label="RCFs"><div class="in">
                <?php if ($row['rcfs'] === []) { ?>
                    <?= $chip('muted', 'None') ?>
                <?php } else { ?>
                    <details>
                        <summary><?= e($plural(count($row['rcfs']), 'form', 'forms')) ?></summary>
                        <ul class="rows">
                            <?php foreach ($row['rcfs'] as $line) { ?>
                                <li>
                                    <?php if ($line['viewable']) { ?>
                                        <a href="<?= e($app->url('rcf?id=' . (int) $line['rcf_id'])) ?>"><?= View::time($app, (string) $line['generated_at']) ?></a>
                                    <?php } else { ?>
                                        <?= View::time($app, (string) $line['generated_at']) ?>
                                    <?php } ?>
                                    by <?= e((string) $line['generator_name']) ?>
                                    &middot; <?= e((string) $line['change']) ?>
                                    <span class="why">
                                        <?= e((string) $line['subcommittee']) ?>
                                        &middot; RCF # <?= (string) $line['serial'] === '' ? 'not yet' : e((string) $line['serial']) ?>
                                        &middot; to the DC <?= e($day($line['sent_to_dc_on'])) ?>
                                        &middot; to Rosters <?= e($day($line['sent_to_rosters_on'])) ?>
                                        &middot; in the roster <?= $line['landed'] === null ? 'not yet' : View::time($app, (string) $line['landed']['at']) ?>
                                    </span>
                                </li>
                            <?php } ?>
                        </ul>
                    </details>
                <?php } ?>
            </div></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } ?>

<?php } ?>
