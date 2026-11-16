<?php

declare(strict_types=1);

/**
 * The Email Log (Phase 14.8, spec-v2 §22) — every message the application
 * tried to send: what prompted it, who it went to, and whether it went.
 *
 * READ-ONLY, in every sense: no form that writes, no CSRF token, no POST.
 * The Mailer writes the rows (Rerm\Mail\EmailLog) at the moment it knows
 * what became of a message, and nothing edits them afterwards. The filter
 * form is a GET, so "every blocked message in October" is a link.
 *
 * The outcome pairs a WORD with a colour, never a hue alone: Sent is the
 * only green, and everything that kept the message on the box — filed,
 * logged, blocked — reads as a warning so a development transport can
 * never be mistaken for delivery.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $log everything EmailLogPage::page() decided
 */

use Rerm\Mail\EmailLog;
use Rerm\View;

$number = static fn (int $n): string => number_format($n);

$href = static function (array $overrides = []) use ($app, $log): string {
    $params = array_filter([
        'outcome' => (string) $log['outcome'],
        'purpose' => (string) $log['purpose'],
        'from'    => (string) $log['from'],
        'to'      => (string) $log['to'],
        'member'  => (string) $log['member'],
        'page'    => $log['page'] > 1 ? (string) $log['page'] : '',
        'size'    => $log['size'] !== $log['size_default'] ? (string) $log['size'] : '',
    ], static fn (string $v): bool => $v !== '');

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }

    $query = http_build_query($params);

    return $app->url('email-log') . ($query === '' ? '' : '?' . $query);
};

/** The outcome's chip class: green only for a message somebody received. */
$chipClass = static fn (string $outcome): string => match ($outcome) {
    EmailLog::SENT                        => 'chip-ok',
    EmailLog::FAILED, EmailLog::REFUSED   => 'chip-danger',
    EmailLog::NO_EMAIL                    => 'chip-muted',
    default                               => 'chip-warn',
};

$filtered = $log['outcome'] !== '' || $log['purpose'] !== '' || $log['from'] !== ''
    || $log['to'] !== '' || $log['member'] !== '';
?>
<h1>Email Log</h1>
<p class="lede">
    Every email this application tried to send &mdash; what prompted it, who it
    went to, and whether it went. Nothing here can be edited or removed. The
    message itself is not kept; a password reset link is a credential.
</p>

<form method="get" action="<?= e($app->url('email-log')) ?>">
    <label for="outcome">What became of it</label>
    <select id="outcome" name="outcome">
        <option value="">Anything</option>
        <?php foreach ($log['outcomes'] as $value => $word) { ?>
            <option value="<?= e((string) $value) ?>"<?= $log['outcome'] === (string) $value ? ' selected' : '' ?>>
                <?= e($word) ?>
            </option>
        <?php } ?>
    </select>

    <label for="purpose">Why it was sent</label>
    <select id="purpose" name="purpose">
        <option value="">Any reason</option>
        <?php foreach ($log['purposes'] as $value => $word) { ?>
            <option value="<?= e((string) $value) ?>"<?= $log['purpose'] === (string) $value ? ' selected' : '' ?>>
                <?= e($word) ?>
            </option>
        <?php } ?>
    </select>

    <label for="from">From</label>
    <input type="date" id="from" name="from" value="<?= e((string) $log['from']) ?>">

    <label for="to">To</label>
    <input type="date" id="to" name="to" value="<?= e((string) $log['to']) ?>">

    <label for="member">Recipient's member number</label>
    <input type="text" id="member" name="member" value="<?= e((string) $log['member']) ?>"
           inputmode="numeric" autocomplete="off" placeholder="Every email to one person">

    <button type="submit">Filter</button>
</form>

<?php if ($filtered) { ?>
    <p class="hint"><a href="<?= e($app->url('email-log')) ?>">Clear every filter</a></p>
<?php } ?>

