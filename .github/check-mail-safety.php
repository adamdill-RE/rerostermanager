<?php

declare(strict_types=1);

/**
 * Refuse to ship a configuration that could send email.
 *
 * Run by CI and safe to run by hand:
 *
 *     php .github/check-mail-safety.php
 *
 * config/config.php is what a fresh checkout and a fresh deploy both read
 * before anyone has written a config.local.php. An import loads ~1,950 real
 * committee members' real email addresses, so a default that can deliver is
 * one mistake away from messaging the whole committee — unrecallably, and on
 * a brand-new dedicated IP whose reputation we are still building.
 *
 * So the committed defaults must be incapable of sending. Turning delivery on
 * is a deliberate edit to config.local.php on the box that should be sending,
 * never something inherited from git. See docs/spec-v1.md section 3.3a.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/config/config.php';

$mail = $config['mail'] ?? null;
if (!is_array($mail)) {
    fwrite(STDERR, "::error::config/config.php has no 'mail' block.\n");
    exit(1);
}

$problems = [];

// Interlock 1. Anything truthy here arms delivery for every environment that
// does not override it, which includes a first deploy.
if (!empty($mail['enabled'])) {
    $problems[] = "mail.enabled must ship false; found " . var_export($mail['enabled'], true);
}

// Interlock 2. 'log' and 'file' both keep the message on the box. 'send' is
// the only value that puts anything on the wire.
$transport = $mail['transport'] ?? null;
if ($transport === 'send') {
    $problems[] = "mail.transport must not ship as 'send'";
}
if (!in_array($transport, ['log', 'file', 'send'], true)) {
    $problems[] = "mail.transport must be one of log|file|send; found " . var_export($transport, true);
}

// Interlock 4. Recovery sends exactly one message. A large ceiling here would
// let a loop through before anything noticed.
$max = $mail['max_per_request'] ?? null;
if (!is_int($max) || $max < 1 || $max > 25) {
    $problems[] = "mail.max_per_request must be an int between 1 and 25; found " . var_export($max, true);
}

// Interlock 3 is deliberately NOT asserted to be non-empty: production runs
// with an empty allowlist, because an allowlist there would break recovery for
// the committee. It must exist and be a list, though - a string here would
// silently allow one address and deny every other.
if (!array_key_exists('allowed_recipients', $mail) || !is_array($mail['allowed_recipients'])) {
    $problems[] = 'mail.allowed_recipients must exist and be an array';
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, "::error::{$problem}\n");
    }
    fwrite(STDERR, "\nThe committed configuration could send email. See docs/spec-v1.md 3.3a.\n");
    exit(1);
}

printf(
    "mail ships disabled: enabled=%s transport=%s max_per_request=%d\n",
    var_export($mail['enabled'], true),
    var_export($transport, true),
    $max
);
