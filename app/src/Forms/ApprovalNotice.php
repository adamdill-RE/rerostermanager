<?php

declare(strict_types=1);

namespace Rerm\Forms;

use PDO;
use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Mail\Mailer;
use Rerm\Roster\RosterPage;
use Throwable;

/**
 * "You have RCF requests to approve" — the one email the approval flow
 * sends (Phase 14.6, spec-v2 §20).
 *
 * It goes to exactly one kind of person for exactly one reason: the account
 * that requests have just been ROUTED TO, the moment they land there — on
 * submission, to the requester's approver; on approval, to the next
 * approver up the chain. Nothing else in the flow emails anybody: a return
 * goes back to the requester's list and not their inbox, generating a form
 * notifies nobody, and a form landing in the roster is read off the screen.
 * The person pressing the button already knows what they did; the person
 * who has to act next is the one who does not.
 *
 * Four rules, and each is a line in waiting():
 *
 *   - The recipient is a VICE CHAIRMAN OR ABOVE, re-checked here against the
 *     account's effective level and not trusted from the flow. The flow
 *     only ever draws accounts at ApprovalFlow::FLOOR (Senior Officer, the
 *     level a Vice Chairman holds) and above, so today this is a second
 *     reading of one rule; it is here so that widening who may be drawn
 *     never widens who is emailed by accident.
 *   - One message per recipient per request, however many entries moved —
 *     "3 requests are waiting" and not three emails — which is also what
 *     keeps a fifty-entry approval under mail.max_per_request.
 *   - NO MEMBER'S DETAILS ARE IN IT. The body carries a count, who sent
 *     them on and a link to the screen; the names, numbers and titles are
 *     on the screen, behind the recipient's sign-in. An email is PII
 *     leaving the building just as an export is (CLAUDE.md), and an inbox
 *     is forwarded, searched and synced to places the roster is not. A
 *     test reads body() for it.
 *   - It never blocks the write. The entries are committed before it runs,
 *     a thrown mail error is logged and swallowed, and every outcome is
 *     returned to the caller as a word — sent, no_email, blocked, off,
 *     ineligible — so the flash can say "Bob has been emailed" only when
 *     he has, and "Bob has no email address on the roster" when that is
 *     what happened.
 *
 * Delivery itself is Rerm\Mail\Mailer's and every interlock there stands
 * (spec-v1 §3.3a): mail.enabled, the transport, the allowlist, the
 * per-request ceiling, and app.debug forcing the file transport. On top of
 * them, mail.rcf_approval_notices is this message's own switch, so it can
 * be turned off without turning off password recovery.
 */
final class ApprovalNotice
{
    /** The lowest level that is ever emailed: a Vice Chairman's. */
    public const FLOOR = ApprovalFlow::FLOOR;

    public const SENT       = 'sent';
    public const NO_EMAIL   = 'no_email';
    public const BLOCKED    = 'blocked';
    public const OFF        = 'off';
    public const INELIGIBLE = 'ineligible';

