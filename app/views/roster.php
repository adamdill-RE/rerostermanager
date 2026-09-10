<?php

declare(strict_types=1);

/**
 * View My Roster (spec 7.2) — the reference view: everyone in scope, not
 * just the outstanding.
 *
 * ONE template, one query, two layouts (spec 8.2): at or above 720px this is
 * a real table with sortable headers in the wide container; below it each
 * member's <tbody> becomes a stacked card — name, the four metric chips on
 * one line, and Call / Text / Email as 56px targets. The transformation is
 * the layout.php pattern (data-label cells at the 720px breakpoint), not a
 * second codebase.
 *
 * There is no JavaScript here and the CSP does not allow any: search is a
 * plain GET form with a server-side three-character floor, and the row
 * expansion is <details>. Every rendered value goes through e() — this
 * screen shows 1,954 people's names and contact details, the largest
 * injection surface in the application.
 *
 * SINCE PHASE 10.2 THE ROW HAS A WRITE: Log contact, the same action and
 * the same sheet My Roster Status carries (View::logContactSheet(), one
 * renderer), posting to the same route, which re-checks Access::allows()
 * with a Subject per member. An officer who has found somebody here — by
 * name, which this screen searches and the working list did not — logs the
 * call where they are standing instead of carrying the name to the other
 * screen. The 303 comes back HERE, with the search, filter, sort and page
 * intact, through roster_return_query()'s whitelist.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $year    the active show year row (id, label, is_open)
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $roster  everything RosterPage::page() decided
 */

use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\View;

// The chip and the relative timestamp are Rerm\View — shared with the
// dashboard, so one status renders one way everywhere.
$chip = static fn (MetricStatus $status): string => View::chip($status);

/**
 * A roster URL carrying the current search, filter, sort and size, with the
 * caller's overrides. Built on $app->url() like every link in this app; a
 * changed filter resets to page 1 unless the override says otherwise.
 *
 * @param array<string, mixed> $overrides
 */
$href = static function (array $overrides = []) use ($app, $roster): string {
    $params = [
        'q'    => $roster['search'],
        'team' => $roster['selected_teams'],
        'sort' => $roster['sort'],
        'dir'  => $roster['dir'],
        'size' => $roster['size'],
        'page' => 1,
    ];
    $params = array_merge($params, $overrides);

    // Defaults stay out of the URL so the plain screen has a plain address.
    if ($params['q'] === '') {
        unset($params['q']);
    }
    if ($params['team'] === []) {
        unset($params['team']);
    }
    if ($params['sort'] === 'name' && $params['dir'] === 'asc') {
        unset($params['sort'], $params['dir']);
    }
    if ($params['size'] === $roster['size_default']) {
        unset($params['size']);
    }
    if ($params['page'] === 1) {
        unset($params['page']);
    }

    $query = http_build_query($params);

    return $app->url('roster') . ($query === '' ? '' : '?' . $query);
};

/**
 * A sortable column header (spec 8.2): a link that sorts by the column, or
 * flips the direction when it already does.
 */
$sortHeader = static function (string $key, string $word) use ($roster, $href): string {
    $active = $roster['sort'] === $key;
    $dir    = $active && $roster['dir'] === 'asc' ? 'desc' : 'asc';
    $marker = $active ? ($roster['dir'] === 'asc' ? ' ▲' : ' ▼') : '';

    return '<a href="' . e($href(['sort' => $key, 'dir' => $dir])) . '">'
        . e($word) . '</a>' . e($marker);
};

/** The header cell's aria-sort (Phase 10.4): the arrow, said. */
$sortState = static fn (string $key): string => $roster['sort'] === $key
    ? ' aria-sort="' . ($roster['dir'] === 'asc' ? 'ascending' : 'descending') . '"'
    : '';

/** What a contact type is called — View's table, shared with the dashboard. */
$contactTypes = View::CONTACT_TYPES;

$number = static fn (int $n): string => number_format($n);
?>
<h1>View My Roster</h1>
<p class="lede">
    Everyone in your scope for show year <?= e((string) $year['label']) ?> —
    the reference view, not just the outstanding.
</p>

