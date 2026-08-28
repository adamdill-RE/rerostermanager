<?php

declare(strict_types=1);

/**
 * Dropped Members (Phase 8.5) — who fell off the roster, in your own scope.
 *
 * Read-only. Purging and restoring live on the Admin screen behind the typed
 * confirmation; what this screen is for is knowing, so somebody can ring the
 * person and find out whether they actually left. The contact actions are
 * therefore the point of the row, not decoration.
 *
 * The same responsive transformation as every other roster screen (spec 8.2):
 * a stacked card below 720px, a real table above it, one template.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $year    active show year (id, label, is_open)
 * @var array<string, mixed>  $dropped everything DroppedPage::page() decided
 */

use Rerm\View;

$number = static fn (int $n): string => number_format($n);

$href = static function (array $overrides = []) use ($app, $dropped): string {
    $params = array_filter([
        'sort' => $dropped['sort'] !== Rerm\Roster\DroppedPage::DEFAULT_SORT ? (string) $dropped['sort'] : '',
        'dir'  => $dropped['dir'] !== Rerm\Roster\DroppedPage::DEFAULT_DIR ? (string) $dropped['dir'] : '',
        'page' => $dropped['page'] > 1 ? (string) $dropped['page'] : '',
        'size' => $dropped['size'] !== $dropped['size_default'] ? (string) $dropped['size'] : '',
    ], static fn (string $v): bool => $v !== '');

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }

    $query = http_build_query($params);

    return $app->url('dropped') . ($query === '' ? '' : '?' . $query);
};

/** A sortable column heading, carrying the direction it would switch to. */
$sortHeader = static function (string $key, string $label) use ($dropped, $href): string {
    $current = $dropped['sort'] === $key;
    $next    = $current && $dropped['dir'] === 'desc' ? 'asc' : 'desc';
    $arrow   = $current ? ($dropped['dir'] === 'desc' ? ' &darr;' : ' &uarr;') : '';

    return '<a href="' . e($href(['sort' => $key, 'dir' => $next, 'page' => null])) . '">'
        . e($label) . $arrow . '</a>';
};
?>
<h1>Dropped Members</h1>
<p class="lede">
    People the last roster import did not list. They are hidden from every other
    screen, which is why they get one of their own.
</p>

<div class="card">
    <span class="chip chip-warn">Dropped is not removed</span>
    <p>
        A drop means one import file did not mention somebody &mdash; not that they
        have left. A team-mode import lists one team, and anyone who reappears in a
        later roster is picked back up automatically. Nothing of theirs has gone:
        their contact history, metrics and assignments are all still here.
    </p>
    <p>
        If you know one of these people has left, tell an Admin &mdash; only they can
        purge, and that is a separate, confirmed step.
    </p>
</div>

<?php if ($dropped['total'] === 0) { ?>
    <div class="card">
        <p>
            Nobody in your scope has been dropped. Every member you can see was in the
            last import that covered them.
        </p>
    </div>
<?php } else { ?>

<p class="lede">
    Showing <?= e($number((int) $dropped['from'])) ?>&ndash;<?= e($number((int) $dropped['to'])) ?>
    of <?= e($number((int) $dropped['total'])) ?>
    <?= $dropped['total'] === 1 ? 'member' : 'members' ?>
    <?php if ($dropped['pages'] > 1) { ?>
        &middot; page <?= e($number((int) $dropped['page'])) ?> of <?= e($number((int) $dropped['pages'])) ?>
    <?php } ?>
    &middot;
    <?php if ($dropped['size'] === $dropped['size_default']) { ?>
        <a href="<?= e($href(['size' => $dropped['size_large'], 'page' => null])) ?>">Show <?= e((string) $dropped['size_large']) ?> per page</a>
    <?php } else { ?>
        <a href="<?= e($href(['size' => null, 'page' => null])) ?>">Show <?= e((string) $dropped['size_default']) ?> per page</a>
    <?php } ?>
</p>

<table class="roster">
    <thead>
        <tr>
            <th><?= $sortHeader('name', 'Name') ?></th>
            <th><?= $sortHeader('team', 'Team') ?></th>
            <th><?= $sortHeader('dropped', 'Dropped by') ?></th>
            <th class="num">Contacts</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($dropped['rows'] as $row) { ?>
        <tr>
            <td class="who" data-label="Name">
                <?= e((string) $row['name']) ?>
                <span class="sub">
                    <?= e((string) $row['member_number']) ?>
                    <?php if ($row['title'] !== '') { ?>
                        &middot; <?= e((string) $row['title']) ?>
                    <?php } ?>
                </span>
            </td>
            <td data-label="Team">
                <?= e($row['team_name'] === '' ? '(No team)' : (string) $row['team_name']) ?>
                <span class="sub"><?= e((string) $row['division_name']) ?></span>
            </td>
            <td data-label="Dropped by">
                <?php if ($row['batch_id'] !== null) { ?>
                    Import #<?= e((string) $row['batch_id']) ?>
                    <span class="sub">
                        <?php if ($row['dropped_at'] !== null) {
                            [$words, $absolute] = View::when($app, (string) $row['dropped_at']); ?>
                            <span title="<?= e($absolute) ?>"><?= e($words) ?></span>
                            &middot;
                        <?php } ?>
                        <?= e((string) $row['batch_mode']) ?> mode
                    </span>
                <?php } else { ?>
                    &mdash;
                <?php } ?>
            </td>
            <td class="num" data-label="Contacts"><?= e($number((int) $row['contact_count'])) ?></td>
            <?php
            // Absent, never disabled (spec 8.4): a greyed button invites a
            // tap that does nothing. Text only for a CELL PHONE — 116 members
            // hold numbers a text silently fails against.
            echo '<td class="actions" data-label="Actions">';
            if ($row['can_call']) {
                echo '<a href="tel:', e($row['phone_e164']), '">Call</a>';
            }
            if ($row['can_text']) {
                echo '<a href="sms:', e($row['phone_e164']), '">Text</a>';
            }
            if ($row['can_email']) {
                echo '<a href="mailto:', e($row['email']), '">Email</a>';
            }
            echo '</td>';
            ?>
        </tr>
    <?php } ?>
    </tbody>
</table>

<?php if ($dropped['pages'] > 1) { ?>
    <p class="lede">
        <?php if ($dropped['page'] > 1) { ?>
            <a href="<?= e($href(['page' => $dropped['page'] - 1])) ?>">&larr; Previous</a>
        <?php } ?>
        <?php if ($dropped['page'] < $dropped['pages']) { ?>
            <a href="<?= e($href(['page' => $dropped['page'] + 1])) ?>">Next &rarr;</a>
        <?php } ?>
    </p>
<?php } ?>

<?php } ?>

<p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
