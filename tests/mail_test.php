<?php

declare(strict_types=1);

/**
 * The mail interlocks (spec 3.3a) — one test per interlock, plus the hard one
 * — and the wording of the two messages this application sends.
 *
 * The live database holds 1,953 real committee members' real addresses, so
 * the property under test is not "can it send" but "can it POSSIBLY send when
 * it should not". Every test that exercises the delivery path injects a
 * recorder in place of mail(), which is also what proves a blocked send never
 * reached the wire: the recorder stays empty.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Auth\Level;
use Rerm\Auth\PasswordReset;
use Rerm\Forms\ApprovalNotice;
use Rerm\Mail\Mailer;

/** A scratch var/mail that cleans itself up. */
function mail_scratch(): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/rerm-mail-' . getmypid();
        @mkdir($dir, 0700, true);
        register_shutdown_function(static function () use (&$dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });
    }

    return $dir;
}

/**
 * A Mailer with every default DANGEROUS, so each test arms all interlocks
 * but the one it is about — proving each blocks a send ON ITS OWN.
 *
 * @param array<int, array{0:string,1:string,2:string}> $wire records what reached mail()
 */
function mail_mailer(array $overrides, array &$wire): Mailer
{
    // Each test starts against an empty var/mail, so "one .eml landed" and
    // "nothing landed" are both assertable.
    foreach (glob(mail_scratch() . '/*') ?: [] as $file) {
        @unlink($file);
    }

    $settings = $overrides + [
        'enabled'   => true,
        'transport' => Mailer::TRANSPORT_SEND,
        'allowed'   => [],
        'max'       => 5,
        'debug'     => false,
    ];

    return new Mailer(
        $settings['enabled'],
        $settings['transport'],
        $settings['allowed'],
        $settings['max'],
        'noreply@example.com',
        'Test Mailer',
        $settings['debug'],
        mail_scratch(),
        function (string $to, string $subject, string $body) use (&$wire): bool {
            $wire[] = [$to, $subject, $body];

            return true;
        }
    );
}

// ---------------------------------------------------------------------------
// The four interlocks
// ---------------------------------------------------------------------------

test('interlock 1: mail.enabled=false blocks a real send on its own', function (): void {
    $wire   = [];
    $mailer = mail_mailer(['enabled' => false], $wire);

    assertSame(false, $mailer->send('member@example.com', 'subject', 'body'));
    assertSame([], $wire, 'nothing may reach the wire');
    assertSame([], glob(mail_scratch() . '/*') ?: [], 'and nothing lands on disk either');
});

test('interlock 2: only transport=send reaches the wire; file and log keep it on the box', function (): void {
    $wire   = [];
    $mailer = mail_mailer(['transport' => Mailer::TRANSPORT_FILE], $wire);

    assertSame(true, $mailer->send('member@example.com', 'a subject', 'a body'));
    assertSame([], $wire, 'file transport must not deliver');

    $files = glob(mail_scratch() . '/*.eml') ?: [];
    assertSame(1, count($files), 'file transport writes exactly one .eml');

    $eml = (string) file_get_contents($files[0]);
    assertTrue(str_contains($eml, 'To: member@example.com'), 'the .eml is readable and complete');
    assertTrue(str_contains($eml, 'Subject: a subject'));
    assertTrue(str_contains($eml, 'a body'), 'the body — with its clickable link — is right there');

    $wire = [];
    assertSame(true, mail_mailer(['transport' => Mailer::TRANSPORT_LOG], $wire)->send('member@example.com', 's', 'b'));
    assertSame([], $wire, 'log transport must not deliver');
});

test('interlock 3: a non-empty allowlist drops every address not on it', function (): void {
    $wire   = [];
    $mailer = mail_mailer(['allowed' => ['developer@example.com']], $wire);

    assertSame(false, $mailer->send('member@example.com', 'subject', 'body'));
    assertSame([], $wire, 'the interlock that survives human error');

    // A listed address still goes through — the allowlist narrows, it does
    // not disable — and matching is case-insensitive because inboxes are.
    assertSame(true, $mailer->send('DEVELOPER@example.com', 'subject', 'body'));
    assertSame(1, count($wire));

    // Empty means production: no allowlist, delivery decided by the others.
    $wire = [];
    assertSame(true, mail_mailer(['allowed' => []], $wire)->send('member@example.com', 's', 'b'));
    assertSame(1, count($wire));
});

