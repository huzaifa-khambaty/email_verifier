# Finalized Decisions

Resolves items from [`TODO.md`](TODO.md). These are locked in for
implementation; revisit here (not by re-reading old chat) if anything
needs to change.

## Auth & users
**Single-tenant, one admin.** No self-registration, no roles/permissions.
`users` table exists but is seeded with a single admin account (via
`php artisan db:seed` or a setup command) rather than exposing a public
registration endpoint. Sanctum used in SPA mode (cookie-based session
auth via `/sanctum/csrf-cookie` + login), not personal access tokens,
since there's exactly one first-party frontend.

## CSV import
**Columns:** `First Name`, `Last Name`, `Email` (header row required,
matched case-insensitively by name rather than fixed column position, so
reordered/renamed-but-recognizable headers still work).

- `Email` is required per row; rows with missing/malformed email are
  rejected during import validation and counted as import errors, not
  silently dropped.
- `First Name` / `Last Name` are optional (nullable columns on `emails`)
  — verification only needs the address, names are supplementary.

## Dedup scope
**`email` is globally unique across the `emails` table** ("master data"),
not just unique within a batch. Consequence for CSV import:

- On import, each row is **upserted by email**: if the address doesn't
  exist yet, insert it and associate with the current `import_batches`
  row. If it already exists, **do not** create a duplicate row or move it
  to the new batch — its existing verification status/history is
  preserved. Instead, increment a `duplicate_count` on the *new* batch so
  the admin can see how many rows in that upload were already-known
  addresses.
- This means `emails.batch_id` reflects the *first* batch an address was
  ever seen in, not the most recent.
- Practical effect: re-uploading the same list (or an overlapping one)
  never re-verifies already-verified addresses, which is both cheaper and
  reduces unnecessary SMTP traffic to those domains (see next section).

## Domain priority & scheduler — anti-blacklist defaults
No specific algorithm was mandated, so this implements a conservative
default designed to minimize the chance of the VPS's IP getting
rate-limited or blacklisted. All numbers below are configurable at
runtime (via the `settings`/`domains` tables), not hardcoded — treat them
as sane starting defaults, not fixed requirements.

- **One active connection per domain at a time** (per v2 §5, already
  mandated) — enforced via the `domains.max_workers` column, default `1`.
- **Per-domain delay between requests**, `domains.delay_seconds`, default
  `3s`, with ±20% random jitter applied so traffic doesn't look
  machine-timed.
- **Stricter defaults for major providers** — Gmail, Yahoo, Outlook/
  Hotmail/Live, AOL, iCloud are the most aggressive about throttling
  verification traffic. Seed the `domains` table with `delay_seconds: 8`,
  `max_workers: 1` for these; everything else defaults to `3s`.
- **Global concurrency cap** across *all* domains combined,
  `settings.global_worker_limit`, default `20` — prevents the sum of
  many "1 per domain" workers from adding up to something that looks like
  a burst/scan from the VPS's single IP.
- **Circuit breaker per domain**: if a domain accumulates 5 consecutive
  `TEMP_FAILURE`/timeout results within a rolling window, flip it to a
  `cooling_down` state and stop scheduling new jobs for it for 30 minutes,
  then resume at the normal rate. Prevents hammering a domain that's
  actively greylisting or blocking the VPS's IP.
- **Retry backoff for `TEMP_FAILURE`**: exponential — 5min → 15min →
  45min → 2h, capped, up to `max_attempts` (default `5`) before the job
  is left in a terminal `TEMP_FAILURE` state (mirrors the v1 approach,
  now per-job in `verification_jobs` instead of a single `attempts`
  column).
- **Catch-all detection**: reuse the v1 approach — after a `RCPT TO`
  returns 250/251, probe a random, near-certainly-nonexistent mailbox on
  the same domain within the same SMTP session; if that also returns
  250/251, mark `CATCH_ALL` instead of `VALID`.
