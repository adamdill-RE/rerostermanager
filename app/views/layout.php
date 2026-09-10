<?php

declare(strict_types=1);

/**
 * The page shell.
 *
 * One file, inline CSS, no framework and no webfont: the non-functional
 * budget is under 100KB on first paint and under 2s on 3G, because this is a
 * phone tool used in parking lots.
 *
 * Tokens are RESM's, verbatim, so the two applications read as one product.
 * Dark theme is required and follows the device — an officer checking a roster
 * at 06:00 in February should not be flashbanged.
 *
 * @var Rerm\App $app
 * @var string   $title
 * @var string   $body    already-escaped HTML from the view
 * @var bool     $wide    true for a data screen (spec 8.2), false for a list of choices
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e((string) $app->config()->get('app.name')) ?></title>
<meta name="robots" content="noindex, nofollow">
<?php /*
    The dark theme is declared to the browser as well as painted by the CSS
    (Phase 10.3): without color-scheme, a <select>'s drop-down, the date
    picker, the checkboxes and the scrollbars are drawn light on a dark page,
    and without theme-color the phone's own browser chrome stays white above
    it. Two theme-color metas, one per scheme, in the page colours.
*/ ?>
<meta name="color-scheme" content="light dark">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#FFFFFF">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#191310">
<?php /*
    The "RE" tab icon, RESM's own file byte for byte (bin/gen-icons.php says
    why the two applications share one mark rather than having one each).

    Named explicitly for a second reason that has nothing to do with branding:
    an unnamed favicon makes the browser probe the DOCUMENT ROOT for
    /favicon.ico, and the document root is not ours — it is the domain, where
    the landing page sits and RESM is served from the directory beside us. A
    404 there is harmless; a file somebody else's deploy puts there later is
    this application wearing another product's mark.
*/ ?>
<link rel="icon" type="image/png" href="<?= e($app->asset('assets/icons/favicon.png')) ?>">
<link rel="apple-touch-icon" href="<?= e($app->asset('assets/icons/apple-touch-icon.png')) ?>">
<?php /*
    Installable (Phase 10.4, spec-v2 §9.5): the manifest gives Add to Home
    Screen a real icon and a standalone window, which on the working list is
    the address bar's height back — one more row. No service worker, so
    nothing is cached and nothing can go stale; this is a name and an icon.
*/ ?>
<link rel="manifest" href="<?= e($app->asset('manifest.webmanifest')) ?>">
<meta name="apple-mobile-web-app-title" content="RE Roster">
<meta name="mobile-web-app-capable" content="yes">
<style>
:root {
    --rodeo-orange: #EF7622;
    --action-orange: #B85416;
    --rodeo-brown: #7F5E46;
    --rodeo-dust: #C9B29B;
    --dust-light: #F2EAE2;
    --ink: #2B2018;

    --ok: #2F6B3A;
    --warn: #8A5A00;
    --danger: #A32B1C;
    --info: #1F5C8A;

    --page: #FFFFFF;
    --surface: var(--dust-light);
    --text: var(--ink);
    --muted: var(--rodeo-brown);
    --border: var(--rodeo-dust);
    /* Links, in a token of their own (Phase 10.3). Action Orange is 4.9:1 on
       white and 4.1:1 on Dust Light — under spec 10's 4.5:1 — and the links
       that matter sit on Dust Light: menu tiles, empty states, notices, the
       hints under a form. This is 6.4:1 on white and 5.4:1 on the surface.
       Buttons keep Action Orange, where white text is what is measured. */
    --link: #9C4512;

    /* The browser draws its own parts of a form control in this scheme. */
    color-scheme: light dark;

    /* Menu, login and single-record screens keep the narrow phone column at
       every width. Roster and dashboard screens use --page-wide. */
    --page-max: 34rem;
    --page-wide: 78rem;
}

@media (prefers-color-scheme: dark) {
    :root {
        --page: #191310;
        --surface: #241B16;
        --text: #F2EAE2;
        --muted: #C9B29B;
        --border: #4A3729;
        /* Rodeo Orange on the dark surface is 5.9:1. */
        --link: var(--rodeo-orange);

        --ok: #6FBF7F;
        --warn: #D9A441;
        --danger: #E5796A;
        --info: #7FB3D9;
    }
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 2rem 1rem 4rem;
    background: var(--page);
    color: var(--text);
    font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}

main { max-width: var(--page-max); margin: 0 auto; }

/* Spec 8.2. RESM is one screen used outdoors at 02:00; this application is
   also a data-comprehension tool read at a desk, and 34rem is the wrong
   column for a 1,954-row diff. Menu, login and single-member screens keep the
   narrow one at every width — they are lists of choices, not data. */
main.wide { max-width: var(--page-wide); }

h1 { font-size: 1.5rem; line-height: 1.25; margin: 0 0 .25rem; }
h2 { font-size: 1.05rem; margin: 2rem 0 .5rem; }
.card > h2:first-child { margin-top: 0; }

.brand {
    display: inline-block;
    margin-bottom: 1.5rem;
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .09em;
    text-transform: uppercase;
    /* Accent only. 2.9:1 on white, so it never carries body text and never
       has white text on it. */
    color: var(--action-orange);
}

/* The way back to the menu, on every signed-in screen (Phase 8.5).
   Sticky, because the screen it matters on is a 100-row roster: the whole
   point is not having to scroll to the bottom to leave. It bleeds through
   body's padding so it meets the edges of the phone and nothing shows
   beside it as the page scrolls under. */
