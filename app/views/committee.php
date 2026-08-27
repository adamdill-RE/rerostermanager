<?php

declare(strict_types=1);

/**
 * The Committee Dashboard (spec 7.3) — the 7.1 dashboard computed per group,
 * rolled up by division, then area, then team, with the four metric bars
 * beside the three columns that actually distinguish one team from another.
 *
 * THREE COLLAPSIBLE LEVELS, WITHOUT JAVASCRIPT — AND WITHOUT <details>
 *
 * <details> is the tool this application reaches for (Phase 5 for the status
 * definitions, Phase 6 for the log sheet) and it is the wrong one here, for a
 * reason worth writing down: <details> collapses PIXELS, not BYTES. A closed
 * <details> has already shipped every row inside it. Four divisions, ~20
 * areas and 96 teams, each carrying four proportion bars, is a page the
 * browser would draw a corner of and download all of — and spec 10 budgets
 * the download, not the drawing.
 *
 * So the levels open through the URL instead: `?division=` opens one
 * division into its areas and `?area=` opens one of those into its teams,
 * exactly as Phase 6 lists one bucket at a time and Phase 5 opens one log
 * sheet at a time. The page is at most every division, one division's areas
 * and one area's teams; the state is shareable, survives a reload and back,
 * and needs no script. Where there is only ONE choice at a level — a Senior
 * Officer's single division — it is simply open, because there is nothing to
 * collapse it to.
 *
 * WHAT LINKS, AND WHY THE REST DOES NOT
 *
 * Every figure equals the list filtered to it (spec 7.1's rule since Phase
 * 5), so a number is a link only when spec 7.1 can express exactly the people
 * it counted:
 *
 *   a team's name           the working list for that team
 *   Members                 all of them
 *   Unassigned              ...&assigned=none
 *   Never contacted         ...&contact=never
 *   No officer on team      NOT a link. There is no spec 7.1 filter for
 *                           "their team has nobody assignable", and inventing
 *                           a fourth filter spelling is not this phase's to
 *                           take. It is a number leadership must see, and the
 *                           remedy is a designation, not a call list
 *
 * Every one of those links carries `mode=team` explicitly (decided 4). Spec
 * 7.1 defaults to My members the moment an officer holds an assignment, and
 * Phase 6 made that real: without it, a Senior Officer drilling into "40
 * never contacted" would land on the three of those forty assigned to them
 * personally. An AREA drills down as the list of its own teams, never as an
 * area filter — `area` is display grouping and must not become a query.
 *
 * ONE TEMPLATE, TWO LAYOUTS (spec 8.2). Above 720px this is a real table with
 * sortable headers; below it each row is a stacked card carrying its own
 * labels. The level word rides in the name cell rather than as indentation,
 * because indentation does not survive the card transform.
 *
 * Everything here was decided in CommitteePage; the view renders decided
 * values and derives no status. Every rendered value goes through e().
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $year       active show year (id, label, is_open)
 * @var array<string, mixed>  $committee  everything CommitteePage::page() decided
 */

use Rerm\Roster\CommitteePage;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\View;

$number = static fn (int $n): string => number_format($n);

/**
 * A Committee Dashboard URL carrying the sort and the open levels, with the
 * caller's overrides. A null override removes the key; defaults stay out of
 * the URL so the plain screen has a plain address.
 *
 * @param array<string, mixed> $overrides
 */