<?php if (!$year['is_open']) { ?>
    <div class="card">
        <span class="chip chip-warn">Read-only</span>
        Show year <?= e((string) $year['label']) ?> is closed. Everything here is
        still visible, but contacts can no longer be logged.
    </div>
<?php } ?>

<div class="card">
    <form method="get" action="<?= e($app->url('roster')) ?>">
        <?php /* GET, so no CSRF: this form changes nothing, and the URL it
                 builds is shareable. The floor is enforced server-side. */ ?>
        <p>
            <label for="q">Search name or member number</label><br>
            <input type="text" id="q" name="q" value="<?= e((string) $roster['search']) ?>"
                inputmode="search" autocomplete="off"
                placeholder="From <?= e((string) $roster['search_min_chars']) ?> characters">
        </p>

        <?php if ($roster['can_filter_teams'] && $roster['teams'] !== []) { ?>
            <?php /* Checkboxes, not a <select multiple> (Phase 10.4): the
                     native multi-select needs Ctrl-click at a desk and opens
                     an unlabelled picker on a phone; the Export screen's
                     fieldset of boxes is the touch-friendly answer, and the
                     same team[] parameter. Folded, open when a choice is in
                     force so a narrowed list never hides what narrowed it. */ ?>
            <details class="teams"<?= $roster['selected_teams'] !== [] ? ' open' : '' ?>>
                <summary>Teams &middot;
                    <?= $roster['selected_teams'] === []
                        ? 'all ' . e($number(count($roster['teams'])))
                        : e($number(count($roster['selected_teams']))) . ' of ' . e($number(count($roster['teams']))) . ' selected' ?></summary>
                <fieldset>
                    <legend class="vh">Teams &mdash; leave every box clear for all of them</legend>
                    <?php foreach ($roster['teams'] as $team) { ?>
                        <label class="choice" for="team-<?= e((string) $team['id']) ?>">
                            <input type="checkbox" id="team-<?= e((string) $team['id']) ?>" name="team[]"
                                   value="<?= e((string) $team['id']) ?>"<?=
                                   in_array((int) $team['id'], $roster['selected_teams'], true) ? ' checked' : '' ?>>
                            <span><span class="what"><?= e((string) $team['name']) ?></span></span>
                        </label>
                    <?php } ?>
                </fieldset>
            </details>
        <?php } ?>

        <p>
            <label for="sort">Sort by</label><br>
            <select id="sort" name="sort">
                <option value="name" <?= $roster['sort'] === 'name' ? 'selected' : '' ?>>Name</option>
                <option value="team" <?= $roster['sort'] === 'team' ? 'selected' : '' ?>>Team</option>
                <option value="contact" <?= $roster['sort'] === 'contact' ? 'selected' : '' ?>>
                    Last contact &mdash; never contacted first
                </option>
                <option value="number" <?= $roster['sort'] === 'number' ? 'selected' : '' ?>>Member number</option>
            </select>
        </p>

        <?php if ($roster['size'] !== $roster['size_default']) { ?>
            <input type="hidden" name="size" value="<?= e((string) $roster['size']) ?>">
        <?php } ?>
        <?php if ($roster['dir'] !== 'asc') { ?>
            <input type="hidden" name="dir" value="desc">
        <?php } ?>

        <button type="submit" class="quiet">Search and filter</button>
    </form>
</div>

<?php if ($roster['search_too_short']) { ?>
    <div class="card">
        <span class="chip chip-warn"><span class="chip-word">Note</span></span>
        Search starts at <?= e((string) $roster['search_min_chars']) ?> characters
        &mdash; showing everyone in your scope instead.
    </div>
<?php } ?>

