<?php

declare(strict_types=1);

/**
 * My Roster Status (spec 7.1, as revised at Phase 4 close) — the product,
 * and the landing screen. Two halves on one page:
 *
 *   * The dashboard: the overall Fully Complete banner, then one nested
 *     card per scored metric — headline pair, a stacked proportion bar in
 *     ladder order, and a word+count legend for every non-zero state
 *     (decided 4; the two spec 7.1 summary tiles are deleted, an owner
 *     decision that supersedes the spec). Every status word is a button
 *     that opens its definition — the native HTML popover attribute, no
 *     JavaScript, CSP untouched (decided 6).
 *
 *   * The working list: outstanding-on-any-metric by default, never
 *     contacted first, then oldest contact first — the top of the list is
 *     always the next call to make; and, since Phase 10.2, a search box
 *     (spec 7.2's, by the same clause) so one member can be found under
 *     either half of the toggle without leaving the screen. Each row
 *     carries the member's imported TITLE, the four chips, the last
 *     contact, the RESULT that contact produced, and Call / Text / Email /
 *     Log contact; the log-contact sheet is its own small per-row <form>
 *     (decided 2), rendered by View::logContactSheet() and shared with View
 *     My Roster, so a submit posts only that row's fields. Under each
 *     row a closed <details> holds the whole show year's contact history
 *     and the member's assigned officers — the move View My Roster makes,
 *     without its facts list, for the reason recorded at the row loop
 *     (spec-v2 §6).
 *
 * Everything here was decided in StatusPage: the view renders decided
 * values and never derives a status — MetricStatus::derive() ran once, in
 * one place, for both halves. Every rendered value goes through e(); the
 * contact notes echoed back in "last contact" are the first stored
 * user-authored text in the app, the exact surface the strict CSP exists
 * for.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $year     active show year (id, label, is_open)
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $statusPage  everything StatusPage::page()
 *      decided. NOT named $status: render() extracts view data with
 *      EXTR_SKIP, and its own int $status parameter (the HTTP code) would
 *      win the collision and hand this view the number 200.
 */

use Rerm\Csrf;
use Rerm\Roster\ContactOutcome;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Roster\TeamFilter;
use Rerm\View;

$number = static fn (int $n): string => number_format($n);

/** Percentage width for a bar segment, one decimal, of a non-zero total. */
$pct = static fn (int $n, int $total): string => number_format($n * 100 / max(1, $total), 1);

/**
 * The ladder order every bar and legend walks (decided 4). Not reported is
 * last and grey, rendered only if it ever occurs.
 *
 * It lives on MetricStatus since Phase 7, beside the segment class and the
 * chip class, because the Committee Dashboard draws the same bar: a
 * proportion that ran in a different order on two screens would be as wrong
 * as a chip that did.
 */
$ladder = MetricStatus::ladder();

/** One shared popover per status; every legend button targets these ids. */
$popId = [
    MetricStatus::Complete->value    => 'd-complete',
    MetricStatus::Reported->value    => 'd-reported',
    MetricStatus::InProgress->value  => 'd-handling',
    MetricStatus::Contacted->value   => 'd-contacted',
    MetricStatus::Outstanding->value => 'd-open',
    MetricStatus::NotReported->value => 'd-notrep',
];

$fullyDefinition = 'Members whose official roster shows all four requirements met: '
    . 'HLSR dues, committee dues, indemnity and background check.';

/** What a contact type is called — View's table, shared with View My Roster. */
$contactTypes = View::CONTACT_TYPES;

$defaultMode = $statusPage['has_assignments'] ? 'mine' : 'team';

/** The drill-down filters as applied (spec 7.3, decided 4), or none. */
$filters = $statusPage['filters'];

/**
 * The team picker's state (Phase 10) — the options, the caller's own team,
 * and whether the selection was chosen or defaulted to their own.
 *
 * @var array<string, mixed> $teams
 */
$teams = $statusPage['team_choice'];

/**
 * A dashboard URL carrying the toggle, filter and size with the caller's
 * overrides. Defaults stay out of the URL so the plain screen has a plain
 * address; a changed toggle or filter resets to page 1 unless told otherwise.
 *
 * The Committee Dashboard's drill-down filters ride along on EVERY link here.
 * An officer who followed "40 never contacted" into a team and then turned a
 * page, or flipped the toggle, must not silently get the whole roster back —
 * losing a filter is losing the forty people they came to work.
 *
 * @param array<string, mixed> $overrides
 */
