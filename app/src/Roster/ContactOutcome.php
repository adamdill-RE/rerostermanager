<?php

declare(strict_types=1);

namespace Rerm\Roster;

/**
 * What the last contact PRODUCED, for one member — the answer to "I rang
 * them, what did they say", in one word (spec-v2 §6).
 *
 * My Roster Status already carries the four metric chips, and they say where
 * each requirement stands. What they do not say, at a glance down fifty rows,
 * is whether the conversation that produced them got anywhere: a member with
 * three amber chips might have promised to pay on Friday or might simply have
 * answered the phone. That difference decides whether the officer rings again
 * today, and until now it could only be read by opening a row.
 *
 * So this is a pure function of what the screen has already derived — the
 * four scored MetricStatus values and whether the member has been contacted
 * this show year — and never a second read of anything. It cannot disagree
 * with the chips beside it, because it is computed FROM them.
 *
 * THE RULE, and the one judgement call in it: the word is the FURTHEST the
 * member has committed on anything still open, not the least. A member who
 * said "dues are paid, I'll get to the background check" reads as Reported
 * Complete, with `2 of 3` beside it saying how far that answer reached. The
 * alternative — reporting the worst rung — would have every partial answer
 * read as though the call had produced nothing, which is the one thing this
 * column exists to distinguish. The coverage note is what keeps it honest,
 * and the chips beside it hold the detail.
 *
 * OPEN MEANS "NOT COMPLETE", exactly as the cards above the list mean it.
 * Not reported is open here, because a card's `outstanding` figure is every
 * effective status except Complete and because the working list deliberately
 * keeps a member no import has covered — "so nobody vanishes". A Result
 * column that called those four grey chips "Nothing outstanding" would be
 * contradicting, on the same row, the list that put the member there.
 *
 * WHAT WAS SAID OUTRANKS WHETHER A CALL WAS LOGGED, which is why the two
 * commitment questions are asked before the contact one. Reported Complete
 * and Member Handling are things a member told an officer; they are set by
 * `LogContact` alongside a `contact_log` row and survive independently of it
 * (an import may clear progress, spec 6.6, and it never touches the history).
 * Asking "were they contacted" first would let a row show "Reported Complete"
 * in a chip and "No contact yet" as its result — the one contradiction this
 * column must not produce. So `contacted` decides only between the two ways
 * of having committed to nothing: reached and non-committal, or not reached
 * at all.
 */
enum ContactOutcome: string
{
    /** All four scored metrics are Complete — there is nothing to chase. */
    case Settled = 'settled';

    /** The member says at least one open requirement is done. */
    case Reported = 'reported';

    /** The member says they are taking care of at least one open requirement. */
    case Handling = 'handling';

    /** Reached this show year, and has committed to nothing. */
    case NoCommitment = 'no_commitment';

    /** Nobody has reached them this show year, so there is no result yet. */
    case NotContacted = 'not_contacted';

    /**
     * The outcome plus its coverage: `at` is how many open metrics sit at the
     * outcome's own state, `open` how many are open at all. The view says
     * "2 of 3" when they differ and nothing when they do not — a qualifier on
     * an answer that covered everything is noise on fifty rows.
     *
     * $statuses is the row's derived map, metric value => MetricStatus, and
     * may carry the unscored harassment training too; only Metric::scored()
     * is read, because those are the four the committee is scored on.
     *
     * @param array<string, MetricStatus> $statuses
     * @return array{outcome: self, at: int, open: int}
     */
    public static function summarise(array $statuses, bool $contacted): array
    {
        $open     = 0;
        $reported = 0;
        $handling = 0;

        foreach (Metric::scored() as $metric) {
            $status = $statuses[$metric->value] ?? MetricStatus::NotReported;

            // Everything but Complete is open, the cards' own definition of
            // outstanding — Not reported included.
            if ($status === MetricStatus::Complete) {
                continue;
            }

            $open++;

            if ($status === MetricStatus::Reported) {
                $reported++;
            } elseif ($status === MetricStatus::InProgress) {
                $handling++;
            }
        }

        // Nothing to chase, whether or not anybody ever rang them. This is
        // exactly the row's Fully Complete flag, arrived at from the same
        // four statuses.
        if ($open === 0) {
            return ['outcome' => self::Settled, 'at' => 0, 'open' => 0];
        }

        // What the member SAID, asked before whether a call was logged: these
        // two are the chips printed beside this word, and the word may not
        // contradict them.
        if ($reported > 0) {
            return ['outcome' => self::Reported, 'at' => $reported, 'open' => $open];
        }

        if ($handling > 0) {
            return ['outcome' => self::Handling, 'at' => $handling, 'open' => $open];
        }

        // Committed to nothing, so the only question left is whether anybody
        // has reached them — which is the same question that separates
        // MetricStatus's own Contacted from Open/No Contact.
        return $contacted
            ? ['outcome' => self::NoCommitment, 'at' => $open, 'open' => $open]
            : ['outcome' => self::NotContacted, 'at' => 0, 'open' => $open];
    }

    /**
     * The word the chip carries. Never rendered without it (spec 8.3).
     *
     * Two of the five DELEGATE to MetricStatus rather than repeating its
     * words: the chips beside this one already say "Reported Complete" and
     * "Member Handling", and a rename that moved one and not the other would
     * put two spellings of one state in one row. The other three are states
     * only this column has — nothing open, reached with nothing committed,
     * and not reached at all.
     */
    public function label(): string
    {
        return match ($this) {
            self::Settled      => 'Nothing outstanding',
            self::Reported     => MetricStatus::Reported->label(),
            self::Handling     => MetricStatus::InProgress->label(),
            self::NoCommitment => 'No commitment yet',
            self::NotContacted => 'No contact yet',
        };
    }

    /**
     * The chip class in app/views/layout.php — delegated for the same two,
     * for the same reason: a state that is amber-filled as a metric chip and
     * something else as an outcome chip is two colours for one fact.
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Settled      => 'chip-ok',
            self::Reported     => MetricStatus::Reported->chipClass(),
            self::Handling     => MetricStatus::InProgress->chipClass(),
            self::NoCommitment => MetricStatus::Contacted->chipClass(),
            self::NotContacted => 'chip-muted',
        };
    }

    /**
     * What the word MEANS, rendered in the definitions at the foot of My
     * Roster Status beside the metric statuses' own.
     */
    public function definition(): string
    {
        return match ($this) {
            self::Settled      => 'The official roster shows all four requirements met. '
                . 'There is nothing to chase, whether or not anybody has called.',
            self::Reported     => 'The member told an officer that at least one outstanding '
                . 'requirement is done. Waiting for the next roster import to confirm it.',
            self::Handling     => 'The member told an officer they are taking care of at least '
                . 'one outstanding requirement.',
            self::NoCommitment => 'An officer reached the member this show year and the member '
                . 'has not committed to anything yet. Worth another call.',
            self::NotContacted => 'Nobody has reached this member this show year and they have '
                . 'committed to nothing, so there is no result yet. These are the next calls to make.',
        };
    }
}