test('interlock 4: max_per_request THROWS rather than trims, whatever the transport', function (): void {
    $wire   = [];
    $mailer = mail_mailer(['max' => 2, 'transport' => Mailer::TRANSPORT_FILE], $wire);

    $mailer->send('member@example.com', 'one', 'b');
    $mailer->send('member@example.com', 'two', 'b');

    assertThrows(
        fn () => $mailer->send('member@example.com', 'three', 'b'),
        'max_per_request',
        'a third message in one request is a loop that should not exist'
    );

    // A silent cap would hide the bug; the throw names it. Nothing extra
    // landed either.
    assertSame(2, count(glob(mail_scratch() . '/*.eml') ?: []));
});

// ---------------------------------------------------------------------------
// The hard interlock
// ---------------------------------------------------------------------------

test('app.debug=true forces the file transport, whatever the configuration says', function (): void {
    // The one rule config.local.php cannot defeat: everything below is armed
    // for real delivery, and debug still keeps it on the box.
    $wire   = [];
    $mailer = mail_mailer(['debug' => true, 'enabled' => true, 'transport' => Mailer::TRANSPORT_SEND], $wire);

    assertSame(Mailer::TRANSPORT_FILE, $mailer->effectiveTransport());
    assertSame(true, $mailer->send('member@example.com', 'subject', 'body'));
    assertSame([], $wire, 'debug NEVER delivers');
    assertSame(1, count(glob(mail_scratch() . '/*.eml') ?: []));
});

test('an unrecognised transport fails towards the box, not the wire', function (): void {
    $wire   = [];
    $mailer = mail_mailer(['transport' => 'smtp-someday'], $wire);

    assertSame(Mailer::TRANSPORT_FILE, $mailer->effectiveTransport());
    $mailer->send('member@example.com', 's', 'b');
    assertSame([], $wire);
});

// ---------------------------------------------------------------------------
// The committed defaults
// ---------------------------------------------------------------------------

test('a fresh checkout cannot send email', function (): void {
    // fromApp() over the real committed configuration — the same file
    // .github/check-mail-safety.php holds the line on. Belt and braces:
    // CI checks the config, this checks what the Mailer does with it.
    $mailer = Mailer::fromApp(App::boot(dirname(__DIR__)));

    assertTrue(Mailer::TRANSPORT_SEND !== $mailer->effectiveTransport(),
        'the shipped transport must keep messages on the box');
});

// ---------------------------------------------------------------------------
// The approval notice (Phase 14.6, spec-v2 §20.2) — the second message
// ---------------------------------------------------------------------------

test('the approval notice says how many requests wait and where, and names no member', function (): void {
    assertSame('Rodeo Express Roster: 1 RCF request is waiting for your approval', ApprovalNotice::subject(1));
    assertSame('Rodeo Express Roster: 3 RCF requests are waiting for your approval', ApprovalNotice::subject(3));

    $link = 'https://host.example.com/rerm/rcf-approvals';
    $one  = ApprovalNotice::body('Bob Approver', 1, 'Erin Delta', ApprovalNotice::SUBMITTED, $link);
    assertTrue(str_starts_with($one, "Hi Bob Approver,\n"), 'addressed to the approver');
    assertTrue(str_contains($one, '1 Roster Change Form request is waiting for your approval'));
    assertTrue(str_contains($one, 'Erin Delta submitted it.'), 'who sent it, on submission');
    assertTrue(str_contains($one, "Approve or return it here:\n" . $link), 'the link is in the body');

    $three = ApprovalNotice::body('Carl Chair', 3, 'Bob Approver', ApprovalNotice::PASSED_ON, $link);
    assertTrue(str_contains($three, '3 Roster Change Form requests are waiting'));
    assertTrue(str_contains($three, 'Bob Approver approved them and passed them on to you.'), 'who sent them, on approval');
    assertTrue(str_contains($three, "No member's details are in this message"), 'and it says so');

    // No member in it, by construction: the body takes a count, two names
    // of account holders and a link, and the class never reads an entry.
    // An email is PII leaving the building, and an inbox is forwarded.
    $source = (string) file_get_contents(__DIR__ . '/../app/src/Forms/ApprovalNotice.php');
    assertSame(0, preg_match('/rcf_entry|rcf_row|member_name|new_title|previous_title/', $source), 'the notice never reads an entry');
    assertSame(1, preg_match('/function body\(string \$name, int \$count, string \$by, string \$how, string \$link\)/', $source),
        'body() has no parameter an entry could travel in');
});

