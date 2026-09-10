<?php

declare(strict_types=1);

/**
 * The call loop and the shell (Phase 10.3) — the parts that need no roster
 * in a database to prove. The search that finds "John Smith" is proved
 * against a fixture in tests/roster_test.php, the flat teams page in
 * tests/committee_test.php, and the two-step purge, the one-form export and
 * the Show Year step in tests/admin_test.php, beside the fixtures those
 * screens already have.
 *
 * What is held here:
 *
 *   1. A search term is words, and every word has to land — the clause is
 *      one group per word, ANDed, four placeholders each, wildcards literal.
 *   2. A chip carries a short word where the owner's word is three, and the
 *      owner's word travels as its title; nothing else changes spelling.
 *   3. "41 days to go" is derived from the show year's end date, in the
 *      display zone, and a year with no date says nothing.
 *   4. The shell: one notice component with one vocabulary, inside the
 *      sticky bar with role=status; a nav of the four working screens,
 *      filtered by capability, absent while a password change is forced;
 *      a link token that meets 4.5:1 on Dust Light; color-scheme declared.
 *   5. The log-contact sheet carries the row's Call, Text and Email inside
 *      it, on the row's own terms, and opens on its first control.
 *   6. The dashboard folds its cards and its controls on a phone, and the
 *      fold is open whenever something inside it is in force.
 *   7. "12 selected" is a CSS counter, on both screens that tick rows.
 *   8. The refusal page says which of three things it means.
 *   9. Every write that changes one row comes back anchored to it.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;
use Rerm\Roster\RosterPage;
use Rerm\View;

/** Renders a view inside the shell exactly as render() does, $view included. */
function cl_render(string $view, string $title, array $data): string
{
    /** @var App $app */
    $app = $GLOBALS['rerm_app'];

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

function cl_user(Level $level, bool $forced = false): User
{
    return new User(1, 1, 'CL0000001', $level, null, null, $forced, 'Cal Loop');
}

function cl_source(string $relative): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relative);
}

// ---------------------------------------------------------------------------
// 1. The search is words
// ---------------------------------------------------------------------------

test('a search term splits on whitespace and commas, de-duplicates, and is capped', function (): void {
    assertSame(['John', 'Smith'], RosterPage::searchTokens('John Smith'));
    assertSame(['Smith', 'John'], RosterPage::searchTokens('Smith, John'));
    assertSame(['Smith', 'John'], RosterPage::searchTokens("  Smith ,\tJohn  "));
    assertSame(['Ann'], RosterPage::searchTokens('Ann Ann'), 'a repeated word is one word');
    assertSame(6, count(RosterPage::searchTokens('a b c d e f g h')), 'capped at SEARCH_MAX_TOKENS');
    // A term with no words in it is still one token — itself — so the clause
    // is never empty, and it matches the nobody it should.
    assertSame([',,,'], RosterPage::searchTokens(',,,'));
});

test('the clause is one group per word, ANDed, with four placeholders each and wildcards literal', function (): void {
    [$clause, $bind] = RosterPage::searchClause('John Smith');

    assertSame(2, substr_count($clause, 'm.last_name LIKE'), 'one group per word');
    assertSame(1, substr_count($clause, ') AND ('), 'and the groups are ANDed');
    assertSame(8, count($bind), 'four placeholders per word');
    assertSame('%John%', $bind[':search0p']);
    assertSame('%Smith%', $bind[':search1l']);

    [$one, $bindOne] = RosterPage::searchClause('Findme');
    assertSame(0, substr_count($one, ' AND '), 'one word, one group');
    assertSame(4, count($bindOne));

    [, $bindWild] = RosterPage::searchClause('100% Smith');
    assertSame('%100\\%%', $bindWild[':search0n'], 'a typed % is a literal in every word');

    // Every name on the statement is distinct: a named placeholder cannot
    // be reused within one statement here.
    assertSame(count($bind), count(array_unique(array_keys($bind))));
});

