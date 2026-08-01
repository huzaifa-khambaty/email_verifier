# Open Questions — v2 Spec Clarifications

Per [`NextMatchMail_Email_Verification_AI_Development_Plan_v2.md`](NextMatchMail_Email_Verification_AI_Development_Plan_v2.md)
§1: *"If any requirement is not explicitly stated, the agent must not assume
behavior. Instead, it must mark the item as TODO for clarification."*

Most items originally listed here are resolved — see
[`DECISIONS.md`](DECISIONS.md). What's left below is later-milestone
(Reports/Deployment) territory and doesn't block bootstrap, auth, or the
DB schema.

## Exports (§9 acceptance criteria)
- [ ] Export file format (CSV assumed, but XLSX not ruled out) and exact
      column set.

## Audit logs
- [ ] Which actions are audited (login, CSV upload, export, settings
      change, all of the above?) and retention period.

## Deployment (§11)
- [ ] GitHub Actions target — which VPS/environment, zero-downtime
      strategy, whether migrations run automatically on deploy, and
      whether there's a staging environment before production.
