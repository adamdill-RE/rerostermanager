<?php

declare(strict_types=1);

/**
 * The email log (Phase 14.8, spec-v2 §22): one row for every message the
 * application tried to send — what prompted it, who it went to, and what
 * became of it — written by the Mailer, read by an Admin at /email-log.
 *
 * What is held here:
 *
 *   1. Every transport and every interlock writes its row, with the right
 *      word: sent, filed, logged, blocked (naming the interlock), refused,
 *      failed. A Mailer built without a log writes none.
 *   2. The caller's half travels: purpose, reason, recipient's account,
 *      actor. The approval notice writes `no_email` itself for the one
 *      attempt the Mailer never sees, and names no member.
 *   3. The screen is Admin's, read-only, drawn from the menu, and the
 *      page filters by outcome, purpose, date and recipient.
 *   4. The log can never fail the send, and nothing deletes a row.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use Rerm\App;
use Rerm\Admin\EmailLogPage;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\User;
use Rerm\Forms\ApprovalNotice;
use Rerm\Mail\EmailLog;
use Rerm\Mail\Mailer;
use Rerm\Menu;
use Rerm\Routes;

function el_app(): App
{
    static $app = null;

    return $app ??= App::boot(dirname(__DIR__));
}

function el_source(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/' . $relative);
}

// ---------------------------------------------------------------------------
// The pure parts: the route, the menu, the words
// ---------------------------------------------------------------------------

test('the email log is an Admin screen on the Audit Log\'s row, under Administer, and read-only', function (): void {
    assertSame(Capability::ViewAuditLog->value, Routes::guard('email-log'), 'who may read the audit log may read what was emailed');

    $tiles = array_values(array_filter(Menu::tiles(), static fn (array $t): bool => $t['route'] === 'email-log'));
    assertSame(1, count($tiles), 'one tile');
    assertSame('Email Log', $tiles[0]['label']);
    assertSame('administer', $tiles[0]['group']);
    assertSame(Capability::ViewAuditLog, $tiles[0]['cap']);

    $view = el_source('app/views/email-log.php');
    assertTrue(str_contains($view, 'method="get"'), 'the filter is a GET');
    assertTrue(!str_contains($view, 'method="post"') && !str_contains($view, 'Csrf::field'), 'no write, no token');

    foreach (['app/src/Admin/EmailLogPage.php', 'app/src/Mail/EmailLog.php'] as $file) {
        $source = el_source($file);
        assertTrue(preg_match('/\bDELETE\s+FROM\b/i', $source) !== 1, $file . ' never deletes');
        assertTrue(preg_match('/\bUPDATE\s+[a-z_]+\s+SET\b/i', $source) !== 1, $file . ' never updates');
    }

    // The dispatch arm exists (auth_test holds the table and the switch
    // equal; this names the handler it reaches).
    assertTrue(str_contains(el_source('public/index.php'), 'function email_log_screen('));
});

test('every outcome and every purpose has a word, and only Sent counts as delivered', function (): void {
    foreach ([EmailLog::SENT, EmailLog::FAILED, EmailLog::FILED, EmailLog::LOGGED, EmailLog::BLOCKED, EmailLog::REFUSED, EmailLog::NO_EMAIL] as $outcome) {
        assertTrue(isset(EmailLog::OUTCOMES[$outcome]) && EmailLog::OUTCOMES[$outcome] !== '', $outcome . ' has a word');
    }
    assertSame([EmailLog::SENT], EmailLog::DELIVERED);
    assertSame('Sent', EmailLog::OUTCOMES[EmailLog::SENT]);
    assertSame('Filed, not sent', EmailLog::OUTCOMES[EmailLog::FILED], 'a development transport never reads as delivery');
    assertSame(['password_reset', 'rcf_approval_notice'], array_keys(EmailLog::PURPOSES), 'the two messages that exist');
});

test('the approval notice\'s reason names the count and who moved the requests, never a member', function (): void {
    assertSame('1 RCF request submitted by Erin Delta', ApprovalNotice::reason(1, 'Erin Delta', ApprovalNotice::SUBMITTED));
    assertSame('3 RCF requests approved and passed on by Bob Approver', ApprovalNotice::reason(3, 'Bob Approver', ApprovalNotice::PASSED_ON));
});

test('the two callers hand the Mailer the log\'s half of the row', function (): void {
    $forgot = el_source('public/index.php');
    assertTrue(str_contains($forgot, "'purpose'           => Rerm\\Mail\\EmailLog::PURPOSE_PASSWORD_RESET"), 'the password reset says why');
    assertTrue(str_contains($forgot, "'recipient_user_id' => (int) \$account['id']"), 'and for whom');

    $notice = el_source('app/src/Forms/ApprovalNotice.php');
    assertTrue(str_contains($notice, 'EmailLog::PURPOSE_APPROVAL_NOTICE'));
    assertTrue(str_contains($notice, 'EmailLog::NO_EMAIL'), 'the one row the notice writes itself');

    $entries = el_source('app/src/Forms/RcfEntries.php');
    assertSame(2, preg_match_all('/->waiting\(([^;]*)\$(requester|approver)->id\s*\)/s', $entries), 'both callers pass the actor');
});

// ---------------------------------------------------------------------------
// The database: what the Mailer writes
// ---------------------------------------------------------------------------

function el_pdo(): PDO
{
    static $pdo = null;
    static $failure = null;

    if ($failure !== null) {
        skip($failure);
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = el_app()->db();
    } catch (Throwable $e) {
        $failure = 'no database: ' . $e->getMessage();
        skip($failure);
    }

    $ready = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_log'"
    )->fetchColumn();
    if ($ready === 0) {
        $failure = 'database is not migrated — run: php bin/migrate.php';
        skip($failure);
    }

    return $pdo;
}

/** Every row this test file wrote, oldest first. Rows are keyed by the subject prefix. */
function el_rows(): array
{
    $read = el_pdo()->prepare("SELECT * FROM email_log WHERE subject LIKE 'EL-%' OR reason LIKE 'EL-%' ORDER BY id");
    $read->execute();

    return $read->fetchAll();
}

