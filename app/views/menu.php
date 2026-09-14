<?php

declare(strict_types=1);

use Rerm\Auth\Access;
use Rerm\Auth\Capability;

/**
 * The menu (spec 7.0) — its own route since Phase 5 moved the landing page
 * to My Roster Status. It still lands here for a signed-in user whose level
 * has no dashboard (a future Member-level Allowed User).
 *
 * Tiles are FILTERED by capability, and that is presentation only: every
 * target re-checks server-side through the same Access call, and a screen
 * that has not shipped yet is simply not a route, so its tile carries no
 * link. Hiding a tile hides nothing.
 *
 * @var Rerm\App       $app
 * @var Rerm\Auth\User $user
 */

/**
 * The tiles, grouped by the job they serve (Phase 10.4, spec-v2 §9.8):
 * Chase is the officer's loop, Lead is the desk, Administer is the Admin's
 * once-a-month. Fifteen identical cards in one column were a list to read;
 * three headings are a shape to recognise. Each tile stays one line, with
 * its route, because tests read the menu a line at a time.
 *
 * @var array<int, array{cap: Capability, label: string, route: ?string, phase: string, group: string, why: string}> $tiles
 */
$tiles = [
    ['cap' => Capability::ViewStatusDashboard,   'label' => 'My Roster Status',    'route' => 'dashboard', 'phase' => '', 'group' => 'chase', 'why' => 'Current status for your members and a button that dials them.'],
    ['cap' => Capability::ViewRoster,            'label' => 'View My Roster',      'route' => 'roster', 'phase' => '', 'group' => 'chase', 'why' => 'Everyone you can see, searched by name or number, with their history.'],
    ['cap' => Capability::ViewRoster,            'label' => 'Dropped Members',     'route' => 'dropped', 'phase' => '', 'group' => 'chase', 'why' => 'Members dropped from your Roster this show year.'],
    ['cap' => Capability::AssignOfficers,        'label' => 'Assign Officers',     'route' => 'assign', 'phase' => '', 'group' => 'lead', 'why' => 'Assign Officers to call members.'],
    ['cap' => Capability::ViewCommitteeDashboard, 'label' => 'Committee Dashboard', 'route' => 'committee', 'phase' => '', 'group' => 'lead', 'why' => 'Every division, area and team rolled up, sorted by where nobody is working.'],
    ['cap' => Capability::CreateForms,           'label' => 'Create Forms',        'route' => 'forms', 'phase' => '', 'group' => 'lead', 'why' => 'The committee’s paperwork, filled in from the roster and downloaded.'],
    ['cap' => Capability::CreateForms,           'label' => 'Track RCFs',          'route' => 'rcfs', 'phase' => '', 'group' => 'lead', 'why' => 'Every Roster Change Form made here, its RCF number, and where each line has got to.'],
    ['cap' => Capability::ExportRoster,          'label' => 'Export Roster',       'route' => 'export', 'phase' => '', 'group' => 'lead', 'why' => 'The members you can see, as a spreadsheet, for one show year.'],
    ['cap' => Capability::ImportRoster,          'label' => 'Import Roster',       'route' => 'import', 'phase' => '', 'group' => 'administer', 'why' => 'Rodeo Houston’s file, diffed before a row is written.'],
    ['cap' => Capability::ImportRoster,          'label' => 'Import History',      'route' => 'import-history', 'phase' => '', 'group' => 'administer', 'why' => 'What every import changed, and when a member disappeared.'],
    ['cap' => Capability::ImportContactHistory,  'label' => 'Import Contact History', 'route' => 'import-contacts', 'phase' => '', 'group' => 'administer', 'why' => 'Contacts made before the application existed, on their real dates.'],
    ['cap' => Capability::ImportRoster,          'label' => 'Flagged for Purge',   'route' => 'purge',  'phase' => '', 'group' => 'administer', 'why' => 'Members an import did not see; purge the ones you know have gone.'],
    ['cap' => Capability::DesignateAllowedUser,  'label' => 'Designate Users',     'route' => 'designate', 'phase' => '', 'group' => 'administer', 'why' => 'Give a member a level, a scope, or a fresh password.'],
    ['cap' => Capability::ManageTeams,           'label' => 'Manage Teams',        'route' => 'teams',  'phase' => '', 'group' => 'administer', 'why' => 'Which area a team groups under on the Committee Dashboard.'],
    ['cap' => Capability::ManageShowYear,        'label' => 'Show Year',           'route' => 'show-year', 'phase' => '', 'group' => 'administer', 'why' => 'Create, activate, close and carry assignments forward.'],
    ['cap' => Capability::ViewAuditLog,          'label' => 'Audit Log',           'route' => 'audit',  'phase' => '', 'group' => 'administer', 'why' => 'Every grant, import, purge and reset, with who and when.'],
];

// The headings are the owner's words (Phase 10.4 fit): what the group is
// FOR, in the committee's own vocabulary, with no line under them.
$groups = [
    'chase'      => ['This Show Year’s To-Do Items:', ''],
    'lead'       => ['Team Functions:', ''],
    'administer' => ['Administer', ''],
];
?>
<h1>Menu</h1>
<p class="lede">
    Signed in as <?= e($user->displayName) ?> —
    <?= e($user->level->label()) ?>, member number <?= e($user->memberNumber) ?>.
</p>

<?php foreach ($groups as $groupKey => [$groupWord, $groupWhy]) {
    $shown = array_filter($tiles, static fn (array $t): bool => $t['group'] === $groupKey && Access::mayUse($user, $t['cap']));
    if ($shown === []) {
        continue;
    }
?>
    <h2 class="menu-group"><?= e($groupWord) ?><?php if ($groupWhy !== '') { ?> <span class="why"><?= e($groupWhy) ?></span><?php } ?></h2>
    <ul class="menu">
        <?php foreach ($shown as $tile) { ?>
            <li>
                <?php if ($tile['route'] !== null) { ?>
                    <a href="<?= e($app->url($tile['route'])) ?>">
                        <span class="what"><?= e($tile['label']) ?></span>
                        <span class="why"><?= e($tile['why']) ?></span>
                    </a>
                <?php } else { ?>
                    <span class="what"><?= e($tile['label']) ?></span>
                    <span class="why">Arrives with <?= e($tile['phase']) ?>.</span>
                <?php } ?>
            </li>
        <?php } ?>
    </ul>
<?php } ?>

<h2 class="menu-group">Account</h2>
<ul class="menu">
    <li><a href="<?= e($app->url('password')) ?>"><span class="what">Change password</span>
        <span class="why">Signs out every other device this account is signed in on.</span></a></li>
</ul>

<form method="post" action="<?= e($app->url('logout')) ?>">
    <?= Rerm\Csrf::field() ?>
    <button type="submit" class="quiet">Sign out</button>
</form>