test('Designate Users and Import History search through the one clause', function (): void {
    assertTrue(str_contains(cl_source('app/src/Admin/DesignatePage.php'), 'RosterPage::searchClause('));
    assertTrue(str_contains(cl_source('app/src/Import/ImportHistory.php'), 'RosterPage::searchClause('));
    assertTrue(str_contains(cl_source('app/src/Roster/StatusPage.php'), 'RosterPage::searchClause('));

    // And none of them spells a LIKE over a name column of its own.
    foreach (['app/src/Admin/DesignatePage.php', 'app/src/Import/ImportHistory.php', 'app/src/Roster/StatusPage.php'] as $file) {
        assertSame(0, substr_count(cl_source($file), 'last_name LIKE'), $file . ' has no second search');
    }
});

// ---------------------------------------------------------------------------
// 2. The chip's word
// ---------------------------------------------------------------------------

test('a chip carries the short word where the owner\'s word is three, and the full word as its title', function (): void {
    assertSame('Open', MetricStatus::Outstanding->chipLabel());
    assertSame('Reported', MetricStatus::Reported->chipLabel());
    assertSame('Handling', MetricStatus::InProgress->chipLabel());
    foreach ([MetricStatus::Complete, MetricStatus::Contacted, MetricStatus::NotReported] as $same) {
        assertSame($same->label(), $same->chipLabel(), $same->value . ' is already one word');
    }

    // The owner's words are untouched: label() is still the one place they
    // are spelled, and the legend, the popovers and the Result read it.
    assertSame('Open/No Contact', MetricStatus::Outstanding->label());

    $open = View::chip(MetricStatus::Outstanding);
    assertTrue(str_contains($open, '>Open<'), 'the chip says Open');
    assertTrue(str_contains($open, 'title="Open/No Contact"'), 'and carries the full word');

    $done = View::chip(MetricStatus::Complete);
    assertTrue(!str_contains($done, 'title='), 'no title where the words are the same');

    $handling = View::chip(MetricStatus::InProgress);
    assertTrue(str_contains($handling, 'chip-word'), 'the filled chip keeps its inner span');
    assertTrue(str_contains($handling, '>Handling<'));
});

// ---------------------------------------------------------------------------
// 3. Days to go
// ---------------------------------------------------------------------------

test('days to go is derived from the end date in the display zone, and a dateless year says nothing', function (): void {
    /** @var App $app */
    $app   = $GLOBALS['rerm_app'];
    $today = new DateTimeImmutable('today', $app->displayTimezone());

    assertSame('', View::daysLeft($app, null));
    assertSame('', View::daysLeft($app, ''));
    assertSame('', View::daysLeft($app, 'not a date'));
    assertSame('41 days to go', View::daysLeft($app, $today->modify('+41 days')->format('Y-m-d')));
    assertSame('1 day to go', View::daysLeft($app, $today->modify('+1 day')->format('Y-m-d')));
    assertSame('closes today', View::daysLeft($app, $today->format('Y-m-d')));
    assertSame('ended yesterday', View::daysLeft($app, $today->modify('-1 day')->format('Y-m-d')));
    $past = $today->modify('-30 days');
    assertSame('ended ' . $past->format('j M Y'), View::daysLeft($app, $past->format('Y-m-d')));
    assertSame('1,000 days to go', View::daysLeft($app, $today->modify('+1000 days')->format('Y-m-d')));
});

test('the ledes carry the days to go, and the active year read carries the date', function (): void {
    assertTrue(str_contains(cl_source('app/views/dashboard.php'), 'View::daysLeft('));
    assertTrue(str_contains(cl_source('app/views/committee.php'), 'View::daysLeft('));
    assertTrue(str_contains(cl_source('public/index.php'), 'SELECT id, label, is_open, ends_on FROM show_year'));
});

