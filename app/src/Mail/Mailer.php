<?php

declare(strict_types=1);

namespace Rerm\Mail;

use Rerm\App;
use RuntimeException;

/**
 * The only way this application sends email, and it ships unable to.
 *
 * Password recovery is the one message v1 sends, and there is no bulk path —
 * deliberately. The risk here is not deliverability (the account has a
 * dedicated IP, docs/hosting.md); it is sending BY MISTAKE while building:
 * the live database holds 1,953 real committee members' real addresses, and
 * a stray loop against that table reaches actual people, cannot be recalled,
 * and burns the IP's reputation on its first day.
 *
 * So delivery is a state production opts INTO, through four independent
 * interlocks (spec 3.3a) — any ONE of them blocks a real send on its own:
 *
 *   1. mail.enabled              ships false
 *   2. mail.transport            ships 'file' — only 'send' reaches the wire
 *   3. mail.allowed_recipients   when non-empty, only listed addresses receive
 *   4. mail.max_per_request      more messages than this in one request THROWS
 *
 * Plus the hard interlock configuration cannot defeat: app.debug === true
 * forces the transport to 'file' before any transport is selected. Debug is
 * only ever true off production, so no config.local.php on the wrong machine
 * can arm delivery there.
 *
 * Interlocks 1 and 3 gate DELIVERY, not the file and log transports: 'file'
 * writes a readable .eml into var/mail/ — outside the document root and
 * gitignored — precisely so the whole recovery flow is testable end to end
 * while nothing exists that could leave the box. Interlock 4 gates
 * everything: recovery sends exactly one message, so a second is already
 * suspicious and a sixth is a loop that should not exist, whatever transport
 * it would have used. It throws rather than trims, because a silent cap
 * would hide the very bug the ceiling exists to catch.
 *
 * .github/check-mail-safety.php fails the build if the committed defaults
 * could ever deliver.
 */
final class Mailer
{
    public const TRANSPORT_SEND = 'send';
    public const TRANSPORT_FILE = 'file';
    public const TRANSPORT_LOG  = 'log';

    private int $sent = 0;

    /**
     * @param array<int, string> $allowedRecipients
     * @param ?callable          $deliver  the wire itself — mail() unless a test
     *                                     substitutes a recorder. The interlocks
     *                                     all sit in front of it, which is what
     *                                     lets a test prove a blocked send never
     *                                     reached this far.
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $transport,
        private readonly array $allowedRecipients,
        private readonly int $maxPerRequest,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly bool $debug,
        private readonly string $mailDir,
        private $deliver = null,
    ) {
    }

    public static function fromApp(App $app): self
    {
        $config = $app->config();

        return new self(
            $config->get('mail.enabled', false) === true,
            (string) $config->get('mail.transport', self::TRANSPORT_FILE),
            array_values(array_map('strval', (array) $config->get('mail.allowed_recipients', []))),
            (int) $config->get('mail.max_per_request', 5),
            (string) $config->get('mail.from_address'),
            (string) $config->get('mail.from_name'),
            $app->debug(),
            $app->path('var/mail'),
        );
    }

    /**
     * The transport that will actually be used — the hard interlock, applied
     * before any transport is selected. Asserted by a test, read by /status.
     */
    public function effectiveTransport(): string
    {
        if ($this->debug) {
            return self::TRANSPORT_FILE;
        }

        // An unrecognised transport fails towards the box, not the wire.
        return in_array($this->transport, [self::TRANSPORT_SEND, self::TRANSPORT_FILE, self::TRANSPORT_LOG], true)
            ? $this->transport
            : self::TRANSPORT_FILE;
    }

    /**
     * Sends one message — or blocks it, and says which interlock did.
     *
     * @return bool true when the message was delivered, written or logged;
     *              false when an interlock blocked a real send. The recovery
     *              flow shows the same response either way (no enumeration),
     *              so false is information for the log, not for the screen.
     *
     * @throws RuntimeException past mail.max_per_request — a loop, not a request
     */
    public function send(string $to, string $subject, string $body): bool
    {
        // Interlock 4 first, and unconditionally: it counts attempts, not
        // deliveries, because the loop it exists to catch is a loop whatever
        // the other three would have done with its output.
        $this->sent++;
        if ($this->sent > $this->maxPerRequest) {
            throw new RuntimeException(sprintf(
                'Refusing to send message %d of a request: mail.max_per_request is %d. '
                . 'Password recovery sends exactly one message, so this is a loop that '
                . 'should not exist — see docs/spec-v1.md 3.3a.',
                $this->sent,
                $this->maxPerRequest
            ));
        }

        return match ($this->effectiveTransport()) {
            self::TRANSPORT_SEND => $this->sendForReal($to, $subject, $body),
            self::TRANSPORT_LOG  => $this->sendToLog($to, $subject),
            default              => $this->sendToFile($to, $subject, $body),
        };
    }

    private function sendForReal(string $to, string $subject, string $body): bool
    {
        // Interlock 1. The master switch, false until somebody edits
        // config.local.php on the machine that should be sending.
        if (!$this->enabled) {
            error_log("rerm mail: BLOCKED (mail.enabled is false) — would have sent \"{$subject}\"");

            return false;
        }

        // Interlock 3. The one that survives human error: still standing when
        // somebody arms the first two on a box holding a real roster.
        // Dropped and logged WITH the address it would have gone to, so the
        // near-miss is visible.
        if ($this->allowedRecipients !== [] && !$this->recipientAllowed($to)) {
            error_log("rerm mail: DROPPED (not on mail.allowed_recipients): {$to} — \"{$subject}\"");

            return false;
        }

        $deliver = $this->deliver ?? static function (string $to, string $subject, string $body, string $headers): bool {
            return mail($to, $subject, $body, $headers);
        };

        return (bool) $deliver($to, $subject, $body, $this->headers());
    }

    private function sendToLog(string $to, string $subject): bool
    {
        error_log("rerm mail: [log transport] to={$to} subject=\"{$subject}\"");

        return true;
    }

    /**
     * A readable .eml in var/mail/ — the useful development setting. The
     * recovery link inside is real and clickable; the file cannot leave the
     * machine.
     */
    private function sendToFile(string $to, string $subject, string $body): bool
    {
        if (!is_dir($this->mailDir)) {
            mkdir($this->mailDir, 0700, true);
        }

        $file = $this->mailDir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';

        $message = $this->headers()
            . "To: {$to}\r\n"
            . "Subject: {$subject}\r\n"
            . 'Date: ' . gmdate('r') . "\r\n"
            . "\r\n"
            . $body;

        return file_put_contents($file, $message) !== false;
    }

    private function recipientAllowed(string $to): bool
    {
        foreach ($this->allowedRecipients as $allowed) {
            if (strcasecmp(trim($allowed), trim($to)) === 0) {
                return true;
            }
        }

        return false;
    }

    private function headers(): string
    {
        // The from name is ours, from config; nothing user-supplied is ever
        // interpolated into a header, which is what keeps header injection
        // impossible rather than filtered.
        $name = preg_replace('/[\r\n"]+/', '', $this->fromName) ?? '';

        return "From: \"{$name}\" <{$this->fromAddress}>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n";
    }
}