function el_scratch(): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/rerm-email-log-' . getmypid();
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
 * A Mailer with a real log, every interlock OPEN unless overridden, and a
 * wire that records — or throws, when the test asks it to.
 */
function el_mailer(array $overrides, array &$wire, bool $wireThrows = false): Mailer
{
    $settings = $overrides + [
        'enabled'   => true,
        'transport' => Mailer::TRANSPORT_SEND,
        'allowed'   => [],
        'max'       => 5,
        'debug'     => false,
        'accept'    => true,
    ];

    return new Mailer(
        $settings['enabled'],
        $settings['transport'],
        $settings['allowed'],
        $settings['max'],
        'noreply@example.com',
        'Test Mailer',
        $settings['debug'],
        el_scratch(),
        function (string $to, string $subject, string $body) use (&$wire, $wireThrows, $settings): bool {
            if ($wireThrows) {
                throw new RuntimeException('EL-wire down');
            }
            $wire[] = [$to, $subject, $body];

            return (bool) $settings['accept'];
        },
        new EmailLog(el_pdo())
    );
}

test('email log fixtures start clean', function (): void {
    // The table is a record: this is the one place a test-written row is
    // removed, and only the rows this file wrote, by their prefix.
    el_pdo()->exec("DELETE FROM email_log WHERE subject LIKE 'EL-%' OR reason LIKE 'EL-%'");
    assertSame([], el_rows());
});