$href = static function (array $overrides = []) use ($app, $committee): string {
    $params = [
        'sort'     => $committee['sort'],
        'dir'      => $committee['dir'],
        'division' => $committee['open_division'],
        'area'     => $committee['open_area'],
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    if ($params['sort'] === CommitteePage::DEFAULT_SORT) {
        unset($params['sort']);
    }
    if ($params['dir'] === CommitteePage::DEFAULT_DIR) {
        unset($params['dir']);
    }
    // area '' is the (No area) group and must survive; only null is absence.
    foreach (['division', 'area'] as $key) {
        if ($params[$key] === null) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);

    return $app->url('committee') . ($query === '' ? '' : '?' . $query);
};

/**
 * The drill-down into My Roster Status (decided 4), for one group row.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $extra
 */
$drill = static function (array $row, array $extra = []) use ($app): string {
    // mode=team, always and explicitly: see the note at the head of this file.
    $params = ['mode' => 'team', 'division' => $row['division_id']];

    // A division needs no team[] — `division=` alone is exactly its members,
    // including the ones with no team. Anything narrower is spelled as spec
    // 7.2's team[] and never as an area.
    if ($row['level'] !== 'division') {
        $params['team'] = $row['team_ids'];
    }

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    return $app->url('dashboard') . '?' . http_build_query($params);
};

/**
 * A sortable column header: a link that sorts by the column, or flips the
 * direction when it already does. A triage column leads DESCENDING — most
 * work first — and the name leads ascending.
 */
$sortHeader = static function (string $key, string $word) use ($committee, $href): string {
    $active = $committee['sort'] === $key;
    $lead   = $key === 'name' ? 'asc' : 'desc';
    $dir    = $active ? ($committee['dir'] === 'asc' ? 'desc' : 'asc') : $lead;
    $marker = $active ? ($committee['dir'] === 'asc' ? ' ▲' : ' ▼') : '';

    return '<a href="' . e($href(['sort' => $key, 'dir' => $dir])) . '">'
        . e($word) . '</a>' . e($marker);
};

/** What each level's rows are called, in the cell and in the card. */
$levelWord = ['division' => 'Division', 'area' => 'Area', 'team' => 'Team'];
?>
<h1>Committee Dashboard</h1>
<p class="lede">
    Show year <?= e((string) $year['label']) ?> &middot;
    <?= e($number((int) $committee['total'])) ?> members in
    <?= e($number((int) $committee['divisions'])) ?>
    <?= (int) $committee['divisions'] === 1 ? 'division' : 'divisions' ?>.
    Sorted by <?= e($committee['sort'] === 'contact' ? 'never contacted' : $committee['sort']) ?>,
    <?= $committee['dir'] === 'desc' ? 'highest first' : 'lowest first' ?>.
</p>

<?php if ((int) $committee['total'] === 0) { ?>
    <div class="card">
        <h2>There is nothing in your scope yet</h2>
        <p>
            No members are visible to you for this show year. If you expected a
            division here, an Admin can check the division on your member record
            or set a scope for your account.
        </p>
    </div>
<?php } else { ?>

<table class="committee">
    <caption>
        Open a division or an area by its name; open a team, or any linked
        number, to reach the people it counts. Each requirement shows how many
        of the group are complete, out of its members.
    </caption>
    <thead>
        <tr>
            <th><?= $sortHeader('name', 'Group') ?></th>
            <th class="num"><?= $sortHeader('members', 'Members') ?></th>
            <?php foreach (Metric::scored() as $metric) { ?>
                <th class="num"><?= $sortHeader($metric->value, $metric->shortLabel()) ?></th>
            <?php } ?>
            <th class="num"><?= $sortHeader('unassigned', 'Unassigned') ?></th>
            <th class="num"><?= $sortHeader('no_officer', 'No officer') ?></th>
            <th class="num"><?= $sortHeader('contact', 'Never contacted') ?></th>
        </tr>
    </thead>
    <tbody>
<?php
// Echoed compactly, the Phase 4 lesson: this block repeats once per group
// against the 100KB first-paint budget, and pretty whitespace was measured
// costing more than the data. Compact never means unescaped.
foreach ($committee['rows'] as $row) {
    $members = (int) $row['members'];

    echo '<tr class="lv-', e($row['level']), '"><td class="grp" data-label="Group">',
        '<span class="lvl">', e($levelWord[$row['level']]), '</span> ';

    if ($row['level'] === 'team') {
        // A leaf: the name goes where the work is — the working list for this
        // team, spec 7.1's own default filter.
        if ($row['drillable']) {
            echo '<a href="', e($drill($row)), '">', e($row['name']), '</a>';
        } else {
            echo e($row['name']);
        }
    } elseif ($row['sole']) {
        // The only group at its level. Nothing to collapse to, so no control.
        echo e($row['name']);
    } else {
        // A parent: the whole name is the 56px target that opens or closes it.
        echo '<a href="', e($row['open']
                ? ($row['level'] === 'division' ? $href(['division' => null, 'area' => null]) : $href(['area' => null]))
                : ($row['level'] === 'division' ? $href(['division' => (int) $row['key'], 'area' => null]) : $href(['area' => $row['key']]))),
            '">', e($row['name']), '</a>';
    }

    if ($row['level'] !== 'team') {
        echo '<span class="sub">', e($number((int) $row['children'])), ' ',
            e($row['level'] === 'division'
                ? ((int) $row['children'] === 1 ? 'area' : 'areas')
                : ((int) $row['children'] === 1 ? 'team' : 'teams')),
            $row['open'] ? ', open' : '', '</span>';
    } elseif ($row['placeholder']) {
        // (No team): unassignable by definition, because assignment is
        // same-team. Counted rather than silently absent (spec 7.4 bucket 3's
        // reason), and it carries no drill-down because spec 7.1 has no
        // filter that means "no team".
        echo '<span class="sub">cannot be assigned &mdash; no team</span>';
    }

    echo '</td>';

    echo '<td class="num" data-label="Members">',
        $row['drillable']
            ? '<a href="' . e($drill($row, ['show' => 'all'])) . '">' . e($number($members)) . '</a>'
            : e($number($members)),
        '</td>';

    foreach (Metric::scored() as $metric) {
        $card = $row['metrics'][$metric->value];
        echo '<td class="metric" data-label="', e($metric->shortLabel()), '">',
            View::bar($card['statuses'], $members),
            '<span class="mn">', e($number((int) $card['complete'])), '/', e($number($members)), '</span></td>';
    }

    $triage = [
        ['Unassigned', (int) $row['unassigned'], ['show' => 'all', 'assigned' => 'none']],
        ['No officer', (int) $row['no_officer'], null],
        ['Never contacted', (int) $row['never_contacted'], ['show' => 'all', 'contact' => 'never']],
    ];
    foreach ($triage as [$label, $count, $extra]) {
        echo '<td class="num" data-label="', e($label), '">',
            $count > 0 && $extra !== null && $row['drillable']
                ? '<a href="' . e($drill($row, $extra)) . '">' . e($number($count)) . '</a>'
                : e($number($count)),
            '</td>';
    }

    echo '</tr>', "\n";
}
?>
    </tbody>
</table>

<?php } ?>

<details class="defs">
    <summary>What these columns mean</summary>
    <dl>
        <dt>Members</dt>
        <dd>
            Everyone in this group that you may see. A team appears under every
            division its members belong to &mdash; division is a property of
            the member, not of the team, and seven teams span two.
        </dd>
        <dt>The four requirements</dt>
        <dd>
            How many of the group the official roster shows complete, out of
            its members, with a bar in the same order as My Roster Status:
            <?php $words = [];
            foreach (MetricStatus::ladder() as $s) { $words[] = $s->label(); }
            echo e(implode(', ', $words)); ?>.
        </dd>
        <dt>Unassigned</dt>
        <dd>Members with no current officer &mdash; the Assign Officers screen's first bucket.</dd>
        <dt>No officer</dt>
        <dd>
            Members whose team has nobody who could be assigned them at all.
            Not something an officer can fix: the remedy is designating an
            Allowed User on that team.
        </dd>
        <dt>Never contacted</dt>
        <dd>
            Members nobody has logged a contact with this show year. This is
            the default sort, because at roughly half the committee outstanding
            on every requirement, the compliance columns describe the committee
            rather than distinguishing its teams.
        </dd>
        <?php foreach (MetricStatus::ladder() as $s) { ?>
            <dt><?= e($s->label()) ?></dt><dd><?= e($s->definition()) ?></dd>
        <?php } ?>
    </dl>
</details>

<p>
    <a href="<?= e($app->url('dashboard')) ?>">My Roster Status</a> &middot;
    <a href="<?= e($app->url('roster')) ?>">View My Roster</a> &middot;
    <a href="<?= e($app->url('menu')) ?>">Menu</a>
</p>
