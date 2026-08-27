<?php

declare(strict_types=1);

namespace Rerm;

use DateTimeImmutable;
use DateTimeZone;
use Rerm\Roster\MetricStatus;

/**
 * The two rendering fragments every roster-shaped screen repeats: the status
 * chip and the relative timestamp. Promoted out of app/views/roster.php when
 * Phase 5's dashboard became the second screen to need them — one spelling of
 * each, because a chip that renders differently on two screens is a status
 * that reads differently on two screens.
 *
 * Everything here returns ALREADY-ESCAPED HTML or plain strings the caller
 * still escapes; each method says which it is.
 */
final class View
{
    /**
     * A chip: always a word plus a colour, never a colour alone (spec 8.3).
     * The inner span exists only on the filled variant, where the word has to
     * take the page colour; everywhere else the word rides directly in the
     * chip — this markup repeats up to 500 times a page against a 100KB
     * budget. Returns escaped HTML, safe to echo.
     */
    public static function chip(MetricStatus $status): string
    {
        $class = $status->chipClass();
        $word  = str_contains($class, 'chip-fill')
            ? '<span class="chip-word">' . e($status->label()) . '</span>'
            : e($status->label());

        return '<span class="chip ' . e($class) . '">' . $word . '</span>';
    }

    /**
     * The stacked proportion bar: one segment per NON-ZERO status, in
     * MetricStatus::ladder() order, widths summing to 100% of $total. Zero
     * counts render nothing at all — a segment of width zero is bytes that
     * draw no pixels, and this markup repeats four times a row.
     *
     * $titles adds a per-segment `title` naming the state and its count. My
     * Roster Status wants it: four big cards, and the hover is how the exact
     * number is read off a bar. The Committee Dashboard does not: at up to
     * forty rows the attribute is ~35 bytes x 4 segments x 4 metrics x 40
     * rows against a 100KB first-paint budget (spec 10), and that screen
     * prints the count beside the bar instead.
     *
     * Returns escaped HTML, safe to echo.
     *
     * @param array<string, int> $counts MetricStatus->value => count
     */
    public static function bar(array $counts, int $total, bool $titles = false): string
    {
        $html = '<div class="bar">';

        foreach (MetricStatus::ladder() as $status) {
            $n = (int) ($counts[$status->value] ?? 0);
            if ($n === 0) {
                continue;
            }

            $html .= '<span class="' . e($status->barClass()) . '" style="width:'
                . e(number_format($n * 100 / max(1, $total), 1)) . '%"'
                . ($titles ? ' title="' . e($status->label()) . ' ' . e(number_format($n)) . '"' : '')
                . '></span>';
        }

        return $html . '</div>';
    }

    /**
     * A UTC DATETIME as relative words with the absolute (the display
     * timezone via $app->toDisplay) to ride in the title attribute. Returns
     * two PLAIN strings — the caller escapes both.
     *
     * @return array{0: string, 1: string} words, absolute
     */
    public static function when(App $app, string $utc): array
    {
        $seconds  = max(0, time() - (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp());
        $display  = $app->toDisplay($utc);
        $absolute = $display->format('j M Y, g:i a');

        $ago = static fn (int $n, string $unit): string => $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';

        $words = match (true) {
            $seconds < 90         => 'just now',
            $seconds < 3600       => $ago(max(1, intdiv($seconds, 60)), 'minute'),
            $seconds < 86400      => $ago(intdiv($seconds, 3600), 'hour'),
            $seconds < 45 * 86400 => $ago(intdiv($seconds, 86400), 'day'),
            default               => $display->format('j M Y'),
        };

        return [$words, $absolute];
    }
}
