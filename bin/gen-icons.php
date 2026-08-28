<?php

declare(strict_types=1);

/**
 * Generate the tab icon set.
 *
 * The icons themselves are committed — the host has no build step and nothing
 * may require one to run (CLAUDE.md) — so this script is not part of any
 * pipeline. It exists so that the icons are reproducible rather than folklore,
 * and so that replacing the wordmark with a committee logo, if one ever
 * arrives, is one edit and one command.
 *
 *   php bin/gen-icons.php
 *
 *
 * WHY THIS IS A COPY OF RESM'S SCRIPT AND NOT A DIFFERENT MARK
 *
 * The two applications share a domain, a palette and, for most people, a
 * phone. A committeeman signing in to check dues has both open in adjacent
 * tabs, and a tab is sixteen pixels of icon and four characters of title.
 * Two different marks there would read as two organisations rather than as
 * one product with two screens — which is the same reasoning the design
 * system already applies to the colours, applied to the one part of the
 * interface that is visible when the page is not.
 *
 * So the committed files are RESM's, byte for byte, and this script is its
 * generator with the PWA sizes removed: this application is not installable
 * and has no manifest, so an icon-192 nothing names is a file nobody fetches.
 * If the mark ever changes it changes in both repositories, in one commit
 * each, and this script is how the second one is produced.
 *
 * Ink lettering on Rodeo Orange, which is the palette's own rule rather than
 * a choice made here: Rodeo Orange is 2.9:1 on white and takes dark text
 * only, never white (CLAUDE.md → Design system).
 */

$out = dirname(__DIR__) . '/public/assets/icons';
if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}

if (!function_exists('imagettftext')) {
    fwrite(STDERR, "GD with FreeType support is required; the committed icons already exist.\n");
    exit(1);
}

const ORANGE = [0xEF, 0x76, 0x22];
const INK    = [0x2B, 0x20, 0x18];

$fonts = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
];
$font = null;
foreach ($fonts as $candidate) {
    if (is_file($candidate)) {
        $font = $candidate;
        break;
    }
}

if ($font === null) {
    fwrite(STDERR, "No bold sans TTF found; install fonts-dejavu-core and re-run.\n");
    exit(1);
}

/**
 * @param float $inset fraction of the edge to keep the mark clear of.
 */
function icon(string $path, int $size, string $font, float $inset): void
{
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, true);

    $bg = imagecolorallocate($im, ...ORANGE);
    $fg = imagecolorallocate($im, ...INK);
    imagefilledrectangle($im, 0, 0, $size, $size, $bg);

    $text = 'RE';
    $safe = $size * (1 - 2 * $inset);

    // Measured rather than guessed: the point size that makes "RE" fill the
    // safe zone depends on the font that was actually found, and a hard-coded
    // ratio would clip on one and swim on another.
    $points = $safe;
    for ($i = 0; $i < 24; $i++) {
        $box = imagettfbbox($points, 0, $font, $text);
        $width = $box[2] - $box[0];
        $height = $box[1] - $box[7];
        $scale = min($safe / max($width, 1), $safe / max($height, 1));

        if (abs($scale - 1.0) < 0.01) {
            break;
        }
        $points *= $scale;
    }

    $box = imagettfbbox($points, 0, $font, $text);
    $width = $box[2] - $box[0];
    $height = $box[1] - $box[7];
    $x = (int) round(($size - $width) / 2 - $box[0]);
    $y = (int) round(($size - $height) / 2 - $box[7]);

    imagettftext($im, $points, 0, $x, $y, $fg, $font, $text);

    imagepng($im, $path);
    imagedestroy($im);
    chmod($path, 0644);

    printf("  %-28s %dx%d\n", basename($path), $size, $size);
}

echo "Writing icons to public/assets/icons/\n";

// Named in the page head so the browser stops probing the DOCUMENT ROOT for
// /favicon.ico — which is not ours: this application is mounted at /rerm/ and
// the root belongs to the domain, where the landing page and RESM live
// (CLAUDE.md).
icon($out . '/favicon.png', 64, $font, 0.14);

// iOS reads this for a home-screen shortcut and composites it onto its own
// background, so it is full-bleed rather than inset further.
icon($out . '/apple-touch-icon.png', 180, $font, 0.18);

echo "Done.\n";