.topbar {
    position: sticky;
    top: 0;
    z-index: 10;
    margin: -2rem -1rem 1.5rem;
    padding: 0 1rem;
    background: var(--page);
    border-bottom: 1px solid var(--border);
}
.topbar .inner {
    max-width: var(--page-max);
    margin: 0 auto;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0 1rem;
    /* Minimum touch target is 56px — this is used one-handed, outdoors, by
       somebody wearing gloves in February. */
    min-height: 56px;
}
.topbar.wide .inner { max-width: var(--page-wide); }
.topbar .brand { margin: 0; }
.topbar a.back {
    display: inline-flex;
    align-items: center;
    min-height: 56px;
    padding: 0 .25rem;
    font-weight: 700;
    text-decoration: none;
}
.topbar a.back:hover { text-decoration: underline; }
.topbar a.back:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 2px; }
.topbar .who {
    margin-left: auto;
    color: var(--muted);
    font-size: .85rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    /* Last on a phone, where the name would otherwise push the link off. */
    flex: 1 0 100%;
    padding-bottom: .4rem;
}
@media (min-width: 40rem) {
    .topbar .who { flex: 0 1 auto; padding-bottom: 0; }
}
/* Sign out, on every signed-in screen (Phase 10.3): small, quiet, and the
   only control offered while a password change is forced. */
.topbar form.signout { margin: 0; margin-left: auto; }
.topbar form.signout button {
    width: auto;
    min-width: 0;
    min-height: 44px;
    padding: 0 .75rem;
    font-size: .85rem;
    font-weight: 600;
}

/* The screens, in the bar (Phase 10.3). Text links, each a 56px target,
   the current one underlined in the accent; on a phone they wrap to their
   own line under the brand. A desk user hops between Status, Roster and
   Committee all day, and each hop was two taps through the menu. */
.topbar nav.nav { display: flex; flex-wrap: wrap; align-items: center; gap: 0 .15rem; }
.topbar nav.nav a {
    display: inline-flex;
    align-items: center;
    min-height: 56px;
    padding: 0 .5rem;
    font-weight: 700;
    font-size: .95rem;
    text-decoration: none;
    color: var(--link);
}
.topbar nav.nav a:hover { text-decoration: underline; }
.topbar nav.nav a:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: -3px; }
.topbar nav.nav a.current,
.topbar nav.nav a[aria-current="page"] { color: var(--text); box-shadow: inset 0 -3px 0 var(--action-orange); }

/* The notices (Phase 10.3): inside the sticky bar so they survive a landing
   further down the page, and small so they cost the phone one line. */
.topbar .inner.notes { min-height: 0; }
.notices { padding: .3rem 0 .5rem; }
.notice { display: flex; align-items: flex-start; gap: .6rem; padding: .25rem 0; font-size: .92rem; line-height: 1.4; }
.notice .chip { flex: 0 0 auto; margin-top: .1rem; }
main > .notices { margin: -.5rem 0 1rem; }

/* The row a 303 landed on (Phase 10.3): a rule down its left edge, from the
   :target the anchor sets, so "Done" in the bar and the row that changed
   are read together. scroll-margin keeps it out from under the bar. */
tbody.member:target, tr:target, .roster tbody:target { scroll-margin-top: 8rem; }
tbody.member:target > tr.entry > td:first-child,
tbody.member:target > tr:first-child > td:first-child,
tr:target > td:first-child { box-shadow: inset 4px 0 0 var(--rodeo-orange); }

.lede { color: var(--muted); margin: 0 0 1.5rem; }

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1rem 1.15rem;
    margin: 0 0 1rem;
}

dl.facts { display: grid; grid-template-columns: minmax(9rem, auto) 1fr; gap: .4rem 1rem; margin: 0; }
dl.facts dt { color: var(--muted); font-size: .9rem; }
dl.facts dd { margin: 0; overflow-wrap: anywhere; }

code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .92em; }

/* Every status carries a WORD as well as a colour. Nothing in this app is
   ever distinguishable by hue alone. */
.chip {
    display: inline-block;
    padding: .1rem .5rem;
    border-radius: 999px;
    border: 1px solid currentColor;
    font-size: .82rem;
    font-weight: 600;
}
.chip-ok { color: var(--ok); }
.chip-warn { color: var(--warn); }
.chip-danger { color: var(--danger); }
.chip-info { color: var(--info); }
.chip-muted { color: var(--muted); }
/* The filled variant separates In Progress (solid amber) from Contacted
   (amber outline) before the word is even read — spec 5.4's two amber
   states. The text takes the page colour so it stays readable both ways
   round: white on #8A5A00 in light, near-black on #D9A441 in dark. */
.chip-fill { background: currentColor; }
.chip-fill .chip-word { color: var(--page); }

.hint { margin-top: .4rem; }

/* Minimum touch target is 56px, primary 64px — this is used one-handed, on a
   phone, outdoors, by somebody wearing gloves in February. */
button {
    min-height: 64px;
    width: 100%;
    padding: 0 1.25rem;
    border: 0;
    border-radius: 8px;
    /* Action Orange, the one orange that takes white text safely. */
    background: var(--action-orange);
    color: #FFFFFF;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}
button:hover { filter: brightness(1.08); }
button:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 2px; }

/* Above 720px this stops being a phone. RESM is one screen used one-handed
   outdoors in February and every control is sized for that; RERM is also a
   data-comprehension tool used at a desk, which is why the tables transform
   at this width (CLAUDE.md). The controls never got the same treatment, so a
   Search button sat as a full-width 64px slab across a 1300px page.
   Below this width nothing changes: the phone keeps its 64px targets. */
