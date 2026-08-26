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
       every width. Roster and dashboard screens use --page-wide (78rem). */
    --page-max: 34rem;
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
footer { margin-top: 2.5rem; color: var(--muted); font-size: .85rem; }
a { color: var(--action-orange); }
@media (prefers-color-scheme: dark) { a { color: var(--rodeo-orange); } }
</style>
</head>
<body>
<main>
<span class="brand"><?= e((string) $app->config()->get('app.name')) ?></span>
<?= $body ?>
</main>
</body>
</html>
