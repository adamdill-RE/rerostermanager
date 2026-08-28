<?php

declare(strict_types=1);

/**
 * Designate Users (spec 7.5, 4.4) — search the roster, see where somebody's
 * level came from, grant one, take it back.
 *
 * ONE form on the page, not one per row. The list renders a link per member
 * and the controls only for the member named by `?member=`, which is the
 * Phase 5 budget lesson applied a third time: a level select, two scope
 * selects and three buttons repeated a hundred times is 90KB of markup that
 * ninety-nine of those rows will never use, against a 100KB first-paint
 * budget (spec 10).
 *
 * Everything was decided in DesignatePage and Designate; this file renders
 * decided values and derives nothing — in particular it asks no permission
 * questions of its own, because `may_designate`, `may_revoke`,
 * `may_override` and `grantable` are already answers. Hiding a control hides
 * nothing: every one of them is asked again by the write path.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $designate everything DesignatePage::page() decided
 */

use Rerm\Csrf;

$number = static fn (int $n): string => number_format($n);

/** A link back to this screen carrying the list state it was clicked from. */
$href = static function (array $overrides = []) use ($app, $designate): string {
    $params = array_filter([
        'q'      => (string) $designate['search'],
        'only'   => (string) $designate['only'],
        'page'   => $designate['page'] > 1 ? (string) $designate['page'] : '',
        'size'   => $designate['size'] !== $designate['size_default'] ? (string) $designate['size'] : '',
        'member' => $designate['selected'] !== null ? (string) $designate['selected'] : '',
    ], static fn (string $v): bool => $v !== '');

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }

    $query = http_build_query($params);

    return $app->url('designate') . ($query === '' ? '' : '?' . $query);
};

/**
 * The list state a form carries so its 303 lands back on the exact page it
 * was submitted from. One hidden field rather than four — whitelisted again
 * on the way out by return_query(), never echoed into a Location raw.
 */
$returnState = http_build_query(array_filter([
    'q'    => (string) $designate['search'],
    'only' => (string) $designate['only'],
    'page' => $designate['page'] > 1 ? (string) $designate['page'] : '',
    'size' => $designate['size'] !== $designate['size_default'] ? (string) $designate['size'] : '',
], static fn (string $v): bool => $v !== ''));

/** What the row says this person's level is, and where it came from. */
$levelWord = static function (array $row): string {
    if ($row['effective_level'] === null) {
        return 'No account';
    }

    return $row['effective_level']->label();
};
?>
<h1>Designate Users</h1>
<p class="lede">
    Give any member on the roster a level — whatever their title says — and take
    it back. A grant survives every import: that is what it is for.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<form method="get" action="<?= e($app->url('designate')) ?>">
    <label for="q">Search by name or member number</label>
    <input type="search" id="q" name="q" value="<?= e((string) $designate['search']) ?>"
           inputmode="search" autocomplete="off">
    <?php if ($designate['only'] === 'granted') { ?>
        <input type="hidden" name="only" value="granted">
    <?php } ?>
    <?php if ($designate['size'] !== $designate['size_default']) { ?>
        <input type="hidden" name="size" value="<?= e((string) $designate['size']) ?>">
    <?php } ?>
    <button type="submit">Search</button>
</form>

<?php if ($designate['search_too_short']) { ?>
    <p class="hint">
        Type at least <?= e((string) $designate['search_min_chars']) ?> characters to search.
        Showing everyone you may designate.
    </p>
<?php } ?>

<div class="toggle">
    <a href="<?= e($href(['only' => null, 'page' => null, 'member' => null])) ?>"
       class="<?= $designate['only'] === '' ? 'current' : '' ?>">Everyone</a>
    <a href="<?= e($href(['only' => 'granted', 'page' => null, 'member' => null])) ?>"
       class="<?= $designate['only'] === 'granted' ? 'current' : '' ?>">Granted only</a>
</div>

