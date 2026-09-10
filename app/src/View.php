<?php

declare(strict_types=1);

namespace Rerm;

use DateTimeImmutable;
use DateTimeZone;
use Rerm\Roster\ContactOutcome;
use Rerm\Roster\LogContact;
use Rerm\Roster\Metric;
use Rerm\Roster\MetricStatus;

/**
 * The rendering fragments the roster-shaped screens repeat: the status chip,
 * the contact-outcome chip, the stacked proportion bar, the relative
 * timestamp and — since Phase 10.2 — the log-contact sheet. Promoted out of
 * app/views/roster.php when Phase 5's dashboard became the second screen to
 * need them — one spelling of each, because a chip that renders differently
 * on two screens is a status that reads differently on two screens, and a
 * sheet that posts different fields from two screens is a write that
 * behaves differently depending on where the officer was standing.
 *
 * Everything here returns ALREADY-ESCAPED HTML or plain strings the caller
 * still escapes; each method says which it is.
 */
final class View
{
    /**
     * What a contact type is called on screen — the history lists and the
     * sheet's select, on both roster screens, from one table.
     */
    public const CONTACT_TYPES = [
        'call'      => 'Call',
        'text'      => 'Text',
        'email'     => 'Email',
        'in_person' => 'In person',
        'other'     => 'Other',
    ];

    /**
     * The per-row log-contact sheet (spec 7.1 + 8.4, Phase 5 decided 2): a
     * whole table row holding an open <details> and its own small <form> —
     * type, optional note, and a progress select for every scored metric the
     * member is not yet Complete on. Its own form, so a submit posts only
     * this row's fields and max_input_vars stays distant.
     *
     * One renderer for the two screens that offer it, My Roster Status and
     * (since Phase 10.2) View My Roster: the POST it produces is read by ONE
     * handler, LogContact, and a sheet that differed between the screens by
     * a field name would be a contact that logs from one and 404s from the
     * other. The caller supplies what differs — the action URL and the
     * $shared block (the CSRF token, the return state and the screen to
     * come back to), built ONCE per page rather than once per row.
     *
     * Rendered for ONE row at a time (?log=id), never for every row: the
     * option lists are ~1.6KB and fifty copies are half the spec 10
     * first-paint budget by themselves.
     *
     * Returns escaped HTML, safe to echo. $shared is already-escaped HTML.
     *
     * @param array<string, MetricStatus> $statuses the row's effective
     *        statuses, Metric->value => status, as the screen derived them
     */
    public static function logContactSheet(
        string $action,
        string $shared,
        int $memberId,
        string $displayName,
        array $statuses,
        int $colspan = 9,
        array $contact = []
    ): string {
        $typeOptions = '';
        foreach (LogContact::TYPES as $type) {
            $typeOptions .= '<option value="' . e($type) . '">'
                . e(self::CONTACT_TYPES[$type] ?? $type) . '</option>';
        }

        // CALL, THEN LOG (Phase 10.3, spec 8.4's intent without a script).
        // The row's own Call, Text and Email are here again, as the sheet's
        // first and largest targets, so the natural order is: open the
        // sheet, dial from inside it, come back to a page already open on
        // this row with the form waiting. Absent, never disabled, on the
        // row's own terms — Text only for a cell phone, Email only with an
        // address. An older caller that passes nothing gets no buttons.
        $dial = '';
        if (($contact['can_call'] ?? false) && (string) ($contact['phone_e164'] ?? '') !== '') {
            $dial .= '<a class="dial" href="tel:' . e((string) $contact['phone_e164']) . '">Call'
                . ((string) ($contact['phone'] ?? '') !== '' ? ' ' . e((string) $contact['phone']) : '') . '</a>';
        }
        if (($contact['can_text'] ?? false) && (string) ($contact['phone_e164'] ?? '') !== '') {
            $dial .= '<a class="dial" href="sms:' . e((string) $contact['phone_e164']) . '">Text</a>';
        }
        if (($contact['can_email'] ?? false) && (string) ($contact['email'] ?? '') !== '') {
            $dial .= '<a class="dial" href="mailto:' . e((string) $contact['email']) . '">Email</a>';
        }
        if ($dial !== '') {
            $dial = '<p class="dials">' . $dial . '</p>';
        }

        // The choices a per-metric progress select offers, spelled as the
        // chips are — one spelling, from the one enum.
        $progressChoices = [
            ''                 => 'No change',
            'in_progress'      => MetricStatus::InProgress->label() . ' — they are taking care of it',
            'claimed_complete' => MetricStatus::Reported->label() . ' — they say it is done',
            'not_started'      => 'Not started — clear a status set by mistake',
        ];

        $html = '<tr class="detail"><td class="expand" colspan="' . e((string) $colspan) . '">'
            . '<details open><summary>Log contact &mdash; ' . e($displayName) . '</summary>'
            . '<form method="post" action="' . e($action) . '">'
            . $shared
            . '<input type="hidden" name="member_id" value="' . e((string) $memberId) . '">'
            . $dial
            // autofocus: the link that opened this sheet re-rendered the page,
            // and the page should open on the sheet's first control rather
            // than on the top of the tbody the anchor named.
            . '<p class="lc"><select name="contact_type" aria-label="How the contact happened" autofocus>'
            . $typeOptions
            . '</select><textarea name="note" rows="2" maxlength="1000" aria-label="Note"'
            . ' placeholder="Note &mdash; optional, kept forever"></textarea></p>';

        // A select only for what is still open: a member already Complete on
        // HLSR dues has nothing to say about them.
        $pending = array_filter(
            Metric::scored(),
            static fn (Metric $m): bool => ($statuses[$m->value] ?? null) !== MetricStatus::Complete
        );
        if ($pending !== []) {
            $html .= '<p class="pgh">What they said &mdash; optional</p>';
            foreach ($pending as $metric) {
                $html .= '<label class="pg">' . e($metric->shortLabel())
                    . '<select name="progress[' . e($metric->value) . ']">';
                foreach ($progressChoices as $value => $label) {
                    $html .= '<option value="' . e((string) $value) . '">' . e($label) . '</option>';
                }
                $html .= '</select></label>';
            }
        }

        return $html . '<button type="submit">Log this contact</button></form></details></td></tr>';
    }