- **Priority ordering**: `domains.priority` determines scheduling order
  (higher-priority domains' pending emails are pulled first), but it's
  not a hard gate — workers still pull from any domain that isn't at its
  `max_workers` cap or `cooling_down`, so low-priority domains aren't
  starved indefinitely while a high-priority domain is slow/rate-limited.
  This is a weighted queue, not strict drain-highest-first blocking.

## Queue driver — no Redis, no broker
**Implemented** — `backend/app/Services/Verification/VerificationScheduler.php`
(claim/release) and `backend/app/Console/Commands/Verify/WorkCommand.php`
(`php artisan verify:work`), backed by `backend/app/Services/Verification/SmtpEmailVerifier.php`
(the ported v1 dialogue logic). End-to-end tested against a real Gmail
mailbox (VALID path, full smtp_logs transcript, domain
active_workers/last_dispatched_at bookkeeping) and against simulated
repeated TEMP_FAILURE results (confirmed the 5→15→45→120min backoff and
the 5-failure circuit breaker both fire correctly). Design notes below
are now "as-built," not just planned.

**Revised: no Redis.** The VPS doesn't run one, and running a broker just
to coordinate a fixed pool of verification workers is unnecessary weight.
Instead, MariaDB itself is the coordination point — a custom polling
mechanism claims a bounded batch of unprocessed rows, verifies them, and
writes the result back. No `jobs`/`failed_jobs` tables, no Laravel Queue
facade for this path.

**Claim query** — one atomic transaction per batch, callable concurrently
by multiple worker processes without double-claiming, using
`FOR UPDATE SKIP LOCKED` (supported by MariaDB 10.6+, which Ubuntu
24.04's `mariadb-server` package ships by default):

```sql
START TRANSACTION;

SELECT vj.id AS job_id, e.id AS email_id, e.email, d.id AS domain_id
FROM verification_jobs vj
JOIN emails  e ON e.id = vj.email_id
JOIN domains d ON d.id = e.domain_id
WHERE vj.status = 'PENDING'
  AND d.active_workers < d.max_workers
  AND (d.cooling_down_until IS NULL OR d.cooling_down_until < NOW())
  AND (d.last_dispatched_at IS NULL
       OR d.last_dispatched_at <= NOW() - INTERVAL d.delay_seconds SECOND)
ORDER BY d.priority DESC, vj.id ASC
LIMIT :batch_size          -- e.g. 10 per worker process, per claim
FOR UPDATE SKIP LOCKED;

-- for each row claimed:
UPDATE verification_jobs SET status = 'PROCESSING' WHERE id = :job_id;
UPDATE domains SET active_workers = active_workers + 1,
                    last_dispatched_at = NOW()
WHERE id = :domain_id;

COMMIT;
```

The `WHERE` clause is what replaces a broker: `active_workers <
max_workers` enforces the per-domain concurrency cap, the
`last_dispatched_at`/`delay_seconds` check enforces the per-domain
throttle, `cooling_down_until` enforces the circuit breaker, and
`ORDER BY priority` enforces scheduling order — all four scheduler rules
from the section above, evaluated in one query, race-free across
processes because of `SKIP LOCKED`.

**After verifying each claimed job** (outside the transaction, since SMTP
round-trips take real seconds and shouldn't hold row locks):

```sql
UPDATE verification_jobs
SET status = :result, smtp_code = :code, smtp_response = :resp, verified_at = NOW()
WHERE id = :job_id;

UPDATE domains SET active_workers = active_workers - 1 WHERE id = :domain_id;
-- plus consecutive_failures++/reset and cooling_down_until per the
-- circuit-breaker rule above, when the result is TEMP_FAILURE.
```

**Process model**: a `php artisan verify:work` command runs this
claim → verify → update loop indefinitely (short sleep when a claim
comes back empty), and Supervisor runs a fixed number of copies of it
(`numprocs`, e.g. 10–20 — this number *is* the global concurrency cap,
replacing the earlier `settings.global_worker_limit` idea). This is the
same shape as v1's `Worker.php`/`cron.php`, generalized to run as N
long-lived Supervisor-managed processes instead of one cron-triggered run
every 5 minutes, since 6.5M records need continuous throughput rather
than a periodic sweep.

v2 §11's "queue workers" (as distinct from "verification workers") can
still mean Laravel's ordinary queue for lightweight async tasks (e.g. a
CSV-import-finished notification) if `backend/` ends up needing any — but
the bulk verification pipeline does not depend on it, so it can use the
`database` queue driver trivially if/when that's needed, with no Redis
either way.

## STARTTLS / IPv6
**Deferred**, same as v1 — plaintext SMTP over IPv4 only for now. Revisit
only if a specific verification failure is traced back to a domain that
requires it.

## Frontend state management
v2 explicitly excludes Pinia. Using **Composition API composables**
(plain reactive/ref-based modules under `src/composables/`) for shared
state — dashboard stats, auth/session state — rather than
`provide`/`inject`, since composables are simpler to unit test and don't
require a component tree.

## SMTP identity
- `EHLO`/`HELO` domain: `verify.nextmatchmail.com`
- `MAIL FROM`: `verify@nextmatchmail.com`

Both `nextmatchmail.com` and `verify.nextmatchmail.com` are confirmed
propagating. **Still outstanding before this can be trusted in
production:** confirm the VPS's reverse DNS (PTR) record resolves to
`verify.nextmatchmail.com` (ask the VPS provider — this is separate from
the forward A record and easy to forget), and confirm outbound port 25 is
unblocked from the VPS. Both are called out in
`legacy-v1/DEPLOYMENT.md` and still apply to v2.

## Porting notes from v1 `EmailVerifier.php`
Checked `legacy-v1/` for anything important not yet reflected above. The
overall design (MX lookup → SMTP dialogue → status) already carries
forward, but these specific behaviors are easy to silently drop when
rewriting as a Laravel service and must be preserved:

- **Try every MX host, not just the first.** `resolveMxHosts()` returns
  all MX records sorted by priority (`array_multisort($weights, $hosts)`,
  since `getmxrr()` doesn't sort them itself); `verify()` then loops
  through them, moving to the next host on connect failure *or* dialogue
  failure, and only returns `TEMP_FAILURE` if all of them fail. A rewrite
  that only tries the lowest-preference MX would be measurably less
  reliable.
- **A/AAAA fallback when there's no MX record**, per RFC 5321 §5.1
  (`checkdnsrr($domain, 'A')`/`'AAAA'`) — before concluding `NO_MX`.
- **EHLO, then fall back to HELO** if EHLO is rejected — some older
  servers only understand HELO.
- **Multiline SMTP response parsing**: a response can span multiple
  lines (`250-...` continuation lines, final line `250 ...` without the
  dash). The status code is read from the *last* line's first 3 digits.
  Also must detect a stream read timeout via
  `stream_get_meta_data($fp)['timed_out']`, not just a `false`/EOF
  return from `fgets()` — these are different failure modes.
- **Exact catch-all probe mechanics**: within the *same* SMTP session
  used for the real check, send `RSET`, then `MAIL FROM` again, then
  `RCPT TO` for a random local-part (`'verify-probe-' .
  bin2hex(random_bytes(8))`) at the same domain. Only probe when the real
  `RCPT TO` already came back 250/251 — don't waste a probe on addresses
  that are already `INVALID`.
- **`smtp_response` truncation to 255 chars** before persisting
  (`substr($smtpResponse, 0, 255)` in `Database.php`) — the v1 column is
  `VARCHAR(255)`. Whatever v2 uses for `verification_jobs.smtp_response`
  / `smtp_logs.message` needs the same guard (or a `TEXT` column, if
  full transcripts should be kept — v2 §6 says "store SMTP response code,
  message and timestamp" but doesn't cap length; worth deciding at
  migration time rather than truncating by accident).
- **Reference defaults** from v1 `config.php`, useful starting points for
  v2's `settings` table: SMTP connect/read timeout `10s` each, batch size
  `100` rows per claim, `max_attempts` `5`.
- **Per-row error isolation**: `Worker.php` wraps each row's verification
  in its own try/catch so one bad row (e.g. a DNS resolution crash)
  doesn't abort the whole batch — the `verify:work` loop must do the
  same, or one unlucky email kills a worker process.
- The v1 lock-file (`flock`) approach to prevent overlapping cron runs is
  **intentionally not carried forward** — it solved a problem specific to
  periodic cron invocations, which v2 no longer has (see "Queue driver"
  above, long-lived Supervisor-managed processes instead).

## Responsible use (carried forward from v1 README)
Still applies at 6.5M-record scale, arguably more so:
- Only verify addresses there's a legitimate reason to check (own lists,
  not third-party harvesting) — high-speed verification of addresses you
  don't own risks the VPS's IP getting blocklisted and can violate the
  receiving provider's terms of service.
- `VALID`/`CATCH_ALL` are strong signals, not guarantees — some servers
  accept every `RCPT TO` or defer real validation to `DATA` time.

---

Still open (not blocking bootstrap/schema work, see `TODO.md`): export
file format, audit log scope/retention, GitHub Actions deploy target.