test('the approval notice tells the screen what happened, in one sentence per outcome', function (): void {
    $bob = static fn (string $outcome): array => ['outcome' => $outcome, 'name' => 'Bob Approver', 'count' => 2];

    assertSame('Bob Approver has been emailed.', ApprovalNotice::sentence($bob(ApprovalNotice::SENT)));
    assertSame('Bob Approver has no email address on the roster, so let them know yourself.',
        ApprovalNotice::sentence($bob(ApprovalNotice::NO_EMAIL)));
    assertSame('Email notices are switched off here, so let Bob Approver know yourself.',
        ApprovalNotice::sentence($bob(ApprovalNotice::OFF)));
    assertSame('Email notices are switched off here, so let Bob Approver know yourself.',
        ApprovalNotice::sentence($bob(ApprovalNotice::BLOCKED)), 'an interlock reads the same to the officer');
    assertSame('', ApprovalNotice::sentence($bob(ApprovalNotice::INELIGIBLE)), 'nothing useful to say');
});

test('the approval notice is switched on in the committed configuration, behind the interlocks, and links to the approvals screen', function (): void {
    $app    = App::boot(dirname(__DIR__));
    $config = $app->config();

    // On, so a production box that arms mail.enabled and the transport
    // gets the notice without a second edit — and still unable to send
    // from a fresh checkout, because the Mailer underneath is not.
    assertSame(true, $config->get('mail.rcf_approval_notices'));
    assertTrue(Mailer::TRANSPORT_SEND !== Mailer::fromApp($app)->effectiveTransport());

    $notice = ApprovalNotice::fromApp($app);
    $link   = (new ReflectionProperty($notice, 'approvalsLink'))->getValue($notice);
    assertSame(rtrim((string) $config->get('app.canonical_url'), '/') . $app->url('rcf-approvals'), $link,
        'absolute, from configuration, never from the Host header');
    assertTrue(str_ends_with($link, '/rerm/rcf-approvals'));

    // Never below a Vice Chairman: the floor is the flow's floor.
    assertSame(Level::SeniorOfficer, ApprovalNotice::FLOOR);
});

// ---------------------------------------------------------------------------
// The recovery email wording (spec 3.3)
// ---------------------------------------------------------------------------

test('the recovery email names its member number in the subject', function (): void {
    // Two addresses in the real roster serve two members each, and the two
    // people behind each inbox hold different titles. The subject line is
    // the disambiguation.
    $subject = PasswordReset::emailSubject('1234567');

    assertSame('Reset the password for Rodeo Express member number 1234567', $subject);
});

test('the recovery email names its member number in the FIRST line of the body', function (): void {
    $body  = PasswordReset::emailBody('1234567', 'https://host.example.com/app/reset?token=x', 60);
    $lines = explode("\n", $body);

    assertSame('This resets the password for member number 1234567 only.', $lines[0]);
    assertTrue(str_contains($body, 'household'), 'the household caveat is spec wording, not optional');
    assertTrue(str_contains($body, "will not change that account's password"));
    assertTrue(str_contains($body, 'https://host.example.com/app/reset?token=x'), 'the link is in the body');
    assertTrue(str_contains($body, '60 minutes'), 'and so is the lifetime');
});
