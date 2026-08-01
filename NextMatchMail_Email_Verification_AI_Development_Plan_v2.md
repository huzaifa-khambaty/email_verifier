# NextMatchMail Email Verification Platform
## AI Development Plan (Summary Specification)

## 1. Project Overview

NextMatchMail is a **single-tenant** enterprise email verification platform designed to process approximately **6.5 million email addresses** safely and efficiently.

The system allows an administrator to upload CSV files containing email addresses, prioritize verification by domain (Gmail, Yahoo, Outlook, etc.), verify email addresses through SMTP without sending email, and export the processed results.

The primary objective is accuracy, reliability, resumability, and controlled SMTP traffic to reduce the risk of rate limiting or blacklisting.

This document defines the implementation requirements for an AI coding agent. If any requirement is not explicitly stated, the agent **must not assume behavior**. Instead, it must mark the item as **TODO** for clarification.

---

# 2. System Architecture

Frontend:
- URL: https://nextmatchmail.com
- Vue 3
- Vue Router
- Axios
- Vite
- Tailwind CSS
- Chart.js
- Composition API only
- No Inertia
- No Livewire
- No Pinia

Backend:
- URL Prefix: /api
- Laravel 12 API
- PHP 8.3
- MariaDB
- Laravel Sanctum
- Queue Workers
- Supervisor
- Nginx

Architecture Flow

Vue SPA
→ Axios
→ Laravel REST API
→ Service Layer
→ Queue
→ MariaDB

---

# 3. Functional Modules

Authentication, Dashboard, CSV Upload, Batch Management, Scheduler, Domain Priority, SMTP Verification Engine, Reports, Export, Settings, Audit Logs.

Each module shall expose REST APIs. Backend returns JSON only.

---

# 4. CSV Processing

Upload CSV, validate structure, create import batch, store original file, import in background, track progress, prevent duplicates within a batch, support resume after interruption.

---

# 5. Scheduler

Scheduler is responsible for selecting pending emails according to configurable domain priority.

Default rule:
- One active worker per domain.
- Domain delay configurable.
- Worker count configurable.
- Scheduler must prevent duplicate processing.

---

# 6. SMTP Verification

Verification must never send a real email.

Typical flow:
DNS MX Lookup
→ SMTP Connect
→ EHLO
→ MAIL FROM
→ RCPT TO
→ Interpret response
→ QUIT

Store SMTP response code, message and timestamp.

---

# 7. Database

Initial tables:
users
settings
domains
import_batches
emails
verification_jobs
smtp_logs
exports
audit_logs
worker_status

Indexes must exist on:
email
domain_id
status
batch_id
processed_at

---

# 8. Dashboard

Display:
Pending
Processing
Verified
Invalid
Unknown
Catch-all
Queue speed
Workers
Today's statistics

Charts:
Daily processed
Status distribution
Top domains

---

# 9. API Standards

REST only.
JSON only.
Validation using FormRequest.
Thin Controllers.
Business Logic in Services.
Consistent response format.

Example:

GET /api/dashboard

Response

{
 "pending":1200,
 "verified":900,
 "invalid":50
}

---

# 10. Security

Laravel Sanctum
Rate limiting
Audit logging
Environment variables
No credentials in source code
Server-side validation

---

# 11. Deployment

GitHub Actions deploys automatically.

Nginx
- serves Vue frontend
- proxies /api to Laravel

Supervisor manages:
scheduler
verification workers
queue workers

No manual production edits after CI/CD.

---

# 12. Coding Standards

PSR-12
Meaningful names
SOLID principles
Reusable services
Repository only where beneficial
No duplicated business logic
Unit tests for core services

---

# 13. Milestones

1. Bootstrap
2. Authentication
3. Database
4. CSV Import
5. Scheduler
6. SMTP Engine
7. Dashboard
8. Reports
9. Deployment
10. Testing

---

# 14. Acceptance Criteria

- Supports ~6.5 million records.
- Recoverable after interruption.
- Configurable domain priorities.
- Configurable worker limits.
- Export by date range/status.
- Comprehensive logs.
- Stable unattended operation.

End of summary specification.
