<?php

declare(strict_types=1);

/**
 * Create Forms (spec-v2 §1) — the menu of committee paperwork this
 * application can produce.
 *
 * One entry today. It is a screen rather than a row on /menu because the
 * point of "Create Forms" is that there will be several, and because each of
 * them needs a sentence saying what it is FOR before somebody picks it: the
 * cost of choosing the wrong form is a Division Chairman sending it back.
 *
 * @var Rerm\App       $app
 * @var Rerm\Auth\User $user
 */
?>
<h1>Create Forms</h1>
<p class="lede">
    Committee paperwork, filled in from the roster you can already see and
    downloaded as a spreadsheet. The form comes out looking exactly like the
    one you fill in by hand today, so it can be sent on without explanation.
</p>

<div class="card">
    <h2><a href="<?= e($app->url('form-rcf')) ?>">Roster Change Form (RCF)</a></h2>
    <p>
        Additions, removals, title changes and team changes, for one
        sub-committee, up to twenty-five people at a time. Pick people off your
        own roster, or type in somebody who is not on it yet.
    </p>
    <p class="hint">Downloads as an <code>.xlsx</code><?php
        if (($year ?? null) !== null) { ?> for show year <?= e((string) $year['label']) ?><?php } ?>.</p>
</div>

<?php if (($last ?? null) !== null) { ?>
    <?php /* The acknowledgement a streamed download cannot give (Phase 10.4)
             — and since Phase 11 the form is kept, so the sentence has
             somewhere to go: the form's own page, where its tracking is. */ ?>
    <p class="hint">
        <span class="chip chip-ok">Your last form</span>
        Roster Change Form for <?= e((string) ($last['after']['sub_committee'] ?? '')) ?>,
        <?= e((string) (int) ($last['after']['rows'] ?? 0)) ?>
        <?= (int) ($last['after']['rows'] ?? 0) === 1 ? 'person' : 'people' ?>,
        <?= Rerm\View::time($app, $last['at']) ?>. It was logged with your name.
        <?php if ((int) ($last['after']['rcf_id'] ?? 0) > 0) { ?>
            <a href="<?= e($app->url('rcf?id=' . (int) $last['after']['rcf_id'])) ?>">Track it</a>
            &mdash; mark it sent, add the RCF number, or download it again.
        <?php } ?>
    </p>
<?php } ?>

<div class="card">
    <h2><a href="<?= e($app->url('rcfs')) ?>">Track RCFs</a></h2>
    <p>
        Every Roster Change Form made here is kept. See where each line has got
        to &mdash; sent to the Division Chairman, numbered, sent to Rosters, and
        whether the roster now shows it &mdash; find any member on any form, and
        download a form again.
    </p>
</div>

<div class="card">
    <span class="chip chip-warn">A filled-in form is personal data</span>
    <p>
        It names members and carries their member numbers, and it leaves this
        server the moment you download it. Every form is logged with your name,
        the sub-committee and the number of people on it. Send it where it
        needs to go and delete your copy.
    </p>
</div>

