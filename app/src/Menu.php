<?php

declare(strict_types=1);

namespace Rerm;

use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\User;

/**
 * The menu, written down once (Phase 14.4, spec-v2 §18).
 *
 * Two things draw it: the menu page (app/views/menu.php), which carries a
 * line under every screen saying what it is for, and the drop-down in the
 * bar of every signed-in screen (app/views/layout.php), which carries the
 * names alone. One table, two renderings — a screen added here is on both,
 * and a screen renamed here is renamed on both, so the word in the bar and
 * the word on the page can never disagree.
 *
 * The groups and their order are the owner's (spec-v2 §18.1): RCFs, Dues &
 * Compliance, Team Functions, Administer. Each tile is FILTERED by
 * capability, and that is presentation only: every target re-checks
 * server-side through the same Access call. Hiding a tile hides nothing.
 *
 * Each tile stays one line, with its route, because tests read this file a
 * line at a time.
 */
final class Menu
{
    /**
     * The groups, in the order they are drawn, keyed by the word the code
     * uses for them. The keys are internal and stable; the headings are the
     * owner's and may be renamed at will.
     *
     * @var array<string, string> key => heading
     */
    public const GROUPS = [
        'rcfs'       => 'RCFs',
        'chase'      => 'Dues & Compliance',
        'lead'       => 'Team Functions',
        'administer' => 'Administer',
    ];

    /**
     * Every screen the menu offers, in the order it offers them within each
     * group. A screen that has not shipped yet is simply not a route, so its
     * tile would carry no link; today every tile has one.
     *
     * @return array<int, array{cap: Capability, label: string, route: ?string, phase: string, group: string, why: string}>
     */
    public static function tiles(): array
    {
        return [
            ['cap' => Capability::CreateRcfEntries,      'label' => 'Create RCF Requests',  'route' => 'rcf-entries', 'phase' => '', 'group' => 'rcfs', 'why' => 'Ask for one roster change at a time; it goes up the chain for approval and onto a consolidated RCF.'],
            ['cap' => Capability::ApproveRcfEntries,     'label' => 'Approve RCF Requests', 'route' => 'rcf-approvals', 'phase' => '', 'group' => 'rcfs', 'why' => 'Requests waiting on you: approve them up the chain, or return them with a note.'],
            ['cap' => Capability::GenerateConsolidatedRcf, 'label' => 'Export Approved Requests to RCF Forms', 'route' => 'rcf-consolidate', 'phase' => '', 'group' => 'rcfs', 'why' => 'Everything approved that ended with you, onto one or more Roster Change Forms.'],
            ['cap' => Capability::CreateForms,           'label' => 'Track RCFs',          'route' => 'rcfs', 'phase' => '', 'group' => 'rcfs', 'why' => 'Every Roster Change Form made here or uploaded, its RCF number, where each line has got to, and the import that fulfilled it.'],
            ['cap' => Capability::ViewStatusDashboard,   'label' => 'My Roster Status',    'route' => 'dashboard', 'phase' => '', 'group' => 'chase', 'why' => 'Current status for your members and a button that dials them.'],
            ['cap' => Capability::ViewRoster,            'label' => 'View My Roster',      'route' => 'roster', 'phase' => '', 'group' => 'chase', 'why' => 'Everyone you can see, searched by name or number, with their history.'],
            ['cap' => Capability::ViewRoster,            'label' => 'Dropped Members',     'route' => 'dropped', 'phase' => '', 'group' => 'chase', 'why' => 'Members dropped from your Roster this show year.'],
            ['cap' => Capability::AssignOfficers,        'label' => 'Assign Officers',     'route' => 'assign', 'phase' => '', 'group' => 'lead', 'why' => 'Assign Officers to call members.'],
            ['cap' => Capability::ViewCommitteeDashboard, 'label' => 'Committee Dashboard', 'route' => 'committee', 'phase' => '', 'group' => 'lead', 'why' => 'Every division, area and team rolled up, sorted by where nobody is working.'],
            ['cap' => Capability::CreateForms,           'label' => 'Create Forms',        'route' => 'forms', 'phase' => '', 'group' => 'lead', 'why' => 'The committee’s paperwork, filled in from the roster and downloaded.'],
            ['cap' => Capability::ExportRoster,          'label' => 'Export Roster',       'route' => 'export', 'phase' => '', 'group' => 'lead', 'why' => 'The members you can see, as a spreadsheet, for one show year.'],
            ['cap' => Capability::ImportRoster,          'label' => 'Import Roster',       'route' => 'import', 'phase' => '', 'group' => 'administer', 'why' => 'Rodeo Houston’s file, diffed before a row is written.'],
            ['cap' => Capability::ImportRoster,          'label' => 'Import History',      'route' => 'import-history', 'phase' => '', 'group' => 'administer', 'why' => 'What every import changed, and when a member disappeared.'],
            ['cap' => Capability::LookUpMembers,         'label' => 'Look Up Members',     'route' => 'lookup', 'phase' => '', 'group' => 'administer', 'why' => 'Paste a list of member numbers: where each one stands, when they first appeared, and every RCF about them.'],
            ['cap' => Capability::ImportContactHistory,  'label' => 'Import Contact History', 'route' => 'import-contacts', 'phase' => '', 'group' => 'administer', 'why' => 'Contacts made before the application existed, on their real dates.'],
            ['cap' => Capability::ImportRoster,          'label' => 'Flagged for Purge',   'route' => 'purge',  'phase' => '', 'group' => 'administer', 'why' => 'Members an import did not see; purge the ones you know have gone.'],
            ['cap' => Capability::DesignateAllowedUser,  'label' => 'Designate Users',     'route' => 'designate', 'phase' => '', 'group' => 'administer', 'why' => 'Give a member a level, a scope, or a fresh password.'],
            ['cap' => Capability::ManageApprovalFlow,    'label' => 'Create Approval Flow', 'route' => 'approval-flow', 'phase' => '', 'group' => 'administer', 'why' => 'Who approves whose RCF requests, and who is at the top of each chain.'],
            ['cap' => Capability::SignInAs,              'label' => 'Sign In As',          'route' => 'sign-in-as', 'phase' => '', 'group' => 'administer', 'why' => 'See the application as another account sees it: check a scope, or walk an approval flow as each person in it.'],
            ['cap' => Capability::ManageTeams,           'label' => 'Manage Teams',        'route' => 'teams',  'phase' => '', 'group' => 'administer', 'why' => 'Which area a team groups under on the Committee Dashboard.'],
            ['cap' => Capability::ManageShowYear,        'label' => 'Show Year',           'route' => 'show-year', 'phase' => '', 'group' => 'administer', 'why' => 'Create, activate, close and carry assignments forward.'],
            ['cap' => Capability::ViewAuditLog,          'label' => 'Audit Log',           'route' => 'audit',  'phase' => '', 'group' => 'administer', 'why' => 'Every grant, import, purge and reset, with who and when.'],
        ];
    }

    /**
     * The groups this user may see, each with the tiles they may use, in
     * drawing order; a group with nothing in it for them is left out, so an
     * Officer never sees an empty Administer heading.
     *
     * @return array<string, array{heading: string, tiles: array<int, array{cap: Capability, label: string, route: ?string, phase: string, group: string, why: string}>}>
     */
    public static function forUser(User $user): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $heading) {
            $tiles = array_values(array_filter(
                self::tiles(),
                static fn (array $tile): bool => $tile['group'] === $key && Access::mayUse($user, $tile['cap'])
            ));
            if ($tiles !== []) {
                $groups[$key] = ['heading' => $heading, 'tiles' => $tiles];
            }
        }

        return $groups;
    }
}
