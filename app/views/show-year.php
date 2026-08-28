<?php

declare(strict_types=1);

/**
 * Show Year (spec 5.1, Phase 8 decided 1 and 5) — create, set active,
 * open/close, and carry assignments into a new year.
 *
 * Two things this screen says out loud rather than enforcing silently:
 *
 *   * Closing WARNS and then closes (decided 1). The number of metrics still
 *     mid-chase is printed before the confirm, because a metric stuck at
 *     "they said they would pay" is the normal end-of-year state and refusing
 *     to close would mean faking edits to be allowed to.
 *   * The rollover reports BOTH numbers before it runs (decided 5): how many
 *     assignments carry, and how many are dropped because their officer no
 *     longer qualifies. Those members arrive in the new year unassigned, in
 *     the bucket somebody already works.
 *
 * @var Rerm\App              $app
 * @var Rerm\Auth\User        $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed>  $showYear everything the handler decided
 */

use Rerm\Admin\ShowYears;
use Rerm\Csrf;

$number = static fn (int $n): string => number_format($n);
$years  = $showYear['years'];
$word   = ShowYears::CONFIRM_WORD;
?>
<h1>Show Year</h1>
<p class="lede">
    Metrics, contacts and assignments are all keyed to a show year. Exactly one
    is active at a time &mdash; that is the year every officer sees.
</p>

<?php foreach ($notices as [$level, $message]) { ?>
    <div class="card">
        <span class="chip chip-<?= e($level === 'ok' ? 'ok' : ($level === 'warn' ? 'warn' : 'danger')) ?>">
            <?= e($level === 'ok' ? 'Done' : ($level === 'warn' ? 'Note' : 'Stopped')) ?>
        </span>
        <span><?= e($message) ?></span>
    </div>
<?php } ?>

<table>
    <caption>Every show year, active first.</caption>
    <thead>
        <tr>
            <th>Year</th>
            <th>State</th>
            <th class="num">Members</th>
            <th class="num">Assignments</th>
            <th class="num">Contacts</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($years as $row) { ?>
        <tr>
            <td data-label="Year">
                <strong><?= e((string) $row['label']) ?></strong>
                <?php if ($row['starts_on'] !== null || $row['ends_on'] !== null) { ?>
                    <span class="sub">
                        <?= e((string) ($row['starts_on'] ?? '?')) ?>
                        &ndash; <?= e((string) ($row['ends_on'] ?? '?')) ?>
                    </span>
                <?php } ?>
            </td>
            <td data-label="State">
                <?php if ($row['is_active']) { ?>
                    <span class="chip chip-ok">Active</span>
                <?php } ?>
                <?php if ($row['is_open']) { ?>
                    <span class="chip chip-info">Open</span>
                <?php } else { ?>
                    <span class="chip chip-muted">Closed</span>
                <?php } ?>
            </td>
            <td class="num" data-label="Members"><?= e($number((int) $row['members'])) ?></td>
            <td class="num" data-label="Assignments"><?= e($number((int) $row['assignments'])) ?></td>
            <td class="num" data-label="Contacts"><?= e($number((int) $row['contacts'])) ?></td>
            <td data-label="Actions">
                <?php if (!$row['is_active']) { ?>
                    <form method="post" action="<?= e($app->url('show-year')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="year_id" value="<?= e((string) $row['id']) ?>">
                        <button type="submit" class="quiet">Make active</button>
                    </form>
                <?php } ?>

                <?php if ($row['is_open']) { ?>
                    <details>
                        <summary>Close <?= e((string) $row['label']) ?></summary>
                        <form method="post" action="<?= e($app->url('show-year')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="close">
                            <input type="hidden" name="year_id" value="<?= e((string) $row['id']) ?>">
                            <p class="hint">
                                <?php if ($row['in_progress'] > 0) { ?>
                                    <strong><?= e($number((int) $row['in_progress'])) ?></strong>
                                    metric<?= $row['in_progress'] === 1 ? '' : 's' ?>
                                    <?= $row['in_progress'] === 1 ? 'is' : 'are' ?> still mid-chase.
                                    Closing freezes <?= $row['in_progress'] === 1 ? 'it' : 'them' ?>
                                    exactly as <?= $row['in_progress'] === 1 ? 'it is' : 'they are' ?> —
                                    that is the normal end of a year, not a problem to tidy up first.
                                <?php } else { ?>
                                    No metric is mid-chase.
                                <?php } ?>
                                Nothing is deleted: contacts, assignments and metrics all stay,
                                and a closed year can be re-opened and is still exportable.
                            </p>
                            <label for="close-<?= e((string) $row['id']) ?>">
                                Type <code><?= e($word) ?></code> to close it
                            </label>
                            <input type="text" id="close-<?= e((string) $row['id']) ?>" name="confirm"
                                   value="" autocomplete="off" spellcheck="false" size="10">
                            <button type="submit">Close <?= e((string) $row['label']) ?></button>
                        </form>
                    </details>
                <?php } else { ?>
                    <form method="post" action="<?= e($app->url('show-year')) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="open">
                        <input type="hidden" name="year_id" value="<?= e((string) $row['id']) ?>">
                        <button type="submit" class="quiet">Re-open</button>
                    </form>
                <?php } ?>
            </td>
        </tr>
    <?php } ?>
    </tbody>
