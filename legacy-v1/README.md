# Email Verifier

A CLI PHP worker that verifies email addresses stored in a database by
talking directly to the recipient's SMTP server, without ever sending an
actual message (`DATA` is never issued).

## How it works

1. `cron.php` runs `Worker::run()`, which:
   - Takes a lock (`worker.lock`) so overlapping cron runs don't double-process.
   - Atomically claims up to `batch_size` `PENDING` rows, flipping them to `PROCESSING`.
   - For each row, runs `EmailVerifier::verify()`:
     1. Validates syntax (`filter_var(..., FILTER_VALIDATE_EMAIL)`).
     2. Resolves MX records for the domain (falls back to the domain's own
        A/AAAA record per RFC 5321 if no MX exists).
     3. Opens a raw SMTP connection to each MX host in priority order.
     4. Issues `EHLO`/`HELO`, `MAIL FROM`, `RCPT TO` and reads the response.
     5. If the mailbox appears deliverable, probes a random nonexistent
        mailbox on the same domain to detect catch-all servers.
     6. Always sends `QUIT`. Never sends `DATA`.
   - Persists the resulting status, SMTP code/response, and MX host used.
   - Sleeps briefly (`sleep_between_checks_us`) between checks to avoid
     tripping rate limits or greylisting on remote servers.

## Status values

| Outcome                                   | `verification_status` |
|--------------------------------------------|------------------------|
| Malformed address                          | `INVALID`              |
| No MX / A record for domain                | `NO_MX`                |
| SMTP 250 / 251                             | `VALID`                |
| SMTP 250/251 but domain accepts anything   | `CATCH_ALL`            |
| SMTP 550 / 551 / 553                       | `INVALID`              |
| SMTP 450 / 451 / 452, or connection error  | `TEMP_FAILURE`* / `PENDING`* |
| Anything else (e.g. `MAIL FROM` rejected)  | `UNKNOWN`               |

\* Transient failures are requeued as `PENDING` and retried on the next
cron run until `max_attempts` is reached, at which point they're left as a
terminal `TEMP_FAILURE`.

## Setup

1. Create the database and table:

   ```bash
   mysql -u root -p your_database < setup.sql
   ```

2. Configure via environment variables (recommended) or by editing
   `config.php` directly:

   | Variable | Default | Purpose |
   |---|---|---|
   | `DB_DSN` | `mysql:host=127.0.0.1;port=3306;dbname=email_verifier;charset=utf8mb4` | PDO DSN |
   | `DB_USER` | `root` | DB user |
   | `DB_PASS` | *(empty)* | DB password |
   | `BATCH_SIZE` | `100` | Rows claimed per run |
   | `SLEEP_US` | `300000` | Microseconds between checks |
   | `MAX_ATTEMPTS` | `5` | Retries before a transient failure becomes terminal |
   | `SMTP_HELO_DOMAIN` | `verifier.local` | Domain sent in `EHLO`/`HELO` |
   | `SMTP_MAIL_FROM` | `verify@verifier.local` | Sender used in `MAIL FROM` |
   | `SMTP_CONNECT_TIMEOUT` | `10` | Seconds |
   | `SMTP_READ_TIMEOUT` | `10` | Seconds |
   | `SMTP_PORT` | `25` | Outbound SMTP port must be reachable from the host |
   | `LOG_LEVEL` | `INFO` | `DEBUG` \| `INFO` \| `WARNING` \| `ERROR` |

3. Insert email addresses to verify:

   ```sql
   INSERT INTO email_addresses (email) VALUES ('someone@example.com');
   ```

4. Run once manually to confirm it works:

   ```bash
   php cron.php
   tail -f logs/worker.log
   ```

5. Schedule via cron:

   ```cron
   */5 * * * * php /path/to/email-verifier/cron.php
   ```

## Responsible use

- This tool performs SMTP-level *verification* only — it never sends real
  messages and always issues `QUIT` before `DATA` would occur.
- Outbound port 25 is frequently blocked by hosting providers/ISPs; you
  need a host that allows outbound SMTP.
- Only verify addresses you have a legitimate reason to check (e.g.
  cleaning your own mailing list before sending). Verifying large volumes
  of addresses you don't own, at high speed, can get your server's IP
  blocklisted and may violate the target mail provider's terms of service.
  `sleep_between_checks_us` and `batch_size` exist to keep request rates
  polite — don't set them so low/high that you look like a spam harvester.
- Some servers accept every `RCPT TO` (catch-all) or defer real validation
  to `DATA` time; treat `VALID`/`CATCH_ALL` as a strong signal, not a
  100%-certain guarantee.

## Requirements

- PHP 8.0+ with `pdo_mysql` and `openssl` extensions.
- MySQL/MariaDB with the `email_addresses` table using `InnoDB` (required
  for the `SELECT ... FOR UPDATE` row locking used to claim batches safely
  if you ever run more than one worker concurrently).
- Outbound network access on port 25.

## Not implemented (see plan's Future Enhancements)

STARTTLS, IPv6, disposable-email detection, per-domain throttling beyond
the global sleep, a dedicated retry queue, full SMTP transcript logging,
and a PHPUnit test suite are intentionally out of scope for this version.