    /**
     * A notice — the one component every screen's "Done" / "Note" / "Stopped"
     * comes through (Phase 10.3). Before this, fourteen views each spelled
     * their own six lines, and the same danger level read "Refused" on the
     * auth screens, "Failed" on setup and "Stopped" everywhere else. The
     * layout renders these, inside the sticky bar for a signed-in user, so no
     * view carries a loop of its own. Returns escaped HTML, safe to echo.
     */
    public static function notice(string $level, string $message): string
    {
        [$class, $word] = match ($level) {
            'ok'   => ['chip-ok', 'Done'],
            'warn' => ['chip-warn', 'Note'],
            default => ['chip-danger', 'Stopped'],
        };

        return '<div class="notice"><span class="chip ' . $class . '">' . $word . '</span>'
            . '<span>' . e($message) . '</span></div>';
    }

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
        $label = $status->chipLabel();
        $word  = str_contains($class, 'chip-fill')
            ? '<span class="chip-word">' . e($label) . '</span>'
            : e($label);

        // The full word travels as the title where the chip carries the
        // short one (Phase 10.3), so a hover reads what the legend reads.
        $title = $label === $status->label() ? '' : ' title="' . e($status->label()) . '"';

        return '<span class="chip ' . e($class) . '"' . $title . '>' . $word . '</span>';
    }

    /**
     * How long the show year has left, in words, from its end date — or
     * nothing at all for a year with none (Phase 10.3). The four requirements
     * have to be met before the show, and the number of days until then is
     * the one every Division Chairman quotes; it was in the table and on no
     * screen. Returns a PLAIN string the caller escapes.
     */
    public static function daysLeft(App $app, ?string $endsOn): string
    {
        if ($endsOn === null || $endsOn === '') {
            return '';
        }

        $zone  = $app->displayTimezone();
        $today = new DateTimeImmutable('today', $zone);
        $end   = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn, $zone);
        if ($end === false) {
            return '';
        }

        $days = (int) $today->diff($end)->format('%r%a');

        return match (true) {
            $days > 1   => number_format($days) . ' days to go',
            $days === 1 => '1 day to go',
            $days === 0 => 'closes today',
            $days === -1 => 'ended yesterday',
            default     => 'ended ' . $end->format('j M Y'),
        };
    }

    /**
     * The contact-outcome chip (spec-v2 §6): one word for what the last
     * contact produced, with the coverage note when the member's answer
     * reached only some of what is open. Never contacted renders as the same
     * em dash the other empty cells use — the "Last contact" cell beside it
     * already carries the words, and fifty duplicate chips are bytes the
     * spec 10 budget does not have.
     *
     * Returns escaped HTML, safe to echo.
     *
     * @param array{outcome: ContactOutcome, at: int, open: int} $summary
     *        exactly what ContactOutcome::summarise() decided
     */
    public static function outcome(array $summary): string
    {
        $outcome = $summary['outcome'];

        if ($outcome === ContactOutcome::NotContacted) {
            return '&mdash;';
        }

        $class = $outcome->chipClass();
        $word  = str_contains($class, 'chip-fill')
            ? '<span class="chip-word">' . e($outcome->label()) . '</span>'
            : e($outcome->label());

        $html = '<span class="chip ' . e($class) . '">' . $word . '</span>';

        // Said only when the answer did not cover everything still open: a
        // "3 of 3" on every row is a qualifier that qualifies nothing.
        if ($summary['at'] > 0 && $summary['at'] < $summary['open']) {
            $html .= ' <span class="why">' . e((string) $summary['at']) . ' of '
                . e((string) $summary['open']) . '</span>';
        }

        return $html;
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