$href = static function (array $overrides = []) use ($app, $statusPage, $defaultMode, $filters, $teams): string {
    $params = [
        'mode' => $statusPage['mode'],
        'show' => $statusPage['show'],
        'size' => $statusPage['size'],
        'page' => 1,
    ];

    // The search rides on every link too (Phase 10.2): turning a page or
    // flipping the toggle with the term dropped would hand back fifty rows
    // where the officer had asked for one.
    if ($statusPage['search'] !== '') {
        $params['q'] = $statusPage['search'];
    }

    // Written before the overrides so a caller can drop one by passing null.
    if ($filters['division'] !== null) {
        $params['division'] = $filters['division'];
    }

    // The team selection rides on EVERY link, in whichever of its three
    // shapes is in force — `team[]=12`, `team=all`, or nothing at all for a
    // caller with one team who was never offered the choice. Explicit even
    // when it is the default, because a link that leaves it out is a link
    // that re-derives it at the other end: turn a page with `team` missing
    // and the default comes back, which for somebody who asked for all teams
    // is their roster silently shrinking to twenty-five people.
    if ($teams['may_choose'] || $filters['teams'] !== []) {
        $params['team'] = TeamFilter::param($teams);
    }
    if ($filters['contact'] !== null) {
        $params['contact'] = $filters['contact'];
    }
    if ($filters['assigned'] !== null) {
        $params['assigned'] = $filters['assigned'];
    }

    $params = array_merge($params, $overrides);

    if ($params['mode'] === $defaultMode) {
        unset($params['mode']);
    }
    if ($params['show'] === 'outstanding') {
        unset($params['show']);
    }
    if ($params['size'] === $statusPage['size_default']) {
        unset($params['size']);
    }
    if ($params['page'] === 1) {
        unset($params['page']);
    }
    foreach ($params as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);

    return $app->url('dashboard') . ($query === '' ? '' : '?' . $query);
};

/**
 * What this screen was narrowed to, in words — a filtered roster that does
 * not say it is filtered is a roster that is quietly missing people.
 *
 * @var array<int, string> $filterWords
 */
$filterWords = [];
if ($filters['division_name'] !== '') {
    $filterWords[] = $filters['division_name'];
}
foreach ($filters['team_names'] as $teamName) {
    $filterWords[] = $teamName;
}
if ($filters['contact'] === 'never') {
    $filterWords[] = 'never contacted';
}
if ($filters['assigned'] === 'none') {
    $filterWords[] = 'no officer assigned';
}

$dash  = $statusPage['dashboard'];
$total = (int) $dash['total'];
$fully = (int) $dash['fully_complete'];
?>
<?php $daysLeft = View::daysLeft($app, $year['ends_on'] ?? null); ?>
<h1>My Roster Status</h1>
<p class="lede">
    Show year <?= e((string) $year['label']) ?><?php
        if ($daysLeft !== '') { ?> &middot; <strong><?= e($daysLeft) ?></strong><?php } ?> &middot;
    <?= $statusPage['mode'] === 'mine'
        ? 'members assigned to you'
        : ($teams['all'] ? 'everyone in your scope' : 'everyone on the team below') ?><?php
    if ($statusPage['search_applied']) { ?>, matching &ldquo;<?= e((string) $statusPage['search']) ?>&rdquo;<?php } ?>.
    The list below is <?= $statusPage['show'] === 'outstanding'
        ? 'the working set: outstanding on at least one requirement, next call first'
        : 'everyone in this view, next call first' ?>.
    <?php if ($total > 0 && $statusPage['total'] > 0) { ?>
        <a class="skip" href="#list">Skip to the <?= $statusPage['show'] === 'outstanding' ? 'next calls' : 'list' ?></a>
    <?php } ?>
</p>

<?php if ($filters['active']) { ?>
    <?php /* Arrived from the Committee Dashboard (spec 7.3, decided 4). Every
             figure there equals this list filtered to it, so this screen has
             to say WHICH filter it is showing — and offer the way out. */ ?>
    <div class="card">
        <span class="chip chip-info">Filtered</span>
        <span><?= e($filterWords === []
            ? 'a group from the Committee Dashboard'
            : implode(' · ', $filterWords)) ?></span>
        <p class="hint">
            <a href="<?= e($href([
                'division' => null, 'team' => TeamFilter::ALL, 'contact' => null, 'assigned' => null,
            ])) ?>">Show my whole roster</a>
            &middot;
            <a href="<?= e($app->url('committee')) ?>">Back to the Committee Dashboard</a>
        </p>
    </div>
<?php } ?>

<?php if (!$year['is_open']) { ?>
    <div class="card">
        <span class="chip chip-warn">Read-only</span>
        Show year <?= e((string) $year['label']) ?> is closed. Everything here is
        still visible, but contacts and progress can no longer be logged.
    </div>
<?php } ?>

<nav class="toggle" aria-label="Whose roster">
    <a href="<?= e($href(['mode' => 'mine'])) ?>"
        <?= $statusPage['mode'] === 'mine' ? 'class="current" aria-current="page"' : '' ?>>My members</a>
    <a href="<?= e($href(['mode' => 'team'])) ?>"
        <?= $statusPage['mode'] === 'team' ? 'class="current" aria-current="page"' : '' ?>>My team</a>
