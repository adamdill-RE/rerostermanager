<?php

declare(strict_types=1);

/**
 * Phase 14.4 — the menu by the owner's groups, and the shell (spec-v2 §18).
 *
 *   1. One menu, written down once: Rerm\Menu is the table, the page and
 *      the drop-down both draw it, and the groups are the owner's in the
 *      owner's order.
 *   2. The drop-down: on every signed-in screen, filtered by capability,
 *      the current screen marked, withheld while a password change is
 *      forced, and no JavaScript anywhere near it.
 *   3. The bar is one line on a phone: the screens and the name are not
 *      drawn there, and the acting-as row is the exception.
 *   4. The renamed screens: the word in the menu is the word on the page.
 *   5. The two fixes the audit found: the toggle's desktop sizing comes
 *      after the base rule, and controls share one height token.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Menu;

function sh_app(): App
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

    return $app;
}

function sh_source(string $relative): string
{
    $source = (string) file_get_contents(dirname(__DIR__) . '/' . $relative);
    assertTrue($source !== '', $relative . ' is readable');

    return $source;
}

function sh_user(Level $level, bool $mustChange = false, string $name = 'Uma Miller'): User
{
    return new User(7, 107, '1000006', $level, 1, 1, $mustChange, $name);
}

function sh_render(string $template, string $title, array $data): string
{
    $app = sh_app();
    $_SESSION ??= [];
    $wide = false;
    // render() extracts with EXTR_SKIP and its own parameter is $view, so in
    // production $view is always the screen being drawn. Here the screen
    // can be told apart from the template, so the menu template can stand
    // in for any screen when it is the bar being tested.
    $view = (string) ($data['view'] ?? $template);
    unset($data['view']);
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $template . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// 1. One menu, written down once
// ---------------------------------------------------------------------------

test('the groups are the owner\'s, in the owner\'s order, and nothing left the menu', function (): void {
    assertSame(['rcfs', 'chase', 'lead', 'administer'], array_keys(Menu::GROUPS));
    assertSame('RCFs', Menu::GROUPS['rcfs']);
    assertSame('Dues & Compliance', Menu::GROUPS['chase']);
    assertSame('Team Functions', Menu::GROUPS['lead']);
    assertSame('Administer', Menu::GROUPS['administer']);
    foreach (Menu::GROUPS as $heading) {
        assertTrue(!str_ends_with($heading, ':'), 'a heading carries no trailing colon');
    }

    // The screenshot the owner sent, transcribed: each group's screens in
    // the order given. Administer was not in it and is unchanged.
    $byGroup = [];
    foreach (Menu::tiles() as $tile) {
        $byGroup[$tile['group']][] = $tile['label'];
        assertTrue(array_key_exists($tile['group'], Menu::GROUPS), $tile['label'] . ' is in a known group');
    }
    assertSame(['Create RCF Requests', 'Approve RCF Requests', 'Export Approved Requests to RCF Forms', 'Track RCFs'], $byGroup['rcfs']);
    assertSame(['My Roster Status', 'View My Roster', 'Dropped Members'], $byGroup['chase']);
    assertSame(['Assign Officers', 'Committee Dashboard', 'Create Forms', 'Export Roster'], $byGroup['lead']);
    assertSame([
        'Import Roster', 'Import History', 'Look Up Members', 'Import Contact History', 'Flagged for Purge',
        'Designate Users', 'Create Approval Flow', 'Sign In As', 'Manage Teams', 'Show Year', 'Audit Log',
    ], $byGroup['administer']);
    assertSame(22, count(Menu::tiles()), 'twenty-two tiles, as at 14.1: a regrouping adds no screen');

    // Every route named is a route the front controller guards.
    foreach (Menu::tiles() as $tile) {
        assertTrue($tile['route'] !== null && Rerm\Routes::guard($tile['route']) !== null, $tile['label'] . ' points at a route');
    }
});

test('the regrouping did not move a capability: every tile is behind the power it was behind', function (): void {
    $caps = [];
    foreach (Menu::tiles() as $tile) {
        $caps[$tile['label']] = $tile['cap'];
    }
    assertSame(Capability::CreateRcfEntries, $caps['Create RCF Requests']);
    assertSame(Capability::ApproveRcfEntries, $caps['Approve RCF Requests']);
    assertSame(Capability::GenerateConsolidatedRcf, $caps['Export Approved Requests to RCF Forms']);
    assertSame(Capability::CreateForms, $caps['Track RCFs']);
    assertSame(Capability::ExportRoster, $caps['Export Roster']);
    assertSame(Capability::ViewStatusDashboard, $caps['My Roster Status']);
});

test('forUser filters by capability and drops an empty group, so an Officer never sees Administer', function (): void {
    $officer = Menu::forUser(sh_user(Level::Officer));
    assertSame(['rcfs', 'chase', 'lead'], array_keys($officer), 'RCFs (Track RCFs), Dues & Compliance, Team Functions');
    assertSame(['Track RCFs'], array_column($officer['rcfs']['tiles'], 'label'), 'an Officer may create forms, not requests');
    assertSame(['Assign Officers', 'Create Forms', 'Export Roster'], array_column($officer['lead']['tiles'], 'label'), 'no Committee Dashboard');

    $member = Menu::forUser(sh_user(Level::Member));
    assertSame([], $member, 'a Member-level login has no screen');

    $admin = Menu::forUser(sh_user(Level::Admin));
    assertSame(['rcfs', 'chase', 'lead', 'administer'], array_keys($admin));
    assertSame(22, array_sum(array_map(static fn (array $g): int => count($g['tiles']), $admin)));
});

test('the page and the drop-down draw the same table, and neither spells a tile of its own', function (): void {
    $page   = sh_source('app/views/menu.php');
    $layout = sh_source('app/views/layout.php');
    assertTrue(str_contains($page, 'Menu::forUser($user)'), 'the page draws Rerm\\Menu');
    assertTrue(str_contains($layout, 'Rerm\\Menu::forUser($user)'), 'the drop-down draws Rerm\\Menu');
    assertSame(0, substr_count($page, "'cap' => Capability::"), 'no tile is written in the page');
    assertSame(0, substr_count($layout, "'cap' => Capability::"), 'nor in the layout');

    // The same words, from the same table: whatever an Admin sees on the
    // page they see in the drop-down, and in the same order.
    $admin = sh_user(Level::Admin);
    $html  = sh_render('menu', 'Menu', ['user' => $admin, 'view' => 'menu']);
    $panel = substr($html, (int) strpos($html, '<details class="appmenu">'), (int) strpos($html, '</details>') - (int) strpos($html, '<details class="appmenu">'));
    $main  = substr($html, (int) strpos($html, '<main id="content"'));
    foreach (Menu::tiles() as $tile) {
        assertSame(1, substr_count($panel, '>' . e($tile['label']) . '</a>'), $tile['label'] . ' is in the drop-down once');
        assertSame(1, substr_count($main, '<span class="what">' . e($tile['label']) . '</span>'), $tile['label'] . ' is on the page once');
    }
    foreach (Menu::GROUPS as $key => $heading) {
        assertSame(1, substr_count($panel, '<h3 id="mg-' . $key . '">' . e($heading) . '</h3>'), $heading . ' heads a group in the drop-down');
        assertSame(1, substr_count($main, '<h2 class="menu-group">' . e($heading) . '</h2>'), $heading . ' heads a group on the page');
    }
    assertTrue(strpos($panel, 'mg-rcfs') < strpos($panel, 'mg-chase') && strpos($panel, 'mg-chase') < strpos($panel, 'mg-lead') && strpos($panel, 'mg-lead') < strpos($panel, 'mg-administer'), 'in the owner\'s order');
});

// ---------------------------------------------------------------------------
// 2. The drop-down
// ---------------------------------------------------------------------------

test('the drop-down is a <details> on every signed-in screen, with no script, filtered by capability', function (): void {
    $officer = sh_render('menu', 'Menu', ['user' => sh_user(Level::Officer), 'view' => 'menu']);
    assertSame(1, substr_count($officer, '<details class="appmenu">'), 'one drop-down');
    assertTrue(str_contains($officer, '<summary>Menu</summary>'), 'its button is the summary');
    assertSame(0, substr_count($officer, '<script'), 'no JavaScript, as on every screen');
    assertSame(0, substr_count($officer, 'onclick='), 'none inline either');
    assertTrue(str_contains($officer, '<h3 id="mg-chase">Dues &amp; Compliance</h3>'), 'the owner\'s heading');
    assertTrue(!str_contains($officer, 'mg-administer'), 'no Administer for an Officer');
    assertTrue(!str_contains($officer, '>Committee Dashboard</a>'), 'no Committee Dashboard for an Officer');
    assertTrue(str_contains($officer, '>Track RCFs</a>'), 'but the RCFs group, for Track RCFs');

    // The account line, Change password, the way to the menu page and Sign
    // out are in it, so the bar needs none of them.
    assertTrue(str_contains($officer, '<p class="account">'), 'who, in the panel');
    assertTrue(str_contains($officer, '<strong>Uma Miller</strong>'), 'by name');
    assertTrue(str_contains($officer, 'Officer &middot; member number 1000006'), 'level and number');
    assertTrue(str_contains($officer, '>Change password</a>'), 'Change password');
    assertTrue(str_contains($officer, '>Full menu</a>'), 'the menu page');
    $foot = substr($officer, (int) strpos($officer, '<div class="foot">'), (int) strpos($officer, '</details>') - (int) strpos($officer, '<div class="foot">'));
    assertTrue(str_contains($foot, '<form class="signout"'), 'Sign out, in the panel');
    assertSame(1, substr_count($officer, '<form class="signout"'), 'and only there');

    // Two columns only when there are more than two groups to show.
    assertTrue(str_contains($officer, '<div class="panel cols">'), 'an Officer has three groups');
    $senior = sh_render('menu', 'Menu', ['user' => sh_user(Level::Member)]);
    assertTrue(str_contains($senior, '<div class="panel">'), 'a Member-level login has none, so one column');
});

test('the current screen is marked in the drop-down, in the strip and on the foot links', function (): void {
    // Counted in the markup, after the style sheet, which names the
    // attribute in three selectors.
    $markup = static fn (string $html): string => substr($html, (int) strpos($html, '</style>'));
    $onRoster = sh_render('menu', 'Menu', ['user' => sh_user(Level::Officer), 'view' => 'roster']);
    assertTrue(str_contains($onRoster, 'aria-current="page">View My Roster</a>'), 'the drop-down');
    assertTrue(str_contains($onRoster, 'class="current" aria-current="page">Roster</a>'), 'the strip');
    assertSame(2, substr_count($markup($onRoster), 'aria-current="page"'), 'and nothing else');

    $onMenu = sh_render('menu', 'Menu', ['user' => sh_user(Level::Officer), 'view' => 'menu']);
    assertTrue(str_contains($onMenu, 'aria-current="page">Full menu</a>'));
    assertSame(1, substr_count($markup($onMenu), 'aria-current="page"'));

    $onPassword = sh_render('password', 'Change password', [
        'user' => sh_user(Level::Officer), 'notices' => [], 'forced' => false, 'minLength' => 8, 'view' => 'password',
    ]);
    assertTrue(str_contains($onPassword, 'aria-current="page">Change password</a>'));
});

test('while a password change is forced there is no drop-down, and Sign out is in the bar instead', function (): void {
    $forced = sh_render('password', 'Choose your password', [
        'user' => sh_user(Level::Officer, true), 'notices' => [], 'forced' => true, 'minLength' => 8,
    ]);
    assertSame(0, substr_count($forced, '<details class="appmenu">'), 'no drop-down: every target 303s back here');
    assertSame(0, substr_count($forced, 'aria-label="Screens"'), 'no strip');
    assertSame(1, substr_count($forced, '<form class="signout"'), 'Sign out, once');
    assertTrue(str_contains($forced, '<div class="who alone">'), 'and the name row stays visible on a phone');

    // An Admin acting as such an account is not pinned: they get the menu.
    $admin  = new User(1, 101, '987654321', Level::Admin, 1, 1, false, 'Ada Admin');
    $walked = sh_render('menu', 'Menu', ['user' => sh_user(Level::Officer, true), 'actor' => $admin]);
    assertSame(1, substr_count($walked, '<details class="appmenu">'));
});

// ---------------------------------------------------------------------------
// 3. One line on a phone
// ---------------------------------------------------------------------------

test('the bar is one line on a phone: the strip and the name are not drawn there, the acting row is', function (): void {
    $css = sh_source('app/views/layout.php');
    assertTrue(str_contains($css, '@media (max-width: 719px) { .topbar nav.nav { display: none; } }'), 'the strip is a desk thing');
    assertTrue(str_contains($css, '.topbar .who:not(.acting):not(.alone) { display: none; }'), 'the name is in the panel on a phone');
    assertTrue(str_contains($css, '.topbar .who.acting, .topbar .who.alone { flex: 1 0 100%; order: 9;'), 'Signed in as keeps its row, after the button');
    assertTrue(str_contains($css, 'details.appmenu { margin-left: auto; }'), 'the button sits at the right edge');
    assertTrue(str_contains($css, '.appmenu .panel {') && str_contains($css, 'position: absolute;') && str_contains($css, 'top: 100%;'), 'the panel hangs from the bar');
    assertTrue(str_contains($css, '.topbar .notes { flex: 1 0 100%; }'), 'the notices are inside the box it hangs from');

    // The notices are still inside the bar, so the panel hangs under them.
    $html = sh_render('menu', 'Menu', ['user' => sh_user(Level::Officer), 'notices' => [['ok', 'Done.']]]);
    $inner = strpos($html, '<div class="inner">');
    $note  = strpos($html, '<div class="notes">');
    $end   = strpos($html, '</header>');
    assertTrue($inner !== false && $note !== false && $end !== false && $inner < $note && $note < $end);
    assertSame(1, substr_count($html, 'role="status"'));

    // The brand is a link home, once.
    assertSame(1, substr_count($html, '<a class="brand" href="/rerm/">'));
    assertSame(1, substr_count($html, 'class="brand"'));

    // The panel is hidden on paper with the rest of the bar.
    $print = substr($css, (int) strpos($css, '@media print'));
    assertTrue(str_contains($print, '.topbar, footer.shell, form'), 'the bar, and the drop-down inside it, go');
});

test('the acting-as row still carries the word and the way back, beside the menu button', function (): void {
    $officer = sh_user(Level::ExecutiveOfficer, false, 'Sam Brown');
    $admin   = new User(1, 101, '987654321', Level::Admin, 1, 1, false, 'Ada Admin');
    $acting  = sh_render('menu', 'Menu', ['user' => $officer, 'actor' => $admin]);
    assertTrue(str_contains($acting, '<div class="who acting">'));
    assertTrue(str_contains($acting, '<span class="as">Signed in as</span>'));
    assertTrue(str_contains($acting, '>Back to Ada Admin (Admin)</button>'));
    assertTrue(str_contains($acting, '<details class="appmenu">'), 'and the drop-down, drawn as the account');
    assertTrue(str_contains($acting, '<strong>Sam Brown</strong>'), 'whose name the panel carries');
    assertTrue(!str_contains($acting, '<strong>Ada Admin</strong>'), 'not the Admin\'s');
    assertTrue(strpos($acting, '<div class="who acting">') < strpos($acting, '<details class="appmenu">'), 'the row is before the button in source; CSS orders it after on a phone');
});

// ---------------------------------------------------------------------------
// 4. The renamed screens
// ---------------------------------------------------------------------------

test('the word in the menu is the word on the page: the three RCF screens are titled as the menu names them', function (): void {
    $index = sh_source('public/index.php');
    foreach ([
        'rcf-entries'     => 'Create RCF Requests',
        'rcf-approvals'   => 'Approve RCF Requests',
        'rcf-consolidate' => 'Export Approved Requests to RCF Forms',
    ] as $view => $title) {
        assertTrue(str_contains(sh_source('app/views/' . $view . '.php'), '<h1>' . $title . '</h1>'), $view . ' is headed ' . $title);
        assertTrue(str_contains($index, "render(\$app, '{$view}', '{$title}', ["), $view . ' is titled ' . $title);
        assertTrue(str_contains($index, "'{$view}' => ['{$view}', '{$title}']") || str_contains($index, "'{$view}'     => ['{$view}', '{$title}']") || str_contains($index, "'{$view}'   => ['{$view}', '{$title}']"), 'the member card\'s way back says ' . $title);
    }
    // The old names are gone from everything a person reads.
    foreach (glob(dirname(__DIR__) . '/app/views/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        $code   = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        assertSame(0, substr_count($code, '<h1>Create RCF Entries</h1>'), basename($file));
        assertSame(0, substr_count($code, '>Consolidate RCFs</a>'), basename($file) . ' links by the new name');
        assertSame(0, substr_count($code, '>Create RCF Entries</a>'), basename($file) . ' links by the new name');
    }
    // The routes, the capabilities and the audit verbs did not move.
    assertSame('create_rcf_entries', Capability::CreateRcfEntries->value);
    assertSame('generate_consolidated_rcf', Capability::GenerateConsolidatedRcf->value);
});

// ---------------------------------------------------------------------------
// 5. The two fixes
// ---------------------------------------------------------------------------

test('the toggle\'s desktop sizing comes after its base rule, and controls share one height token', function (): void {
    $css  = sh_source('app/views/layout.php');
    $base = strpos($css, '.toggle a { flex: 1 1 10rem; padding: 0 .6rem; text-align: center; }');
    $desk = strpos($css, '.toggle a { flex: 0 1 auto; min-width: 10rem; min-height: 48px; padding: 0 1.25rem; }');
    assertTrue($base !== false && $desk !== false && $base < $desk, 'the desk rule wins because it is later');
    assertSame(1, substr_count($css, '.toggle a { flex: 0 1 auto;'), 'and it is written once');

    assertTrue(str_contains($css, '--control: 56px;'), 'the phone height');
    assertTrue(str_contains($css, '@media (min-width: 720px) { :root { --control: 48px; } }'), 'the desk height, the one buttons already had');
    foreach ([
        'input[type="password"], input[type="text"], input[type="search"], input[type="date"] {',
        'select, input[type="file"] {',
        'textarea {',
    ] as $selector) {
        $rule = substr($css, (int) strpos($css, $selector), 220);
        assertTrue(str_contains($rule, 'min-height: var(--control);'), $selector . ' uses the token');
    }
    assertTrue(str_contains($css, 'button.quiet { background: transparent; color: var(--link); border: 1px solid var(--border); min-height: var(--control); }'), 'the quiet button too');
});
