# Hosting environment

Ahosting Reseller Gold, package 3000. Every figure below was **measured** on
the live account for the RESM application between 2026-08-23 and 2026-08-24 and
applies unchanged to this app — same server, same PHP, same database host.
Reproduce them against `/rerm/` before go-live rather than trusting this file.

## Platform

| Item | Detail |
| --- | --- |
| Server | sh193 · cPanel 136.0 (build 35) · EL9 / CloudLinux · x86_64 |
| Kernel | 5.14.0-570.19.1.el9_6.x86_64 |
| Web server | **LiteSpeed** (confirmed by the `Server:` response header) |
| PHP | **8.2.33**, SAPI `litespeed` (LSAPI) |
| Database | **MySQL 8.0.41** at **152.160.193.196** — a separate machine |
| Shared IP | 152.160.208.75 |
| Document root | `/home/reshiftmanager/public_html` |

cPanel misreports three things at once, and each cost time on RESM:

- It shows **Apache 2.4.68** — that is the config LSWS parses, not the running
  server. `.htaccess` works; `php_value`, `php_flag` and anything inside
  `<IfModule mod_php*>` never fires under LSAPI.
- It shows **MariaDB 10.11.18** — that is the database on the *web* server,
  which neither app touches.
- It shows **`apache_php_fpm` down** — expected, because LiteSpeed serves PHP
  over LSAPI and Apache's FPM has nothing to do. Do not "fix" it.

## The database is on a different server

`db.host` is **152.160.193.196**, not `localhost` and not `127.0.0.1` — the
address cPanel shows under **Remote MySQL**, an IP rather than a hostname.

Point the app at the web server instead and you reach a MySQL instance that is
not yours. It answers:

```
SQLSTATE[HY000] [1524] Plugin 'unix_socket' is not loaded
```

That reads like a credentials problem and is not one. It is a different server
refusing an account it has never heard of. **No password reset will fix it** —
MariaDB rejects `SET PASSWORD` outright for a `unix_socket` account, so
cPanel's Change Password reports success while changing nothing — and
**recreating the database user will not either.**

### MySQL 8.0, with consequences

- **No `RETURNING`.** That is a MariaDB extension; an insert that needs its own
  row back takes a second statement.
- **A STORED generated column's source cannot carry `ON DELETE CASCADE`** —
  error 1215, and the table simply will not create. MariaDB accepts the same
  shape. Every uniqueness key over a generated column in this schema is
  therefore `VIRTUAL`. RESM shipped a STORED one, passed a MariaDB-only
  pipeline, and failed on the real server; CI here runs both engines for that
  reason.
- Collation defaults differ between the engines (`utf8mb4_0900_ai_ci` here), so
  every table names `utf8mb4_unicode_ci` explicitly and a test asserts it.
- `-cll-lve` confirms CloudLinux LVE. Per-account entry-process and CPU caps
  are real — they are why WebSockets and SSE are forbidden.

## Where application code must live

`DOCUMENT_ROOT` is `public_html` itself and this app is served from
`public_html/rerm/`, so **everything under `public_html` is web-reachable**,
including anything placed beside `rerm/`. Non-public code therefore lives
outside it entirely:

```
/home/reshiftmanager/rerm-app/           app/ bin/ db/ config/ var/
/home/reshiftmanager/public_html/rerm/   public/ — the only web content
/home/reshiftmanager/public_html/index.html   the landing page, from site/
/home/reshiftmanager/public_html/resm/   RESM — not ours, never touch
```

`public/index.php` finds the app root by probing, so the same file works
locally and on the server with nothing to configure.

## Extensions

Present: `pdo`, `pdo_mysql`, `mysqlnd`, `mbstring`, `json`, `openssl`,
`session`, `curl`, `fileinfo`, `zip`, `gd`.

Absent, and worth knowing before writing code against them:

- **`intl`** — no `IntlDateFormatter`, `NumberFormatter` or `Collator`. Format
  with `DateTimeImmutable`.
- **`sodium`** — no `sodium_crypto_*`. Use `random_bytes()` for tokens and
  `hash_hmac()` / `hash_equals()` for signing and comparison.
- **OPcache** — not installed. A file-copy deploy takes effect on the very next
  request with no revalidation lag, and every request recompiles every file it
  touches. Worth asking the host to enable `ea-php82-php-opcache`; if it is
  enabled later, `opcache.validate_timestamps` becomes a deploy concern.

### No spreadsheet extension, and why it does not matter

There is no `PhpSpreadsheet`, because there is no Composer. The readers are
therefore written directly against the extensions this host does have, and
**all three roster formats are supported natively** — see `docs/spec-v1.md`
§6.1 and the verification in `docs/data-findings.md` §10.

- **`.xlsx`** is a zip of XML. `zip` and `xmlreader` are both present, so
  `XlsxReader` streams it with `XMLReader` rather than building a DOM.
- **`.xls`** is BIFF8 records inside an OLE2 compound file. That needs no
  extension at all — `CompoundFile` walks the container with `unpack`, and
  `XlsReader` walks the records. This is the format Rodeo Houston actually
  sends, so it was never optional.
