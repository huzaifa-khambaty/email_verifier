# Draft reply to Contabo — SMTP limit increase

**Before sending:** questions 5, 6 and 7 are about your business and I
have deliberately left them blank rather than guess. See the notes at the
bottom — the list-provenance answer in particular is the one Contabo will
weigh most heavily.

---

Dear Contabo Support,

Thank you for the detailed requirements. Answering each point in order.

**1. Describe your individual use case**

The server runs an email address verification service. It does **not send
email and does not relay mail** — no message is ever transmitted, and no
mail server on my side accepts or forwards mail.

The application opens an SMTP session to a recipient's mail server, issues
`EHLO`, `MAIL FROM` and `RCPT TO` to ask whether a given mailbox exists,
reads the response code, then issues `QUIT`. The `DATA` command — the
point at which a message body would be transmitted — is never sent. The
session is closed before any content could be delivered, and the mailbox
owner receives nothing.

The purpose is list hygiene: identifying and removing addresses that no
longer exist, so that mail is never sent to them. This *reduces* bounce
rates and spam complaints across the wider network rather than adding to
them.

**2. Marketing email or transactional email**

**Neither.** No email of any kind is sent from this server. The outbound
port 25 traffic consists entirely of verification handshakes that
terminate before message transmission.

I appreciate this questionnaire is designed around sending; I have
answered the remaining questions in the way that best fits a
non-sending workload, and flagged where a question does not apply.

**3. Website URLs running on your server**

`https://nextmatchmail.com` — a private, password-protected administration
interface for uploading lists and reviewing verification results. There is
no public sign-up and no publicly accessible content.

**4. Website URLs advertised in email**

None. No email is sent, so no URL is advertised in any email originating
from this server.

**5. How the mailing list was built or acquired**

> **[TO COMPLETE — see notes below. Answer this truthfully and
> specifically.]**

**6. How bounces and complaints are handled**

> **[TO COMPLETE — see notes below.]**
>
> Factual part you can state: this server generates no bounces and no
> complaints, because it sends no mail. Verification results are recorded
> against each address, and addresses returning a permanent rejection are
> marked invalid so they are excluded from any future use.

**7. How recipients can opt out**

> **[TO COMPLETE — see notes below.]**
>
> Factual part you can state: recipients receive no email from this
> server, so there is nothing to opt out of at this stage. Verification
> leaves no trace in the recipient's mailbox.

**8. Reason for adjustment**

The application verifies a list of approximately 6.5 million addresses.
Each address requires one short SMTP handshake, and the current limit
throttles this to the point where the work cannot complete in a
reasonable timeframe.

The application already implements deliberate rate limiting to protect
both your network's reputation and my own:

- One concurrent connection per recipient domain, with a configurable
  delay between checks to that domain
- Automatic exponential back-off when a provider returns temporary
  failures
- A circuit breaker that pauses a domain entirely after repeated failures
- Domains that refuse connections are suspended and retried days later
- Domains identified as "catch-all" are resolved once and their remaining
  addresses skipped entirely, which removes a large share of connections

The IP is currently listed on no major blocklist (verified against
Spamhaus, SpamCop, Barracuda and SORBS), forward and reverse DNS match
(`169.58.99.110` ↔ `verify.nextmatchmail.com`), and the sending domain has
a valid MX record.

**9. Desired value of outgoing SMTP connections**

> **[CHOOSE ONE — see the table in the notes below.]**
> Requesting **_____ connections per hour** (approximately _____ per day).

**10. How the desired value was chosen**

It is derived from measured throughput on this server rather than
estimated. Over 48 hours of operation the application made 17,108 outbound
SMTP connections, averaging 355 per hour with an observed peak of 3,908 in
a single hour.

Not every address requires a connection: addresses on domains already
identified as catch-all, or excluded by configuration, are resolved
without contacting the remote server at all. In current data, 58,169 of
70,492 processed addresses required no outbound connection.

Allowing for retries of temporary failures, the full 6.5 million address
list is expected to require in the order of 4.5 million connections
in total. The requested rate reflects completing that work over a
reasonable period while retaining the per-domain throttling described
above.

I am happy to cap the application at whatever limit you consider
appropriate, and to provide further detail or logs on request.

Kind regards,
Huzaifa Khambaty

---

## Notes — please read before sending

### Choosing your number for question 9

Measured: **3,908 connections/hour** peak, **355/hour** average.
Estimated total remaining: **~4.5 million** connections.

| Request | Per day | Time to finish 6.5M | Comment |
|---|---|---|---|
| 5,000/hour | 120,000 | ~37 days | Close to current peak; easiest to justify |
| 10,000/hour | 240,000 | ~19 days | Reasonable middle ground |
| 20,000/hour | 480,000 | ~9 days | Larger ask; expect more scrutiny |

I would suggest **10,000/hour**. It is a modest step up from what you have
already demonstrated, easy to defend with the measured figures, and you can
request a further increase later once you have a clean track record. Asking
for a very large number immediately tends to invite refusal.

### Questions 5, 6 and 7 — why I left these blank

These are factual claims about your business that I cannot make on your
behalf, and Contabo can verify some of them independently. An inaccurate
answer here risks the account outright, which is a far worse outcome than
a smaller limit.

**Question 5 is the one that matters most.** Providers ask it because
purchased, scraped or harvested lists are the single strongest predictor
of abuse. Answer it specifically — where the data came from, and on what
basis those people's addresses are held.

Worth being aware of, if it is relevant to your situation:

- A purchased or scraped list is likely to be refused, and in the EU
  processing such data generally has no lawful basis under GDPR.
- Verifying addresses is itself lawful and routine. The scrutiny is about
  what happens afterwards.
- If you do eventually send to this list, a purchased list produces high
  bounce and complaint rates, which will get the IP blocked regardless of
  what limit Contabo grants.

If the list is your own customer or subscriber data, say so plainly and
describe how it was collected — that is a strong answer.

### One correction to your earlier reply

Your first reply said the application "implements rate limiting and
throttling", which is accurate. Everything in the draft above is likewise
verifiable from the running system, and I have kept every claim to things
I could confirm from real measurements rather than rounding in your
favour.
