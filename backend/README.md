# backend/ — Laravel 12 API

REST API under `/api`, JSON only. Sanctum SPA (cookie) auth, MariaDB in
production (SQLite works fine for local dev). See
[`../DECISIONS.md`](../DECISIONS.md) for the architecture decisions this
implements and [`BOOTSTRAP_NOTES.md`](BOOTSTRAP_NOTES.md) for a Windows
dev-machine gotcha with `artisan serve`.

## What's here

- **Auth** — `AuthController`, session-based, single seeded admin (no
  registration). `php artisan db:seed` creates/updates the admin from
  `ADMIN_EMAIL`/`ADMIN_PASSWORD` in `.env`.
- **CSV import** — `POST /api/import-batches` accepts a CSV, queues
  `ProcessImportBatch`, which `CsvImportService` processes in chunks:
  upserts by email, creates domains with the right throttle defaults,
  and creates one `PENDING` `verification_jobs` row per new address.
  Resumable via a byte offset checkpointed after each chunk.
- **Verification engine** — `app/Services/Verification/`:
  `SmtpEmailVerifier` (the SMTP dialogue, ported from `legacy-v1/`) and
  `VerificationScheduler` (the claim/release mechanism — no Redis, no
  broker, just `SELECT ... FOR UPDATE SKIP LOCKED` against MariaDB).
  `php artisan verify:work` runs the claim → verify → record loop; run N
  copies under Supervisor (see `../deploy/supervisor/`).
- **Dashboard** — `GET /api/dashboard`, stat counts from the latest
  `verification_jobs` row per email.

## Local setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan verify:work   # in a separate terminal, to actually process PENDING jobs
```

Serving it: `php artisan serve` is broken on this project's Windows dev
box specifically — see `BOOTSTRAP_NOTES.md` for the one-line workaround.
Elsewhere it works normally.
