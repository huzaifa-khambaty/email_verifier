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
  backend/    Laravel 12 API (PHP 8.3, MariaDB, Sanctum, queues)  — not yet bootstrapped
  frontend/   Vue 3 SPA (Vite, Tailwind, Chart.js)                — not yet bootstrapped
  legacy-v1/  Archived CLI prototype, kept for reusable SMTP logic
  NextMatchMail_Email_Verification_AI_Development_Plan_v2.md   Full spec
  DECISIONS.md   Finalized answers to the spec's open questions
  TODO.md        Remaining ambiguities (non-blocking, later milestones)
```

## Status

Reorganized for v2, nothing bootstrapped yet. Auth model, CSV format,
dedup scope, the anti-blacklist scheduler design, and SMTP identity are
all decided — see [`DECISIONS.md`](DECISIONS.md). Next steps, in order:

1. Bootstrap `backend/` (Laravel 12 + Sanctum) — see
   [`backend/README.md`](backend/README.md).
2. Bootstrap `frontend/` (Vue 3 + Vite + Tailwind) — see
   [`frontend/README.md`](frontend/README.md).
3. Follow the milestone order in v2 §13: Bootstrap → Auth → Database →
   CSV Import → Scheduler → SMTP Engine → Dashboard → Reports →
   Deployment → Testing.

Remaining open items in [`TODO.md`](TODO.md) (export format, audit log
scope, CI/CD deploy target) only matter for later milestones (Reports,
Deployment) and don't block starting.

## Domain / infra already provisioned

- Domain: `nextmatchmail.com`, with `verify.nextmatchmail.com` confirmed
  propagating (used as SMTP HELO/MAIL FROM identity — see `DECISIONS.md`)
- VPS: Ubuntu 24.04 (SSH access available)

The v1 deployment guide (`legacy-v1/DEPLOYMENT.md`) covers general VPS
hardening (non-root user, ufw, PTR/reverse-DNS for outbound SMTP
reputation) that still applies; a v2-specific deployment guide (Nginx +
PHP-FPM + Supervisor + GitHub Actions per §11) will replace it once
`backend/`/`frontend/` are bootstrapped.

`legacy-v1/` isn't just kept for show — `DECISIONS.md`'s "Porting notes
from v1 `EmailVerifier.php`" section lists specific SMTP-dialogue
behaviors (multi-MX fallback, EHLO/HELO fallback, multiline response
parsing, exact catch-all probe mechanics) that must survive the rewrite
into a Laravel service, plus a responsible-use reminder worth keeping in
mind at 6.5M-record scale.
