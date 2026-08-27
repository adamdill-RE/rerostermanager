<?php

declare(strict_types=1);

namespace Rerm\Roster;

/**
 * The effective status of one metric for one member (spec 5.4).
 *
 * THE single function every screen uses — the chips on View My Roster, the
 * dashboard and list on My Roster Status, the roll-ups on the Committee
 * Dashboard, and the export all call derive() and nothing anywhere re-derives
 * the table. It is a pure function of three inputs, so
 * tests/roster_test.php can prove it by enumerating all 18 combinations
 * (3 imported values x 3 progress states x contacted-or-not).
 *
 *   imported = Y                                        -> Complete     (green)
 *   imported = unknown                                  -> Not reported (grey)
 *   imported = N, progress = claimed_complete           -> Reported     (blue)
 *   imported = N, progress = in_progress                -> In Progress  (amber)
 *   imported = N, progress = not_started, contacted     -> Contacted    (amber outline)
 *   imported = N, progress = not_started, never contacted -> Outstanding (red)
 *
 * "Contacted this year" means any contact_log row for the member and the
 * active show year — about the member, not the metric, because a phone call
 * covers whatever was discussed on it.
 *
 * Every chip is a WORD plus a colour, never a colour alone (spec 8.3), which
 * is why the label lives here beside the derivation: a screen that has the
 * status has its word and cannot render a bare hue.
 */
enum MetricStatus: string
{
    case Complete    = 'complete';
    case Reported    = 'reported';
    case InProgress  = 'in_progress';
    case Contacted   = 'contacted';
    case Outstanding = 'outstanding';
    case NotReported = 'not_reported';

    /**
     * @param string $importedValue member_metric.imported_value: Y | N | unknown
     * @param string $progress      member_metric.progress:
     *                              not_started | in_progress | claimed_complete
     * @param bool   $contacted     any contact_log row for this member and the
     *                              active show year
     */
    public static function derive(string $importedValue, string $progress, bool $contacted): self
    {
        if ($importedValue === 'Y') {
            return self::Complete;
        }

        // Anything that is not a plain Y or N is 'unknown' — the tri-state
        // blank, 1,716 of 1,954 rows for harassment training. Not the same as
        // N, and never a failure.
        if ($importedValue !== 'N') {
            return self::NotReported;
        }

        return match ($progress) {
            'claimed_complete' => self::Reported,
            'in_progress'      => self::InProgress,
            // not_started, and any value a future migration adds that this
            // match has not met: the safe reading of an unknown progress is
            // that no progress has been made.
            default            => $contacted ? self::Contacted : self::Outstanding,
        };
    }

    /**
     * The word the chip carries. Never rendered without it.
     *
     * The display names are the owner's (renamed 2026-08-27, Phase 5
     * decided 5): Reported Complete, Member Handling and Open/No Contact
     * replaced the original words. This is the ONLY place they are spelled —
     * the enum case names, backing values and database enums are internal
     * identifiers and did not move.
     */
    public function label(): string
    {
        return match ($this) {
            self::Complete    => 'Complete',
            self::Reported    => 'Reported Complete',
            self::InProgress  => 'Member Handling',
            self::Contacted   => 'Contacted',
            self::Outstanding => 'Open/No Contact',
            self::NotReported => 'Not reported',
        };
    }

    /**
     * What the status word MEANS, in the owner's exact wording (Phase 5
     * decided 6). Rendered twice on the dashboard — a popover per status and
     * the plain <details> fallback at the foot — from this one source.
     */
    public function definition(): string
    {
        return match ($this) {
            self::Complete    => 'The official Rodeo Houston roster shows this requirement met. '
                . 'Nothing left to do.',
            self::Reported    => 'The roster still shows this unmet, but the member told an officer '
                . 'it is done. Waiting for the next roster import to confirm.',
            self::InProgress  => 'The roster shows this unmet; the member told an officer they are '
                . 'taking care of it.',
            self::Contacted   => 'The roster shows this unmet. An officer has reached the member '
                . 'this show year, but the member has not committed to anything yet.',
            self::Outstanding => 'The roster shows this unmet and nobody has contacted the member '
                . 'this show year. These are the next calls to make.',
            self::NotReported => 'No roster import has covered this member for this item. '
                . 'Unknown, never a failure.',
        };
    }

    /**
     * The order every stacked proportion bar and every legend walks, best to
     * worst: Complete, then the two states an officer has moved, then the two
     * that are still open, then Not reported.
     *
     * It is here rather than in a view because two screens draw that bar —
     * My Roster Status's four cards and the Committee Dashboard's roll-up
     * rows — and a bar whose segments ran in a different order on one of them
     * would be a proportion that reads differently on two screens, exactly as
     * a chip would.
     *
     * @return array<int, self>
     */
    public static function ladder(): array
    {
        return [
            self::Complete,
            self::Reported,
            self::InProgress,
            self::Contacted,
            self::Outstanding,
            self::NotReported,
        ];
    }

    /**
     * The bar-segment class in app/views/layout.php — also the legend's dot.
     * Contacted is the hatched amber, so it reads apart from Member Handling
     * inside a bar even before the legend is.
     */
    public function barClass(): string
    {
        return match ($this) {
            self::Complete    => 's-complete',
            self::Reported    => 's-reported',
            self::InProgress  => 's-handling',
            self::Contacted   => 's-contacted',
            self::Outstanding => 's-open',
            self::NotReported => 's-notrep',
        };
    }

    /**
     * The chip class in app/views/layout.php. Colour accompanies the word,
     * never replaces it: In Progress is a filled amber chip and Contacted an
     * amber outline, so the two amber states stay tellable apart even before
     * the word is read.
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Complete    => 'chip-ok',
            self::Reported    => 'chip-info',
            self::InProgress  => 'chip-warn chip-fill',
            self::Contacted   => 'chip-warn',
            self::Outstanding => 'chip-danger',
            self::NotReported => 'chip-muted',
        };
    }
}