</nav>

<?php
/*
 * THE FOLDS (Phase 10.3). On a phone the list a Captain came for sat under
 * the toggle, the team picker and its hint, the search box and its hint,
 * the banner and four cards each with a legend — roughly two screens of
 * scroll before "The next calls to make", on the screen spec 1.2 describes
 * as "one screen: who is outstanding, and a button that dials them". So
 * below 720px the controls and the cards each fold behind a 56px label,
 * and the list rises to the first screen. Above 720px nothing folds.
 *
 * A checkbox and a label, not <details>: <details> cannot be told to be
 * open at one width and closed at another without a script, and a
 * checkbox can — the CSS ignores it above the breakpoint. Not a byte of
 * the folded content changes, so the budget is untouched; and the fold is
 * OPEN whenever something inside it is in force — a search term, a chosen
 * team — because a narrowed list must never hide the control that narrowed
 * it. The checkbox is outside every form and posts nowhere.
 */
$findOpen = $statusPage['search'] !== ''
    || ($teams['may_choose'] && !$filters['drilled'] && !$teams['defaulted']);
?>
<input type="checkbox" id="fold-find" class="fold vh"<?= $findOpen ? ' checked' : '' ?>>
<label for="fold-find" class="fold-label">Find or narrow the list</label>
<div class="folded">
<?php if ($teams['may_choose'] && !$filters['drilled']) {
    /*
     * WHICH TEAM (Phase 10) — for a caller whose scope holds more than one:
     * Senior Officer and above, and an Officer an Admin has given a team set.
     * An Officer's team IS their scope, so they are never offered a control
     * that could only re-select what they already have.
     *
     * A GET form, like every other filter in this application: the choice
     * lands in the URL, so it survives a bookmark, a page turn and the back
     * button, and the server decides what it means. There is no JavaScript
     * here, so the button is not decoration — it is how the select is
     * submitted.
     *
     * It is NOT drawn while a Committee Dashboard drill-down is in force.
     * That screen's own rule is that every figure on it equals this list
     * filtered to it, and a control that could quietly widen or narrow the
     * group would break that for exactly the people the figure counted. The
     * banner above offers the one way out; this appears once it is taken.
     *
     * Everything else on screen travels with it in hidden fields. Losing the
     * toggle or a drill-down filter by choosing a team would be the same
     * quiet subtraction the links above are careful to avoid.
     */
    $carry = [];
    if ($statusPage['mode'] !== $defaultMode) {
        $carry['mode'] = (string) $statusPage['mode'];
    }
    if ($statusPage['show'] !== 'outstanding') {
        $carry['show'] = (string) $statusPage['show'];
    }
    if ((int) $statusPage['size'] !== (int) $statusPage['size_default']) {
        $carry['size'] = (string) $statusPage['size'];
    }
    if ($filters['division'] !== null) {
        $carry['division'] = (string) $filters['division'];
    }
    if ($filters['contact'] !== null) {
        $carry['contact'] = (string) $filters['contact'];
    }
    if ($filters['assigned'] !== null) {
        $carry['assigned'] = (string) $filters['assigned'];
    }
    if ($statusPage['search'] !== '') {
        $carry['q'] = (string) $statusPage['search'];
    }

    $selectedTeams = $teams['selected'];
    $inScope       = 0;
    foreach ($teams['options'] as $option) {
        $inScope += (int) $option['members'];
    }
?>
    <form class="quick teams" method="get" action="<?= e($app->url('dashboard')) ?>">
        <?php foreach ($carry as $name => $value) { ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
        <?php } ?>

        <label for="teampick">Which team</label>
        <select id="teampick" name="team">
            <?php if (count($selectedTeams) > 1) { ?>
                <?php /* A drill-down named several teams at once, and a select
                         can only show one. Saying so is better than showing
                         the first of them as though it were the whole
                         selection. */ ?>
                <option value="" selected>
                    <?= e($number(count($selectedTeams))) ?> teams from the Committee Dashboard
                </option>
            <?php } ?>
            <option value="<?= e(TeamFilter::ALL) ?>"<?= $teams['all'] ? ' selected' : '' ?>>
                All teams you can see &mdash; <?= e($number($inScope)) ?> members in
                <?= e($number(count($teams['options']))) ?> teams
            </option>
            <?php foreach ($teams['options'] as $option) {
                $id = (int) $option['id'];
            ?>
                <option value="<?= e((string) $id) ?>"
                    <?= count($selectedTeams) === 1 && $selectedTeams[0] === $id ? ' selected' : '' ?>>
                    <?= e((string) $option['name']) ?> &mdash;
                    <?= e($number((int) $option['members'])) ?> members<?php
                        if ($teams['own'] === $id) { ?> (your team)<?php } ?>
                </option>
            <?php } ?>
        </select>
        <button type="submit" class="quiet">Show this team</button>

        <p class="hint">
            <?php if ($teams['all']) { ?>
                Showing every team you can see.
            <?php } elseif ($teams['defaulted']) { ?>
                Showing <strong><?= e((string) $teams['own_name']) ?></strong>, the team you are
                on. This screen starts there; it is not a filter somebody left on.
            <?php } elseif (count($selectedTeams) === 1) { ?>
                Showing <strong><?= e($filters['team_names'][$selectedTeams[0]] ?? 'one team') ?></strong>.
            <?php } else { ?>
                Showing <?= e($number(count($selectedTeams))) ?> teams.
            <?php } ?>
            <?php if (!$teams['all']) { ?>
                &middot; <a href="<?= e($href(['team' => TeamFilter::ALL])) ?>">Show all teams</a>
            <?php } ?>
            <?php if ($teams['own'] !== null && $selectedTeams !== [(int) $teams['own']]) { ?>
                &middot; <a href="<?= e($href(['team' => [(int) $teams['own']]])) ?>">Show my team</a>
            <?php } ?>
        </p>
    </form>
<?php } ?>