<?php if ($log['total'] === 0) { ?>
    <div class="card">
        <p>
            <?php if ($filtered) { ?>
                Nothing matches those filters.
            <?php } else { ?>
                The email log is empty. Nothing has prompted an email yet.
            <?php } ?>
        </p>
    </div>
<?php } else { ?>

<p class="lede">
    Showing <?= e($number((int) $log['from_row'])) ?>&ndash;<?= e($number((int) $log['to_row'])) ?>
    of <?= e($number((int) $log['total'])) ?> messages
    <?php if ($log['pages'] > 1) { ?>
        &middot; page <?= e($number((int) $log['page'])) ?> of <?= e($number((int) $log['pages'])) ?>
    <?php } ?>
    &middot;
    <?php if ($log['size'] === $log['size_default']) { ?>
        <a href="<?= e($href(['size' => $log['size_large'], 'page' => null])) ?>">Show <?= e((string) $log['size_large']) ?> per page</a>
    <?php } else { ?>
        <a href="<?= e($href(['size' => null, 'page' => null])) ?>">Show <?= e((string) $log['size_default']) ?> per page</a>
    <?php } ?>
</p>

<table class="log">
    <thead>
        <tr>
            <th scope="col">When</th>
            <th scope="col">What prompted it</th>
            <th scope="col">To</th>
            <th scope="col">Subject</th>
            <th scope="col">Did it go</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($log['rows'] as $row) { ?>
        <tr>
            <td data-label="When">
                <?= View::time($app, (string) $row['occurred_at']) ?>
                <span class="sub"><?= View::timeFull($app, (string) $row['occurred_at']) ?></span>
            </td>
            <td data-label="What prompted it">
                <?= e((string) $row['purpose_word']) ?>
                <?php if ($row['reason'] !== '') { ?>
                    <span class="sub"><?= e((string) $row['reason']) ?></span>
                <?php } ?>
                <?php if ($row['actor_name'] !== null) { ?>
                    <span class="sub">by <?= e((string) $row['actor_name']) ?> &middot; <?= e((string) $row['actor_number']) ?></span>
                <?php } ?>
            </td>
            <td data-label="To">
                <?php if ($row['recipient_name'] !== null) { ?>
                    <?= e((string) $row['recipient_name']) ?>
                    <span class="sub"><?= e((string) $row['recipient_number']) ?></span>
                <?php } ?>
                <?php if ($row['recipient'] !== '') { ?>
                    <span class="sub mono"><?= e((string) $row['recipient']) ?></span>
                <?php } elseif ($row['recipient_name'] === null) { ?>
                    &mdash;
                <?php } ?>
            </td>
            <td data-label="Subject"><?= $row['subject'] !== '' ? e((string) $row['subject']) : '&mdash;' ?></td>
            <td data-label="Did it go">
                <span class="chip <?= e($chipClass((string) $row['outcome'])) ?>"><span class="chip-word"><?= e((string) $row['outcome_word']) ?></span></span>
                <?php if ($row['detail'] !== '') { ?>
                    <span class="sub"><?= e((string) $row['detail']) ?></span>
                <?php } ?>
                <?php if ($row['transport'] !== '' && !$row['delivered']) { ?>
                    <span class="sub">transport: <?= e((string) $row['transport']) ?></span>
                <?php } ?>
            </td>
        </tr>
    <?php } ?>
    </tbody>
</table>

<?php if ($log['pages'] > 1) { ?>
    <p class="lede">
        <?php if ($log['page'] > 1) { ?>
            <a href="<?= e($href(['page' => $log['page'] - 1])) ?>">&larr; Newer</a>
        <?php } ?>
        <?php if ($log['page'] < $log['pages']) { ?>
            <a href="<?= e($href(['page' => $log['page'] + 1])) ?>">Older &rarr;</a>
        <?php } ?>
    </p>
<?php } ?>

<?php } ?>
