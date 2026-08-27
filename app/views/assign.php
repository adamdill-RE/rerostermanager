<?php

declare(strict_types=1);

/**
 * Assign Officers to Committeemen (spec 7.4) — one team at a time, four
 * buckets in order, select-then-assign.
 *
 * The interaction is bulk because the data says it has to be: one Captain
 * covers 27 people on Bus Ops Team A, and a per-member officer select on an
 * 85-person team would post 255 inputs against max_input_vars 1000, which
 * this host truncates SILENTLY (docs/data-findings.md 7). So the row carries
 * a checkbox — about thirty bytes — the officer is chosen once in the sticky
 * bar, and the set the quick action covers is resolved by the server and
 * never posted at all.
 *
 * No JavaScript anywhere, so three things are adapted rather than scripted,
 * and each is a decision rather than a compromise:
 *
 *   * **Select all / Select all outstanding are links.** A checkbox cannot
 *     tick another checkbox without script, so the selection travels in the
 *     GET (`?sel=all`) and the page re-renders with those rows ticked —
 *     whitelisted in AssignPage like every other input, never echoed raw.
 *   * **The sticky bar cannot count "12 selected" live.** The count comes
 *     back in the flash after the write, naming what landed and what did not.
 *   * **Two controls, two names.** The assign select and the remove select
 *     sit in one form because they act on one selection, and two controls
 *     sharing a name would overwrite each other on submit.
 *
 * Everything was decided in AssignPage and AssignOfficers; this file renders
 * decided values and derives nothing. Every rendered value goes through e().
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $year    active show year (id, label, is_open)
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $assign  everything AssignPage::page() decided
 */

use Rerm\Csrf;
use Rerm\Roster\Metric;
use Rerm\View;

$number = static fn (int $n): string => number_format($n);

/** The four buckets in spec order. The third is a roll-up, not a list. */
$bucketLabels = [
    'unassigned' => 'Unassigned',
    'ineligible' => 'Officer no longer eligible',
    'assigned'   => 'Assigned',
];

$teamId  = $assign['team_id'];
$counts  = $assign['counts'];
$bucket  = $assign['bucket'];
$isOpen  = (bool) $year['is_open'];
$rows    = $assign['rows'];
$thin    = $assign['thin_teams'];

/**
 * An Assign URL carrying the team, bucket, page size and pre-tick state.
 * Defaults stay out so the plain screen has a plain address.
 *
 * @param array<string, mixed> $overrides
 */
$href = static function (array $overrides = []) use ($app, $assign): string {
    $params = [
        'team'   => $assign['can_choose_team'] ? $assign['team_id'] : null,
        'bucket' => $assign['bucket'],
        'size'   => $assign['size'],
        'page'   => 1,
        'sel'    => $assign['sel'],
    ];
    $params = array_merge($params, $overrides);

    if ($params['team'] === null || (int) $params['team'] === 0) {
        unset($params['team']);
    }
    if ($params['bucket'] === 'unassigned') {
        unset($params['bucket']);
    }
    if ($params['size'] === $assign['size_default']) {
        unset($params['size']);
    }
    if ((int) $params['page'] === 1) {
        unset($params['page']);
    }
    if (($params['sel'] ?? '') === '') {
        unset($params['sel']);
    }

    $query = http_build_query($params);

    return $app->url('assign') . ($query === '' ? '' : '?' . $query);
};
?>
<h1>Assign Officers</h1>
<p class="lede">
    Show year <?= e((string) $year['label']) ?> &middot; assignment is same-team
    only, and a member may hold up to <?= e((string) $assign['max_officers']) ?> officers.
    Assigning is additive: run it once for each officer who should share the team.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<?php if (!$isOpen) { ?>
    <div class="card">
        <span class="chip chip-warn">Read-only</span>
        Show year <?= e((string) $year['label']) ?> is closed. Assignments are
        still visible, but they can no longer be changed.
    </div>
<?php } ?>