<?php
/*
 * FIND ONE MEMBER (Phase 10.2) — spec 7.2's search box, on the screen where
 * the calls are made. An officer ringing somebody back should not page
 * through fifty rows to find them, and should not have to leave for View My
 * Roster, which has no Log contact of its own on the row until this phase
 * and carries no cards at all.
 *
 * A GET form, like every filter in this application: the term lands in the
 * URL, survives a page turn and the back button, and the server enforces
 * the floor. It narrows WITHIN whatever is already in force — the toggle,
 * the team, a drill-down — never around it, so everything else on screen
 * travels in hidden fields: a search that dropped "My members" would find
 * a Smith the officer is not chasing, with no error and no way to tell.
 * It is offered under a drill-down too, unlike the team picker: it can only
 * subtract, and the term is printed beside the box, so nothing about the
 * group is altered quietly.
 */
$findCarry = [];
if ($statusPage['mode'] !== $defaultMode) {
    $findCarry['mode'] = (string) $statusPage['mode'];
}
if ($statusPage['show'] !== 'outstanding') {
    $findCarry['show'] = (string) $statusPage['show'];
}
if ((int) $statusPage['size'] !== (int) $statusPage['size_default']) {
    $findCarry['size'] = (string) $statusPage['size'];
}
if ($filters['division'] !== null) {
    $findCarry['division'] = (string) $filters['division'];
}
if ($filters['contact'] !== null) {
    $findCarry['contact'] = (string) $filters['contact'];
}
if ($filters['assigned'] !== null) {
    $findCarry['assigned'] = (string) $filters['assigned'];
}
// The team selection in whichever of its shapes is in force — the ALL
// token, or ids — for the same reason $href carries it: left out, it is
// re-derived at the other end as the caller's own team.
$findTeam = $teams['may_choose'] || $filters['teams'] !== [] ? TeamFilter::param($teams) : null;
?>
<form class="quick find" method="get" action="<?= e($app->url('dashboard')) ?>">
    <?php foreach ($findCarry as $name => $value) { ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php } ?>
    <?php if (is_string($findTeam)) { ?>
        <input type="hidden" name="team" value="<?= e($findTeam) ?>">
    <?php } elseif (is_array($findTeam)) { ?>
        <?php foreach ($findTeam as $teamId) { ?>
            <input type="hidden" name="team[]" value="<?= e((string) $teamId) ?>">
        <?php } ?>
    <?php } ?>

    <label for="q">Find a member &mdash; name or member number</label>
    <input type="search" id="q" name="q" value="<?= e((string) $statusPage['search']) ?>"
        inputmode="search" autocomplete="off"
        placeholder="From <?= e((string) $statusPage['search_min_chars']) ?> characters">
    <button type="submit" class="quiet">Find</button>

    <?php if ($statusPage['search_too_short']) { ?>
        <p class="hint">
            <span class="chip chip-warn"><span class="chip-word">Note</span></span>
            Search starts at <?= e((string) $statusPage['search_min_chars']) ?> characters
            &mdash; showing everyone in this view instead.
        </p>
    <?php } elseif ($statusPage['search_applied']) { ?>
        <p class="hint">
            Showing only members matching <strong>&ldquo;<?= e((string) $statusPage['search']) ?>&rdquo;</strong>
            &middot; <a href="<?= e($href(['q' => null])) ?>">Clear the search</a>
        </p>
    <?php } ?>
</form>
</div>

