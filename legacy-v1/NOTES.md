# Legacy v1 — Standalone CLI Prototype

Superseded by [`NextMatchMail_Email_Verification_AI_Development_Plan_v2.md`](../NextMatchMail_Email_Verification_AI_Development_Plan_v2.md).
The v1 plan (`PHP_Email_Verification_Worker_Developer_Plan.md`) described a
single-file-per-class CLI worker with no UI, no queueing, and no batch
management — fine for a proof of concept, not for the ~6.5M-record
enterprise platform v2 specifies.

This code is kept for reference, not run as-is. Specifically:

- **`EmailVerifier.php`** — the MX lookup, SMTP dialogue (`EHLO`/`MAIL
  FROM`/`RCPT TO`/`QUIT`, never `DATA`), and catch-all probe logic is
  directly portable into the Laravel **SMTP Verification Engine** service
  described in v2 §6, once `backend/` is bootstrapped.
- **`Database.php`**'s batch-claiming query (`SELECT ... FOR UPDATE` to
  atomically flip `PENDING` → `PROCESSING`) is the same pattern the v2
  **Scheduler** (§5) needs per-domain, and is a useful reference for
  avoiding double-processing under concurrent workers.
- **`Worker.php`**'s lock-file + throttle-between-checks approach maps to
  v2's "one active worker per domain" / "configurable domain delay" rules,
  though v2 implements this via Laravel queue workers + Supervisor instead
  of cron + `flock`.
- `config.php`, `Logger.php`, `cron.php`, `setup.sql`, `README.md`,
  `DEPLOYMENT.md` are all v1-specific (flat-file config, custom logger,
  single-table schema) and do not carry forward — v2 uses Laravel's
  config/env, logging, migrations, and deployment conventions instead.
