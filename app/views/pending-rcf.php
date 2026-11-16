<?php

declare(strict_types=1);

/**
 * The people a team is waiting for (Phase 14.8, spec-v2 §22.2) — everyone
 * asked for on a Roster Change Form as an addition to the team on the
 * screen, whom Rodeo Houston's roster does not list yet. ONE partial,
 * drawn by View My Roster and My Roster Status after their lists, so the
 * two screens cannot draw a ghost two ways.
 *
 * A ghost reads as one on sight and in words: the row is dashed and muted
 * (layout.php, .ghosts), and the chip says "Pending RCF" beside the name,
 * because nothing in this application is ever distinguishable by hue
 * alone. It carries no metric chips, no contact and no actions — there is
 * nothing to chase and nobody to ring until an import lists them — and
 * the heading says they are counted nowhere above.
 *
 * Nothing here is rendered when there is nobody pending: a team with no
 * one on the way gets no empty section to read past.
 *
 * @var Rerm\App                           $app
 * @var Rerm\Auth\User                     $user
 * @var array<int, array<string, mixed>>   $pending PendingAdditions::forTeams()
 */

use Rerm\View;

if ($pending === []) {
    return;
}

$number = static fn (int $n): string => number_format($n);
$count  = count($pending);
?>
<section class="ghosts" aria-labelledby="ghosts-heading">
    <h2 id="ghosts-heading">
        Pending on an RCF &middot; <?= e($number($count)) ?>
    </h2>
    <p class="hint">
        Asked for on a Roster Change Form as an addition to this team, and not yet
        on Rodeo Houston&rsquo;s roster. Not counted above, and nothing can be
        logged against them until an import lists them.
    </p>
    <table class="roster">
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Team</th>
                <th scope="col">Asked for</th>
                <th scope="col">Where it stands</th>
            </tr>
        </thead>
<?php
foreach ($pending as $ghost) {
    echo '<tbody class="member ghost" id="g', e(str_replace(':', '-', (string) $ghost['key'])), '"><tr class="entry">';

    // The name, as the roster would print it, with the word beside it.
    echo '<td class="who">', e($ghost['name'] !== '' ? $ghost['name'] : ($ghost['member_number'] !== '' ? $ghost['member_number'] : 'Name not given')),
        ' <span class="chip chip-warn"><span class="chip-word">Pending RCF</span></span>',
        '<span class="sub">',
        $ghost['member_number'] !== '' ? e($ghost['member_number']) : 'no member number yet',
        $ghost['rookie'] ? ' &middot; rookie' : '',
        '</span></td>';

    echo '<td data-label="Team">', e($ghost['team_name']), '</td>';

    echo '<td data-label="Asked for">',
        $ghost['title'] !== '' ? e($ghost['title']) : '<span class="why">no title on the form</span>',
        '<span class="sub">', View::time($app, (string) $ghost['asked_at']), '</span></td>';

    echo '<td data-label="Where it stands">', e($ghost['where']);
    if ($ghost['link'] === 'rcf' && $ghost['rcf_id'] !== null) {
        echo ' <a href="', e($app->url('rcf') . '?id=' . (int) $ghost['rcf_id']), '">Open the RCF</a>';
    } elseif ($ghost['link'] === 'rcf-entries') {
        echo ' <a href="', e($app->url('rcf-entries')), '">Your requests</a>';
    } elseif ($ghost['link'] === 'rcf-approvals') {
        echo ' <a href="', e($app->url('rcf-approvals')), '">Waiting for you</a>';
    }
    echo '</td></tr></tbody>', "\n";
}
?>
    </table>
</section>