<?php if ($total === 0) { ?>
    <div class="card">
        <?php if ($statusPage['search_applied']) { ?>
            <?php /* Said first: with a term in the box, "no members are
                     assigned to you" would be false, and "your roster is
                     empty" would send an officer to an Admin over a search
                     they can clear themselves. */ ?>
            <h2>Nobody matches</h2>
            <p>
                No member in this view matches
                &ldquo;<?= e((string) $statusPage['search']) ?>&rdquo;.
                <?php if ($statusPage['mode'] === 'mine') { ?>
                    They may be on your team without being assigned to you &mdash;
                    <a href="<?= e($href(['mode' => 'team'])) ?>">search My team</a>, or
                <?php } elseif (!$teams['all'] && $teams['may_choose'] && !$filters['drilled']) { ?>
                    They may be on another team &mdash;
                    <a href="<?= e($href(['team' => TeamFilter::ALL])) ?>">search all teams you can see</a>, or
                <?php } ?>
                <a href="<?= e($href(['q' => null])) ?>">clear the search</a>.
            </p>
        <?php } elseif ($statusPage['mode'] === 'mine') { ?>
            <h2>No members are assigned to you yet</h2>
            <p>
                Assignments arrive with the Assign Officers screen. Until then,
                <a href="<?= e($href(['mode' => 'team'])) ?>">My team</a> shows
                everyone in your scope.
            </p>
        <?php } elseif ($filters['active']) { ?>
            <h2>Nobody matches this filter</h2>
            <p>
                Your scope holds members, but none of them are in the group
                this link named. The figure that sent you here may have been
                worked since &mdash;
                <a href="<?= e($href([
                    'division' => null, 'team' => TeamFilter::ALL, 'contact' => null, 'assigned' => null,
                ])) ?>">show my whole roster</a>.
            </p>
        <?php } elseif (!$teams['all']) { ?>
            <?php /* A team with nobody in it is not a scope with nobody in
                     it, and saying the second when the first is true sends an
                     officer to an Admin over a filter they can clear
                     themselves. */ ?>
            <h2>Nobody is on this team</h2>
            <p>
                <?php if ($teams['defaulted']) { ?>
                    This screen starts on the team you are on, and it has no
                    members you can see.
                <?php } else { ?>
                    The team selected above has no members you can see.
                <?php } ?>
                <a href="<?= e($href(['team' => TeamFilter::ALL])) ?>">Show all
                teams you can see</a>.
            </p>
        <?php } else { ?>
            <h2>Your roster is empty</h2>
            <p>
                No members are in your scope. If you expected a team or a
                division here, an Admin can check the team on your member
                record or set a scope for your account.
            </p>
        <?php } ?>
    </div>