</table>

<div class="card">
    <h2>Create a show year</h2>
    <p class="hint">
        A new year is created open and <em>not</em> active. Making it active is a
        second, deliberate step &mdash; it is what every officer&rsquo;s dashboard
        switches to.
    </p>
    <form method="post" action="<?= e($app->url('show-year')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">

        <label for="label">Name</label>
        <input type="text" id="label" name="label" value="" maxlength="32"
               autocomplete="off" placeholder="2028" required>

        <label for="starts_on">Starts (optional)</label>
        <input type="date" id="starts_on" name="starts_on" value="">

        <label for="ends_on">Ends (optional)</label>
        <input type="date" id="ends_on" name="ends_on" value="">

        <button type="submit">Create</button>
    </form>
</div>

<?php if (count($years) > 1) { ?>
<div class="card">
    <h2>Carry assignments forward</h2>
    <p class="hint">
        Officers rarely change wholesale, so a new year starts from last year&rsquo;s
        assignments rather than from nothing. Only assignments whose officer
        <em>still qualifies</em> are carried: still on the member&rsquo;s team, still
        an Officer or above, still on the roster. The rest are dropped, and those
        members arrive unassigned &mdash; in the bucket somebody already works,
        rather than as invisible cleanup.
    </p>
    <p class="hint">
        Metrics and contacts do not carry. Last year&rsquo;s dues and last
        year&rsquo;s phone calls say nothing about this year.
    </p>

    <form method="get" action="<?= e($app->url('show-year')) ?>">
        <label for="from_year">Carry from</label>
        <select id="from_year" name="from_year">
            <?php foreach ($years as $row) { ?>
                <option value="<?= e((string) $row['id']) ?>"
                    <?= (int) $row['id'] === (int) $showYear['from_year'] ? ' selected' : '' ?>>
                    <?= e((string) $row['label']) ?>
                </option>
            <?php } ?>
        </select>

        <label for="to_year">Carry into</label>
        <select id="to_year" name="to_year">
            <?php foreach ($years as $row) { ?>
                <?php if (!$row['is_open']) { continue; } ?>
                <option value="<?= e((string) $row['id']) ?>"
                    <?= (int) $row['id'] === (int) $showYear['to_year'] ? ' selected' : '' ?>>
                    <?= e((string) $row['label']) ?>
                </option>
            <?php } ?>
        </select>
        <button type="submit" class="quiet">Show me what would happen</button>
    </form>

    <?php if ($showYear['preview'] !== null) { ?>
        <p>
            <strong><?= e($number((int) $showYear['preview']['carry'])) ?></strong>
            assignment<?= (int) $showYear['preview']['carry'] === 1 ? '' : 's' ?> would carry.
            <strong><?= e($number((int) $showYear['preview']['drop'])) ?></strong>
            would be dropped because the officer no longer qualifies.
        </p>

        <?php if ((int) $showYear['preview']['carry'] > 0 || (int) $showYear['preview']['drop'] > 0) { ?>
            <form method="post" action="<?= e($app->url('show-year')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="carry">
                <input type="hidden" name="from_year" value="<?= e((string) $showYear['from_year']) ?>">
                <input type="hidden" name="to_year" value="<?= e((string) $showYear['to_year']) ?>">

                <label for="carry-confirm">Type <code><?= e($word) ?></code> to carry them</label>
                <input type="text" id="carry-confirm" name="confirm" value=""
                       autocomplete="off" spellcheck="false" size="10">
                <button type="submit">Carry assignments forward</button>
            </form>
        <?php } else { ?>
            <p class="hint">There is nothing to carry between those two years.</p>
        <?php } ?>
    <?php } ?>
</div>
<?php } ?>

<p><a href="<?= e($app->url('menu')) ?>">Back to the menu</a></p>
