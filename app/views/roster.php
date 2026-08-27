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
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $year    the active show year row (id, label)
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

$when = static fn (string $utc): array => View::when($app, $utc);

$contactTypes = [
    'call'      => 'Call',
    'text'      => 'Text',
    'email'     => 'Email',
    'in_person' => 'In person',
    'other'     => 'Other',
];

$number = static fn (int $n): string => number_format($n);
?>
<h1>View My Roster</h1>
<p class="lede">
    Everyone in your scope for show year <?= e((string) $year['label']) ?> —
    the reference view, not just the outstanding.
</p>

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
            <p>
                <label for="team">Teams &mdash; leave empty for all of them</label><br>
                <select id="team" name="team[]" multiple size="6">
                    <?php foreach ($roster['teams'] as $team) { ?>
                        <option value="<?= e((string) $team['id']) ?>"
                            <?= in_array((int) $team['id'], $roster['selected_teams'], true) ? 'selected' : '' ?>>
                            <?= e((string) $team['name']) ?>
                        </option>
                    <?php } ?>
                </select>
            </p>
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
                <th><?= $sortHeader('name', 'Name') ?></th>
                <th><?= $sortHeader('team', 'Team') ?></th>
                <?php foreach (Metric::scored() as $metric) { ?>
                    <th><?= e($metric->shortLabel()) ?></th>
                <?php } ?>
                <th><?= $sortHeader('contact', 'Last contact') ?></th>
                <th>Officer</th>
                <th>Actions</th>
            </tr>
        </thead>
<?php
        // The rows are echoed compactly rather than templated with the page's
        // indentation: this block repeats up to 100 times against the 100KB
        // first-paint budget (spec 10), and pretty whitespace at 30 bytes a
        // line was measured costing more than the data. Every value still
        // goes through e() — compact never means unescaped.
        foreach ($roster['rows'] as $row) {
            echo '<tbody class="member"><tr class="entry">';
            echo '<td class="who">', e($row['display_name']),
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
                [$words, $absolute] = $when((string) $row['last_contact']['occurred_at']);
                echo '<td data-label="Last contact"><span title="', e($absolute), '">',
                    e($words), '</span></td>';
                echo '<td data-label="Officer">', e((string) $row['last_contact']['officer_name']), '</td>';
            }

            // Absent, never disabled (spec 8.4): a greyed button invites a
            // tap that does nothing. Text only for CELL PHONE; Email only
            // when an address exists.
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
                    [$words, $absolute] = $when((string) $entry['occurred_at']);
                    echo '<li><span title="', e($absolute), '">', e($words), '</span> &middot; ',
                        e($contactTypes[$entry['contact_type']] ?? $entry['contact_type']),
                        ' &middot; ', e((string) $entry['officer_name']);
                    if (trim((string) $entry['notes']) !== '') {
                        echo ' &mdash; ', e((string) $entry['notes']);
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

            echo '</details></td></tr></tbody>', "\n";
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

<p><a href="<?= e($app->url('dashboard')) ?>">&larr; My Roster Status</a> &middot; <a href="<?= e($app->url('menu')) ?>">Menu</a></p>