<?php } else { ?>

    <div class="overall">
        <h2><button type="button" class="deflink" popovertarget="d-fully">Fully Complete</button></h2>
        <p class="headline"><strong><?= e($number($fully)) ?></strong>
            of <?= e($number($total)) ?> members have met all four requirements
            <span class="out"><?= e($number($total - $fully)) ?> still have at least one outstanding</span></p>
        <div class="bar">
            <?php if ($fully > 0) { ?>
                <span class="s-complete" style="width:<?= e($pct($fully, $total)) ?>%"
                    title="Fully complete <?= e($number($fully)) ?>"></span>
            <?php } ?>
            <?php if ($total - $fully > 0) { ?>
                <span class="s-open" style="width:<?= e($pct($total - $fully, $total)) ?>%"
                    title="At least one outstanding <?= e($number($total - $fully)) ?>"></span>
            <?php } ?>
        </div>
    </div>

    <?php /* The strip is the four cards in one line — each requirement's
             outstanding count — and it is the fold's label on a phone.
             Above 720px the label is not drawn and the cards always are. */ ?>
    <input type="checkbox" id="fold-cards" class="fold vh">
    <label for="fold-cards" class="fold-label">
        <span class="strip">
            <?php foreach (Metric::scored() as $metric) { ?>
                <span><?= e($metric->shortLabel()) ?> <strong><?= e($number((int) $dash['cards'][$metric->value]['outstanding'])) ?></strong></span>
            <?php } ?>
            <span class="out">outstanding</span>
        </span>
    </label>
    <div class="cards folded">
        <?php foreach (Metric::scored() as $metric) {
            $card     = $dash['cards'][$metric->value];
            $counts   = $card['statuses'];
            $complete = (int) $card['complete'];
        ?>
        <div class="mcard">
            <h2><?= e($metric->label()) ?></h2>
            <p class="headline"><strong><?= e($number($complete)) ?></strong>
                of <?= e($number($total)) ?> complete
                <span class="out"><?= e($number((int) $card['outstanding'])) ?> outstanding</span></p>
            <?= View::bar($counts, $total, true) ?>
            <ul class="legend">
                <?php foreach ($ladder as $s) {
                    $n = (int) $counts[$s->value];
                    if ($n === 0) {
                        continue;
                    }
                ?>
                <li><span class="dot <?= e($s->barClass()) ?>"></span><button type="button"
                    class="deflink" popovertarget="<?= e($popId[$s->value]) ?>"><?= e($s->label()) ?></button>
                    <span class="n"><?= e($number($n)) ?></span></li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>
    </div>

    <h2 id="list">
        <?= $statusPage['show'] === 'outstanding' ? 'The next calls to make' : 'Everyone in this view' ?>
    </h2>

    <?php if ($statusPage['total'] === 0) { ?>
        <div class="card">
            <p>
                Nobody in this view is outstanding on any requirement — all
                <?= e($number($total)) ?> are fully complete.
                <a href="<?= e($href(['show' => 'all'])) ?>">Show everyone anyway</a>
            </p>
        </div>
    <?php } else { ?>

    <p class="lede">
        Showing <?= e($number((int) $statusPage['from'])) ?>&ndash;<?= e($number((int) $statusPage['to'])) ?>
        of <?= e($number((int) $statusPage['total'])) ?>
        <?= $statusPage['show'] === 'outstanding' ? 'outstanding members' : 'members' ?>
        <?php if ($statusPage['pages'] > 1) { ?>
            &middot; page <?= e($number((int) $statusPage['page'])) ?> of <?= e($number((int) $statusPage['pages'])) ?>
        <?php } ?>
        &middot;
        <?php if ($statusPage['show'] === 'outstanding') { ?>
            <a href="<?= e($href(['show' => 'all'])) ?>">Include the complete</a>
        <?php } else { ?>
            <a href="<?= e($href(['show' => 'outstanding'])) ?>">Outstanding only</a>
        <?php } ?>
        &middot;
        <?php if ($statusPage['size'] === $statusPage['size_default']) { ?>
            <a href="<?= e($href(['size' => $statusPage['size_large'], 'page' => $statusPage['page']])) ?>">Show <?= e((string) $statusPage['size_large']) ?> per page</a>
        <?php } else { ?>
            <a href="<?= e($href(['size' => $statusPage['size_default'], 'page' => $statusPage['page']])) ?>">Show <?= e((string) $statusPage['size_default']) ?> per page</a>
        <?php } ?>
    </p>

    <table class="roster">
        <thead>
            <tr>
                <th>Name</th>
                <?php foreach (Metric::scored() as $metric) { ?>
                    <th><?= e($metric->shortLabel()) ?></th>
                <?php } ?>
                <th>Last contact</th>
                <th>By</th>
                <th>Result</th>
                <th>Actions</th>
            </tr>
        </thead>
<?php
    // Echoed compactly, the Phase 4 lesson: this block repeats up to 100
    // times against the 100KB first-paint budget, and pretty whitespace was
    // measured costing more than the data. Compact never means unescaped.
    // Everything identical across rows is built ONCE here — the same token
    // serves every form in this session, and fifty copies of the option
    // lists are the bytes the budget does not have.
    $lcAction = $app->url('log-contact');

    // The filters travel too, whitelisted again on the way back by
    // dashboard_return_query() — a 303 that dropped them would land the
    // officer on the whole roster after logging one call.
    $returnState = http_build_query(array_filter([
        'mode'     => $statusPage['mode'],
        'show'     => $statusPage['show'] === 'all' ? 'all' : null,
        'division' => $filters['division'],
        // The team selection in whichever shape is in force, including the
        // ALL token — the same rule as $href above, for the same reason: a
        // 303 that dropped it would land somebody who asked for every team
        // back on their own one, with no error and no way to tell.
        'team'     => $teams['may_choose'] || $filters['teams'] !== []
            ? TeamFilter::param($teams)
            : null,
        'contact'  => $filters['contact'],
        'assigned' => $filters['assigned'],
        // The search term as typed (Phase 10.2), so the officer comes back
        // to the one member they found rather than to the whole list.
        'q'        => $statusPage['search'] !== '' ? $statusPage['search'] : null,
        'page'     => $statusPage['page'] > 1 ? $statusPage['page'] : null,
        'size'     => $statusPage['size'] !== $statusPage['size_default'] ? $statusPage['size'] : null,
    ]));
    // `screen` names the screen to come back to; log_contact_act() reads it
    // through a whitelist and View My Roster's sheet says 'roster'.
    $lcShared = Rerm\Csrf::field()
        . '<input type="hidden" name="screen" value="dashboard">'
        . '<input type="hidden" name="return" value="' . e($returnState) . '">';

    $openSheet = (int) ($statusPage['log_open'] ?? 0);

    foreach ($statusPage['rows'] as $row) {
        echo '<tbody class="member" id="m', e((string) $row['id']), '"><tr class="entry">';
        // Number, then the TITLE the last import gave them, then the team.
        // The title is Rodeo Houston's word — Captain, Committee Member — and
        // not the level this application derived from it: an officer working
        // a list of calls needs to know which of these people already hold a
        // job, and the two are not the same sentence (CLAUDE.md, spec 6.6).
        echo '<td class="who">', e($row['display_name']),
            ' <span class="sub">', e($row['member_number']),
            $row['title'] !== '' ? ' &middot; ' . e($row['title']) : '',
            $row['team_name'] !== '' ? ' &middot; ' . e($row['team_name']) : '',
            '</span></td>';

        foreach (Metric::scored() as $metric) {
            echo '<td class="metric" data-label="', e($metric->shortLabel()), '">',
                View::chip($row['statuses'][$metric->value]), '</td>';
        }

        if ($row['last_contact'] === null) {
            echo '<td data-label="Last contact"><span class="chip chip-muted">Never contacted</span></td>';
            echo '<td data-label="By">&mdash;</td>';
        } else {
            [$words, $absolute] = View::when($app, (string) $row['last_contact']['occurred_at']);
            echo '<td data-label="Last contact"><span title="', e($absolute), '">', e($words), '</span></td>';
            echo '<td data-label="By">', e((string) $row['last_contact']['officer_name']),
                ' &middot; ', e($contactTypes[$row['last_contact']['contact_type']]
                    ?? (string) $row['last_contact']['contact_type']), '</td>';
        }

        // WHAT THE CONTACT PRODUCED (spec-v2 §6). The two cells above say a
        // call happened and who made it; this one says whether it got
        // anywhere, which is what decides whether to ring again today. It is
        // ContactOutcome::summarise() over the very statuses printed to the
        // left — never a second read, so the word cannot contradict the chips
        // — and it carries "2 of 3" when the member's answer reached only
        // some of what is still open.
        echo '<td class="result" data-label="Result">', View::outcome($row['outcome']), '</td>';

        // Absent, never disabled (spec 8.4): Text only for CELL PHONE,
        // Email only when an address exists — decided in StatusPage. Log
        // contact is the fourth action (spec 7.1): a link that re-renders
        // this page with THIS row's sheet open — no JavaScript to open it
        // in place, and fifty inline copies of the sheet's form were
        // measured at over twice the spec 10 first-paint budget, so the one
        // row being worked carries it (~1.6KB) and the other forty-nine
        // carry this ~100-byte link.
        echo '<td class="actions">';
        if ($row['can_call']) {
            echo '<a href="tel:', e($row['phone_e164']), '">Call</a>';
        }
        if ($row['can_text']) {
            echo '<a href="sms:', e($row['phone_e164']), '">Text</a>';
        }
        if ($row['can_email']) {
            echo '<a href="mailto:', e($row['email']), '">Email</a>';
        }
        if ($year['is_open']) {
            echo '<a href="', e($href(['page' => $statusPage['page'], 'log' => $row['id']])),
                '#m', e((string) $row['id']), '">Log contact</a>';
        }
        echo '</td></tr>';

        // THE EXPANSION (spec-v2 §6) — the same move View My Roster makes, on
        // the screen where the calls are actually made: the whole show year's
        // contact history with every note, and who else is chasing this
        // member. Closed by default, because this is a working list first and
        // a reference second; <details> opens it with no round trip and no
        // JavaScript, so the CSP is untouched.
        //
        // IT CARRIES LESS THAN VIEW MY ROSTER'S DOES, and the difference is
        // the byte budget rather than an oversight. That screen's expansion
        // opens with a facts list — phone, email, division, harassment
        // training; here every one of those is either already on the row (the
        // name cell now names the title and the team) or one tap away (Call,
        // Text and Email are the row's own actions), and harassment training
        // is deliberately not on this screen at all: the four cards above are
        // exactly the four SCORED metrics, and a fifth appearing underneath
        // them would be the first place in the application that scored it.
        // Fifty of those lists measured ~16KB against spec 10's 100KB
        // first-paint budget, buying repetition. The reference view is one
        // link away at the foot of the page and carries all of it.
        //
        // Unlike the log-contact sheet below, this is rendered for EVERY row:
        // collapsed, it is its summary plus its contents, a few hundred bytes
        // for a member with a couple of contacts. The sheet is ~1.6KB of
        // repeated <option> text and stays one row at a time.
        $contactCount = count($row['contacts']);
        $officerCount = count($row['officers']);

        echo '<tr class="detail"><td class="expand" colspan="9"><details><summary>Details &middot; ',
            $contactCount === 0
                ? 'no contacts'
                : e($number($contactCount)) . ' contact' . ($contactCount === 1 ? '' : 's'),
            ' &middot; ',
            $officerCount === 0
                ? 'no officer assigned'
                : e($number($officerCount)) . ' officer' . ($officerCount === 1 ? '' : 's'),
            '</summary>';

        // The show year is named once, in the lede at the top of the screen,
        // rather than fifty times here.
        echo '<h2>Contact history</h2>';
        if ($row['contacts'] === []) {
            echo '<p class="hint">Never contacted this show year.</p>';
        } else {
            echo '<ul class="rows">';
            foreach ($row['contacts'] as $entry) {
                [$words, $absolute] = View::when($app, (string) $entry['occurred_at']);
                echo '<li><span title="', e($absolute), '">', e($words), '</span> &middot; ',
                    e($contactTypes[$entry['contact_type']] ?? (string) $entry['contact_type']),
                    ' &middot; ', e((string) $entry['officer_name']);
                if (trim((string) $entry['notes']) !== '') {
                    echo ' &mdash; ', e((string) $entry['notes']);
                }
                // Loaded from a spreadsheet rather than logged here as it
                // happened (spec 6.7). Said quietly and said anyway: the date
                // is the officer's word for when it was, not this
                // application's record of when it was typed.
                if ($entry['from_history'] ?? false) {
                    echo ' <span class="why">loaded from history</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>Assigned officers</h2>';
        if ($row['officers'] === []) {
            echo '<p class="hint">No officer assigned yet.</p>';
        } else {
            echo '<ul class="rows">';
            foreach ($row['officers'] as $officer) {
                echo '<li>', e((string) $officer['officer_name']);
                if ((string) $officer['officer_title'] !== '') {
                    echo ' &middot; ', e((string) $officer['officer_title']);
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</details></td></tr>';

        // The log-contact sheet (decided 2): type, optional note and
        // per-metric progress, its own small form, so a submit posts only
        // this row's fields. View::logContactSheet() since Phase 10.2 — the
        // same sheet View My Roster now offers, from one renderer. Absent
        // entirely on a closed year — the server refuses regardless; this
        // just stops offering the form.
        if ($year['is_open'] && $row['id'] === $openSheet) {
            echo View::logContactSheet(
                $lcAction,
                $lcShared,
                (int) $row['id'],
                (string) $row['display_name'],
                $row['statuses'],
                9,
                $row
            );
        }
        echo '</tbody>', "\n";
    }
?>
    </table>

    <?php if ($statusPage['pages'] > 1) { ?>
        <p>
            <?php if ($statusPage['page'] > 1) { ?>
                <a href="<?= e($href(['page' => $statusPage['page'] - 1])) ?>#list">&larr; Previous <?= e((string) $statusPage['size']) ?></a>
            <?php } ?>
            <?php if ($statusPage['page'] > 1 && $statusPage['page'] < $statusPage['pages']) { ?>
                &middot;
            <?php } ?>
            <?php if ($statusPage['page'] < $statusPage['pages']) { ?>
                <a href="<?= e($href(['page' => $statusPage['page'] + 1])) ?>#list">Next <?= e((string) $statusPage['size']) ?> &rarr;</a>
            <?php } ?>
        </p>
    <?php } ?>

    <?php } ?>

<?php } ?>

<?php
// One popover per status, shared by every legend that mentions it —
// declarative HTML: the button opens it, Esc / the backdrop / Close dismiss
// it. No JavaScript anywhere (decided 6).
echo '<div popover id="d-fully"><h3>Fully Complete</h3><p>', e($fullyDefinition), '</p>',
    '<button type="button" class="close" popovertarget="d-fully" popovertargetaction="hide">Close</button></div>';
foreach (MetricStatus::cases() as $s) {
    echo '<div popover id="', e($popId[$s->value]), '"><h3>', e($s->label()), '</h3><p>',
        e($s->definition()), '</p>',
        '<button type="button" class="close" popovertarget="', e($popId[$s->value]),
        '" popovertargetaction="hide">Close</button></div>';
}
?>

<details class="defs">
    <summary>What the statuses mean</summary>
    <dl>
        <dt>Fully Complete</dt><dd><?= e($fullyDefinition) ?></dd>
        <?php foreach (MetricStatus::cases() as $s) { ?>
            <dt><?= e($s->label()) ?></dt><dd><?= e($s->definition()) ?></dd>
        <?php } ?>
    </dl>

    <?php /* The Result column's own words. Two of them are the metric
             statuses' — one spelling, from the one enum — and the rest name
             states only this column has. "2 of 3" beside one of them says the
             member's answer covered two of the three requirements still open
             for them. */ ?>
    <h2>What the Result column means</h2>
    <p class="hint">
        The result of the last contact: what the member actually said, across
        everything still outstanding for them. A count beside it &mdash;
        &ldquo;2 of 3&rdquo; &mdash; is how much of what is open their answer
        covered.
    </p>
    <dl>
        <?php foreach (ContactOutcome::cases() as $o) { ?>
            <dt><?= e($o->label()) ?></dt><dd><?= e($o->definition()) ?></dd>
        <?php } ?>
    </dl>
</details>

