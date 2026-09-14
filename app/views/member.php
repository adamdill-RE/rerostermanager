<?php

declare(strict_types=1);

/**
 * One member, on one screen (Phase 10.4, spec-v2 §9.1) — the single-member
 * screen spec-v1 §8.2 named and nothing ever built. Narrow column: name and
 * number, the imported title and team, the four chips and the Result word,
 * Call / Text / Email as the page's largest targets, the log-contact form
 * OPEN on an open year, this show year's contact history, every earlier
 * year's under a closed <details> (OI-12), and who is assigned.
 *
 * It renders decided values only: MemberPage derived every status and flag
 * with the same functions the lists use, so nothing here can disagree with
 * the row the person was opened from. Every rendered value goes through e().
 *
 * @var Rerm\App             $app
 * @var Rerm\Auth\User       $user
 * @var array<string, mixed> $year    the active show year row
 * @var array<string, mixed> $member  everything MemberPage::page() decided
 * @var string               $from      the screen the card was opened from, or ''
 * @var string               $back      that screen's list state, as it handed it over
 * @var string               $backPath  the way back, whitelisted, or ''
 * @var string               $backWord  what the way back is called
 */

use Rerm\Csrf;
use Rerm\Roster\Metric;
use Rerm\View;

$number       = static fn (int $n): string => number_format($n);
$contactTypes = View::CONTACT_TYPES;
$links        = View::contactLinks($app, $user, $member);

$lcShared = Csrf::field()
    . '<input type="hidden" name="screen" value="member">'
    . '<input type="hidden" name="return" value="' . e(http_build_query(array_filter([
        'id'   => $member['id'],
        'from' => $from !== '' ? $from : null,
        'back' => $back !== '' ? $back : null,
    ]))) . '">';

/** One contact entry, as the lists spell it. */
$entryHtml = static function (array $entry) use ($app, $contactTypes): string {
    $html = '<li>' . View::time($app, (string) $entry['occurred_at']) . ' &middot; '
        . e($contactTypes[$entry['contact_type']] ?? (string) $entry['contact_type'])
        . ' &middot; ' . e((string) $entry['officer_name']);
    if (trim((string) $entry['notes']) !== '') {
        $html .= ' &mdash; ' . e((string) $entry['notes']);
    }
    if ($entry['from_history'] ?? false) {
        $html .= ' <span class="why">loaded from history</span>';
    }

    return $html . '</li>';
};
?>
<?php if ($backPath !== '') { ?>
    <p class="hint"><a href="<?= e($app->url($backPath)) ?>">&larr; <?= e($backWord) ?></a></p>
<?php } ?>

<h1><?= e((string) $member['display_name']) ?></h1>
<p class="lede">
    <?= e((string) $member['member_number']) ?><?php
    if ($member['title'] !== '') { ?> &middot; <?= e((string) $member['title']) ?><?php }
    if ($member['team_name'] !== '') { ?> &middot; <?= e((string) $member['team_name']) ?><?php } ?>
    &middot; <?= e((string) $member['division_name']) ?>
    &middot; show year <?= e((string) $year['label']) ?>
</p>

