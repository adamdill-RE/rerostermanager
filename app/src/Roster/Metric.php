<?php

declare(strict_types=1);

namespace Rerm\Roster;

/**
 * The five metrics the import carries, four of which are scored.
 *
 * The backing values are exactly the ENUM in member_metric.metric, so a
 * metric crosses the PDO boundary as ->value and comes back through from().
 *
 * Harassment training is the fifth: imported and displayed, tri-state, and
 * NEVER one of the four chips — it enters no completion percentage, and its
 * blank majority (1,716 of 1,954) renders as "Not reported", never as a
 * failure (spec 5.4, open item OI-3). scored() is the list every roster row,
 * dashboard card and roll-up iterates; a screen that iterates cases()
 * instead has scored a metric the committee is not scored on.
 */
enum Metric: string
{
    case HlsrDues           = 'hlsr_dues';
    case CommitteeDues      = 'committee_dues';
    case Indemnity          = 'indemnity';
    case BackgroundCheck    = 'background_check';
    case HarassmentTraining = 'harassment_training';

    /**
     * The four scored metrics, in the order every screen shows them.
     *
     * @return array<int, self>
     */
    public static function scored(): array
    {
        return [self::HlsrDues, self::CommitteeDues, self::Indemnity, self::BackgroundCheck];
    }

    /** The full name, as headers and the expanded row spell it. */
    public function label(): string
    {
        return match ($this) {
            self::HlsrDues           => 'HLSR Dues',
            self::CommitteeDues      => 'Committee Dues',
            self::Indemnity          => 'Indemnity',
            self::BackgroundCheck    => 'Background Check',
            self::HarassmentTraining => 'Harassment Training',
        };
    }

    /**
     * A short name for narrow places — the stacked card's chip line, where
     * four full names would wrap to four lines.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::HlsrDues           => 'HLSR',
            self::CommitteeDues      => 'Committee',
            self::Indemnity          => 'Indemnity',
            self::BackgroundCheck    => 'Bg Check',
            self::HarassmentTraining => 'Harassment',
        };
    }
}