@media (min-width: 720px) {
    button {
        width: auto;
        min-width: 12rem;
        min-height: 48px;
    }

    /* Full width is right inside a narrow column: these are the last control
       in a card or a table cell, where a 12rem button beside nothing reads as
       unfinished rather than as roomy. */
    .roster button[type="submit"],
    form.quick button,
    .mcard button { width: 100%; }

    /* A row of filters is a set of choices, not a set of destinations, so
       each one is as wide as its own label rather than a third of the page. */
    .toggle a { flex: 0 1 auto; min-width: 10rem; min-height: 48px; }
}

label { font-weight: 600; }

/* ONE TEMPLATE, ONE QUERY, TWO LAYOUTS (spec 8.2). The breakpoint is 720px:
   below it every row is a stacked card whose cells carry their own label,
   above it the same markup is a real table. Never a horizontally scrolling
   table on a phone, and never two codebases. */
table { width: 100%; border-collapse: collapse; margin: .5rem 0 0; }
caption { text-align: left; color: var(--muted); font-size: .9rem; padding-bottom: .4rem; }
th { text-align: left; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }

@media (max-width: 719px) {
    table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    table tr {
        display: block;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: .6rem .75rem;
        margin-bottom: .6rem;
    }
    table td { display: flex; justify-content: space-between; gap: 1rem; padding: .15rem 0; border: 0; }
    /* The header text travels with the cell, so a stacked card is readable
       without the column it came from. */
    table td::before { content: attr(data-label); color: var(--muted); font-size: .85rem; }
    table td.num { text-align: right; }
}

@media (min-width: 720px) {
    table th, table td { padding: .4rem .6rem; border-bottom: 1px solid var(--border); }
    table thead th { border-bottom: 2px solid var(--border); font-size: .85rem; color: var(--muted); }
    table td::before { content: none; }
}

/* The roster list (spec 7.2 + 8.2) — the same transformation as above, with
   one refinement: each member is a <tbody> holding their row AND their
   <details> expansion row, so below 720px the CARD is the tbody and the two
   rows stay inside one border instead of splitting into two cards. */
.roster .who { font-weight: 700; }
.roster .who .sub { display: block; font-weight: 400; color: var(--muted); font-size: .85rem; }
/* The quiet notes a roster row carries beside a value rather than instead of
   one: the Result column's "2 of 3" coverage, and "loaded from history" on a
   contact that came out of a spreadsheet. Muted and small, because each
   qualifies the thing in front of it. */
.roster .why { color: var(--muted); font-size: .88rem; }
.roster td.actions a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    text-decoration: none;
}

@media (max-width: 719px) {
    .roster tbody.member {
        display: block;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: .6rem .75rem;
        margin-bottom: .6rem;
    }
    .roster tr { border: 0; border-radius: 0; padding: 0; margin: 0; }
    .roster td.who { display: block; font-size: 1.05rem; }
    .roster td.who::before { content: none; }
    /* The four metric chips share one wrapped line, each with its short
       label riding along as the cell's data-label. */
    .roster td.metric { display: inline-flex; align-items: center; gap: .3rem; margin: .1rem .6rem .1rem 0; }
    /* Call / Text / Email as a row of real touch targets — 56px minimum,
       gloves in February. Absent actions leave no gap: only what works
       is rendered. */
    .roster td.actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
    .roster td.actions::before { content: none; }
    .roster td.actions a {
        flex: 1;
        min-height: 56px;
        border: 1px solid var(--border);
        border-radius: 8px;
    }
    .roster td.expand { display: block; }
    .roster td.expand::before { content: none; }
}

@media (min-width: 720px) {
    .roster td.actions a { margin-right: .75rem; min-height: 44px; padding: 0 .25rem; }
    /* The expansion row reads as part of the row above it: the member's own
       row keeps a light rule, the expansion carries the section one. On the
       dashboard a tbody may hold TWO detail rows — the details expansion and,
       for the one member being worked, the log-contact sheet — so no detail
       row draws a rule of its own and the LAST row of the tbody carries it,
       whichever row that turns out to be. A rule between them would split one
       member into two cards. */
    .roster tr.detail td { padding-top: 0; border-bottom: 0; }
    .roster tr.entry td { border-bottom: 0; }
    .roster tbody.member tr:last-child td { border-bottom: 1px solid var(--border); }
}

/* --- My Roster Status (spec 7.1, decided 4) ------------------------------
   The overall banner and the four nested metric cards: a 2x2 grid on a
   phone, one row on a desktop. Every number pairs with a word; the bars
   carry their numbers in title attributes and the legend spells them out. */
