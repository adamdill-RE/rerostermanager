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

        // The app is served from https://www.reshiftmanager.com/rerm/, never
        // the domain root — that is the landing page in site/index.html, and
        // RESM sits beside us at /resm/. Every link, form action, redirect,
        // asset URL and cookie path is built from this value. Keep the
        // trailing slash.
        'base_path' => '/rerm/',

        // Everything is stored and compared in UTC; this is display only.
        'display_timezone' => 'America/Chicago',

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

        // Deliberately loose (spec 3.5): a locked-out Captain is a worse
        // outcome than a guessing attempt on an internal roster tool.
        'lockout_attempts'       => 10,
        'lockout_window_seconds' => 900,
        'lockout_seconds'        => 60,

        // Single-use password reset tokens (spec 3.3).
        'reset_token_minutes' => 60,
    ],

    'mail' => [
        // Password recovery is the only mail this app sends. Deliverability
        // from a shared IP is open item OI-9 — see docs/hosting.md.
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