test('every transport and every interlock writes its row, with the word and the detail', function (): void {
    $wire    = [];
    $context = ['purpose' => EmailLog::PURPOSE_PASSWORD_RESET, 'reason' => 'EL-why', 'recipient_user_id' => null, 'actor_user_id' => null];

    assertSame(true, el_mailer([], $wire)->send('a@example.com', 'EL-sent', 'body', $context));
    assertSame(false, el_mailer(['accept' => false], $wire)->send('a@example.com', 'EL-failed', 'body', $context));
    assertSame(true, el_mailer(['transport' => Mailer::TRANSPORT_FILE], $wire)->send('a@example.com', 'EL-filed', 'body', $context));
    assertSame(true, el_mailer(['transport' => Mailer::TRANSPORT_LOG], $wire)->send('a@example.com', 'EL-logged', 'body', $context));
    assertSame(false, el_mailer(['enabled' => false], $wire)->send('a@example.com', 'EL-blocked-1', 'body', $context));
    assertSame(false, el_mailer(['allowed' => ['dev@example.com']], $wire)->send('a@example.com', 'EL-blocked-3', 'body', $context));
    assertSame(true, el_mailer(['debug' => true], $wire)->send('a@example.com', 'EL-debug', 'body', $context));

    $ceiling = el_mailer(['max' => 1], $wire);
    $ceiling->send('a@example.com', 'EL-first', 'body', $context);
    assertThrows(static fn () => $ceiling->send('a@example.com', 'EL-refused', 'body', $context), 'max_per_request');

    assertThrows(static fn () => el_mailer([], $wire, true)->send('a@example.com', 'EL-wire', 'body', $context), 'wire down');

    $rows = [];
    foreach (el_rows() as $row) {
        $rows[(string) $row['subject']] = $row;
    }

    $expect = [
        'EL-sent'      => [EmailLog::SENT, 'send', ''],
        'EL-failed'    => [EmailLog::FAILED, 'send', 'mail() returned false'],
        'EL-filed'     => [EmailLog::FILED, 'file', null],
        'EL-logged'    => [EmailLog::LOGGED, 'log', ''],
        'EL-blocked-1' => [EmailLog::BLOCKED, 'send', 'mail.enabled is false'],
        'EL-blocked-3' => [EmailLog::BLOCKED, 'send', 'not on mail.allowed_recipients'],
        'EL-debug'     => [EmailLog::FILED, 'file', null],
        'EL-first'     => [EmailLog::SENT, 'send', ''],
        'EL-refused'   => [EmailLog::REFUSED, 'send', 'message 2 of a request; mail.max_per_request is 1'],
        'EL-wire'      => [EmailLog::FAILED, 'send', 'EL-wire down'],
    ];
    foreach ($expect as $subject => [$outcome, $transport, $detail]) {
        assertTrue(isset($rows[$subject]), $subject . ' has a row');
        assertSame($outcome, (string) $rows[$subject]['outcome'], $subject . ' outcome');
        assertSame($transport, (string) $rows[$subject]['transport'], $subject . ' transport');
        if ($detail !== null) {
            assertSame($detail, (string) $rows[$subject]['detail'], $subject . ' detail');
        } else {
            assertTrue(str_ends_with((string) $rows[$subject]['detail'], '.eml'), $subject . ' names the file');
        }
        assertSame('a@example.com', (string) $rows[$subject]['recipient']);
        assertSame(EmailLog::PURPOSE_PASSWORD_RESET, (string) $rows[$subject]['purpose']);
        assertSame('EL-why', (string) $rows[$subject]['reason']);
    }
    assertSame(count($expect), count($rows), 'one row per attempt, and no more');

    // A Mailer built without a log writes none — the test suite's own
    // Mailers, and the CLI's.
    $before = count(el_rows());
    $silent = new Mailer(true, Mailer::TRANSPORT_LOG, [], 5, 'noreply@example.com', 'Test', false, el_scratch());
    $silent->send('a@example.com', 'EL-silent', 'body');
    assertSame($before, count(el_rows()));
});

test('a context that cannot be stored is clipped, and a broken log never fails the send', function (): void {
    $wire = [];
    $long = str_repeat('x', 400);
    assertSame(true, el_mailer([], $wire)->send('a@example.com', 'EL-long', 'body', ['purpose' => 'password_reset', 'reason' => 'EL-' . $long]));
    $rows = array_values(array_filter(el_rows(), static fn (array $r): bool => $r['subject'] === 'EL-long'));
    assertSame(255, mb_strlen((string) $rows[0]['reason']), 'clipped to the column');

    // A log pointed at a database with no table swallows the failure.
    $broken = new PDO('sqlite::memory:');
    $mailer = new Mailer(true, Mailer::TRANSPORT_SEND, [], 5, 'noreply@example.com', 'Test', false, el_scratch(),
        function (string $to, string $subject, string $body) use (&$wire): bool {
            $wire[] = [$to, $subject, $body];

            return true;
        },
        new EmailLog($broken));
    $wire = [];
    assertSame(true, $mailer->send('a@example.com', 'EL-broken-log', 'body'));
    assertSame(1, count($wire), 'the message went, whatever the log did');
});

