# NextMatchMail Email Verification Platform

Enterprise email verification platform for ~6.5M records: CSV import,
domain-prioritized SMTP verification (never sends real email), a Vue
dashboard, and exports. Full spec:
[`NextMatchMail_Email_Verification_AI_Development_Plan_v2.md`](NextMatchMail_Email_Verification_AI_Development_Plan_v2.md).

This supersedes the earlier standalone CLI prototype, archived in
[`legacy-v1/`](legacy-v1/README.md).

## Layout

```
email_verifier/
  backend/    Laravel 12 API (PHP 8.3, MariaDB, Sanctum, DB-polling verification worker)
  frontend/   Vue 3 SPA (Vite, Tailwind, mobile-first)
  deploy/     Nginx/Supervisor configs + the remote deploy script GitHub Actions runs
  .github/workflows/   CI (tests) + CD (build, package, ship to the VPS)
  legacy-v1/  Archived CLI prototype, kept for reusable SMTP logic
  NextMatchMail_Email_Verification_AI_Development_Plan_v2.md   Full spec
  DECISIONS.md    Finalized answers to the spec's open questions
  TODO.md         Remaining ambiguities (non-blocking)
  DEPLOYMENT.md   VPS setup + how the CI/CD pipeline works
```

## Status

Working end-to-end: auth, dashboard, CSV import (chunked, resumable,
dedup-by-email), and the DB-polling SMTP verification worker
(`verify:work`) — no Redis, tested against a real mailbox and against
simulated failures (backoff + circuit breaker both confirmed). Frontend
covers login, dashboard, and CSV upload, mobile-first with a bottom tab
bar on small screens and a sidebar on larger ones.

Design/architecture decisions are in [`DECISIONS.md`](DECISIONS.md).
Still open (non-blocking, see [`TODO.md`](TODO.md)): export file format,
audit log scope/retention.

Remaining per the v2 §13 milestone order: Scheduler admin UI (domain
priority/delay editing), Reports, Settings, and the dashboard's charts
(daily processed / status distribution / top domains).

## Running it locally

```bash
# backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php -S 127.0.0.1:8000 -t public public/index.php   # see backend/BOOTSTRAP_NOTES.md if on Windows

# in another terminal: the verification worker
php artisan verify:work

# frontend
cd frontend
npm install
npm run dev
```

## Domain / infra

- Domain: `nextmatchmail.com`, with `verify.nextmatchmail.com` confirmed
  propagating (used as SMTP HELO/MAIL FROM identity — see `DECISIONS.md`)
- VPS: Ubuntu 24.04
- Deployment: GitHub Actions → VPS, flat overwrite-in-place — see
  [`DEPLOYMENT.md`](DEPLOYMENT.md) for one-time server setup and how
  deploys work day to day. `legacy-v1/DEPLOYMENT.md`'s general VPS
  hardening (non-root user, ufw, PTR/reverse-DNS for outbound SMTP
  reputation) still applies and isn't repeated there.

`legacy-v1/` isn't just kept for show — `DECISIONS.md`'s "Porting notes
from v1 `EmailVerifier.php`" section lists specific SMTP-dialogue
behaviors (multi-MX fallback, EHLO/HELO fallback, multiline response
parsing, exact catch-all probe mechanics) that survived the rewrite into
`backend/app/Services/Verification/SmtpEmailVerifier.php`, plus a
responsible-use reminder worth keeping in mind at 6.5M-record scale.
