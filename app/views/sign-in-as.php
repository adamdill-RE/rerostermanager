<?php

declare(strict_types=1);

/**
 * Sign In As (Phase 14.1, spec-v2 §16) — every account an Admin may run
 * their session as, and a button per row that does it.
 *
 * ONE form for the whole table, not one per row: each button carries the
 * account id as its value, so two hundred rows cost two hundred buttons and
 * one CSRF field rather than two hundred forms — the Phase 5 budget lesson,
 * applied the way Assign applies it.
 *
 * Everything was decided in SignInAsPage; this file renders decided values
 * and asks no permission question of its own. The write path re-asks every
 * one (Rerm\Auth\Auth::signInAs), and the capability that reaches this
 * screen is re-read on every later request before the acting-as is honoured.
 *
 * @var Rerm\App             $app
 * @var Rerm\Auth\User       $user
 * @var array<int, array{0:string,1:string}> $notices
 * @var array<string, mixed> $signInAs everything SignInAsPage::page() decided
 */

use Rerm\Csrf;

$number = static fn (int $n): string => number_format($n);
?>
<h1>Sign In As</h1>
<p class="lede">
    See the application exactly as another account sees it &mdash; their tiles,
    their roster, the RCF requests waiting on them &mdash; without asking them to
    sign in. Your own account stays signed in underneath, and the bar at the top
    carries a button that brings you back to it.
</p>

<div class="card">
    <p>
        Anything you do while signed in as somebody is recorded under
        <strong>their</strong> name with your account noted beside it, and the
        audit log holds the moment you stepped in and the moment you stepped
        out. Changing their password is not offered; Designate Users resets one.
        Admin accounts are not offered: there is nothing they would show you
        that you cannot already see.
    </p>
</div>

<form method="get" action="<?= e($app->url('sign-in-as')) ?>" class="quick find">
    <label for="q">Search by name or member number</label>
    <input type="search" id="q" name="q" value="<?= e((string) $signInAs['search']) ?>"
           inputmode="search" autocomplete="off">
    <button type="submit">Search</button>
</form>

<?php if ($signInAs['search_too_short']) { ?>
    <p class="hint">
        Type at least <?= e((string) $signInAs['search_min_chars']) ?> characters to search.
        Showing every account.
    </p>
<?php } ?>

<?php if ($signInAs['total'] === 0) { ?>
    <div class="card">
        <p>
            <?php if ($signInAs['search_applied']) { ?>
                No account matches &ldquo;<?= e((string) $signInAs['search']) ?>&rdquo;.
            <?php } else { ?>
                There is no other active account to sign in as.
            <?php } ?>
        </p>
    </div>
<?php } else { ?>

<p class="lede">
    <?php if ($signInAs['total'] > $signInAs['ceiling']) { ?>
        Showing the first <?= e($number((int) $signInAs['ceiling'])) ?>
        of <?= e($number((int) $signInAs['total'])) ?> accounts &mdash; search to find the rest.
    <?php } else { ?>
        <?= e($number((int) $signInAs['total'])) ?>
        account<?= $signInAs['total'] === 1 ? '' : 's' ?>, broadest level first.
    <?php } ?>
</p>

<form method="post" action="<?= e($app->url('sign-in-as')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="start">
    <table class="roster">
        <thead>
            <tr>
                <th scope="col">Account</th>
                <th scope="col">Title</th>
                <th scope="col">Level</th>
                <th scope="col">RCF requests go to</th>
                <th scope="col">Action</th>
            </tr>
        </thead>
        <?php foreach ($signInAs['rows'] as $row) { ?>
            <tbody class="member" id="u<?= e((string) $row['id']) ?>">
            <tr>
                <td class="who" data-label="Account">
                    <span class="card-link"><?= e((string) $row['name']) ?></span>
                    <span class="sub">
                        <?= e((string) $row['member_number']) ?>
                        &middot; <?= e($row['team_name'] === '' ? '(No team)' : (string) $row['team_name']) ?>
                        <?php if ($row['division_name'] !== '') { ?>
                            &middot; <?= e((string) $row['division_name']) ?>
                        <?php } ?>
                    </span>
                </td>
                <td data-label="Title"><?= e((string) $row['title']) ?></td>
                <td data-label="Level">
                    <span class="chip chip-<?= $row['source'] === 'grant' ? 'info' : 'muted' ?>"><?= e($row['level']->label()) ?></span>
                    <span class="sub"><?= $row['source'] === 'grant' ? 'Granted' : 'Title' ?><?php
                        if ($row['must_change']) { ?> &middot; password not set<?php } ?></span>
                </td>
                <td data-label="RCF requests go to">
                    <?php if ($row['flow']['state'] === 'approver') { ?>
                        <?= e((string) $row['flow']['approver']) ?>
                    <?php } elseif ($row['flow']['state'] === 'top') { ?>
                        <span class="chip chip-ok">Top of the chain</span>
                    <?php } elseif ($row['flow']['state'] === 'unset') { ?>
                        <span class="chip chip-warn">Not set</span>
                    <?php } else { ?>
                        <span class="sub">&mdash;</span>
                    <?php } ?>
                </td>
                <td class="actions" data-label="Action">
                    <button type="submit" class="quiet" name="user_id" value="<?= e((string) $row['id']) ?>">
                        Sign in as <?= e((string) $row['name']) ?>
                    </button>
                </td>
            </tr>
            </tbody>
        <?php } ?>
    </table>
</form>

<?php } ?>