test('the page reads newest first, pairs each row with its words, and filters by outcome, purpose, date and recipient', function (): void {
    $page = (new EmailLogPage(el_pdo(), 50, 100))->page([]);
    assertTrue($page['total'] >= 11);
    $subjects = array_column($page['rows'], 'subject');
    $mine     = array_values(array_filter($subjects, static fn (string $s): bool => str_starts_with($s, 'EL-')));
    assertSame('EL-long', $mine[0], 'newest first');

    $sent = (new EmailLogPage(el_pdo(), 50, 100))->page(['outcome' => EmailLog::SENT]);
    foreach ($sent['rows'] as $row) {
        assertSame('Sent', $row['outcome_word']);
        assertSame(true, $row['delivered']);
    }
    assertSame(EmailLog::SENT, $sent['outcome']);

    $blocked = (new EmailLogPage(el_pdo(), 50, 100))->page(['outcome' => EmailLog::BLOCKED, 'purpose' => 'password_reset']);
    $names   = array_column(array_filter($blocked['rows'], static fn (array $r): bool => str_starts_with($r['subject'], 'EL-')), 'subject');
    sort($names);
    assertSame(['EL-blocked-1', 'EL-blocked-3'], $names);
    foreach ($blocked['rows'] as $row) {
        assertSame(false, $row['delivered']);
        assertSame('Password reset', $row['purpose_word']);
    }

    $nonsense = (new EmailLogPage(el_pdo(), 50, 100))->page(['outcome' => 'teleported', 'purpose' => 'x', 'size' => '7', 'page' => '-3']);
    assertSame('', $nonsense['outcome'], 'an unknown word is "anything"');
    assertSame('', $nonsense['purpose']);
    assertSame(50, $nonsense['size']);
    assertSame(1, $nonsense['page']);

    $future = (new EmailLogPage(el_pdo(), 50, 100))->page(['from' => '2099-01-01']);
    assertSame(0, $future['total']);
    assertSame('2099-01-01', $future['from']);

    $nobody = (new EmailLogPage(el_pdo(), 50, 100))->page(['member' => 'EL-no-such-number']);
    assertSame(0, $nobody['total'], 'a number that is nobody\'s matches nothing');
});

test('the screen renders the three columns the request asked for, escaped, and a Member may not open it', function (): void {
    $app  = el_app();
    $user = new User(1, 1, '987654321', Level::Admin, null, null, false, 'Ada Admin');
    $log  = (new EmailLogPage(el_pdo(), 50, 100))->page(['outcome' => EmailLog::FAILED]);

    ob_start();
    require $app->path('app/views/email-log.php');
    $html = (string) ob_get_clean();

    assertTrue(str_contains($html, '<h1>Email Log</h1>'));
    assertTrue(str_contains($html, 'What prompted it') && str_contains($html, '>To<') && str_contains($html, 'Did it go'), 'the three questions');
    assertTrue(str_contains($html, 'EL-wire down'), 'the error beside the word');
    assertTrue(str_contains($html, 'chip-danger'), 'a failure is red, with its word');
    assertTrue(str_contains($html, 'a@example.com'));
    assertTrue(!str_contains($html, 'name="csrf"'), 'read-only');

    $member = new User(2, 2, 'PG000099', Level::Member, null, null, false, 'Nobody');
    assertTrue(!Rerm\Auth\Access::mayUse($member, Capability::ViewAuditLog));
    assertTrue(!isset(Menu::forUser($member)['administer']), 'no tile for a Member');
});

test('email log fixtures are cleaned up', function (): void {
    el_pdo()->exec("DELETE FROM email_log WHERE subject LIKE 'EL-%' OR reason LIKE 'EL-%'");
});
