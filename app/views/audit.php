<?php

declare(strict_types=1);

/**
 * The Audit Log (spec 7.5) — filterable by actor, action and date.
 *
 * READ-ONLY, in every sense: no form that writes, no CSRF token, no POST.
 * An audit row is append-only and outlives whatever it describes, and a log
 * somebody can edit answers no question worth asking. The filter form is a
 * GET, so a link to "everything Rivera did in February" is shareable.
 *
 * The payloads are behind a <details> per row rather than printed inline. A
 * page of fifty entries with two JSON blobs each would be 40KB of monospace
 * that ninety-five percent of readers scroll past, against a 100KB first-paint
 * budget (spec 10) — and the one row somebody is actually investigating is
 * one click away.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<string, mixed>  $audit everything AuditPage::page() decided
 */

use Rerm\View;

$number = static fn (int $n): string => number_format($n);

$href = static function (array $overrides = []) use ($app, $audit): string {
    $params = array_filter([
        'actor'  => (string) $audit['actor'],
        'action' => (string) $audit['action'],
        'from'   => (string) $audit['from'],
        'to'     => (string) $audit['to'],
        'page'   => $audit['page'] > 1 ? (string) $audit['page'] : '',
        'size'   => $audit['size'] !== $audit['size_default'] ? (string) $audit['size'] : '',
    ], static fn (string $v): bool => $v !== '');

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }

    $query = http_build_query($params);

    return $app->url('audit') . ($query === '' ? '' : '?' . $query);
};

$filtered = $audit['actor'] !== '' || $audit['action'] !== ''
    || $audit['from'] !== '' || $audit['to'] !== '';
?>
<h1>Audit Log</h1>
<p class="lede">
    Every grant, import, purge, password reset and export, with who did it and
    when. Nothing here can be edited or removed &mdash; that is the point of it.
    Times are UTC.
</p>

<form method="get" action="<?= e($app->url('audit')) ?>">
    <label for="actor">Who</label>
    <select id="actor" name="actor">
        <option value="">Anyone</option>
        <option value="none"<?= $audit['actor'] === 'none' ? ' selected' : '' ?>>
            No signed-in user
        </option>
        <?php foreach ($audit['actors'] as $actor) { ?>
            <option value="<?= e((string) $actor['id']) ?>"
                <?= $audit['actor'] === (string) $actor['id'] ? ' selected' : '' ?>>
                <?= e((string) $actor['name']) ?>
                (<?= e($number((int) $actor['entries'])) ?>)
            </option>
        <?php } ?>
    </select>

    <label for="action">What</label>
    <select id="action" name="action">
        <option value="">Anything</option>
        <?php foreach ($audit['actions'] as $value => $label) { ?>
            <option value="<?= e((string) $value) ?>"
                <?= $audit['action'] === (string) $value ? ' selected' : '' ?>>
                <?= e($label) ?>
            </option>
        <?php } ?>
    </select>

    <label for="from">From</label>
    <input type="date" id="from" name="from" value="<?= e((string) $audit['from']) ?>">

    <label for="to">To</label>
    <input type="date" id="to" name="to" value="<?= e((string) $audit['to']) ?>">

    <button type="submit">Filter</button>
</form>

<?php if ($filtered) { ?>
    <p class="hint"><a href="<?= e($app->url('audit')) ?>">Clear every filter</a></p>
<?php } ?>

<?php if ($audit['total'] === 0) { ?>
    <div class="card">
        <p>
            <?php if ($filtered) { ?>
                Nothing matches those filters.
            <?php } else { ?>
                The audit log is empty. Nothing recordable has happened yet.
            <?php } ?>
        </p>
    </div>
<?php } else { ?>

<p class="lede">
    Showing <?= e($number((int) $audit['from_row'])) ?>&ndash;<?= e($number((int) $audit['to_row'])) ?>
    of <?= e($number((int) $audit['total'])) ?> entries
    <?php if ($audit['pages'] > 1) { ?>
        &middot; page <?= e($number((int) $audit['page'])) ?> of <?= e($number((int) $audit['pages'])) ?>
    <?php } ?>
    &middot;
    <?php if ($audit['size'] === $audit['size_default']) { ?>
        <a href="<?= e($href(['size' => $audit['size_large'], 'page' => null])) ?>">Show <?= e((string) $audit['size_large']) ?> per page</a>
    <?php } else { ?>
        <a href="<?= e($href(['size' => null, 'page' => null])) ?>">Show <?= e((string) $audit['size_default']) ?> per page</a>
    <?php } ?>
</p>

<table>
    <thead>
        <tr>
            <th>When</th>
            <th>Who</th>
            <th>What</th>
            <th>On</th>
            <th>Detail</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($audit['rows'] as $row) { ?>
        <?php [$words, $absolute] = View::when($app, (string) $row['occurred_at']); ?>
        <tr>
            <td data-label="When">
                <span title="<?= e($absolute) ?>"><?= e($words) ?></span>
                <span class="sub"><?= e((string) $row['occurred_at']) ?> UTC</span>
            </td>
            <td data-label="Who">
                <?php if ($row['actor'] !== null) { ?>
                    <?= e((string) $row['actor']) ?>
                    <span class="sub"><?= e((string) $row['actor_number']) ?></span>
                <?php } else { ?>
                    <span class="chip chip-muted">Nobody signed in</span>
                <?php } ?>
                <?php if ($row['ip'] !== '') { ?>
                    <span class="sub mono"><?= e((string) $row['ip']) ?></span>
                <?php } ?>
            </td>
            <td data-label="What"><?= e((string) $row['action_word']) ?></td>
            <td data-label="On">
                <?php if ($row['entity'] !== '') { ?>
                    <?= e((string) $row['entity']) ?>
                    <?php if ($row['entity_id'] !== '') { ?>
                        <span class="mono"><?= e((string) $row['entity_id']) ?></span>
                    <?php } ?>
                <?php } else { ?>
                    &mdash;
                <?php } ?>
            </td>
            <td data-label="Detail">
                <?php if ($row['before'] === '' && $row['after'] === '') { ?>
                    &mdash;
                <?php } else { ?>
                    <details>
                        <summary>Show</summary>
                        <?php if ($row['before'] !== '') { ?>
                            <p class="hint">Before</p>
                            <pre class="mono"><?= e((string) $row['before']) ?></pre>
                        <?php } ?>
                        <?php if ($row['after'] !== '') { ?>
                            <p class="hint">After</p>
                            <pre class="mono"><?= e((string) $row['after']) ?></pre>
                        <?php } ?>
                    </details>
                <?php } ?>
            </td>
        </tr>
    <?php } ?>
    </tbody>
</table>

<?php if ($audit['pages'] > 1) { ?>
    <p class="lede">
        <?php if ($audit['page'] > 1) { ?>
            <a href="<?= e($href(['page' => $audit['page'] - 1])) ?>">&larr; Newer</a>
        <?php } ?>
        <?php if ($audit['page'] < $audit['pages']) { ?>
            <a href="<?= e($href(['page' => $audit['page'] + 1])) ?>">Older &rarr;</a>
        <?php } ?>
    </p>
<?php } ?>

<?php } ?>

<p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
