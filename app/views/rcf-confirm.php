<?php

declare(strict_types=1);

/**
 * The second look (Phase 14.7, spec-v2 §21.3): a row that names no team
 * anywhere, or a team change with no destination, is shown back with what
 * the roster says the member's team is, and the officer says whether that
 * is what they meant — before the form is built or a request is written.
 *
 * It is here to catch one mistake: somebody who thinks they are asking for
 * a team change and has not written the team. There is no JavaScript in
 * this application (the CSP forbids it), so the "popup" is a server round
 * trip: the POST comes back with the grid intact and this card above it,
 * and the confirming button carries a key for exactly these rows
 * (`RcfPage::confirmKey()`). Edit a row and the key no longer fits, so the
 * question is asked again about the row as it now is.
 *
 * Drawn INSIDE the screen's <form>, after the sub-committee card, by the
 * two grid screens. The confirming button is the screen's own action with
 * the key beside it; "go back" is the Load button, which re-renders with
 * everything still typed in. Every rendered value goes through e().
 *
 * @var Rerm\App                              $app
 * @var array<int, array<string, string>>     $confirmRows  RcfPage::formFromInput()['confirm']
 * @var string                                $confirmKey   RcfPage::formFromInput()['confirm_key']
 * @var string                                $confirmAction the screen's own submit value
 * @var string                                $confirmLabel  what the confirming button says
 */
?>
    <section class="card confirm" role="alertdialog" aria-labelledby="confirm-teams" id="confirm">
        <h2 id="confirm-teams">Check the team before you go on</h2>
        <p>
            <?= count($confirmRows) === 1 ? 'This row does not' : 'These rows do not' ?> say which
            team <?= count($confirmRows) === 1 ? 'the member is' : 'the members are' ?> on, or
            which team they are moving to. If you meant a <strong>team change</strong>, go back
            and pick the new team under New sub-committee. If not, confirm that what the roster
            says is right.
        </p>
        <ul class="rows">
            <?php foreach ($confirmRows as $row) { ?>
                <li>
                    <strong><?= e((string) $row['member_name']) ?></strong>
                    <?php if ((string) $row['member_number'] !== '') { ?><span class="why mono"><?= e((string) $row['member_number']) ?></span><?php } ?>
                    &mdash; <?= e((string) $row['type_label']) ?>
                    <span class="why">
                        <?php if ((string) $row['on_roster'] === '') { ?>
                            not on the roster you can see, so there is no team to show
                        <?php } elseif ((string) $row['roster_team'] === '') { ?>
                            on the roster with no team
                        <?php } else { ?>
                            on the roster as <strong><?= e((string) $row['roster_team']) ?></strong>
                        <?php } ?>
                        <?php if (in_array((string) $row['type'], ['S', 'S & T'], true)) { ?>
                            &middot; a team change with no new team given
                        <?php } ?>
                    </span>
                </li>
            <?php } ?>
        </ul>
        <input type="hidden" name="confirm_teams" value="<?= e($confirmKey) ?>">
        <div class="cards">
            <div>
                <button type="submit" name="action" value="<?= e($confirmAction) ?>" autofocus><?= e($confirmLabel) ?></button>
            </div>
            <div>
                <button type="submit" name="action" value="load" class="quiet">Go back and change the rows</button>
            </div>
        </div>
    </section>