// ---------------------------------------------------------------------------
// 4. The shell
// ---------------------------------------------------------------------------

test('one notice component, one vocabulary, and no view spells its own', function (): void {
    assertTrue(str_contains(View::notice('ok', 'a'), '>Done<'));
    assertTrue(str_contains(View::notice('warn', 'a'), '>Note<'));
    assertTrue(str_contains(View::notice('danger', 'a'), '>Stopped<'));
    assertTrue(str_contains(View::notice('anything-else', 'a'), '>Stopped<'), 'an unknown level is the loud one');
    assertTrue(str_contains(View::notice('ok', '<b>'), '&lt;b&gt;'), 'the message is escaped');

    foreach (glob(__DIR__ . '/../app/views/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        if (basename($file) === 'layout.php') {
            continue;
        }
        assertSame(0, substr_count($source, 'foreach ($notices'), basename($file) . ' renders no notice loop of its own');
        assertSame(0, substr_count($source, "'Refused'"), basename($file) . ' does not spell a second vocabulary');
    }

    $layout = cl_source('app/views/layout.php');
    assertTrue(str_contains($layout, 'Rerm\\View::notice('), 'the layout renders them');
    assertTrue(str_contains($layout, 'role="status"'), 'and announces them');
});

test('the notices ride inside the sticky bar for a signed-in user, and at the top for anybody else', function (): void {
    $signedIn = cl_render('menu', 'Menu', [
        'user' => cl_user(Level::Officer), 'notices' => [['ok', 'Contact with Ann is logged.']],
    ]);
    $bar  = strpos($signedIn, 'class="topbar');
    $done = strpos($signedIn, 'Contact with Ann is logged.');
    $end  = strpos($signedIn, '</header>');
    assertTrue($bar !== false && $done !== false && $end !== false);
    assertTrue($bar < $done && $done < $end, 'the notice is inside the header');
    assertSame(1, substr_count($signedIn, 'role="status"'));

    $anonymous = cl_render('login', 'Sign in', [
        'notices' => [['danger', 'That member number and password did not match.']], 'memberNumber' => '',
    ]);
    assertSame(0, substr_count($anonymous, 'class="topbar'));
    assertTrue(str_contains($anonymous, '>Stopped<'), 'the auth screens say Stopped now, not Refused');
    assertSame(1, substr_count($anonymous, 'role="status"'));
});

test('the bar carries the four working screens, filtered by capability, with the current one marked', function (): void {
    $officer = cl_render('menu', 'Menu', ['user' => cl_user(Level::Officer)]);
    assertTrue(str_contains($officer, '>Status</a>'));
    assertTrue(str_contains($officer, '>Roster</a>'));
    assertTrue(str_contains($officer, '>Assign</a>'));
    assertTrue(!str_contains($officer, '>Committee</a>'), 'an Officer has no Committee Dashboard');
    assertTrue(str_contains($officer, 'aria-current="page">&larr; Menu</a>'), 'the menu is current on the menu');
    assertTrue(str_contains($officer, 'class="signout"'), 'and Sign out is in the bar');

    $senior = cl_render('menu', 'Menu', ['user' => cl_user(Level::SeniorOfficer)]);
    assertTrue(str_contains($senior, '>Committee</a>'));

    $member = cl_render('menu', 'Menu', ['user' => cl_user(Level::Member)]);
    assertTrue(!str_contains($member, '>Status</a>'), 'a Member-level login has no working screen');
    assertTrue(str_contains($member, '&larr; Menu</a>'), 'and still has the menu');

    // The current screen: render() passes $view, and the layout marks it.
    $view    = 'dashboard';
    $current = cl_render('menu', 'Menu', ['user' => cl_user(Level::Officer), 'view' => 'menu']);
    assertTrue(str_contains($current, 'aria-current="page">&larr; Menu</a>'));
});