<?php if ($roster['total'] === 0) { ?>
    <div class="card">
        <?php if ($roster['search_applied'] || $roster['selected_teams'] !== []) { ?>
            <h2>Nobody matches</h2>
            <p>
                No member in your scope matches this search or filter.
                <a href="<?= e($app->url('roster')) ?>">Show everyone</a>
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

    <p class="lede">
        Showing <?= e($number((int) $roster['from'])) ?>&ndash;<?= e($number((int) $roster['to'])) ?>
        of <?= e($number((int) $roster['total'])) ?> members
        <?php if ($roster['pages'] > 1) { ?>
            &middot; page <?= e($number((int) $roster['page'])) ?> of <?= e($number((int) $roster['pages'])) ?>
        <?php } ?>
        &middot;
        <?php if ($roster['size'] === $roster['size_default']) { ?>
            <a href="<?= e($href(['size' => $roster['size_large']])) ?>">Show <?= e((string) $roster['size_large']) ?> per page</a>
        <?php } else { ?>
            <a href="<?= e($href(['size' => $roster['size_default']])) ?>">Show <?= e((string) $roster['size_default']) ?> per page</a>
        <?php } ?>
    </p>

    <table class="roster">
        <thead>
            <tr>
                <th scope="col"<?= $sortState('name') ?>><?= $sortHeader('name', 'Name') ?></th>
                <th scope="col"<?= $sortState('team') ?>><?= $sortHeader('team', 'Team') ?></th>
                <?php foreach (Metric::scored() as $metric) { ?>
                    <th scope="col"><?= e($metric->shortLabel()) ?></th>
                <?php } ?>
                <th scope="col"<?= $sortState('contact') ?>><?= $sortHeader('contact', 'Last contact') ?></th>
                <th scope="col">Officer</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
<?php
        // The rows are echoed compactly rather than templated with the page's
        // indentation: this block repeats up to 100 times against the 100KB
        // first-paint budget (spec 10), and pretty whitespace at 30 bytes a
        // line was measured costing more than the data. Every value still
        // goes through e() — compact never means unescaped.
        // The log-contact sheet's shared block, built ONCE (Phase 10.2): the
        // token, the screen to come back to, and this screen's list state as
        // one query string — whitelisted again on the way back by
        // roster_return_query(), so a 303 that dropped the search would not
        // land the officer on page one of everyone after logging one call.
        $lcAction    = $app->url('log-contact');
        $returnState = http_build_query(array_filter([
            'q'    => $roster['search'] !== '' ? $roster['search'] : null,
            'team' => $roster['selected_teams'] !== [] ? $roster['selected_teams'] : null,
            'sort' => $roster['sort'] !== 'name' ? $roster['sort'] : null,
            'dir'  => $roster['dir'] !== 'asc' ? $roster['dir'] : null,
            'page' => $roster['page'] > 1 ? $roster['page'] : null,
            'size' => $roster['size'] !== $roster['size_default'] ? $roster['size'] : null,
        ]));
        $lcShared = Rerm\Csrf::field()
            . '<input type="hidden" name="screen" value="roster">'
            . '<input type="hidden" name="return" value="' . e($returnState) . '">';
        $openSheet = (int) ($roster['log_open'] ?? 0);

        // The way to one member's card (Phase 10.4): the name is the link,
        // carrying this list's state as `back` — re-whitelisted on the way
        // back by roster_return_query(), as the sheet's 303 is.
        $cardUrl = $app->url('member') . '?from=roster&back=' . rawurlencode($returnState) . '&id=';

        foreach ($roster['rows'] as $row) {
            echo '<tbody class="member" id="m', e((string) $row['id']), '"><tr class="entry">';
            echo '<td class="who"><a class="card-link" href="', e($cardUrl), e((string) $row['id']), '">',
                e($row['display_name']), '</a>',
                ' <span class="sub">', e($row['member_number']), '</span></td>';
            echo '<td data-label="Team">',
                e($row['team_name'] !== '' ? $row['team_name'] : '(no team)'), '</td>';

            foreach (Metric::scored() as $metric) {
                echo '<td class="metric" data-label="', e($metric->shortLabel()), '">',
                    $chip($row['statuses'][$metric->value]), '</td>';
            }

            if ($row['last_contact'] === null) {
                echo '<td data-label="Last contact">',
                    '<span class="chip chip-muted">Never contacted</span></td>';
                echo '<td data-label="Officer">&mdash;</td>';
            } else {
                echo '<td data-label="Last contact">', View::time($app, (string) $row['last_contact']['occurred_at']), '</td>';
                echo '<td data-label="Officer">', e((string) $row['last_contact']['officer_name']), '</td>';
            }

            // Absent, never disabled (spec 8.4): a greyed button invites a
            // tap that does nothing. Text only for CELL PHONE; Email only
            // when an address exists.
            $links = View::contactLinks($app, $user, $row);
            echo '<td class="actions">';
            if (isset($links['call'])) {
                echo '<a href="', e($links['call']), '">Call</a>';
            }
            if (isset($links['text'])) {
                echo '<a href="', e($links['text']), '">Text</a>';
            }
            if (isset($links['email'])) {
                echo '<a href="', e($links['email']), '">Email</a>';
            }
            // Log contact (Phase 10.2), the dashboard's fourth action, on
            // the same terms: a link that re-renders this page with THIS
            // row's sheet open, one row at a time, absent on a closed year.
            if ($year['is_open']) {
                echo '<a href="', e($href(['page' => $roster['page'], 'log' => $row['id']])),
                    '#m', e((string) $row['id']), '">Log contact</a>';
            }
            echo '</td></tr>';

            $contactCount = count($row['contacts']);
            $officerCount = count($row['officers']);

            echo '<tr class="detail"><td class="expand" colspan="9"><details><summary>Details &middot; ',
                $contactCount === 0
                    ? 'no contacts'
                    : e($number($contactCount)) . ' contact' . ($contactCount === 1 ? '' : 's'),
                ' this year &middot; ',
                $officerCount === 0
                    ? 'no officer assigned'
                    : e($number($officerCount)) . ' officer' . ($officerCount === 1 ? '' : 's'),
                '</summary>';

            echo '<dl class="facts"><dt>Phone</dt><dd>',
                $row['phone'] !== ''
                    ? e($row['phone'])
                        . ($row['phone_type'] !== '' ? ' &middot; ' . e(strtolower($row['phone_type'])) : '')
                    : 'None on file',
                '</dd>';
            echo '<dt>Email</dt><dd>', $row['email'] !== '' ? e($row['email']) : 'None on file', '</dd>';
            echo '<dt>Division</dt><dd>', e($row['division_name']), '</dd>';
            // Displayed, tri-state, never scored: not one of the four chips,
            // and its blank majority reads "Not reported", never a failure.
            echo '<dt>', e(Metric::HarassmentTraining->label()), '</dt><dd>',
                $chip($row['statuses'][Metric::HarassmentTraining->value]), '</dd></dl>';

            echo '<h2>Contact history &mdash; show year ', e((string) $year['label']), '</h2>';
            if ($row['contacts'] === []) {
                echo '<p class="hint">Never contacted this show year.</p>';
            } else {
                echo '<ul class="rows">';
                foreach ($row['contacts'] as $entry) {
                    echo '<li>', View::time($app, (string) $entry['occurred_at']), ' &middot; ',
                        e($contactTypes[$entry['contact_type']] ?? $entry['contact_type']),
                        ' &middot; ', e((string) $entry['officer_name']);
                    if (trim((string) $entry['notes']) !== '') {
                        echo ' &mdash; ', e((string) $entry['notes']);
                    }
                    // Loaded from a spreadsheet rather than logged here as it
                    // happened (spec 6.7). Said quietly and said anyway: the
                    // date is the officer's word for when it was, not this
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

            // The one open sheet, if it is this row's — the same renderer My
            // Roster Status uses, so the two screens post the same fields.
            if ($year['is_open'] && $row['id'] === $openSheet) {
                echo View::logContactSheet(
                    $lcAction,
                    $lcShared,
                    (int) $row['id'],
                    (string) $row['display_name'],
                    $row['statuses'],
                    9,
                    ['links' => $links] + $row
                );
            }

            echo '</tbody>', "\n";
        }
        ?>
    </table>

    <?php if ($roster['pages'] > 1) { ?>
        <p>
            <?php if ($roster['page'] > 1) { ?>
                <a href="<?= e($href(['page' => $roster['page'] - 1])) ?>">&larr; Previous <?= e((string) $roster['size']) ?></a>
            <?php } ?>
            <?php if ($roster['page'] > 1 && $roster['page'] < $roster['pages']) { ?>
                &middot;
            <?php } ?>
            <?php if ($roster['page'] < $roster['pages']) { ?>
                <a href="<?= e($href(['page' => $roster['page'] + 1])) ?>">Next <?= e((string) $roster['size']) ?> &rarr;</a>
            <?php } ?>
        </p>
    <?php } ?>

<?php } ?>

