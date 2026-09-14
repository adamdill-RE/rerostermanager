<?php

declare(strict_types=1);

/**
 * The member card and the rest of Phase 10.4 — what needs no roster in a
 * database to prove. The card itself against a fixture is in
 * tests/status_test.php and tests/fitfinish_test.php (a dropped member),
 * one answer for everything open in tests/status_test.php, the share sort
 * in tests/committee_test.php, the chooser's order in tests/assign_test.php,
 * and the last-download card in tests/admin_test.php.
 *
 * Held here:
 *
 *   1. The three ways to reach a member come from one function, on the
 *      row's own terms, and the text and the email start themselves.
 *   2. The log form: one answer for everything open when more than one is,
 *      the per-metric selects folded under it, and neither for one.
 *   3. One way to write a time.
 *   4. The member route, its return path, and the way back re-whitelisted.
 *   5. Installable: the manifest, its icons, the CSP and the type.
 *   6. Print, the grouped menu, and the accessibility pass.
 *   7. Post, redirect, get on the five screens that re-rendered a POST.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Routes;
use Rerm\View;

function mc_app(): App
{
    return $GLOBALS['rerm_app'];
}

function mc_user(Level $level): User
{
    return new User(1, 1, 'MC0000001', $level, null, null, false, 'Cal Loop');
}

function mc_source(string $relative): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relative);
}

function mc_render(string $view, string $title, array $data): string
{
    $app = mc_app();
    $_SESSION ??= [];
    $wide = false;
    extract($data, EXTR_SKIP);

    ob_start();
    require $app->path('app/views/' . $view . '.php');
    $body = (string) ob_get_clean();

    ob_start();
    require $app->path('app/views/layout.php');

    return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// 1. Contact links
// ---------------------------------------------------------------------------

test('the three ways to reach a member come from one function, on the row\'s own terms', function (): void {
    $row = [
        'display_name' => 'Ann Smith', 'team_name' => 'Bus Ops Team A',
        'phone_e164' => '+15555550100', 'email' => 'ann@example.com',
        'can_call' => true, 'can_text' => true, 'can_email' => true,
    ];
    $links = View::contactLinks(mc_app(), mc_user(Level::Officer), $row);

    assertSame('tel:+15555550100', $links['call']);
    assertTrue(str_starts_with($links['text'], 'sms:+15555550100?&body='), 'the text starts itself');
    $body = rawurldecode(substr($links['text'], strlen('sms:+15555550100?&body=')));
    assertTrue(str_contains($body, 'Hi Ann,'), '{first} is the given name: ' . $body);
    assertTrue(str_contains($body, 'Cal Loop'), '{officer} is the signed-in officer');
    assertTrue(str_contains($body, 'Bus Ops Team A'), '{team} is the member\'s team');
    assertTrue(str_starts_with($links['email'], 'mailto:ann@example.com?subject='), 'the email has a subject');

    // A HOME phone: Call, never Text — absent, never disabled.
    $home = ['can_text' => false] + $row;
    $links = View::contactLinks(mc_app(), mc_user(Level::Officer), $home);
    assertTrue(isset($links['call']) && !isset($links['text']));

    // Nothing on file: nothing offered.
    assertSame([], View::contactLinks(mc_app(), null, ['can_call' => false, 'can_text' => false, 'can_email' => false]));

    // A team-less member leaves no double space where {team} was.
    $noTeam = ['team_name' => ''] + $row;
    $body   = rawurldecode(substr(View::contactLinks(mc_app(), mc_user(Level::Officer), $noTeam)['text'], strlen('sms:+15555550100?&body=')));
    assertTrue(!str_contains($body, '  '), 'no double space: ' . $body);

    // The templates are configuration, and every screen reads the one function.
    assertTrue(is_string(mc_app()->config()->get('contact.sms_body')));
    foreach (['dashboard', 'roster', 'dropped', 'member'] as $view) {
        assertTrue(str_contains(mc_source('app/views/' . $view . '.php'), 'View::contactLinks('), $view . '.php');
        assertSame(0, substr_count(mc_source('app/views/' . $view . '.php'), "'sms:'"), $view . '.php spells no bare sms:');
    }
});

// ---------------------------------------------------------------------------
// 2. The log form
// ---------------------------------------------------------------------------

test('the log form offers one answer for everything open when more than one is, and folds the rest', function (): void {
    $allOpen = [];
    foreach (Metric::scored() as $metric) {
        $allOpen[$metric->value] = MetricStatus::Outstanding;
    }
    $form = View::logContactForm('/x/log-contact', '', 7, $allOpen);
    assertSame(3, substr_count($form, 'name="progress_all"'), 'three radios: no change, handling, reported');
    assertTrue(str_contains($form, 'name="progress_all" value="" checked'), 'no change by default');
    assertTrue(str_contains($form, '<details class="pgeach">'), 'the per-metric selects fold');
    assertSame(4, substr_count($form, 'name="progress['), 'and are all still there');
    assertTrue(str_contains($form, 'For everything still open (HLSR, Committee, Indemnity, Bg Check)'));

    $oneOpen = $allOpen;
    foreach (Metric::scored() as $metric) {
        $oneOpen[$metric->value] = MetricStatus::Complete;
    }
    $oneOpen['indemnity'] = MetricStatus::Outstanding;
    $form = View::logContactForm('/x/log-contact', '', 7, $oneOpen);
    assertSame(0, substr_count($form, 'progress_all'), 'one open requirement is its own select');
    assertSame(1, substr_count($form, 'name="progress['));

    $none = $oneOpen;
    $none['indemnity'] = MetricStatus::Complete;
    $form = View::logContactForm('/x/log-contact', '', 7, $none);
    assertSame(0, substr_count($form, 'progress'), 'nothing to ask when everything is Complete');

    // The sheet is the form in a row, and the links passed in win.
    $sheet = View::logContactSheet('/x/log-contact', '', 7, 'Ann', $allOpen, 9, [
        'links' => ['call' => 'tel:+15555550100', 'text' => 'sms:+15555550100?&body=Hi'],
        'can_email' => true, 'email' => 'ignored@example.com',
    ]);
    assertTrue(str_starts_with($sheet, '<tr class="detail">'));
    assertTrue(str_contains($sheet, 'href="sms:+15555550100?&amp;body=Hi"'), 'the computed link');
    assertTrue(!str_contains($sheet, 'mailto:'), 'links passed in are the whole answer');

    // The handler reads it, applying the answer to what is still open only.
    $source = mc_source('app/src/Roster/LogContact.php');
    assertTrue(str_contains($source, "\$input['progress_all']"));
    assertTrue(str_contains($source, "=== 'Y') {\n                    continue;"), 'imported Y is never overwritten');
});

// ---------------------------------------------------------------------------
// 3. One way to write a time
// ---------------------------------------------------------------------------

test('a time is words with the instant machine-readable, and the same instant spelled fully', function (): void {
    $html = View::time(mc_app(), '2026-09-07 19:14:00');
    assertTrue(str_starts_with($html, '<time datetime="2026-09-07T19:14:00Z" title="7 Sep 2026, 2:14 pm">'), $html);
    assertTrue(str_ends_with($html, '</time>'));

    $full = View::timeFull(mc_app(), '2026-09-07 19:14:00');
    assertSame('<time datetime="2026-09-07T19:14:00Z">7 Sep 2026, 2:14 pm</time>', $full);

    // No bare UTC string on any screen but the health check's own footer.
    foreach (glob(__DIR__ . '/../app/views/*.php') ?: [] as $file) {
        if (in_array(basename($file), ['status.php', 'layout.php'], true)) {
            continue;
        }
        $source = (string) file_get_contents($file);
        assertSame(0, preg_match('/\?> UTC\b/', $source), basename($file) . ' prints no bare UTC');
        assertSame(0, substr_count($source, "->format('D j M, H:i T')"), basename($file) . ' spells no second format');
    }
});

// ---------------------------------------------------------------------------
// 4. The member route and the way back
// ---------------------------------------------------------------------------

test('the member route shares view_roster, and its way back is re-whitelisted by the list\'s own rule', function (): void {
    assertSame(Capability::ViewRoster->value, Routes::guard('member'));

    $front = mc_source('public/index.php');
    assertTrue(str_contains($front, "'back' => ['text' => 400]"), 'the state travels bounded');
    assertTrue(str_contains($front, "'dashboard' => dashboard_return_query(\$state)"), 'and comes back through the dashboard\'s whitelist');
    assertTrue(str_contains($front, "'roster'    => roster_return_query(\$state)"), 'or the roster\'s');
    assertTrue(str_contains($front, "'member' => 'member' . member_return_query(\$state)"), 'the sheet returns to the card');

    // The lists hand their state over as `back`.
    assertTrue(str_contains(mc_source('app/views/dashboard.php'), "'?from=dashboard&back=' . rawurlencode(\$returnState)"));
    assertTrue(str_contains(mc_source('app/views/roster.php'), "'?from=roster&back=' . rawurlencode(\$returnState)"));

    // And every list links a name to the card.
    foreach (['dashboard', 'roster', 'dropped', 'designate'] as $view) {
        assertTrue(str_contains(mc_source('app/views/' . $view . '.php'), 'class="card-link"'), $view . '.php');
    }
    assertTrue(str_contains(mc_source('app/views/assign.php'), '?from=assign&amp;id='), 'assign, beside the label');

    // Dropped Members: Log contact leads to the card, and an empty cell says so.
    $dropped = mc_source('app/views/dropped.php');
    assertTrue(str_contains($dropped, "'#log\">Log contact</a>'"));
    assertTrue(str_contains($dropped, 'No phone or email on file'));
    assertTrue(str_contains(mc_source('app/src/Roster/ScopedQuery.php'), 'public static function contactable('));
    assertTrue(str_contains(mc_source('app/src/Roster/LogContact.php'), 'ScopedQuery::contactable('));
});

// ---------------------------------------------------------------------------
// 5. Installable
// ---------------------------------------------------------------------------

test('the manifest is real, relative, iconed, allowed by the CSP and served with its type', function (): void {
    $path = __DIR__ . '/../public/manifest.webmanifest';
    assertTrue(is_file($path), 'the manifest exists');
    $manifest = json_decode((string) file_get_contents($path), true);
    assertTrue(is_array($manifest), 'and is JSON');
    assertSame('./', $manifest['start_url'], 'relative, so /rerm/ is not hard-coded');
    assertSame('./', $manifest['scope']);
    assertSame('standalone', $manifest['display']);
    foreach ($manifest['icons'] as $icon) {
        $file = __DIR__ . '/../public/' . $icon['src'];
        assertTrue(is_file($file), $icon['src'] . ' exists');
        [$w, $h] = getimagesize($file) ?: [0, 0];
        assertSame($icon['sizes'], $w . 'x' . $h, $icon['src'] . ' is the size it says');
    }

    $layout = mc_source('app/views/layout.php');
    assertTrue(str_contains($layout, '<link rel="manifest" href="<?= e($app->asset(\'manifest.webmanifest\')) ?>">'));
    assertTrue(str_contains(mc_source('public/index.php'), "manifest-src 'self'"), 'the CSP admits it');
    assertTrue(str_contains(mc_source('public/.htaccess'), 'AddType application/manifest+json .webmanifest'));

    // The two shared icons are untouched: RESM's, byte for byte.
    assertSame(0, substr_count((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && git status --short public/assets/icons/favicon.png public/assets/icons/apple-touch-icon.png 2>/dev/null'), 'M '));
});

// ---------------------------------------------------------------------------
// 6. Print, the menu, the accessibility pass
// ---------------------------------------------------------------------------

test('on paper the table is a table, every details is open, and the controls are gone', function (): void {
    $layout = mc_source('app/views/layout.php');
    $print  = substr($layout, (int) strpos($layout, '@media print'));
    assertTrue(str_contains($print, 'table thead { position: static;'), 'the hidden header comes back');
    assertTrue(str_contains($print, 'details:not([open]) > *:not(summary) { display: block; }'), 'closed details print open');
    assertTrue(str_contains($print, '.topbar, footer.shell, form, .actionbar'), 'the controls go');
    assertTrue(str_contains($print, '.roster tbody.member { display: table-row-group;'), 'the stacked card is a row again');
});

test('the menu is three groups by job, each screen with one line on what it is for', function (): void {
    $officer = mc_render('menu', 'Menu', ['user' => mc_user(Level::Officer)]);
    assertTrue(str_contains($officer, 'To-Do Items:'), 'the owner\'s word for the officer\'s loop');
    assertTrue(str_contains($officer, 'Team Functions:'), 'and for the desk — Assign is an Officer\'s');
    assertTrue(!str_contains($officer, '>Administer<'), 'no Administer for an Officer');
    assertTrue(str_contains($officer, 'a button that dials them'), 'the line under a screen');
    assertTrue(str_contains($officer, 'Assign Officers to call members.'), 'in the owner\'s words');

    $admin = mc_render('menu', 'Menu', ['user' => mc_user(Level::Admin)]);
    assertTrue(str_contains($admin, '>Administer<'));
    assertSame(3, substr_count($admin, '<h2 class="menu-group">') - 1, 'three job groups, plus Account');

    $source = mc_source('app/views/menu.php');
    assertSame(17, preg_match_all("/'route' => '[a-z-]+',.*'group' => '(chase|lead|administer)', 'why' => '/", $source), 'every tile names its group and its why, on one line');
});

test('the accessibility pass: a skip link, aria-sort, aria-current on every toggle, scope on every header, no disabled button', function (): void {
    $layout = mc_source('app/views/layout.php');
    assertTrue(str_contains($layout, '<a class="skip-main" href="#content">Skip to content</a>'));
    assertTrue(str_contains($layout, '<main id="content"'));
    assertTrue(str_contains($layout, 'a:focus-visible { outline: 3px solid var(--rodeo-orange);'), 'links get a ring');
    assertTrue(str_contains($layout, 'select:focus-visible'), 'and selects');

    foreach (['roster', 'dropped', 'committee'] as $view) {
        assertTrue(str_contains(mc_source('app/views/' . $view . '.php'), "aria-sort=\"' . ("), $view . '.php says its sort');
    }
    assertTrue(str_contains(mc_source('app/views/assign.php'), 'aria-sort="'), 'the chooser too');

    foreach (glob(__DIR__ . '/../app/views/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        assertSame(0, substr_count($source, 'data-label=""'), basename($file) . ' has no empty data-label');
        assertSame(0, preg_match('/<div class="toggle">/', $source), basename($file) . ' has no toggle without a nav');
    }
    foreach (['designate', 'purge'] as $view) {
        assertTrue(str_contains(mc_source('app/views/' . $view . '.php'), 'aria-current="page"'), $view . '.php');
    }
    foreach (['show-year', 'teams', 'purge', 'designate', 'dropped', 'audit', 'dashboard', 'roster', 'assign', 'committee'] as $view) {
        $source = mc_source('app/views/' . $view . '.php');
        assertSame(0, preg_match('/<th(?![^>]*scope=)[\s>]/', $source), $view . '.php has a header cell without scope');
    }
    assertSame(0, preg_match('/<button[^>]*\sdisabled/', mc_source('app/views/designate.php')), 'absent, never disabled');
});

// ---------------------------------------------------------------------------
// 7. Post, redirect, get
// ---------------------------------------------------------------------------

test('every screen that re-rendered a POST now redirects, and the flash joins what it was told', function (): void {
    $front = mc_source('public/index.php');
    foreach (["redirect(\$app, 'import' . (", "redirect(\$app, 'import-contacts' . (",
        "redirect(\$app, 'forgot?sent=1');", "redirect(\$app, 'reset?done=' .", "redirect(\$app, 'setup?key=' ."] as $needle) {
        assertTrue(str_contains($front, $needle), $needle);
    }
    // The setup forms keep the key in the address, so a reload is not a 404.
    assertSame(2, substr_count(mc_source('app/views/setup.php'), "?key=<?= e(rawurlencode(\$key)) ?>"));
    // The contact import no longer changes width mid-flow.
    assertSame(0, substr_count($front, "'wide'     => \$preview !== null"));
    // The result cards offer a way to try again.
    assertSame(2, substr_count(mc_source('app/views/forgot.php'), 'Typed the wrong number? Try again'));

    assertSame(['warn', 'Applied. One warning.'], View::joinNotices([['ok', 'Applied.'], ['warn', 'One warning.']]), 'joined, at the loudest level');
    assertSame(['danger', 'A B'], View::joinNotices([['danger', 'A'], ['ok', 'B']]));
    assertSame(null, View::joinNotices([]), 'nothing to say, nothing said');
    assertTrue(str_contains($front, 'flash_set(...$joined);'), 'and the handler flashes exactly that');
});
