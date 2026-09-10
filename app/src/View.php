<?php

declare(strict_types=1);

namespace Rerm;

use DateTimeImmutable;
use DateTimeZone;
use Rerm\Auth\User;
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
     * The three ways to reach a member, as hrefs, on the row's own terms
     * (spec 8.4): tel: when there is a number, sms: only for a CELL PHONE,
     * mailto: only with an address — absent, never disabled. Since Phase
     * 10.4 the text and the email START THEMSELVES: `contact.sms_body` and
     * `contact.mail_subject` from config, with {first}, {officer} and {team}
     * filled in, ride in the link, so an officer texting twenty people does
     * not type the same opening line twenty times. Nothing is sent by the
     * application; the officer's own phone sends it, which keeps the mail
     * safety design (spec 3.3a) untouched. `?&body=` is the spelling both
     * iOS and Android accept.
     *
     * Returns PLAIN hrefs keyed call / text / email, only for the ways that
     * work; the caller escapes them into attributes.
     *
     * @param array<string, mixed> $row a roster-shaped row: display_name,
     *        team_name, phone_e164, email, can_call, can_text, can_email
     * @return array<string, string>
     */
    public static function contactLinks(App $app, ?User $officer, array $row): array
    {
        $links = [];
        $e164  = (string) ($row['phone_e164'] ?? '');
        $email = trim((string) ($row['email'] ?? ''));

        $first   = trim((string) strtok((string) ($row['display_name'] ?? ''), ' '));
        $fill    = static function (string $template) use ($first, $officer, $row): string {
            $filled = strtr($template, [
                '{first}'   => $first,
                '{officer}' => $officer !== null ? $officer->displayName : '',
                '{team}'    => (string) ($row['team_name'] ?? ''),
            ]);

            return trim((string) preg_replace('/\s{2,}/', ' ', $filled));
        };

        if (($row['can_call'] ?? false) && $e164 !== '') {
            $links['call'] = 'tel:' . $e164;
        }
        if (($row['can_text'] ?? false) && $e164 !== '') {
            $body          = $fill((string) $app->config()->get('contact.sms_body', ''));
            $links['text'] = 'sms:' . $e164 . ($body === '' ? '' : '?&body=' . rawurlencode($body));
        }
        if (($row['can_email'] ?? false) && $email !== '') {
            $subject        = $fill((string) $app->config()->get('contact.mail_subject', ''));
            $links['email'] = 'mailto:' . $email . ($subject === '' ? '' : '?subject=' . rawurlencode($subject));
        }

        return $links;
    }

    /**
     * The per-row log-contact sheet (spec 7.1 + 8.4, Phase 5 decided 2): a
     * whole table row holding an open <details> and the form — the form
     * itself is logContactForm(), shared since Phase 10.4 with the member
     * card, which renders it in a column rather than a row.
     *
     * Rendered for ONE row at a time (?log=id), never for every row: the
     * option lists are ~1.6KB and fifty copies are half the spec 10
     * first-paint budget by themselves.
     *
     * Returns escaped HTML, safe to echo. $shared is already-escaped HTML.
     *
     * @param array<string, MetricStatus> $statuses the row's effective
     *        statuses, Metric->value => status, as the screen derived them
     * @param array<string, mixed>        $contact  the row's contact facts —
     *        can_call / can_text / can_email, phone, phone_e164, email — and
     *        optionally `links`, contactLinks()'s answer, which wins
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
        return '<tr class="detail"><td class="expand" colspan="' . e((string) $colspan) . '">'
            . '<details open><summary>Log contact &mdash; ' . e($displayName) . '</summary>'
            . self::logContactForm($action, $shared, $memberId, $statuses, $contact)
            . '</details></td></tr>';
    }

    /**
     * The log-contact FORM (Phase 10.4): type, optional note, and what the
     * member said about what is still open. One renderer for the three
     * places that offer it — the two list screens' sheets and the member
     * card — because the POST it produces is read by ONE handler, and a form
     * that differed by a field name would be a contact that logs from one
     * screen and 404s from another. The caller supplies what differs: the
     * action URL and the $shared block (the CSRF token, the return state and
     * the screen to come back to).
     *
     * CALL, THEN LOG (Phase 10.3, spec 8.4's intent without a script). The
     * row's own Call, Text and Email are here as the form's first and
     * largest targets, so the natural order is: open, dial from inside,
     * come back to a page already open on this row with the form waiting.
     *
     * ONE ANSWER FOR EVERYTHING OPEN (Phase 10.4, spec-v2 §9.2). With more
     * than one requirement still open, a radio row answers for all of them
     * at once — the common case is one sentence from the member — and the
     * per-metric selects sit under a closed <details> for the exceptional
     * case, winning where one is chosen. One open requirement is just its
     * own select, as before.
     *
     * Returns escaped HTML, safe to echo. $shared is already-escaped HTML.
     *
     * @param array<string, MetricStatus> $statuses
     * @param array<string, mixed>        $contact  see logContactSheet()
     */
    public static function logContactForm(
        string $action,
        string $shared,
        int $memberId,
        array $statuses,
        array $contact = []
    ): string {
        $typeOptions = '';
        foreach (LogContact::TYPES as $type) {
            $typeOptions .= '<option value="' . e($type) . '">'
                . e(self::CONTACT_TYPES[$type] ?? $type) . '</option>';
        }

        // The dial buttons: contactLinks()'s hrefs where the caller computed
        // them, else the bare links from the row's own flags. Absent, never
        // disabled. An older caller that passes nothing gets no buttons.
        $links = is_array($contact['links'] ?? null) ? $contact['links'] : [];
        if ($links === []) {
            if (($contact['can_call'] ?? false) && (string) ($contact['phone_e164'] ?? '') !== '') {
                $links['call'] = 'tel:' . (string) $contact['phone_e164'];
            }
            if (($contact['can_text'] ?? false) && (string) ($contact['phone_e164'] ?? '') !== '') {
                $links['text'] = 'sms:' . (string) $contact['phone_e164'];
            }
            if (($contact['can_email'] ?? false) && (string) ($contact['email'] ?? '') !== '') {
                $links['email'] = 'mailto:' . (string) $contact['email'];
            }
        }
        $dial = '';
        if (isset($links['call'])) {
            $dial .= '<a class="dial" href="' . e($links['call']) . '">Call'
                . ((string) ($contact['phone'] ?? '') !== '' ? ' ' . e((string) $contact['phone']) : '') . '</a>';
        }
        if (isset($links['text'])) {
            $dial .= '<a class="dial" href="' . e($links['text']) . '">Text</a>';
        }
        if (isset($links['email'])) {
            $dial .= '<a class="dial" href="' . e($links['email']) . '">Email</a>';
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

        $html = '<form method="post" action="' . e($action) . '">'
            . $shared
            . '<input type="hidden" name="member_id" value="' . e((string) $memberId) . '">'
            . $dial
            // autofocus: the link that opened this re-rendered the page, and
            // the page should open on the form's first control rather than
            // on the top of the tbody the anchor named.
            . '<p class="lc"><select name="contact_type" aria-label="How the contact happened" autofocus>'
            . $typeOptions
            . '</select><textarea name="note" rows="2" maxlength="1000" aria-label="Note"'
            . ' placeholder="Note &mdash; optional, kept forever"></textarea></p>';

        // A select only for what is still open: a member already Complete on
        // HLSR dues has nothing to say about them.
        $pending = array_values(array_filter(
            Metric::scored(),
            static fn (Metric $m): bool => ($statuses[$m->value] ?? null) !== MetricStatus::Complete
        ));

        $each = '';
        foreach ($pending as $metric) {
            $each .= '<label class="pg">' . e($metric->shortLabel())
                . '<select name="progress[' . e($metric->value) . ']">';
            foreach ($progressChoices as $value => $label) {
                $each .= '<option value="' . e((string) $value) . '">' . e($label) . '</option>';
            }
            $each .= '</select></label>';
        }

        if (count($pending) > 1) {
            $open = implode(', ', array_map(static fn (Metric $m): string => $m->shortLabel(), $pending));
            $html .= '<p class="pgh">What they said &mdash; optional</p>'
                . '<fieldset class="pgall"><legend>For everything still open (' . e($open) . ')</legend>'
                . '<label class="pga"><input type="radio" name="progress_all" value="" checked> No change</label>'
                . '<label class="pga"><input type="radio" name="progress_all" value="in_progress"> '
                . e(MetricStatus::InProgress->label()) . '</label>'
                . '<label class="pga"><input type="radio" name="progress_all" value="claimed_complete"> '
                . e(MetricStatus::Reported->label()) . '</label>'
                . '</fieldset>'
                . '<details class="pgeach"><summary>Different answers per requirement</summary>'
                . $each . '</details>';
        } elseif ($pending !== []) {
            $html .= '<p class="pgh">What they said &mdash; optional</p>' . $each;
        }

        return $html . '<button type="submit">Log this contact</button></form>';
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
     * Several notices as the one the flash can hold (Phase 10.4): the
     * messages joined into one paragraph, at the loudest level among them,
     * so "Applied. …" and its warning travel together across a 303. Null
     * for none.
     *
     * @param array<int, array{0: string, 1: string}> $notices
     * @return ?array{0: string, 1: string}
     */
    public static function joinNotices(array $notices): ?array
    {
        if ($notices === []) {
            return null;
        }

        $rank  = ['ok' => 0, 'warn' => 1, 'danger' => 2];
        $level = 'ok';
        $parts = [];
        foreach ($notices as [$kind, $message]) {
            if (($rank[$kind] ?? 2) > ($rank[$level] ?? 0)) {
                $level = $kind;
            }
            $parts[] = $message;
        }

        return [$level, implode(' ', $parts)];
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
     * A moment, as HTML (Phase 10.4, spec-v2 §9.7): relative words the eye
     * reads, the absolute local time as the title, and the UTC instant in
     * `datetime` so a screen reader and a script agree on what it was. One
     * spelling for the four that coexisted — words with a title, two
     * localised formats and bare UTC strings. Returns escaped HTML.
     */
    public static function time(App $app, string $utc): string
    {
        [$words, $absolute] = self::when($app, $utc);

        return '<time datetime="' . e(self::iso($utc)) . '" title="' . e($absolute) . '">'
            . e($words) . '</time>';
    }

    /**
     * A moment where the absolute is the point — an import applied, a
     * contact loaded, an audit row — spelled the one way (`7 Sep 2026,
     * 2:14 pm`, in the display zone), with the UTC instant in `datetime`.
     * Returns escaped HTML.
     */
    public static function timeFull(App $app, string $utc): string
    {
        [, $absolute] = self::when($app, $utc);

        return '<time datetime="' . e(self::iso($utc)) . '">' . e($absolute) . '</time>';
    }

    /** A stored UTC DATETIME as the ISO instant `datetime` wants. */
    private static function iso(string $utc): string
    {
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return '';
        }
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
