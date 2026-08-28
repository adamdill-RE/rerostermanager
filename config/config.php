<?php

declare(strict_types=1);

/**
 * Base configuration.
 *
 * This file is committed, so it holds no credentials. Two things override it,
 * in this order:
 *
 *   1. config/config.local.php   — gitignored, returns a partial array.
 *   2. RERM_* environment variables — see Rerm\Config::ENV_MAP.
 *
 * On the server this file lives outside public_html, so even the defaults are
 * not web-readable.
 */
return [
    'app' => [
        'name' => 'Rodeo Express Roster',

        // The running version, shown in the footer of every screen.
        //
        // There is no build step on this host and nothing may require one
        // (CLAUDE.md), so there is no tag, no commit stamp and no generated
        // file to read it from — it is a constant, edited on purpose, and
        // that is the point: a number nobody has to maintain is a number
        // nobody can trust when somebody on a phone says "it still does the
        // old thing" and the only question that matters is which build they
        // are looking at.
        //
        // MINOR IS THE BUILD PHASE, so the footer answers that question in
        // the vocabulary the specs and CLAUDE.md already use: 1.9.0 is the
        // application as Phase 9 left it, 1.10.0 is Phase 10, and a patch
        // release is a fix that landed inside a phase. Bump it in the commit
        // that closes the phase, and nowhere else — a version that moves on
        // every commit tells a user nothing they can repeat back.
        'version' => '1.10.0',

        // The app is served from https://www.reshiftmanager.com/rerm/, never
        // the domain root — that is the landing page in site/index.html, and
        // RESM sits beside us at /resm/. Every link, form action, redirect,
        // asset URL and cookie path is built from this value. Keep the
        // trailing slash.
        'base_path' => '/rerm/',

        // Everything is stored and compared in UTC; this is display only.
        'display_timezone' => 'America/Chicago',

        // Scheme and host for the one place a link leaves the browser: the
        // password recovery email. Configured rather than read from the Host
        // header, because a header-derived link is a link an attacker can
        // point at their own host. No trailing slash; app.base_path is
        // appended by $app->url().
        'canonical_url' => 'https://www.reshiftmanager.com',

        // Verbose errors on screen. Never true on the server.
        'debug' => false,

        // Guards /status. Null means the page 404s unless debug is on.
        'status_key' => null,

        // Guards /setup, which applies migrations and sets the master
        // administrator's password from a browser. It is a genuine
        // administrative credential: anyone holding it can take that account.
        // Null disables /setup outright, and that is the state to leave it in
        // once the app is running.
        'setup_key' => null,
    ],

    'db' => [
        // NOT the web server. Ahosting runs MySQL on separate hardware — see
        // docs/hosting.md. The production value is an IP address.
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'rerm',
        'user'    => 'rerm',
        'pass'    => '',
        'socket'  => null,
    ],

    'session' => [
        // Distinct from RESM's RESMSESS. Two cookies from two apps on one
        // domain must not be able to collide, which is also why the path
        // below is /rerm/ and never /.
        'name' => 'RERMSESS',

        // The host defaults for every setting below are unsafe
        // (docs/hosting.md); Rerm\Session sets all of them explicitly before
        // session_start(). 'secure' is the one that varies by environment:
        // local development is plain http, so docker-compose sets
        // RERM_SESSION_SECURE=0 there.
        'secure' => true,

        // Browser-session cookie. The 90-day "keep me signed in" is a
        // separate DB-backed token, because session.gc_maxlifetime here is
        // 1440s and garbage collection is not ours to govern.
        'lifetime' => 0,

        // Null uses <app_root>/var/sessions rather than the cPanel-wide
        // directory that RESM would otherwise share with us.
        'save_path' => null,
    ],

    'auth' => [
        // Every imported officer and newly designated user starts here
        // (spec 3.1), with must_change_password set.
        'default_password' => '1234',

        // Minimum length on reset. Length is what matters; complexity rules
        // nobody can satisfy produce a sticky note on a monitor.
        'min_password_length' => 8,

        // bcrypt at cost 11, for parity with RESM and predictable timing.
        // argon2id is available and affordable here — this app has no
        // shift-start login storm — but measure before switching.
        'password_algo' => PASSWORD_BCRYPT,
        'password_cost' => 11,

        // Rolling persistent login (spec 3.4). Deliberately long: this app is
        // used in bursts weeks apart, and a session that expires between them
        // is the entire friction budget.
        'remember_days' => 90,

        // A login WITHOUT "keep me signed in" still lives in auth_token —
        // the PHP session cannot outlast this host's 1440s gc_maxlifetime —
        // but its row expires after a day rather than 90, rolling while it
        // is used.
        'session_token_hours' => 24,

        // The selector.verifier cookie (spec 3.4). Distinct from the PHP
        // session cookie RERMSESS; scoped to the same path, for the same
        // coexistence reason.
        'cookie_name' => 'RERMAUTH',

        // Unspent, unexpired reset tokens one account may hold at once. The
        // /forgot response never varies (no enumeration), but replaying the
        // form must not fill a shared household inbox on our behalf.
        'max_outstanding_resets' => 3,

        // Deliberately loose (spec 3.5): a locked-out Captain is a worse
        // outcome than a guessing attempt on an internal roster tool.
        'lockout_attempts'       => 10,
        'lockout_window_seconds' => 900,
        'lockout_seconds'        => 60,

        // Single-use password reset tokens (spec 3.3).
        'reset_token_minutes' => 60,
    ],

    'mail' => [
        // Password recovery is the only mail this app sends. There is no bulk
        // send path in v1, deliberately.
        //
        // The account has a DEDICATED IP, so reputation is ours to build
        // rather than inherit — see docs/hosting.md. That solves
        // deliverability; it does nothing about sending by mistake, which is
        // the larger risk while we are building. An import loads ~1,950 real
        // committee members' real addresses, so a stray loop reaches actual
        // people who did not ask for it and cannot be unsent.
        //
        // Four independent interlocks, spec 3.3a. Any one of them blocks a
        // send, and the shipped defaults below block it three times over.

        // 1. Master switch. FALSE here, so a fresh checkout and a fresh
        //    deploy both start unable to send. Enabling it is a deliberate
        //    edit to config.local.php on the machine that should be sending.
        'enabled' => false,

        // 2. Transport.
        //      'log'  — a line in the error log, nothing leaves the box
        //      'file' — a readable .eml in var/mail/, nothing leaves the box
        //      'send' — real delivery through mail()
        //    'file' is the useful development setting: the recovery link is
        //    right there to click, and no message exists that could escape.
        'transport' => 'file',

        // 3. Recipient allowlist. When non-empty, ONLY these addresses can
        //    receive; anything else is dropped and logged with the address it
        //    would have gone to. This is the interlock that survives someone
        //    turning the first two on while a real roster is loaded, so keep
        //    it populated with your own addresses in every environment that
        //    is not production.
        'allowed_recipients' => [],

        // 4. Per-request ceiling. Password recovery sends exactly one message,
        //    so anything above a handful means a loop that should not exist.
        //    Exceeding it throws rather than trimming: a silent cap would hide
        //    the bug it exists to catch.
        'max_per_request' => 5,

        'from_address' => 'noreply@reshiftmanager.com',
        'from_name'    => 'Rodeo Express Roster',
    ],

    'import' => [
        // Rows per transaction while applying. 30s is the execution ceiling
        // and a full roster is ~1,950 members plus ~9,750 metric rows.
        'batch_rows' => 500,

        // Staged-but-unapplied imports are discarded after this (spec 6.3).
        'stage_ttl_hours' => 24,
    ],

    'roster' => [
        // Page sizes (spec 7.2). A compliance list must say "showing 50 of
        // 1,247" rather than scrolling forever.
        'page_size_mobile'  => 50,
        'page_size_desktop' => 100,

        // Officers per committeeman (spec, open item OI-11).
        'max_officers_per_member' => 3,
    ],
];