.toggle { display: flex; gap: .5rem; margin: 0 0 1rem; }
.toggle a {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 56px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-weight: 700;
    text-decoration: none;
}
.toggle a.current { background: var(--action-orange); border-color: var(--action-orange); color: #FFFFFF; }

.overall, .mcard {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: .85rem 1rem;
}
.overall { margin-bottom: .75rem; }
.overall h2, .mcard h2 { font-size: .95rem; margin: 0 0 .15rem; }
.headline { font-variant-numeric: tabular-nums; margin: 0 0 .5rem; }
.overall .headline strong { font-size: 1.6rem; }
.mcard .headline strong { font-size: 1.3rem; }
.headline .out { color: var(--muted); font-size: .85rem; display: block; }

.cards { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: 1.5rem; }
@media (min-width: 720px) { .cards { grid-template-columns: repeat(4, 1fr); } }

/* The stacked proportion bar: one span per non-zero status, ladder order. */
.bar {
    display: flex;
    height: 14px;
    border-radius: 7px;
    overflow: hidden;
    border: 1px solid var(--border);
    background: var(--page);
}
.bar span { display: block; height: 100%; }
.s-complete { background: var(--ok); }
.s-reported { background: var(--info); }
.s-handling { background: var(--warn); }
/* Contacted is the OUTLINE amber state: hatched, so it reads apart from
   Member Handling inside a bar even before the legend is read. */
.s-contacted { background: repeating-linear-gradient(135deg, var(--warn) 0 3px, var(--page) 3px 6px); }
.s-open { background: var(--danger); }
.s-notrep { background: var(--muted); }

.legend { list-style: none; margin: .5rem 0 0; padding: 0; font-size: .8rem; color: var(--muted); }
.legend li { display: flex; align-items: center; gap: .35rem; padding: .05rem 0; font-variant-numeric: tabular-nums; }
.legend .dot { width: .65rem; height: .65rem; border-radius: 3px; border: 1px solid var(--border); flex: 0 0 auto; }
.legend .n { margin-left: auto; color: var(--text); font-weight: 600; }

/* Every status word is a button that opens its definition — the native HTML
   popover attribute, declarative, no JavaScript, CSP untouched (decided 6). */
button.deflink {
    all: unset;
    cursor: pointer;
    color: inherit;
    font: inherit;
    text-decoration: underline dotted;
    text-underline-offset: 2px;
}
button.deflink:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 2px; }

[popover] {
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
    color: var(--text);
    padding: .85rem 1rem;
    max-width: 22rem;
    margin: auto;
    box-shadow: 0 6px 24px rgba(0, 0, 0, .25);
}
[popover] h3 { margin: 0 0 .25rem; font-size: 1rem; }
[popover] p { margin: 0; font-size: .9rem; }
[popover]::backdrop { background: rgba(0, 0, 0, .35); }
[popover] button.close {
    all: unset;
    cursor: pointer;
    color: var(--link);
    font-weight: 700;
    min-height: 44px;
    margin-top: .5rem;
    display: inline-flex;
    align-items: center;
}

/* The same definitions, always reachable, for a browser without popover
   support — there the deflink buttons are inert. */
details.defs { margin-top: 1.5rem; }
details.defs summary { color: var(--muted); }
/* The Result column's words sit under their own heading in here, beside the
   metric statuses'. A page h2's 2rem of air would open a hole inside a small
   box that is already one paragraph long. */
details.defs h2 { font-size: 1rem; margin: 1.25rem 0 .25rem; }
details.defs dt { font-weight: 700; margin-top: .5rem; }
details.defs dd { margin: 0; color: var(--muted); font-size: .9rem; }

/* The per-row log-contact sheet: a compact form inside the row's details. */
.roster form { margin: .5rem 0 0; }
.roster p.lc { display: grid; gap: .5rem; margin: .5rem 0; }
.roster .pgh { margin: .35rem 0 .15rem; font-size: .9rem; }
.roster label.pg { display: flex; align-items: center; gap: .6rem; margin: .35rem 0; font-weight: 700; }
.roster label.pg select { flex: 1; width: auto; }
.roster button[type="submit"] { margin: .5rem 0 .75rem; }

/* --- Assign Officers (spec 7.4) --------------------------------------------
   The checkbox column, the sticky action bar and the bucket counts. The bar
   is CSS position: sticky, and since Phase 10.3 a CSS counter (form.pick,
   below) reads "12 selected" in it live — no JavaScript in this application,
   and none needed. The flash after the write still names what landed. */
.toggle a .n {
    margin-left: .4rem;
    padding: 0 .4rem;
    border-radius: 999px;
    border: 1px solid currentColor;
    font-size: .8rem;
    font-variant-numeric: tabular-nums;
}
.toggle { flex-wrap: wrap; }
.toggle a { flex: 1 1 10rem; padding: 0 .6rem; text-align: center; }

/* "12 selected", with no script (Phase 10.3). CSS counters count the ticked
   boxes in document order, and the action bar sits after the table, so the
   count in it is live as the boxes are ticked. The comment above once said
   this could not be done; it can, in three rules. :has() only dims the
   button while nothing is ticked — the server still answers a bare press. */
form.pick { counter-reset: sel; }
form.pick input[type="checkbox"]:checked { counter-increment: sel; }
form.pick .count::before { content: counter(sel); font-variant-numeric: tabular-nums; }
form.pick:not(:has(input[type="checkbox"]:checked)) .actionbar button.needs { opacity: .55; }

/* 56px minimum, one-handed, in gloves: the whole name is the label, so the
   target is the row's width rather than the box. */
.assign td.pick input[type="checkbox"] {
    width: 1.6rem;
    height: 1.6rem;
    accent-color: var(--action-orange);
}
.assign td.who label { display: block; min-height: 44px; padding: .3rem 0; cursor: pointer; }
.assign td .off { display: block; font-size: .9rem; }
/* The title reads as supporting detail beside the name, not as a heading
   competing with it. */
.assign td.title { color: var(--muted); font-size: .9rem; }

