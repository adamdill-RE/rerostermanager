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
       dashboard a tbody may hold only the entry row (the sheet renders for
       one member at a time), so the LAST row carries the rule, whichever
       row that is. */
    .roster tr.detail td { padding-top: 0; }
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
    color: var(--action-orange);
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
   is CSS position: sticky and nothing else — there is no JavaScript in this
   application, so it cannot count a live selection and does not pretend to;
   the count comes back in the flash after the write. */
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

/* 56px minimum, one-handed, in gloves: the whole name is the label, so the
   target is the row's width rather than the box. */
.assign td.pick input[type="checkbox"] {
    width: 1.6rem;
    height: 1.6rem;
    accent-color: var(--action-orange);
}
.assign td.who label { display: block; min-height: 44px; padding: .3rem 0; cursor: pointer; }
.assign td .off { display: block; font-size: .9rem; }

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

button.quiet { background: transparent; color: var(--action-orange); border: 1px solid var(--border); min-height: 56px; }
button.danger { background: var(--danger); }

details { margin: .35rem 0; }
summary { cursor: pointer; min-height: 44px; padding: .4rem 0; }
ul.rows { margin: .25rem 0 .75rem; padding-left: 1.1rem; color: var(--muted); font-size: .92rem; }
ul.rows li { margin: .15rem 0; }

input[type="password"], input[type="text"] {
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
a { color: var(--action-orange); }
@media (prefers-color-scheme: dark) { a { color: var(--rodeo-orange); } }
</style>
</head>
<body>
<main<?= ($wide ?? false) ? ' class="wide"' : '' ?>>
<span class="brand"><?= e((string) $app->config()->get('app.name')) ?></span>
<?= $body ?>
</main>
</body>
</html>
