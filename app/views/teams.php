<?php

declare(strict_types=1);

/**
 * Manage Teams (spec 7.3) — the area each team groups under on the Committee
 * Dashboard, editable by an Admin.
 *
 * The area is DISPLAY GROUPING and this screen says so out loud, because that
 * is the one thing an Admin needs to understand before editing it: renaming an
 * area moves a row on one dashboard and changes nobody's access. It is not in
 * the export's data contract, it is not in the roster, and it never decides
 * who sees whom.
 *
 * ONE form and N links: 96 teams, each a row with a link that opens its own
 * editor. A page of 96 text inputs would be one POST that could rewrite every
 * area at once, which is a lot of accidental change surface for a column
 * nobody touches twice a year.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $teams everything TeamsPage::page() decided
 */

use Rerm\Csrf;

$number = static fn (int $n): string => number_format($n);

$href = static function (?int $team) use ($app): string {
    return $app->url('teams') . ($team === null ? '' : '?team=' . $team);
};
?>
<h1>Manage Teams</h1>
<p class="lede">
    The area a team groups under on the Committee Dashboard. It is grouping and
    nothing else &mdash; it does not appear in the roster, it never travels back
    to Rodeo Houston, and changing it changes nobody&rsquo;s access.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<?php if ($teams['no_area_count'] > 0) { ?>
    <div class="card">
        <span class="chip chip-warn">Note</span>
        <span>
            <?= e($number((int) $teams['no_area_count'])) ?>
            team<?= $teams['no_area_count'] === 1 ? '' : 's' ?>
            <?= $teams['no_area_count'] === 1 ? 'has' : 'have' ?> no area and
            group<?= $teams['no_area_count'] === 1 ? 's' : '' ?> under
            <strong>(No area)</strong> on the dashboard. That is an honest
            placeholder, not an error &mdash; give one an area only if the
            grouping helps somebody read the roll-up.
        </span>
    </div>
<?php } ?>

<datalist id="areas">
    <?php foreach ($teams['areas'] as $area) { ?>
        <option value="<?= e((string) $area) ?>"></option>
    <?php } ?>
</datalist>

<table>
    <caption>
        <?= e($number(count($teams['teams']))) ?> teams, grouped by area.
    </caption>
    <thead>
        <tr>
            <th>Team</th>
            <th>Division</th>
            <th>Area</th>
            <th class="num">Members</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($teams['teams'] as $team) { ?>
        <?php $open = $teams['selected'] === $team['id']; ?>
        <tr>
            <td data-label="Team"><strong><?= e((string) $team['name']) ?></strong></td>
            <td data-label="Division"><?= e($team['division_name'] === '' ? '&mdash;' : (string) $team['division_name']) ?></td>
            <td data-label="Area">
                <?php if ($team['area'] === '') { ?>
                    <span class="chip chip-muted">(No area)</span>
                <?php } else { ?>
                    <?= e((string) $team['area']) ?>
                <?php } ?>
            </td>
            <td class="num" data-label="Members"><?= e($number((int) $team['members'])) ?></td>
            <td data-label="Actions">
                <?php if ($open) { ?>
                    <a href="<?= e($href(null)) ?>">Close</a>
                <?php } else { ?>
                    <a href="<?= e($href((int) $team['id'])) ?>">Change area&hellip;</a>
                <?php } ?>
            </td>
        </tr>

        <?php if ($open) { ?>
            <tr>
                <td colspan="5">
                    <form method="post" action="<?= e($app->url('teams')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="team_id" value="<?= e((string) $team['id']) ?>">

                        <label for="area-<?= e((string) $team['id']) ?>">
                            Area for <?= e((string) $team['name']) ?>
                        </label>
                        <input type="text" id="area-<?= e((string) $team['id']) ?>"
                               name="area" list="areas" maxlength="64"
                               autocomplete="off"
                               value="<?= e((string) $team['area']) ?>">
                        <p class="hint">
                            Pick one that already exists, or type a new one. Leave it
                            blank to group this team under (No area).
                        </p>
                        <button type="submit">Save</button>
                    </form>
                </td>
            </tr>
        <?php } ?>
    <?php } ?>
    </tbody>
</table>

<p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