<?php if ($designate['total'] === 0) { ?>
    <div class="card">
        <p>
            <?php if ($designate['only'] === 'granted') { ?>
                Nobody you can designate holds a granted level yet.
            <?php } elseif ($designate['search_applied']) { ?>
                No member you may designate matches
                &ldquo;<?= e((string) $designate['search']) ?>&rdquo;.
            <?php } else { ?>
                There is nobody here to designate.
            <?php } ?>
        </p>
    </div>
<?php } else { ?>

<p class="lede">
    Showing <?= e($number((int) $designate['from'])) ?>&ndash;<?= e($number((int) $designate['to'])) ?>
    of <?= e($number((int) $designate['total'])) ?> members
    <?php if ($designate['pages'] > 1) { ?>
        &middot; page <?= e($number((int) $designate['page'])) ?> of <?= e($number((int) $designate['pages'])) ?>
    <?php } ?>
    &middot;
    <?php if ($designate['size'] === $designate['size_default']) { ?>
        <a href="<?= e($href(['size' => $designate['size_large'], 'page' => null])) ?>">Show <?= e((string) $designate['size_large']) ?> per page</a>
    <?php } else { ?>
        <a href="<?= e($href(['size' => null, 'page' => null])) ?>">Show <?= e((string) $designate['size_default']) ?> per page</a>
    <?php } ?>
</p>

<table class="roster">
    <thead>
        <tr>
            <th>Member</th>
            <th>Title</th>
            <th>Level</th>
            <th>From</th>
            <th>Account</th>
            <th>Actions</th>
        </tr>
    </thead>

    <?php foreach ($designate['rows'] as $row) { ?>
        <?php $open = $designate['selected'] === $row['id']; ?>
        <tbody class="member">
        <tr>
            <td class="who" data-label="Member">
                <?= e((string) $row['name']) ?>
                <span class="sub">
                    <?= e((string) $row['member_number']) ?>
                    &middot; <?= e($row['team_name'] === '' ? '(No team)' : (string) $row['team_name']) ?>
                    &middot; <?= e((string) $row['division_name']) ?>
                </span>
            </td>
            <td data-label="Title"><?= e((string) $row['title']) ?></td>
            <td data-label="Level">
                <?php if ($row['effective_level'] === null) { ?>
                    <span class="chip chip-muted">No account</span>
                <?php } else { ?>
                    <span class="chip chip-<?= $row['source'] === 'grant' ? 'info' : 'muted' ?>">
                        <?= e($levelWord($row)) ?>
                    </span>
                <?php } ?>
            </td>
            <td data-label="From">
                <?php if ($row['source'] === 'grant') { ?>
                    Granted<?php if ($row['granted_by'] !== null) { ?>
                        by <?= e((string) $row['granted_by']) ?><?php } ?><?php
                    if ($row['granted_at'] !== null) { ?>,
                        <?= e($app->toDisplay((string) $row['granted_at'])->format('j M Y')) ?><?php } ?>
                <?php } elseif ($row['source'] === 'title') { ?>
                    Title
                <?php } else { ?>
                    &mdash;
                <?php } ?>
                <?php if ($row['scope_division_id'] !== null || $row['scope_team_id'] !== null) { ?>
                    <span class="sub">
                        Scope override:
                        <?= e($row['scope_division_name'] !== '' ? (string) $row['scope_division_name'] : '') ?><?php
                        if ($row['scope_division_name'] !== '' && $row['scope_team_name'] !== '') { ?> &middot; <?php } ?>
                        <?= e((string) $row['scope_team_name']) ?>
                    </span>
                <?php } ?>
            </td>
            <td data-label="Account">
                <?php if (!$row['has_account']) { ?>
                    <span class="chip chip-muted">None</span>
                <?php } elseif (!$row['is_active']) { ?>
                    <span class="chip chip-warn">Deactivated</span>
                <?php } elseif ($row['must_change']) { ?>
                    <span class="chip chip-warn">Password not set</span>
                <?php } else { ?>
                    <span class="chip chip-ok">Active</span>
                <?php } ?>
            </td>
            <td class="actions" data-label="Actions">
                <?php if (!$row['may_designate']) { ?>
                    <span class="sub">Outside your scope</span>
                <?php } elseif ($open) { ?>
                    <a href="<?= e($href(['member' => null])) ?>">Close</a>
                <?php } else { ?>
                    <a href="<?= e($href(['member' => $row['id']])) ?>">Change&hellip;</a>
                <?php } ?>
            </td>
        </tr>

        <?php if ($open && $row['may_designate']) { ?>
            <tr class="detail">
                <td colspan="6">
                    <?php if ($designate['grantable'] === []) { ?>
                        <p>You cannot grant any level.</p>
                    <?php } else { ?>
                        <form method="post" action="<?= e($app->url('designate')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="grant">
                            <input type="hidden" name="member_id" value="<?= e((string) $row['id']) ?>">
                            <input type="hidden" name="return" value="<?= e($returnState) ?>">

                            <label for="level-<?= e((string) $row['id']) ?>">Grant a level</label>
                            <select id="level-<?= e((string) $row['id']) ?>" name="level">
                                <?php foreach ($designate['grantable'] as $level) { ?>
                                    <option value="<?= e($level->value) ?>"
                                        <?= $row['granted_level'] === $level ? ' selected' : '' ?>>
                                        <?= e($level->label()) ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <p class="hint">
                                <?php if ($row['has_account']) { ?>
                                    This replaces the title-derived level and survives every import.
                                <?php } else { ?>
                                    <?= e((string) $row['name']) ?> has no login. Granting a level creates
                                    one with the initial password <code>1234</code>, which they must
                                    change on first sign-in. Nothing is emailed — tell them yourself.
                                <?php } ?>
                            </p>
                            <button type="submit">Grant</button>
                        </form>
                    <?php } ?>

                    <?php if ($row['granted_level'] !== null) { ?>
                        <form method="post" action="<?= e($app->url('designate')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="member_id" value="<?= e((string) $row['id']) ?>">
                            <input type="hidden" name="return" value="<?= e($returnState) ?>">
                            <p class="hint">
                                Revoking leaves the title-derived level
                                (<?= e($row['title_level']->label()) ?>) standing.
                                <?php if (!$row['title_level']->grantsLogin()) { ?>
                                    That is Member, so the account is deactivated — never deleted, and a
                                    later grant reopens the same one.
                                <?php } ?>
                            </p>
                            <button type="submit" class="quiet"
                                <?= $row['may_revoke'] ? '' : ' disabled' ?>>
                                Revoke <?= e($row['granted_level']->label()) ?>
                            </button>
                            <?php if (!$row['may_revoke']) { ?>
                                <p class="hint">
                                    Only somebody who could have granted
                                    <?= e($row['granted_level']->label()) ?> may revoke it.
                                </p>
                            <?php } ?>
                        </form>
                    <?php } ?>

                    <?php if ($designate['may_override'] && $row['has_account']) { ?>
                        <form method="post" action="<?= e($app->url('designate')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="scope">
                            <input type="hidden" name="member_id" value="<?= e((string) $row['id']) ?>">
                            <input type="hidden" name="return" value="<?= e($returnState) ?>">

                            <label for="sd-<?= e((string) $row['id']) ?>">Scope override &mdash; division</label>
                            <select id="sd-<?= e((string) $row['id']) ?>" name="scope_division_id">
                                <option value="">Their own division</option>
                                <?php foreach ($designate['divisions'] as $division) { ?>
                                    <option value="<?= e((string) $division['id']) ?>"
                                        <?= $row['scope_division_id'] === (int) $division['id'] ? ' selected' : '' ?>>
                                        <?= e((string) $division['name']) ?>
                                    </option>
                                <?php } ?>
                            </select>

                            <label for="st-<?= e((string) $row['id']) ?>">Scope override &mdash; team</label>
                            <select id="st-<?= e((string) $row['id']) ?>" name="scope_team_id">
                                <option value="">Their own team</option>
                                <?php foreach ($designate['teams'] as $team) { ?>
                                    <option value="<?= e((string) $team['id']) ?>"
                                        <?= $row['scope_team_id'] === (int) $team['id'] ? ' selected' : '' ?>>
                                        <?= e((string) $team['name']) ?>
                                    </option>
                                <?php } ?>
                            </select>

                            <p class="hint">
                                A Senior Officer sees their division and an Officer their team, read from
                                their own member record. An override points them somewhere else &mdash;
                                which is how somebody comes to own the members in
                                <code>(No Division)</code>.
                            </p>
                            <button type="submit" class="quiet">Save scope</button>
                        </form>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    <?php } ?>
</table>

<?php if ($designate['pages'] > 1) { ?>
    <p class="lede">
        <?php if ($designate['page'] > 1) { ?>
            <a href="<?= e($href(['page' => $designate['page'] - 1, 'member' => null])) ?>">&larr; Previous</a>
        <?php } ?>
        <?php if ($designate['page'] < $designate['pages']) { ?>
            <a href="<?= e($href(['page' => $designate['page'] + 1, 'member' => null])) ?>">Next &rarr;</a>
        <?php } ?>
    </p>
<?php } ?>

<?php } ?>

<p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