- **`.csv`** needs `mbstring`, for the Windows-1252 that "Save as CSV"
  produces on a Windows machine.

Measured on this host's limits: the real 1,954-row `.xls` reads in 0.07s using
8MB; a 1.9M `.xlsx` of 9,770 rows reads in 2.6s using 22MB. Against 30s and
128M, that is roughly five times the headroom the real roster needs.

## Password hashing

`bcrypt`, `argon2i` and `argon2id` all available; `PASSWORD_DEFAULT` is bcrypt.

Argon2id's PHP default `memory_cost` is 64MB **per hash operation** against a
128MB `memory_limit` and an LVE cap on the account as a whole. This app has no
equivalent of RESM's shift-start login storm — sign-ins here are spread over
weeks — so argon2id at a reduced `memory_cost` of 32MB is affordable. **bcrypt
at cost 11 is the shipped default** for parity with RESM and predictable
timing. Measure before changing it.

## Session configuration — defaults are unsafe, override every one

| Setting | Host default | Required here |
| --- | --- | --- |
| `session.cookie_httponly` | **off** | on |
| `session.cookie_secure` | **0** | 1 |
| `session.cookie_samesite` | **unset** | `Lax` |
| `session.cookie_path` | **`/`** | **`/rerm/`** |
| `session.use_strict_mode` | **0** | 1 |
| `session.name` | `PHPSESSID` | **`RERMSESS`** |

Set them with `session_set_cookie_params()` and `ini_set()` before
`session_start()`. Do not rely on host configuration, which can change under
you on a shared box.

`cookie_path` and `session.name` are not cosmetic here: RESM sets `RESMSESS` on
`/resm/`. A cookie at path `/` from either app would be sent to the other.

`session.save_path` is `/var/cpanel/php/sessions/ea-php82`, a cPanel-wide
directory shared with RESM. This app points at `<app_root>/var/sessions`
instead — private, outside the document root, and ours to expire.

### The 90-day session cannot be a PHP session

`session.gc_maxlifetime` is **1440 seconds**. Garbage collection on a shared
host is not ours to govern, and raising `gc_maxlifetime` on a shared save path
does not reliably extend anything. "Keep me signed in" is a DB-backed rotating
selector/verifier token — see `docs/spec-v1.md` §3.4.

## Limits

| Setting | Value | Relevance here |
| --- | --- | --- |
| `memory_limit` | 128M | Export must stream, not assemble |
| `max_execution_time` | **30s** | The 1,954-row import must batch inside it |
| `post_max_size` | 8M | |
| `upload_max_filesize` | **2M** | The sample `.xls` is 1.2M — thin margin. CSV is ~400K |
| `max_input_vars` | **1000** | PHP **truncates silently** past it. The Assign screen is select-then-act for this reason |
| `default_charset` | UTF-8 | |

## Mail

`/usr/sbin/sendmail` is present and `exim` 4.99.5 is running, so PHP `mail()`
works. Password recovery is the only message this app sends.

**This account has a dedicated, exclusive IP**, which changes the calculus. The
usual shared-hosting problem is inheriting a reputation earned by whoever else
sends from the same address; that does not apply here. Sender reputation is
ours alone to build — and, equally, ours alone to ruin. `mail()` plus SPF, DKIM
and DMARC on that IP is a sound plan, and the SMTP fallback drops to a
contingency rather than an expectation.

Two things follow from the IP being ours:

- **Configure SPF, DKIM and DMARC in cPanel before the first real send.** On a
  shared IP these are largely someone else's problem; on a dedicated one an
  unauthenticated message is filed as spam and teaches every receiver that this
  address sends unauthenticated mail.
- **A new IP has no reputation at all**, which is not the same as a good one.
  The first sends should be low volume and wanted — which password recovery
  inherently is, since somebody just asked for it. There is no bulk send path
  in v1, so there is nothing here to warm up gradually.

### Sending by mistake is the larger risk

Deliverability is now a solved problem; sending something we did not mean to is
not. An import loads ~1,950 real committee members' real addresses, so a stray
loop against that table reaches real people, cannot be recalled, and burns a
brand-new IP's reputation on its first day.

Delivery is therefore **off in the committed configuration** and has to be
opted into, four independent ways, with a fifth interlock that configuration
cannot defeat: `app.debug === true` forces the transport to `file` regardless.
CI fails the build if the committed defaults could ever send. The full design
is `docs/spec-v1.md` §3.3a.

The `file` transport writes a readable `.eml` into `var/mail/` — outside the
document root, `0700` — so recovery is fully testable locally with nothing
capable of leaving the machine. (OI-9, closed.)

## Time

`date.timezone` is **UTC**. Store and compare in UTC; convert to
`America/Chicago` only for display, always through a real timezone and never a
fixed offset.

## File permissions

Directories **0755**, files **0644**. `public_html` itself is cPanel's `0750`
and is left alone.

A directory at **0700 produces a 404, not a 403**, on files inside it — the web
server cannot traverse in and LiteSpeed declines to reveal whether the target
exists. If a file you can see in File Manager 404s, check the directory's mode
first.