<?php if ($teamId === null) { ?>

    <?php if ($assign['teams'] === []) { ?>
        <div class="card">
            <h2>No teams in your roster</h2>
            <p>
                <?= $assign['can_choose_team']
                    ? 'No members are in your scope, so there is nothing to assign. If you expected a division here, an Admin can check the division on your member record or set a scope for your account.'
                    : 'Your account is not on a team, so there is nobody to assign. An Admin can check the team on your member record or set a scope for your account.' ?>
            </p>
        </div>
    <?php } else { ?>
        <h2 id="teams">Choose a team</h2>
        <p class="lede">
            <?= e($number(count($assign['teams']))) ?> teams in your roster, worst first
            is not the order &mdash; they are alphabetical, and the two numbers that
            matter are on every row.
        </p>
        <table class="roster">
            <thead>
                <tr>
                    <th>Team</th>
                    <th class="num">Members</th>
                    <th class="num">Unassigned</th>
                    <th class="num">Needs re-pointing</th>
                    <th class="num">Officers</th>
                </tr>
            </thead>
            <tbody>
<?php
        foreach ($assign['teams'] as $team) {
            echo '<tr><td class="who"><a href="', e($href(['team' => $team['id'], 'bucket' => 'unassigned', 'page' => 1])),
                '">', e((string) $team['name']), '</a></td>';
            echo '<td class="num" data-label="Members">', e($number((int) $team['members'])), '</td>';
            echo '<td class="num" data-label="Unassigned">', e($number((int) $team['unassigned'])), '</td>';
            echo '<td class="num" data-label="Needs re-pointing">',
                (int) $team['ineligible'] > 0
                    ? '<span class="chip chip-warn">' . e($number((int) $team['ineligible'])) . '</span>'
                    : e($number(0)),
                '</td>';
            echo '<td class="num" data-label="Officers">',
                (int) $team['officers'] === 0
                    ? '<span class="chip chip-danger">none</span>'
                    : e($number((int) $team['officers'])),
                '</td></tr>', "\n";
        }
?>
            </tbody>
        </table>
    <?php } ?>

<?php } else { ?>

    <h2><?= e($assign['team_name'] !== '' ? $assign['team_name'] : 'This team') ?></h2>
    <?php if ($assign['can_choose_team']) { ?>
        <p><a href="<?= e($app->url('assign')) ?>#teams">&larr; Choose another team</a></p>
    <?php } ?>

    <nav class="toggle" aria-label="Bucket">
        <?php foreach ($bucketLabels as $key => $label) { ?>
            <a href="<?= e($href(['bucket' => $key, 'page' => 1, 'sel' => ''])) ?>"
                <?= $bucket === $key ? 'class="current" aria-current="page"' : '' ?>><?= e($label) ?>
                <span class="n"><?= e($number((int) $counts[$key])) ?></span></a>
        <?php } ?>
        <a href="#thin">No officer on this team <span class="n"><?= e($number(count($thin))) ?></span></a>
    </nav>

<?php
    // Everything identical across rows and forms is built ONCE: the same
    // token serves every form in this session, and a repeated <option> list
    // is the byte cost the Phase 5 dashboard learned about the hard way.
    $action      = e($app->url('assign'));
    $returnState = http_build_query(array_filter([
        'team'   => $assign['can_choose_team'] ? $teamId : null,
        'bucket' => $bucket !== 'unassigned' ? $bucket : null,
        'page'   => $assign['page'] > 1 ? $assign['page'] : null,
        'size'   => $assign['size'] !== $assign['size_default'] ? $assign['size'] : null,
    ]));
    $shared = Csrf::field()
        . '<input type="hidden" name="return" value="' . e($returnState) . '">';

    // The picker, with each officer's current load — the whole load-balancing
    // mechanism (spec 7.4). Nothing balances automatically; the humans read
    // the numbers and spread the work.
    $officerOptions = '';
    foreach ($assign['officers'] as $officer) {
        $officerOptions .= '<option value="' . e((string) $officer['id']) . '">'
            . e($officer['name']) . ' &mdash; ' . e($number((int) $officer['assigned_count']))
            . ' assigned</option>';
    }

    $teamHasOfficers = $assign['officers'] !== [];
    $canAct          = $isOpen && $teamHasOfficers;
?>

    <?php if (!$teamHasOfficers) { ?>
        <div class="card">
            <span class="chip chip-danger">No officer on this team</span>
            <p>
                Nobody on this team holds a title of Officer or above, so nothing
                here can be assigned. The remedy is a person, not a setting: a
                Senior Officer or an Admin designates a member of this team as an
                Allowed User at Officer level, and they become assignable
                immediately.
            </p>
        </div>
    <?php } ?>

    <?php if ($bucket === 'unassigned' && $canAct && (int) $counts['unassigned'] > 0) { ?>
        <!-- The one quick action. Its own form, four inputs, and the set it
             covers is resolved by the SERVER — so a 60-member team costs the
             same as a 6-member one and max_input_vars is not involved. -->
        <form method="post" action="<?= $action ?>" class="quick">
            <?= $shared ?>
            <label for="qa-officer">Assign all <?= e($number((int) $counts['unassigned'])) ?>
                unassigned members on this team to</label>
            <select name="officer_member_id" id="qa-officer"><?= $officerOptions ?></select>
            <button type="submit" name="action" value="assign_all_unassigned" class="quiet">
                Assign all <?= e($number((int) $counts['unassigned'])) ?> unassigned
            </button>
        </form>
    <?php } ?>

    <h3 id="list"><?= e($bucketLabels[$bucket]) ?></h3>

    <?php if ($bucket === 'ineligible' && (int) $counts['ineligible'] > 0) { ?>
        <p class="lede">
            An import demoted these officers, moved them to another team, or flagged
            them absent (spec 6.6). The assignments still exist and still say who was
            responsible &mdash; assigning a replacement below clears the broken one in
            the same action, and leaves any officer who is still valid alone.
        </p>
    <?php } ?>

    <?php if ($rows === []) { ?>
        <div class="card">
            <p>
                <?php if ($bucket === 'unassigned') { ?>
                    Every member of this team has an officer. Nothing is unassigned.
                <?php } elseif ($bucket === 'ineligible') { ?>
                    Nothing needs re-pointing. Every assignment on this team points at
                    an officer who is still on it and still an officer.
                <?php } else { ?>
                    Nobody on this team is assigned yet. Start with
                    <a href="<?= e($href(['bucket' => 'unassigned', 'page' => 1, 'sel' => ''])) ?>">Unassigned</a>.
                <?php } ?>
            </p>
        </div>
    <?php } else { ?>

    <p class="lede">
        Showing <?= e($number((int) $assign['from'])) ?>&ndash;<?= e($number((int) $assign['to'])) ?>
        of <?= e($number((int) $assign['total'])) ?>
        <?php if ($assign['pages'] > 1) { ?>
            &middot; page <?= e($number((int) $assign['page'])) ?> of <?= e($number((int) $assign['pages'])) ?>
        <?php } ?>
        <?php if ($canAct) { ?>
            &middot; <a href="<?= e($href(['page' => $assign['page'], 'sel' => 'all'])) ?>#list">Select all on this page</a>
            &middot; <a href="<?= e($href(['page' => $assign['page'], 'sel' => 'outstanding'])) ?>#list">Select all outstanding</a>
            <?php if ($assign['sel'] !== '') { ?>
                &middot; <a href="<?= e($href(['page' => $assign['page'], 'sel' => ''])) ?>#list">Clear selection</a>
            <?php } ?>
        <?php } ?>
        &middot;
        <?php if ($assign['size'] === $assign['size_default']) { ?>
            <a href="<?= e($href(['size' => $assign['size_large'], 'page' => $assign['page']])) ?>#list">Show <?= e((string) $assign['size_large']) ?> per page</a>
        <?php } else { ?>
            <a href="<?= e($href(['size' => $assign['size_default'], 'page' => $assign['page']])) ?>#list">Show <?= e((string) $assign['size_default']) ?> per page</a>
        <?php } ?>
    </p>

    <?php if ($canAct) { ?><form method="post" action="<?= $action ?>"><?= $shared ?><?php } ?>
    <table class="roster assign">
        <thead>
            <tr>
                <?php if ($canAct) { ?><th><span class="vh">Select</span></th><?php } ?>
                <th>Name</th>
                <?php foreach (Metric::scored() as $metric) { ?>
                    <th><?= e($metric->shortLabel()) ?></th>
                <?php } ?>
                <th>Last contact</th>
                <?php if ($bucket !== 'unassigned') { ?><th>Officers</th><?php } ?>
            </tr>
        </thead>
        <tbody>
<?php
        // Echoed compactly, the Phase 4 lesson: this block repeats up to 100
        // times against the 100KB first-paint budget. Compact never means
        // unescaped.
        $sel = $assign['sel'];

        foreach ($rows as $row) {
            $id      = (string) $row['id'];
            $ticked  = $sel === 'all' || ($sel === 'outstanding' && $row['outstanding']);

            echo '<tr>';
            if ($canAct) {
                echo '<td class="pick"><input type="checkbox" name="member_id[]" value="', e($id),
                    '" id="p', e($id), '"', $ticked ? ' checked' : '', '></td>';
            }
            echo '<td class="who">';
            echo $canAct ? '<label for="p' . e($id) . '">' : '';
            echo e($row['display_name']), ' <span class="sub">', e($row['member_number']), '</span>';
            echo $canAct ? '</label>' : '';
            echo '</td>';

            foreach (Metric::scored() as $metric) {
                echo '<td class="metric" data-label="', e($metric->shortLabel()), '">',
                    View::chip($row['statuses'][$metric->value]), '</td>';
            }

            if ($row['last_contact'] === null) {
                echo '<td data-label="Last contact"><span class="chip chip-muted">Never contacted</span></td>';
            } else {
                [$words, $absolute] = View::when($app, (string) $row['last_contact']);
                echo '<td data-label="Last contact"><span title="', e($absolute), '">', e($words), '</span></td>';
            }

            if ($bucket !== 'unassigned') {
                echo '<td data-label="Officers">';
                foreach ($row['officers'] as $officer) {
                    echo '<span class="off">', e((string) $officer['name']);
                    if (!$officer['eligible']) {
                        echo ' <span class="chip chip-danger">no longer eligible</span>';
                    }
                    echo '</span>';
                }
                echo '</td>';
            }

            echo '</tr>', "\n";
        }
?>
        </tbody>
    </table>

    <?php if ($canAct) { ?>
        <!-- Sticky by CSS, not by script (spec: no JavaScript). Two rows in
             one form because they act on one selection; two names because two
             controls sharing one would overwrite each other on submit. -->
        <div class="actionbar">
            <p class="ab">
                <label class="vh" for="ab-officer">Officer to assign</label>
                <select name="officer_member_id" id="ab-officer"><?= $officerOptions ?></select>
                <button type="submit" name="action" value="assign">
                    <?= $bucket === 'ineligible' ? 'Assign replacement to selected' : 'Assign selected' ?>
                </button>
            </p>
            <?php if ($assign['holders'] !== []) { ?>
                <p class="ab">
                    <label class="vh" for="ab-remove">Officer to remove from</label>
                    <select name="remove_officer_id" id="ab-remove">
                        <option value="<?= e(Rerm\Roster\AssignOfficers::REMOVE_ALL) ?>">Every current officer</option>
                        <?php foreach ($assign['holders'] as $holder) { ?>
                            <option value="<?= e((string) $holder['id']) ?>"><?= e($holder['name']) ?>
                                &mdash; <?= e($number((int) $holder['held'])) ?> on this team<?=
                                    $holder['eligible'] ? '' : ' (no longer eligible)' ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" name="action" value="remove" class="quiet">Remove selected from</button>
                </p>
            <?php } ?>
        </div>
    </form>
    <?php } ?>

    <?php if ($assign['pages'] > 1) { ?>
        <p>
            <?php if ($assign['page'] > 1) { ?>
                <a href="<?= e($href(['page' => $assign['page'] - 1])) ?>#list">&larr; Previous <?= e((string) $assign['size']) ?></a>
            <?php } ?>
            <?php if ($assign['page'] > 1 && $assign['page'] < $assign['pages']) { ?>
                &middot;
            <?php } ?>
            <?php if ($assign['page'] < $assign['pages']) { ?>
                <a href="<?= e($href(['page' => $assign['page'] + 1])) ?>#list">Next <?= e((string) $assign['size']) ?> &rarr;</a>
            <?php } ?>
        </p>
    <?php } ?>

    <?php } ?>
<?php } ?>

<h2 id="thin">No officer on this team</h2>
<?php if ($thin === [] && (int) $assign['no_team_members'] === 0) { ?>
    <div class="card">
        <p>Every team in your roster has at least one officer who can be assigned.</p>
    </div>
<?php } else { ?>
    <div class="card">
        <p>
            <?php if ($thin !== []) { ?>
                <strong><?= e($number(count($thin))) ?></strong>
                <?= count($thin) === 1 ? 'team in your roster has' : 'teams in your roster have' ?>
                no member holding a title of Officer or above, covering
                <strong><?= e($number((int) $assign['thin_members'])) ?></strong>
                <?= (int) $assign['thin_members'] === 1 ? 'member' : 'members' ?>.
            <?php } ?>
            <?php if ((int) $assign['no_team_members'] > 0) { ?>
                <?= e($number((int) $assign['no_team_members'])) ?>
                <?= (int) $assign['no_team_members'] === 1 ? 'member is' : 'members are' ?>
                on no team at all and cannot be assigned by anybody.
            <?php } ?>
        </p>
        <p>
            This is not something an officer can fix from this screen, and it is
            not an error &mdash; it is a number leadership has to see. The remedy is a
            person: a Senior Officer or an Admin designates a member of the team as
            an Allowed User at Officer level, and that member becomes assignable
            here immediately.
        </p>
        <?php if ($thin !== []) { ?>
            <table class="roster">
                <thead><tr><th>Team</th><th class="num">Members with nobody to assign them</th></tr></thead>
                <tbody>
                <?php foreach ($thin as $team) { ?>
                    <tr>
                        <td class="who"><?= e((string) $team['name']) ?></td>
                        <td class="num" data-label="Members"><?= e($number((int) $team['members'])) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
<?php } ?>

<p>
    <a href="<?= e($app->url('dashboard')) ?>">My Roster Status</a> &middot;
    <a href="<?= e($app->url('roster')) ?>">View My Roster</a> &middot;
    <a href="<?= e($app->url('menu')) ?>">Menu</a>
</p>