.actionbar {
    position: sticky;
    bottom: 0;
    z-index: 1;
    margin-top: .5rem;
    padding: .6rem .75rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 -4px 14px rgba(0, 0, 0, .12);
}
.actionbar p.ab { display: grid; gap: .5rem; margin: .35rem 0; }
@media (min-width: 720px) {
    .actionbar p.ab { grid-template-columns: 1fr auto; align-items: center; }
    .actionbar p.ab button { width: auto; min-width: 14rem; }
}

form.quick { margin: 1rem 0; padding: .75rem .9rem; border: 1px dashed var(--border); border-radius: 10px; }
form.quick label { display: block; margin-bottom: .4rem; }
form.quick button { margin-top: .5rem; }

/* The team picker (Phase 10). On a phone it stacks like every other quick
   form and every target stays full size; at a desk the select and its button
   are one control and read as one, rather than as two full-width slabs above
   a 1300px page. The same departure Phase 8.6 made for buttons, at the same
   breakpoint and for the same reason. */
@media (min-width: 720px) {
    form.quick.teams { display: grid; grid-template-columns: 1fr auto; gap: .5rem .75rem; align-items: center; }
    form.quick.teams label { grid-column: 1 / -1; margin: 0; }
    form.quick.teams select { min-height: 48px; }
    form.quick.teams button { width: auto; min-width: 12rem; min-height: 48px; margin: 0; }
    form.quick.teams p.hint { grid-column: 1 / -1; margin: 0; }

    /* The dashboard's search box (Phase 10.2): the same one-control shape as
       the team picker above it, for the same reason. */
    form.quick.find { display: grid; grid-template-columns: 1fr auto; gap: .5rem .75rem; align-items: center; }
    form.quick.find label { grid-column: 1 / -1; margin: 0; }
    form.quick.find input[type="search"] { min-height: 48px; }
    form.quick.find button { width: auto; min-width: 12rem; min-height: 48px; margin: 0; }
    form.quick.find p.hint { grid-column: 1 / -1; margin: 0; }
}

/* The folds on My Roster Status (Phase 10.3). Below 720px a fold is a
   56px label that opens its content; above it the label is not drawn and
   the content always is. The checkbox is visually hidden but focusable, so
   a keyboard toggles it with Space and the label shows the ring. */
.fold-label { display: none; }
@media (max-width: 719px) {
    .fold-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        min-height: 56px;
        margin: 0 0 .75rem;
        padding: .4rem .9rem;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
    }
    .fold-label::after { content: "Show"; color: var(--link); font-size: .9rem; flex: 0 0 auto; }
    .fold:checked + .fold-label::after { content: "Hide"; }
    .fold:focus-visible + .fold-label { outline: 3px solid var(--rodeo-orange); outline-offset: 2px; }
    .fold:not(:checked) + .fold-label + .folded { display: none; }
    .fold-label .strip { display: flex; flex-wrap: wrap; gap: .1rem .6rem; font-weight: 400; font-size: .92rem; }
    .fold-label .strip strong { font-variant-numeric: tabular-nums; }
    .fold-label .strip .out { color: var(--muted); }
}

/* The way past the cards to the list, in the lede (Phase 10.3). */
.lede a.skip { display: inline-block; font-weight: 700; margin-top: .25rem; }

/* The name on a roster row is the way to the member's card (Phase 10.4):
   text-coloured with a quiet underline, so fifty names do not read as fifty
   orange links, and the link colour on hover so it still reads as one. */
a.card-link { color: inherit; text-decoration: underline; text-decoration-color: var(--border); text-underline-offset: 3px; }
a.card-link:hover { color: var(--link); text-decoration-color: currentColor; }
.assign td.who a.open { font-size: .85rem; font-weight: 600; margin-left: .35rem; }

/* One answer for everything open (Phase 10.4): the radio row, and the
   per-metric selects folded under it. */
fieldset.pgall { border: 1px solid var(--border); border-radius: 8px; padding: .4rem .75rem .6rem; margin: .35rem 0; }
fieldset.pgall legend { font-size: .9rem; font-weight: 600; padding: 0 .3rem; }
label.pga { display: flex; align-items: center; gap: .6rem; min-height: 44px; font-weight: 600; }
label.pga input[type="radio"] { width: 1.3rem; height: 1.3rem; accent-color: var(--action-orange); }
details.pgeach { margin: .25rem 0 .5rem; }
details.pgeach summary { color: var(--muted); font-size: .9rem; }

/* The member card (Phase 10.4): the log form in a card rather than a row. */
.card.roster form { margin: 0; }
.card.member dl.facts { margin-top: .75rem; }

/* Call, Text and Email inside the open log-contact sheet (Phase 10.3) and on
   the member card (Phase 10.4): the first and largest targets, 64px like a
   primary button, so the dial happens there and the return lands on the
   form. */
p.dials { display: flex; flex-wrap: wrap; gap: .5rem; margin: .5rem 0; }
p.dials a.dial {
    flex: 1 1 8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 64px;
    padding: 0 1rem;
    border-radius: 8px;
    background: var(--action-orange);
    color: #FFFFFF;
    font-weight: 700;
    text-decoration: none;
}
p.dials a.dial:hover { filter: brightness(1.08); }
p.dials a.dial:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 2px; }
@media (min-width: 720px) {
    p.dials a.dial { flex: 0 1 auto; min-width: 10rem; min-height: 48px; }
}

/* The menu, grouped by job (Phase 10.4): a heading per group and one
   56px link row per screen, the screen's name and one line on what it is
   for. Fifteen identical cards read as a list; three groups read as a
   shape. */