test('while a password change is forced the bar offers Sign out and nothing that loops', function (): void {
    $forced = cl_render('password', 'Choose your password', [
        'user' => cl_user(Level::Officer, true), 'notices' => [], 'forced' => true, 'minLength' => 8,
    ]);
    assertSame(1, substr_count($forced, 'class="topbar'), 'the bar is there');
    assertTrue(!str_contains($forced, '&larr; Menu</a>'), 'without the link that 303s straight back');
    assertTrue(!str_contains($forced, '>Status</a>'), 'and without the screens');
    assertTrue(str_contains($forced, 'class="signout"'), 'Sign out is the one way out');
    assertTrue(!str_contains($forced, 'Back to the menu'), 'the view does not offer it either');

    // And the change is confirmed on landing.
    $front = cl_source('public/index.php');
    $from  = strpos($front, 'function password_act(');
    $to    = strpos($front, "\nfunction ", (int) $from + 1);
    $body  = substr($front, (int) $from, (int) $to - (int) $from);
    assertTrue(str_contains($body, "flash_set('ok', 'Your password is changed"), 'the change is said');
});

test('links have a token that meets 4.5:1 on Dust Light, and the browser is told the scheme', function (): void {
    $layout = cl_source('app/views/layout.php');

    assertTrue(str_contains($layout, '--link: #9C4512;'), 'the light link token');
    assertTrue(str_contains($layout, 'a { color: var(--link); }'), 'and links use it');
    assertTrue(!str_contains($layout, 'a { color: var(--action-orange); }'), 'not Action Orange, which is 4.1:1 on the surface');
    assertTrue(str_contains($layout, 'color-scheme: light dark;'));
    assertTrue(str_contains($layout, '<meta name="color-scheme" content="light dark">'));
    assertSame(2, substr_count($layout, '<meta name="theme-color"'), 'one theme-color per scheme');

    // WCAG relative luminance, from the palette in CLAUDE.md.
    $lum = static function (string $hex): float {
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v   = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $ratio = static fn (string $a, string $b): float => ($lum($a) + 0.05) / ($lum($b) + 0.05);

    assertTrue($ratio('F2EAE2', '9C4512') >= 4.5, 'the link on Dust Light: ' . $ratio('F2EAE2', '9C4512'));
    assertTrue($ratio('FFFFFF', '9C4512') >= 4.5, 'and on white');
    assertTrue($ratio('EF7622', '241B16') >= 4.5, 'and Rodeo Orange on the dark surface');
});

test('the footer link rows are gone from the screens the bar serves', function (): void {
    foreach (['dashboard', 'roster', 'committee', 'assign', 'designate', 'purge', 'export', 'show-year', 'teams', 'audit', 'dropped', 'forms'] as $view) {
        $source = cl_source('app/views/' . $view . '.php');
        assertSame(0, substr_count($source, 'Back to the menu'), $view . '.php has no footer link row');
        assertSame(0, substr_count($source, '<a href="<?= e($app->url(\'menu\')) ?>">Menu</a>'), $view . '.php');
    }
});

// ---------------------------------------------------------------------------
// 5. Call, then log
// ---------------------------------------------------------------------------

test('the open sheet carries the row\'s Call, Text and Email on the row\'s own terms, and opens on its first control', function (): void {
    $open = [];
    foreach (Metric::scored() as $metric) {
        $open[$metric->value] = MetricStatus::Outstanding;
    }

    $cell = [
        'can_call' => true, 'can_text' => true, 'can_email' => true,
        'phone' => '(555) 555-0100', 'phone_e164' => '+15555550100', 'email' => 'ann@example.com',
    ];
    $sheet = View::logContactSheet('/x/log-contact', '', 7, 'Ann', $open, 9, $cell);
    assertTrue(str_contains($sheet, 'href="tel:+15555550100">Call (555) 555-0100</a>'), 'Call, with the number');
    assertTrue(str_contains($sheet, 'href="sms:+15555550100">Text</a>'));
    assertTrue(str_contains($sheet, 'href="mailto:ann@example.com">Email</a>'));
    assertTrue(str_contains($sheet, 'class="dials"'));
    assertTrue(str_contains($sheet, 'aria-label="How the contact happened" autofocus'), 'the page opens on the sheet');
    $dials = strpos($sheet, 'class="dials"');
    $type  = strpos($sheet, 'name="contact_type"');
    assertTrue($dials < $type, 'the dial buttons come first');

    $home = ['can_call' => true, 'can_text' => false, 'can_email' => false, 'phone_e164' => '+15555550101', 'email' => ''];
    $sheet = View::logContactSheet('/x/log-contact', '', 7, 'Ann', $open, 9, $home);
    assertTrue(str_contains($sheet, 'tel:'), 'a home phone can be called');
    assertTrue(!str_contains($sheet, 'sms:'), 'and not texted — absent, never disabled');
    assertTrue(!str_contains($sheet, 'mailto:'));

    $none = View::logContactSheet('/x/log-contact', '', 7, 'Ann', $open);
    assertTrue(!str_contains($none, 'class="dials"'), 'an older caller that passes nothing gets no buttons');

    // Both screens pass the row.
    foreach (['dashboard', 'roster'] as $view) {
        assertTrue(preg_match('/\$row\[\'statuses\'\],\s*9,\s*\$row\s*\)/', cl_source('app/views/' . $view . '.php')) === 1,
            $view . '.php hands the sheet the row');
    }
});

// ---------------------------------------------------------------------------
// 6. The folds
// ---------------------------------------------------------------------------

test('the dashboard folds its controls and its cards below 720px, and nothing above it', function (): void {
    $view   = cl_source('app/views/dashboard.php');
    $layout = cl_source('app/views/layout.php');

    assertTrue(str_contains($view, 'id="fold-find" class="fold vh"'), 'the controls fold');
    assertTrue(str_contains($view, 'id="fold-cards" class="fold vh"'), 'the cards fold');
    assertTrue(str_contains($view, '<div class="cards folded">'));
    assertTrue(str_contains($view, 'class="skip" href="#list"'), 'and the lede offers the way past them');

    // Open whenever something inside is in force: a search, a chosen team.
    assertTrue(str_contains($view, "\$findOpen = \$statusPage['search'] !== ''"));
    assertTrue(str_contains($view, "!\$teams['defaulted']"));

    // The CSS folds only on a phone. The label is display:none by default
    // and drawn inside the max-width query; the hide rule lives there too.
    assertTrue(str_contains($layout, '.fold-label { display: none; }'));
    $phone = strpos($layout, '.fold:not(:checked) + .fold-label + .folded { display: none; }');
    $query = strrpos(substr($layout, 0, (int) $phone), '@media (max-width: 719px)');
    assertTrue($phone !== false && $query !== false, 'the hide rule is inside the phone query');

    // The checkboxes are outside every form: they post nowhere.
    $findAt  = strpos($view, 'id="fold-find"');
    $formAt  = strpos($view, '<form class="quick teams"');
    assertTrue($findAt < $formAt, 'the fold control precedes the forms it folds');
    assertSame(0, preg_match('/<form[^>]*>[^<]*<input type="checkbox" id="fold/', $view));
});

// ---------------------------------------------------------------------------
// 7. Selected, counted
// ---------------------------------------------------------------------------

test('"12 selected" is a CSS counter over the ticked boxes, on both screens that tick', function (): void {
    $layout = cl_source('app/views/layout.php');
    assertTrue(str_contains($layout, 'form.pick { counter-reset: sel; }'));
    assertTrue(str_contains($layout, 'form.pick input[type="checkbox"]:checked { counter-increment: sel; }'));
    assertTrue(str_contains($layout, 'form.pick .count::before { content: counter(sel);'));

    foreach (['assign', 'purge'] as $view) {
        $source = cl_source('app/views/' . $view . '.php');
        assertTrue(str_contains($source, 'class="pick"'), $view . '.php marks its form');
        assertTrue(str_contains($source, '<span class="count"></span>'), $view . '.php reads the counter');
    }

    // Still no script anywhere.
    assertSame(0, preg_match('/<script/i', $layout));
});

// ---------------------------------------------------------------------------
// 8. Which of three things "not found" means
// ---------------------------------------------------------------------------

test('the refusal page says which of three things it means, and gives nothing away to a stranger', function (): void {
    $refused = cl_render('not-found', 'Not open to your level', ['user' => cl_user(Level::Officer), 'reason' => 'refused']);
    assertTrue(str_contains($refused, 'Not open to your level'));
    assertTrue(str_contains($refused, 'above Officer'));
    assertTrue(!str_contains($refused, 'nothing at this address'));

    $noYearAdmin = cl_render('not-found', 'No show year is active', ['user' => cl_user(Level::Admin), 'reason' => 'no_year']);
    assertTrue(str_contains($noYearAdmin, 'No show year is active'));
    assertTrue(str_contains($noYearAdmin, 'show-year'), 'an Admin is sent to the screen that fixes it');

    $noYearOfficer = cl_render('not-found', 'No show year is active', ['user' => cl_user(Level::Officer), 'reason' => 'no_year']);
    assertTrue(str_contains($noYearOfficer, 'An Admin makes a show year active'));
    assertTrue(!str_contains($noYearOfficer, 'href="/rerm/show-year"'), 'and an Officer is not');

    $missing = cl_render('not-found', 'Not found', []);
    assertTrue(str_contains($missing, 'There is nothing at this address.'), 'a stranger reads what they always read');

    // A refused reason from an anonymous request is still incurious.
    $anonymousRefused = cl_render('not-found', 'Not found', ['reason' => 'refused']);
    assertTrue(str_contains($anonymousRefused, 'There is nothing at this address.'));

    // The guard says so with a 403; members and keys keep their 404.
    $front = cl_source('public/index.php');
    assertTrue(str_contains($front, "['reason' => 'refused'], 403)"));
    assertSame(6, substr_count($front, "['reason' => 'no_year'], 404)"), 'every no-year site says no year');
    assertTrue(str_contains($front, "if (!status_permitted(\$app)) {\n            render(\$app, 'not-found', 'Not found', [], 404);"), 'a wrong key is a plain 404');
});

// ---------------------------------------------------------------------------
// 9. Landing on the row
// ---------------------------------------------------------------------------

test('every write that changes one row comes back anchored to it', function (): void {
    $front = cl_source('public/index.php');
    assertTrue(str_contains($front, "redirect(\$app, \$return . '#m' . (int) \$result['member_id']);"), 'log-contact');
    assertTrue(str_contains($front, "'member=' . \$memberId . '#m' . \$memberId"), 'designate, with the row open');
    assertTrue(str_contains($front, "'teams#t' . \$teamId"), 'teams');

    assertTrue(str_contains(cl_source('app/src/Roster/LogContact.php'), "'member_id'        => \$memberId,"));
    assertTrue(str_contains(cl_source('app/views/designate.php'), '<tbody class="member" id="m<?= e((string) $row[\'id\']) ?>">'));
    assertTrue(str_contains(cl_source('app/views/teams.php'), '<tr id="t<?= e((string) $team[\'id\']) ?>">'));

    // The row it landed on is marked, and kept clear of the sticky bar.
    $layout = cl_source('app/views/layout.php');
    assertTrue(str_contains($layout, 'tbody.member:target, tr:target, .roster tbody:target { scroll-margin-top: 8rem; }'));
    assertTrue(str_contains($layout, 'tr:target > td:first-child { box-shadow: inset 4px 0 0 var(--rodeo-orange); }'));
});
