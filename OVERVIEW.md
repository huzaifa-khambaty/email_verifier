# NextMatchMail — Email Verification Platform

A private web application for checking whether email addresses are
deliverable, **without ever sending an email to them**.

---

## What it does

Upload a list of email addresses. The system checks each one against the
mail server that would actually receive it, and tells you which addresses
are real, which will bounce, and which cannot be determined.

The purpose is list hygiene: sending to dead addresses damages your
sender reputation and gets future mail filtered as spam. Removing them
first protects your ability to reach the people who *are* real.

## How the checking works

For each address the system asks the recipient's mail server whether the
mailbox exists — the same opening conversation a real mail server has —
and then **hangs up before any message is transmitted**. No email is ever
delivered, and the recipient sees nothing.

1. Check the address is properly formed
2. Look up which server handles mail for that domain
3. Connect and ask whether the mailbox would be accepted
4. Record the server's answer and disconnect

## Using it

| Step | What happens |
|---|---|
| **1. Upload** | Drop in a CSV (First Name, Last Name, Email). Malformed rows and duplicates — both within the file and against addresses already stored — are separated out into a downloadable error report rather than silently dropped. |
| **2. Verification runs** | Addresses are checked in the background. You can close the browser; progress continues. |
| **3. Review** | The dashboard shows live progress and explains anything that's waiting. A per-domain view shows which domains are producing useful results. |
| **4. Export** | Download results as CSV, filtered by status and date range — for example "everything confirmed valid this week". |

## Understanding the results

| Result | Meaning |
|---|---|
| **Valid** | The server confirmed this mailbox exists. Safe to send to. |
| **Invalid** | The server rejected it, or the domain has no mail server. Will bounce — remove these. |
| **Catch-all** | The domain accepts *every* address, so no verdict is possible. See below. |
| **Unknown** | The server never gave a usable answer (timeout, or it blocked us). |
| **Ignored** | You chose not to spend time on this domain. Stored, not checked. |

### About catch-all — the one result worth understanding

Some providers, most notably **Yahoo**, accept any address you ask about
and only reject undeliverable mail later, privately. The system proves
this on every check by also asking about a randomly generated address
that cannot possibly exist — if the server accepts that too, the domain
is catch-all and its answers carry no information.

**Catch-all means "unverifiable", not "good".** This is a limitation of
how email works, not of this tool; no SMTP-based verifier can validate a
Yahoo address. It is reported separately rather than being counted as
valid, so your confirmed-deliverable figure is never inflated.

*In a real 994-address sample: 273 confirmed valid, 560 catch-all
(mostly Yahoo), 73 invalid.*

## Protecting your sender reputation

Checking addresses too aggressively gets a server's IP address
blocklisted, which would invalidate every result afterwards. The system
is deliberately paced:

- One connection per domain at a time, with a delay between checks
- Automatic back-off when a provider starts refusing traffic
- Domains that consistently refuse connections are paused, then retried
  automatically later

The dashboard explains any pause and when it lifts, so a deliberately
throttled queue is never mistaken for a broken one.

## Working efficiently at scale

The system learns as it goes, so large lists don't repeat pointless work:

- **Catch-all domains are identified once.** Rather than opening a
  connection for every Yahoo address to receive the same non-answer, the
  domain is recognised and its addresses resolved instantly.
- **Unreachable domains are paused** instead of being retried repeatedly.
- **You can exclude domains yourself.** Reviewing the per-domain view, you
  may decide a domain isn't worth checking. Those addresses are still
  **stored in full** — nothing is discarded — and can be re-queued at any
  time by removing the exclusion.

## Technical summary

Vue 3 single-page application, Laravel 12 REST API, MariaDB, running on a
dedicated Ubuntu server behind Nginx over HTTPS. Verification runs in
background worker processes managed by Supervisor. Access is restricted
to a single administrator account; there is no public sign-up. Code is
deployed automatically from version control, with no manual changes made
on the production server.

---

*Verification results are a strong signal, not a guarantee: a mailbox can
be deleted the day after it is checked, and some providers deliberately
withhold information. The value is in reliably removing addresses that
are definitely dead.*
