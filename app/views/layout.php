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
.choice input[type="radio"] { width: 1.4rem; height: 1.4rem; margin-top: .2rem; flex: 0 0 auto; accent-color: var(--action-orange); }
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