h2.menu-group { margin: 1.75rem 0 .35rem; font-size: 1.1rem; }
h2.menu-group .why { display: block; font-weight: 400; font-size: .9rem; color: var(--muted); }
ul.menu { list-style: none; margin: 0 0 .5rem; padding: 0; border-top: 1px solid var(--border); }
ul.menu li { border-bottom: 1px solid var(--border); }
ul.menu a, ul.menu li > span.what { display: block; padding: .7rem .25rem; min-height: 56px; text-decoration: none; }
ul.menu a .what { display: block; font-weight: 700; color: var(--link); }
ul.menu a .why, ul.menu li > span.why { display: block; font-size: .9rem; color: var(--muted); }
ul.menu a:hover .what { text-decoration: underline; }

/* The roster's team filter (Phase 10.4): a fold of tick boxes, compact. */
details.teams { border: 1px dashed var(--border); border-radius: 8px; padding: 0 .75rem; margin: .5rem 0 1rem; }
details.teams summary { font-weight: 600; }
details.teams fieldset { border: 0; padding: 0 0 .5rem; margin: 0; }
details.teams .choice { min-height: 48px; padding: .25rem 0; }

/* The skip link (Phase 10.4): hidden until it takes focus, then the first
   thing on the page, above the sticky bar. Every screen's <main> is
   #content. */
a.skip-main {
    position: absolute;
    left: -100vw;
    top: 0;
    z-index: 20;
    padding: .6rem 1rem;
    background: var(--action-orange);
    color: #FFFFFF;
    font-weight: 700;
    text-decoration: none;
}
a.skip-main:focus-visible { left: 1rem; top: .5rem; outline: 3px solid var(--rodeo-orange); }

/* Keyboard focus on the controls that had none (Phase 10.4): plain links,
   selects, and the toggle whose orange current state hid the ring. */
a:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 2px; border-radius: 3px; }
select:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 1px; }
.toggle a:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 3px; }

/* Visible to a screen reader, not to the eye: the checkbox column header and
   the two action-bar selects, whose buttons already say what they do. */
.vh {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

@media (max-width: 719px) {
    /* The stacked card puts the checkbox beside the name rather than on its
       own labelled line. */
    .assign td.pick { display: inline-flex; margin-right: .6rem; vertical-align: top; }
    .assign td.pick::before { content: none; }
    .assign td.who { display: inline-block; width: calc(100% - 3rem); }
}

/* --- Committee Dashboard (spec 7.3) ----------------------------------------
   One table, three levels, two layouts. Above 720px the level shows as
   indentation and weight; below it the row is a stacked card and the level
   travels as a word in the name cell, because indentation does not survive
   the transform. */
.committee td.grp .lvl {
    display: inline-block;
    min-width: 4.2rem;
    color: var(--muted);
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.committee td.grp .sub { display: block; color: var(--muted); font-size: .85rem; }
.committee td.grp a { font-weight: 700; }
.committee tr.lv-division td.grp { font-size: 1.02rem; }
.committee td.metric { font-variant-numeric: tabular-nums; }
.committee td.metric .bar { height: 8px; border-radius: 4px; }
.committee td.metric .mn { font-size: .82rem; color: var(--muted); }

@media (max-width: 719px) {
    /* The four requirement cells share wrapped lines, each carrying its own
       short label — the roster card's pattern, with the bar under the name. */
    .committee td.metric {
        display: inline-block;
        width: 47%;
        margin: .15rem 1% .15rem 0;
        vertical-align: top;
    }
    .committee td.metric::before { display: block; }
    .committee td.grp { display: block; font-size: 1.05rem; }
    .committee td.grp::before { content: none; }
    /* The three triage numbers read as a row of their own under the bars. */
    .committee tr.lv-area td.grp { padding-left: .6rem; }
    .committee tr.lv-team td.grp { padding-left: 1.2rem; }
}

/* All teams on one page (Phase 10.3): no tree, so no indentation. */
.committee.flat tr.lv-team td.grp { padding-left: .6rem; }
@media (min-width: 720px) {
    .committee td.grp .lvl { min-width: 0; margin-right: .4rem; }
    .committee tr.lv-area td.grp { padding-left: 1.6rem; }
    .committee tr.lv-team td.grp { padding-left: 3rem; }
    .committee.flat tr.lv-team td.grp { padding-left: .6rem; }
    .committee tr.lv-division td.grp { border-left: 3px solid var(--action-orange); }
    .committee td.metric { white-space: nowrap; }
    /* inline-FLEX, not inline-block: .bar lays its segments out with flex
       and a block display would stack them vertically. */
    .committee td.metric .bar { display: inline-flex; width: 3.4rem; vertical-align: middle; }
    .committee td.metric .mn { margin-left: .35rem; }
    .committee th.num a { white-space: nowrap; }
}

/* The Roster Change Form (spec-v2 §2) — twenty-five rows of ten fields, and
   the only screen here that is a GRID somebody types into rather than a list
   they read. It transforms at 720px like every other table in this
   application: the paper form's own layout at a desk, a stacked card per row
   on a phone, one template either way.

   Above 720px the controls come down to 40px. That is the departure Phase 8.6
   already made for buttons, for the same reason and with the same limit: a
   56px control twenty-five rows deep is a 1,600px page nobody can see the
   shape of, and this is a form filled in at a desk from a list of people.
   Below 720px NOTHING changes and every target stays full size. */
.rcf input, .rcf select { min-height: 56px; }
.rcf td.tick { text-align: center; }
.rcf td.tick input[type="checkbox"] {
    width: 1.5rem;
    height: 1.5rem;
    min-height: 0;
    accent-color: var(--action-orange);
}

@media (max-width: 719px) {
    .rcf td { display: block; padding: .3rem 0; }
    .rcf td::before { display: block; margin-bottom: .15rem; }
    .rcf td.n {
        font-weight: 700;
        color: var(--text);
        border-bottom: 1px solid var(--border);
        padding-bottom: .35rem;
        margin-bottom: .35rem;
    }
    .rcf td.n::before { content: none; }
    .rcf td.tick { text-align: left; }
}

@media (min-width: 720px) {
    .rcf { table-layout: fixed; }
    .rcf th, .rcf td { padding: .2rem .25rem; vertical-align: middle; }
    .rcf thead th { font-size: .78rem; line-height: 1.25; white-space: normal; }
    .rcf input, .rcf select {
        min-height: 40px;
        padding: 0 .35rem;
        font-size: .88rem;
        border-radius: 6px;
    }
    .rcf td.n {
        text-align: right;
        color: var(--muted);
        font-variant-numeric: tabular-nums;
        font-size: .85rem;
    }
}

textarea {
    width: 100%;
    min-height: 56px;
    padding: .5rem .75rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--page);
    color: var(--text);
    font: inherit;
}
textarea:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 1px; }