    /** How the requests reached the recipient — the sentence in the body. */
    public const SUBMITTED = 'submitted';
    public const PASSED_ON = 'passed_on';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly string $approvalsLink,
        private readonly bool $enabled,
    ) {
    }

    public static function fromApp(App $app): self
    {
        // Absolute, from configuration — never from the Host header, which
        // whoever sent the request controls (the recovery email's rule).
        $host = rtrim((string) $app->config()->get('app.canonical_url', ''), '/');

        return new self(
            $app->db(),
            Mailer::fromApp($app),
            $host . $app->url('rcf-approvals'),
            $app->config()->get('mail.rcf_approval_notices', false) === true
        );
    }

    /**
     * Tell one account that `$count` requests now wait on them.
     *
     * @param string $by  who sent them on, as a screen names them
     * @param string $how self::SUBMITTED or self::PASSED_ON
     * @return array{outcome: string, name: string, count: int}
     */
    public function waiting(int $approverId, int $count, string $by, string $how): array
    {
        $account = $this->account($approverId);
        $name    = $account['name'] ?? '';

        if ($account === null || !$account['level']->atLeast(self::FLOOR)) {
            return ['outcome' => self::INELIGIBLE, 'name' => $name, 'count' => $count];
        }
        if (!$this->enabled) {
            return ['outcome' => self::OFF, 'name' => $name, 'count' => $count];
        }
        if ($account['email'] === '') {
            return ['outcome' => self::NO_EMAIL, 'name' => $name, 'count' => $count];
        }

        try {
            $delivered = $this->mailer->send(
                $account['email'],
                self::subject($count),
                self::body($name, $count, $by, $how, $this->approvalsLink)
            );
        } catch (Throwable $e) {
            // The entries are already committed; a mail problem is the log's
            // business and must not turn a successful write into an error
            // page that says it failed.
            error_log('rerm mail: approval notice to app_user #' . $approverId . ' failed: ' . $e->getMessage());
            $delivered = false;
        }

        return ['outcome' => $delivered ? self::SENT : self::BLOCKED, 'name' => $name, 'count' => $count];
    }

    /** The subject line: the count, and nothing a filter would have to read twice. */
    public static function subject(int $count): string
    {
        return sprintf(
            'Rodeo Express Roster: %d RCF %s waiting for your approval',
            $count,
            $count === 1 ? 'request is' : 'requests are'
        );
    }

    /**
     * The body — plain text, and no member in it (see the class comment).
     *
     * @param string $how self::SUBMITTED or self::PASSED_ON
     */
    public static function body(string $name, int $count, string $by, string $how, string $link): string
    {
        $them = $count === 1 ? 'it' : 'them';
        $what = $count === 1 ? 'request' : 'requests';

        $sentence = $how === self::PASSED_ON
            ? sprintf('%s approved %s and passed %s on to you.', $by, $them, $them)
            : sprintf('%s submitted %s.', $by, $them);

        return sprintf(
            "Hi %s,\n\n"
            . "%d Roster Change Form %s %s waiting for your approval on Rodeo Express Roster. %s\n\n"
            . "Approve or return %s here:\n%s\n\n"
            . "You are receiving this because you are drawn as the approver for these requests on "
            . "Create Approval Flow. No member's details are in this message; they are on the screen, "
            . "behind your sign-in. Nothing here needs a reply.\n",
            $name,
            $count,
            $what,
            $count === 1 ? 'is' : 'are',
            $sentence,
            $them,
            $link
        );
    }

    /**
     * What the screen tells the person who moved the requests on — a
     * sentence per outcome, or nothing where there is nothing useful to say.
     *
     * @param array{outcome: string, name: string, count: int} $notice
     */
    public static function sentence(array $notice): string
    {
        $name = $notice['name'] !== '' ? $notice['name'] : 'The approver';

        return match ($notice['outcome']) {
            self::SENT                   => $name . ' has been emailed.',
            self::NO_EMAIL               => $name . ' has no email address on the roster, so let them know yourself.',
            self::BLOCKED, self::OFF     => 'Email notices are switched off here, so let ' . $name . ' know yourself.',
            default                      => '',
        };
    }

    /**
     * The recipient: an active account, its effective level and the email
     * on the member's roster row. Null when there is no such account.
     *
     * @return ?array{name: string, email: string, level: Level}
     */
    private function account(int $userId): ?array
    {
        $read = $this->pdo->prepare(
            'SELECT u.effective_level, m.email, m.preferred_name, m.first_name, m.last_name, m.member_number'
            . ' FROM app_user u INNER JOIN member m ON m.id = u.member_id'
            . ' WHERE u.id = :id AND u.is_active = 1 AND m.purged_at IS NULL'
        );
        $read->execute([':id' => $userId]);
        $row = $read->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'name'  => RosterPage::displayName(
                (string) $row['preferred_name'],
                (string) $row['first_name'],
                (string) $row['last_name'],
                (string) $row['member_number']
            ),
            'email' => trim((string) ($row['email'] ?? '')),
            'level' => Level::from((string) $row['effective_level']),
        ];
    }
}