<?php if ($member['dropped']) { ?>
    <div class="card">
        <span class="chip chip-warn">Dropped</span>
        The last roster import<?php if ($member['dropped_batch'] !== null) { ?> (#<?= e((string) $member['dropped_batch']) ?>)<?php } ?>
        did not list this member. That is not the same as them having left:
        a member who reappears in a later roster is picked back up
        automatically. A contact logged here is kept either way.
    </div>
<?php } ?>

<?php if (!$year['is_open']) { ?>
    <div class="card">
        <span class="chip chip-warn">Read-only</span>
        Show year <?= e((string) $year['label']) ?> is closed. Everything here is
        still visible, but contacts and progress can no longer be logged.
    </div>
<?php } ?>

<div class="card member">
    <p class="dials">
        <?php if (isset($links['call'])) { ?>
            <a class="dial" href="<?= e($links['call']) ?>">Call<?= $member['phone'] !== '' ? ' ' . e((string) $member['phone']) : '' ?></a>
        <?php } ?>
        <?php if (isset($links['text'])) { ?>
            <a class="dial" href="<?= e($links['text']) ?>">Text</a>
        <?php } ?>
        <?php if (isset($links['email'])) { ?>
            <a class="dial" href="<?= e($links['email']) ?>">Email</a>
        <?php } ?>
        <?php if ($links === []) { ?>
            <span class="why">No phone or email on file.</span>
        <?php } ?>
    </p>

    <dl class="facts">
        <?php foreach (Metric::scored() as $metric) { ?>
            <dt><?= e($metric->label()) ?></dt>
            <dd><?= View::chip($member['statuses'][$metric->value]) ?></dd>
        <?php } ?>
        <dt><?= e(Metric::HarassmentTraining->label()) ?></dt>
        <dd><?= View::chip($member['statuses'][Metric::HarassmentTraining->value]) ?></dd>
        <dt>Result</dt>
        <dd><?= View::outcome($member['outcome']) ?><?php
            if ($member['last_contact'] === null) { ?> <span class="why">never contacted this show year</span><?php } ?></dd>
        <dt>Phone</dt>
        <dd><?= $member['phone'] !== ''
            ? e((string) $member['phone']) . ($member['phone_type'] !== '' ? ' &middot; ' . e(strtolower((string) $member['phone_type'])) : '')
            : 'None on file' ?></dd>
        <dt>Email</dt>
        <dd><?= $member['email'] !== '' ? e((string) $member['email']) : 'None on file' ?></dd>
    </dl>
</div>

<?php if ($year['is_open']) { ?>
    <h2>Log a contact</h2>
    <div class="card roster">
        <?= View::logContactForm(
            $app->url('log-contact'),
            $lcShared,
            (int) $member['id'],
            $member['statuses'],
            ['links' => $links, 'phone' => $member['phone']]
        ) ?>
    </div>
<?php } ?>

<?php /* Every Roster Change Form this member is on (Phase 11, spec-v2 §12),
         newest first — the answer to "did anybody ever submit one for
         them", on the page the question is asked from. A line the caller
         may open links to its form; one they may not is still shown, as
         the fact it is. */ ?>
<?php if ($member['rcfs'] !== []) { ?>
    <h2>Roster Change Forms</h2>
    <?php
    /** A stored DATE as the day it names, or the words for none. PLAIN. */
    $day = static function (?string $iso): string {
        $parsed = $iso === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('j M Y') : 'not yet';
    };
    ?>
    <ul class="rows">
        <?php foreach ($member['rcfs'] as $line) { ?>
            <li>
                <?php if ($line['viewable']) { ?>
                    <a href="<?= e($app->url('rcf?id=' . (int) $line['rcf_id'])) ?>"><?= View::time($app, (string) $line['generated_at']) ?></a>
                <?php } else { ?>
                    <?= View::time($app, (string) $line['generated_at']) ?>
                <?php } ?>
                &middot; <?= e((string) $line['change']) ?>
                &middot; by <?= e((string) $line['generator_name']) ?>
                <span class="why">
                    RCF # <?= (string) $line['serial'] === '' ? 'not yet' : e((string) $line['serial']) ?>
                    &middot; to the DC <?= e($day($line['sent_to_dc_on'])) ?>
                    &middot; to Rosters <?= e($day($line['sent_to_rosters_on'])) ?>
                    &middot; in the roster <?= $line['landed'] === null ? 'not yet' : View::time($app, (string) $line['landed']['at']) ?>
                </span>
            </li>
        <?php } ?>
    </ul>
<?php } ?>

<h2>Contact history &mdash; show year <?= e((string) $year['label']) ?></h2>
<?php if ($member['contacts'] === []) { ?>
    <p class="hint">Never contacted this show year.</p>
<?php } else { ?>
    <ul class="rows">
        <?php foreach ($member['contacts'] as $entry) { echo $entryHtml($entry); } ?>
    </ul>
<?php } ?>

<?php if ($member['other_years'] !== []) { ?>
    <details class="defs">
        <summary>Earlier show years &middot;
            <?= e($number(array_sum(array_map(static fn (array $y): int => count($y['contacts']), $member['other_years'])))) ?>
            contacts in <?= e($number(count($member['other_years']))) ?>
            <?= count($member['other_years']) === 1 ? 'year' : 'years' ?></summary>
        <?php foreach ($member['other_years'] as $otherYear) { ?>
            <h2>Show year <?= e((string) $otherYear['label']) ?></h2>
            <ul class="rows">
                <?php foreach ($otherYear['contacts'] as $entry) { echo $entryHtml($entry); } ?>
            </ul>
        <?php } ?>
    </details>
<?php } ?>

<h2>Assigned officers</h2>
<?php if ($member['officers'] === []) { ?>
    <p class="hint">No officer assigned yet.</p>
<?php } else { ?>
    <ul class="rows">
        <?php foreach ($member['officers'] as $officer) { ?>
            <li><?= e((string) $officer['officer_name']) ?><?php
                if ((string) $officer['officer_title'] !== '') { ?> &middot; <?= e((string) $officer['officer_title']) ?><?php } ?></li>
        <?php } ?>
    </ul>
<?php } ?>