select, input[type="file"] {
    width: 100%;
    min-height: 56px;
    padding: .5rem .75rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--page);
    color: var(--text);
    font: inherit;
}

/* 56px minimum, one-handed, outdoors, in gloves. A radio the size of a full
   stop is a radio nobody hits. */
.choice { display: flex; align-items: flex-start; gap: .75rem; min-height: 56px; padding: .5rem 0; font-weight: 400; }
.choice input[type="radio"],
.choice input[type="checkbox"] { width: 1.4rem; height: 1.4rem; margin-top: .2rem; flex: 0 0 auto; accent-color: var(--action-orange); }
.choice .what { font-weight: 700; }
.choice .why { display: block; color: var(--muted); font-size: .88rem; }

button.quiet { background: transparent; color: var(--link); border: 1px solid var(--border); min-height: 56px; }

/* A link that leads to a step, drawn as the quiet button it stands where
   (Phase 10.3): Make active… and Re-open… on Show Year open the confirm
   card by GET, so they are links, and a link in a cell of buttons should
   be the same 56px target. */
a.btnlink {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 56px;
    padding: 0 1.25rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-weight: 700;
    text-decoration: none;
}
a.btnlink:hover { text-decoration: underline; }
a.btnlink:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 2px; }
@media (min-width: 720px) { a.btnlink { width: auto; min-width: 12rem; min-height: 48px; } }
button.danger { background: var(--danger); }

details { margin: .35rem 0; }
summary { cursor: pointer; min-height: 44px; padding: .4rem 0; }
ul.rows { margin: .25rem 0 .75rem; padding-left: 1.1rem; color: var(--muted); font-size: .92rem; }
ul.rows li { margin: .15rem 0; }

input[type="password"], input[type="text"], input[type="search"], input[type="date"] {
    width: 100%;
    min-height: 56px;
    padding: 0 .75rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--page);
    color: var(--text);
    font: inherit;
}
input:focus-visible { outline: 3px solid var(--rodeo-orange); outline-offset: 1px; }

form { margin: 1rem 0 0; }
footer { margin-top: 2.5rem; color: var(--muted); font-size: .85rem; }

/* The shell's own footer, outside <main>, carrying the running version.
   Several screens end with a <footer> of their own inside the column; this
   one is a rule across the bottom of the page under all of them, so the two
   never read as one paragraph. It follows the column the page chose, for the
   same reason every other element does — a 78rem rule under a 34rem menu
   would look like a different page. */
footer.shell {
    max-width: var(--page-max);
    margin: 3rem auto 0;
    padding-top: .9rem;
    border-top: 1px solid var(--border);
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: .25rem 1rem;
    color: var(--muted);
    font-size: .78rem;
}
footer.shell.wide { max-width: var(--page-wide); }
footer.shell .ver { font-variant-numeric: tabular-nums; }
a { color: var(--link); }

/* PRINT (Phase 10.4, spec-v2 §9.6). A Division Chairman prints the roll-up
   for a meeting and a Captain prints their twenty names; both got the sticky
   bar, the forms, the orange links and, on a narrow print width, the stacked
   cards. On paper: the table layout whatever the width, every <details>
   open, black on white, no controls. The chips already carry words, so the
   page survives monochrome. */
