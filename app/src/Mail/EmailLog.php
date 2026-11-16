<?php

declare(strict_types=1);

namespace Rerm\Mail;

use PDO;
use Rerm\App;
use Throwable;

/**
 * The email log (Phase 14.8, spec-v2 §22): one row for every message this
 * application tried to send, whatever became of it.
 *
 * The question it answers is the one an Admin is asked by a Division
 * Chairman: "I never got the email" — which until now meant the server's
 * error log, which on shared hosting is not theirs to read. So the Mailer
 * writes here on every attempt, with the same three facts a person wants:
 * what prompted it, who it went to, and whether it went.
 *
 * Three rules:
 *
 *   - **The Mailer writes it, not the caller.** Every message leaves through
 *     Mailer::send() and nowhere else, so a row here is written by the one
 *     place that knows what actually happened — delivered, filed, logged,
 *     or blocked and by which interlock. A caller that wrote its own row
 *     would be recording what it hoped, not what occurred. The one row a
 *     caller writes itself is `no_email`: the attempt that never reached the
 *     Mailer because the roster holds no address for the person.
 *   - **It can never fail the send.** The row is written after the attempt,
 *     inside a try/catch that logs and swallows, because a mail that went
 *     must not come back to the screen as an error — and a mail that was
 *     blocked must not either. A log that takes the thing it logs down is
 *     worse than no log.
 *   - **It is a record.** Append-only, on tests/admin_test.php's protected
 *     list, and it carries nothing more than the screen that prompted the
 *     message already showed: a count, a name, a member number. The body
 *     is never kept — the recovery link in it is a credential.
 */
final class EmailLog
{
    /** Why a message was sent: the two messages that exist. */
    public const PURPOSE_PASSWORD_RESET   = 'password_reset';
    public const PURPOSE_APPROVAL_NOTICE  = 'rcf_approval_notice';

    public const PURPOSES = [
        self::PURPOSE_PASSWORD_RESET  => 'Password reset',
        self::PURPOSE_APPROVAL_NOTICE => 'RCF approval notice',
    ];

    /** What became of it. */
    public const SENT     = 'sent';      // handed to mail(), which accepted it
    public const FAILED   = 'failed';    // handed to mail(), which refused it
    public const FILED    = 'filed';     // the file transport: a .eml in var/mail, nothing left the box
    public const LOGGED   = 'logged';    // the log transport: a line in the error log
    public const BLOCKED  = 'blocked';   // an interlock stopped a real send; detail names it
    public const REFUSED  = 'refused';   // past mail.max_per_request — a loop, thrown
    public const NO_EMAIL = 'no_email';  // the roster holds no address for the recipient

    public const OUTCOMES = [
        self::SENT     => 'Sent',
        self::FAILED   => 'Failed',
        self::FILED    => 'Filed, not sent',
        self::LOGGED   => 'Logged, not sent',
        self::BLOCKED  => 'Blocked',
        self::REFUSED  => 'Refused',
        self::NO_EMAIL => 'No email address',
    ];

    /** The outcomes that mean a person has the message in their inbox. */
    public const DELIVERED = [self::SENT];

    private ?PDO $pdo;
    private ?App $app;

    /**
     * @param PDO|App $source the connection, or the application to open one
     *        from on the first row — lazily, so building a Mailer (which
     *        /status does to read the envelope sender) never opens the
     *        database, and a database that is down is a row not written
     *        rather than a screen not served.
     */
    public function __construct(PDO|App $source)
    {
        $this->pdo = $source instanceof PDO ? $source : null;
        $this->app = $source instanceof App ? $source : null;
    }

    public static function fromApp(App $app): self
    {
        return new self($app);
    }

    /**
     * One row. Never throws: a failure to log is written to the error log
     * and swallowed, for the reason in the class comment.
     *
     * @param array{purpose?: string, reason?: string, recipient_user_id?: ?int,
     *     actor_user_id?: ?int} $context what the caller knows about why
     */
    public function record(
        array $context,
        string $recipient,
        string $subject,
        string $transport,
        string $outcome,
        string $detail = '',
    ): void {
        try {
            $this->pdo ??= $this->app?->db();
            if ($this->pdo === null) {
                throw new \RuntimeException('no database to write to');
            }
            $this->pdo->prepare(
                'INSERT INTO email_log'
                . ' (purpose, reason, recipient_user_id, recipient, subject, transport, outcome, detail, actor_user_id)'
                . ' VALUES (:purpose, :reason, :recipient_user_id, :recipient, :subject, :transport, :outcome, :detail, :actor)'
            )->execute([
                ':purpose'           => mb_substr((string) ($context['purpose'] ?? ''), 0, 32),
                ':reason'            => mb_substr((string) ($context['reason'] ?? ''), 0, 255),
                ':recipient_user_id' => self::id($context['recipient_user_id'] ?? null),
                ':recipient'         => mb_substr($recipient, 0, 255),
                ':subject'           => mb_substr($subject, 0, 255),
                ':transport'         => mb_substr($transport, 0, 8),
                ':outcome'           => mb_substr($outcome, 0, 16),
                ':detail'            => mb_substr($detail, 0, 255),
                ':actor'             => self::id($context['actor_user_id'] ?? null),
            ]);
        } catch (Throwable $e) {
            error_log('rerm mail: email_log row not written: ' . $e->getMessage());
        }
    }

    /** A positive int or null — the two values a nullable key may hold. */
    private static function id(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
