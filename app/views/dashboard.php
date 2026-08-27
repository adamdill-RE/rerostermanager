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
 *     always the next call to make. Each row carries the four chips, the
 *     last contact, and Call / Text / Email / Log contact; the log-contact
 *     sheet is its own small per-row <form> (decided 2), so a submit posts
 *     only that row's fields and max_input_vars stays distant.
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
use Rerm\Roster\LogContact;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
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

$contactTypes = [
    'call'      => 'Call',
    'text'      => 'Text',
    'email'     => 'Email',
    'in_person' => 'In person',
    'other'     => 'Other',
];

/** The choices a per-metric progress select offers, spelled as the chips are. */
$progressChoices = [
    ''                 => 'No change',
    'in_progress'      => MetricStatus::InProgress->label() . ' — they are taking care of it',
    'claimed_complete' => MetricStatus::Reported->label() . ' — they say it is done',
    'not_started'      => 'Not started — clear a status set by mistake',
];

$defaultMode = $statusPage['has_assignments'] ? 'mine' : 'team';

/** The drill-down filters as applied (spec 7.3, decided 4), or none. */
$filters = $statusPage['filters'];

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
$href = static function (array $overrides = []) use ($app, $statusPage, $defaultMode, $filters): string {
    $params = [
        'mode' => $statusPage['mode'],
        'show' => $statusPage['show'],
        'size' => $statusPage['size'],
        'page' => 1,
    ];

    // Written before the overrides so a caller can drop one by passing null.
    if ($filters['division'] !== null) {
        $params['division'] = $filters['division'];
    }
    if ($filters['teams'] !== []) {
        $params['team'] = $filters['teams'];
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
<h1>My Roster Status</h1>
<p class="lede">
    Show year <?= e((string) $year['label']) ?> &middot;
    <?= $statusPage['mode'] === 'mine' ? 'members assigned to you' : 'everyone in your scope' ?>.
    The list below is <?= $statusPage['show'] === 'outstanding'
        ? 'the working set: outstanding on at least one requirement, next call first'
        : 'everyone in this view, next call first' ?>.
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
                'division' => null, 'team' => null, 'contact' => null, 'assigned' => null,
            ])) ?>">Show my whole roster</a>
            &middot;
            <a href="<?= e($app->url('committee')) ?>">Back to the Committee Dashboard</a>
        </p>
    </div>
<?php } ?>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
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

<?php if ($total === 0) { ?>
    <div class="card">
        <?php if ($statusPage['mode'] === 'mine') { ?>
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
                    'division' => null, 'team' => null, 'contact' => null, 'assigned' => null,
                ])) ?>">show my whole roster</a>.
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

    <div class="cards">
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
    $lcAction = e($app->url('log-contact'));

    // The filters travel too, whitelisted again on the way back by
    // dashboard_return_query() — a 303 that dropped them would land the
    // officer on the whole roster after logging one call.
    $returnState = http_build_query(array_filter([
        'mode'     => $statusPage['mode'],
        'show'     => $statusPage['show'] === 'all' ? 'all' : null,
        'division' => $filters['division'],
        'team'     => $filters['teams'] === [] ? null : $filters['teams'],
        'contact'  => $filters['contact'],
        'assigned' => $filters['assigned'],
        'page'     => $statusPage['page'] > 1 ? $statusPage['page'] : null,
        'size'     => $statusPage['size'] !== $statusPage['size_default'] ? $statusPage['size'] : null,
    ]));
    $lcShared = Rerm\Csrf::field()
        . '<input type="hidden" name="return" value="' . e($returnState) . '">';

    $typeOptions = '';
    foreach (LogContact::TYPES as $type) {
        $typeOptions .= '<option value="' . e($type) . '">' . e($contactTypes[$type]) . '</option>';
    }

    $openSheet = (int) ($statusPage['log_open'] ?? 0);

    foreach ($statusPage['rows'] as $row) {
        echo '<tbody class="member" id="m', e((string) $row['id']), '"><tr class="entry">';
        echo '<td class="who">', e($row['display_name']),
            ' <span class="sub">', e($row['member_number']),
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

        // The log-contact sheet (decided 2): type, optional note and
        // per-metric progress, its own small form, so a submit posts only
        // this row's fields. Absent entirely on a closed year — the server
        // refuses regardless; this just stops offering the form.
        if ($year['is_open'] && $row['id'] === $openSheet) {
            echo '<tr class="detail"><td class="expand" colspan="8">',
                '<details open><summary>Log contact &mdash; ', e($row['display_name']), '</summary>';
            echo '<form method="post" action="', $lcAction, '">',
                $lcShared,
                '<input type="hidden" name="member_id" value="', e((string) $row['id']), '">',
                '<p class="lc"><select name="contact_type" aria-label="How the contact happened">',
                $typeOptions,
                '</select><textarea name="note" rows="2" maxlength="1000" aria-label="Note"',
                ' placeholder="Note &mdash; optional, kept forever"></textarea></p>';

            $pending = array_filter(
                Metric::scored(),
                static fn (Metric $m): bool => $row['statuses'][$m->value] !== MetricStatus::Complete
            );
            if ($pending !== []) {
                echo '<p class="pgh">What they said &mdash; optional</p>';
                foreach ($pending as $metric) {
                    echo '<label class="pg">', e($metric->shortLabel()),
                        '<select name="progress[', e($metric->value), ']">';
                    foreach ($progressChoices as $value => $label) {
                        echo '<option value="', e((string) $value), '">', e($label), '</option>';
                    }
                    echo '</select></label>';
                }
            }

            echo '<button type="submit">Log this contact</button></form></details></td></tr>';
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
</details>

<p>
    <a href="<?= e($app->url('roster')) ?>">View My Roster</a> &middot;
    <a href="<?= e($app->url('menu')) ?>">Menu</a>
</p>