@media print {
    :root { --page: #FFFFFF; --surface: #FFFFFF; --text: #000000; --muted: #333333; --border: #999999; --link: #000000; }
    body { padding: 0; font-size: 11pt; }
    .topbar, footer.shell, form, .actionbar, .toggle, .fold-label, a.skip-main, p.dials,
    .roster td.actions, [popover], details.defs, a.btnlink, button, .notices { display: none !important; }
    main, main.wide { max-width: none; }
    a { color: inherit; text-decoration: none; }
    a.card-link { text-decoration: none; }
    details { display: block; }
    details > summary { display: none; }
    details:not([open]) > *:not(summary) { display: block; }
    .fold:not(:checked) + .fold-label + .folded { display: block !important; }
    .chip { border: 1px solid #000; color: #000; }
    .chip-fill { background: none; }
    .chip-fill .chip-word { color: #000; }
    .bar { border: 1px solid #000; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    table thead { position: static; width: auto; height: auto; overflow: visible; clip: auto; }
    table tr { display: table-row; border: 0; padding: 0; margin: 0; }
    table td, table th { display: table-cell; padding: .2rem .4rem; border-bottom: 1px solid #ccc; }
    table td::before { content: none; }
    .roster tbody.member { display: table-row-group; border: 0; padding: 0; margin: 0; }
    .roster td.who { display: table-cell; font-size: inherit; }
    .roster td.metric { display: table-cell; margin: 0; }
    .roster td.expand { display: table-cell; }
    .committee td.metric { display: table-cell; width: auto; }
    .committee td.metric .bar { display: inline-flex; width: 3.4rem; }
    .cards { grid-template-columns: repeat(4, 1fr); }
    h1::after { content: " — " attr(data-print); font-weight: 400; font-size: .8em; color: #333; }
    tr, tbody.member { break-inside: avoid; }
}
</style>
</head>
<body>
<?php /*
    The nav strip renders for a signed-in user and for nobody else, and it
    needs no change to any view to do it: render() extracts the view's data
    into ITS OWN scope and then requires this file from there, so $user is
    already here for the thirteen screens that pass one. Login, forgot,
    reset and an anonymous not-found pass none, and get the plain brand
    below instead.
*/ ?>
<?php
/*
    The notices (Phase 10.3): one component, View::notice(), one vocabulary —
    Done / Note / Stopped — and one place, so a screen cannot spell its own.
    They ride INSIDE the sticky bar for a signed-in user: a 303 that lands on
    the row it changed (R5) scrolls the top of the page away, and a notice
    that scrolled with it is a write nobody saw confirmed. role="status" so a
    screen reader hears it without focus moving. An anonymous screen has no
    bar, so there they sit at the top of the column.
*/
$noticeHtml = '';
foreach ($notices ?? [] as [$level, $message]) {
    $noticeHtml .= Rerm\View::notice((string) $level, (string) $message);
}
if ($noticeHtml !== '') {
    $noticeHtml = '<div class="notices" role="status">' . $noticeHtml . '</div>';
}

/*
    The screens in the bar (Phase 10.3): the four working screens, filtered by
    capability exactly as the menu filters its tiles — presentation only,
    every route re-checks. Whichever one is being drawn carries aria-current.
    $view is render()'s own parameter, in scope because the layout is
    required from there; a caller that renders another way gets no current.
*/
$navItems = [
    ['dashboard', 'Status',    Rerm\Auth\Capability::ViewStatusDashboard],
    ['roster',    'Roster',    Rerm\Auth\Capability::ViewRoster],
    ['assign',    'Assign',    Rerm\Auth\Capability::AssignOfficers],
    ['committee', 'Committee', Rerm\Auth\Capability::ViewCommitteeDashboard],
];
$currentView = isset($view) && is_string($view) ? $view : '';
?>
<a class="skip-main" href="#content">Skip to content</a>
<?php if (isset($user) && $user instanceof Rerm\Auth\User) { ?>
    <header class="topbar<?= ($wide ?? false) ? ' wide' : '' ?>">
        <div class="inner">
            <span class="brand"><?= e((string) $app->config()->get('app.name')) ?></span>
            <?php if ($user->mustChangePassword) { ?>
                <?php /* Every other route 303s back to /password until the
                         change is made (spec 3.2), so a Menu link here is a
                         link that loops. Sign out is the one other thing a
                         person half signed-in may do, and it is offered. */ ?>
            <?php } else { ?>
                <nav class="nav" aria-label="Screens">
                    <?php foreach ($navItems as [$route, $word, $capability]) { ?>
                        <?php if (!Rerm\Auth\Access::mayUse($user, $capability)) { continue; } ?>
                        <a href="<?= e($app->url($route)) ?>"<?= $currentView === $route
                            ? ' class="current" aria-current="page"' : '' ?>><?= e($word) ?></a>
                    <?php } ?>
                    <a class="back" href="<?= e($app->url('menu')) ?>"<?= $currentView === 'menu'
                        ? ' aria-current="page"' : '' ?>>&larr; Menu</a>
                </nav>
            <?php } ?>
            <div class="who">
                <span><?= e($user->displayName) ?> &middot; <?= e($user->level->label()) ?></span>
                <form class="signout" method="post" action="<?= e($app->url('logout')) ?>">
                    <?= Rerm\Csrf::field() ?>
                    <button type="submit" class="quiet">Sign out</button>
                </form>
            </div>
        </div>
        <?php if ($noticeHtml !== '') { ?>
            <div class="inner notes"><?= $noticeHtml ?></div>
        <?php } ?>
    </header>
<?php } ?>
<main id="content"<?= ($wide ?? false) ? ' class="wide"' : '' ?>>
<?php if (!isset($user) || !$user instanceof Rerm\Auth\User) { ?>
    <span class="brand"><?= e((string) $app->config()->get('app.name')) ?></span>
    <?= $noticeHtml ?>
<?php } ?>
<?= $body ?>
</main>
<?php /*
    The running version, on every screen including the ones nobody is signed
    in to — /login is exactly where somebody reporting "it still does the old
    thing" is standing, and a version they cannot reach without signing in is
    a version they cannot read out. It is a configured constant, not a build
    stamp: there is no build step on this host (App::version).
*/ ?>
<footer class="shell<?= ($wide ?? false) ? ' wide' : '' ?>">
    <span><?= e((string) $app->config()->get('app.name')) ?></span>
    <span class="ver">Version <?= e($app->version()) ?></span>
</footer>
</body>
</html>
