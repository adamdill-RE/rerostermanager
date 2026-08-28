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

/** @var array<int, array{cap: Capability, label: string, route: ?string, phase: string}> $tiles */
$tiles = [
    ['cap' => Capability::ViewStatusDashboard,   'label' => 'My Roster Status',    'route' => 'dashboard', 'phase' => ''],
    ['cap' => Capability::ViewRoster,            'label' => 'View My Roster',      'route' => 'roster', 'phase' => ''],
    ['cap' => Capability::AssignOfficers,        'label' => 'Assign Officers',     'route' => 'assign', 'phase' => ''],
    ['cap' => Capability::ViewCommitteeDashboard, 'label' => 'Committee Dashboard', 'route' => 'committee', 'phase' => ''],
    ['cap' => Capability::ViewRoster,            'label' => 'Dropped Members',     'route' => 'dropped', 'phase' => ''],
    ['cap' => Capability::ImportRoster,          'label' => 'Import Roster',       'route' => 'import', 'phase' => ''],
    ['cap' => Capability::ImportRoster,          'label' => 'Import History',      'route' => 'import-history', 'phase' => ''],
    ['cap' => Capability::ImportContactHistory,  'label' => 'Import Contact History', 'route' => 'import-contacts', 'phase' => ''],
    ['cap' => Capability::ExportRoster,          'label' => 'Export Roster',       'route' => 'export', 'phase' => ''],
    ['cap' => Capability::CreateForms,           'label' => 'Create Forms',        'route' => 'forms', 'phase' => ''],
    ['cap' => Capability::ManageShowYear,        'label' => 'Show Year',           'route' => 'show-year', 'phase' => ''],
    ['cap' => Capability::DesignateAllowedUser,  'label' => 'Designate Users',     'route' => 'designate', 'phase' => ''],
    ['cap' => Capability::ImportRoster,          'label' => 'Flagged for Purge',   'route' => 'purge',  'phase' => ''],
    ['cap' => Capability::ManageTeams,           'label' => 'Manage Teams',        'route' => 'teams',  'phase' => ''],
    ['cap' => Capability::ViewAuditLog,          'label' => 'Audit Log',           'route' => 'audit',  'phase' => ''],
];
?>
<h1>Menu</h1>
<p class="lede">
    Signed in as <?= e($user->displayName) ?> —
    <?= e($user->level->label()) ?>, member number <?= e($user->memberNumber) ?>.
</p>

<?php foreach ($tiles as $tile) { ?>
    <?php if (!Access::mayUse($user, $tile['cap'])) { continue; } ?>
    <div class="card">
        <?php if ($tile['route'] !== null) { ?>
            <h2><a href="<?= e($app->url($tile['route'])) ?>"><?= e($tile['label']) ?></a></h2>
        <?php } else { ?>
            <h2><?= e($tile['label']) ?></h2>
            <span class="why">Arrives with <?= e($tile['phase']) ?>.</span>
        <?php } ?>
    </div>
<?php } ?>

<div class="card">
    <h2><a href="<?= e($app->url('password')) ?>">Change password</a></h2>
</div>

<form method="post" action="<?= e($app->url('logout')) ?>">
    <?= Rerm\Csrf::field() ?>
    <button type="submit" class="quiet">Sign out</button>
</form>
