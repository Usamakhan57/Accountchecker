# Security model

This document says what AccountCheck protects, how, and — just as importantly —
what it deliberately does not do.

- [What this product will not do](#what-this-product-will-not-do)
- [Authentication and sessions](#authentication-and-sessions)
- [Authorization](#authorization)
- [CSRF](#csrf)
- [Input handling](#input-handling)
- [Output and information disclosure](#output-and-information-disclosure)
- [Secrets](#secrets)
- [Credits and money](#credits-and-money)
- [Uploads and generated files](#uploads-and-generated-files)
- [Rate limiting](#rate-limiting)
- [Logging and audit](#logging-and-audit)
- [Transport and headers](#transport-and-headers)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## What this product will not do

AccountCheck verifies records **only through official, authorized APIs and
publicly permitted data sources**. This is the constraint the whole design
rests on, and it is enforced in code rather than left to convention.

`AbstractChecker::check()` is the gate. In production mode, an adapter with no
configured provider returns:

```json
{ "status": "UNAVAILABLE", "reason": "No authorized verification source is configured for this checker." }
```

There is no fallback path, because the only fallbacks available would be the
ones this product does not do. Specifically, AccountCheck does **not**:

- test passwords, or attempt credential stuffing of any kind;
- make login attempts, real or simulated, against any platform;
- bypass a CAPTCHA, a rate limit, or an authentication check;
- steal, replay or manipulate a session cookie belonging to any platform;
- call a private or undocumented API;
- enumerate accounts against an endpoint that does not permit it;
- scrape an endpoint that requires authentication or forbids automated access;
- rotate proxies or addresses to evade a platform's restrictions.

A checker that cannot answer through an authorized source says so. The UI shows
`configured: false` before anyone spends a credit, and `UNAVAILABLE` is not
billable, so nobody pays to be told a check could not be made.

The worker also paces its requests to the rate limit configured for each
provider. Staying inside a published limit is part of using an API in an
authorized way, so it is enforced on our side rather than left to the provider
to refuse.

## Authentication and sessions

- Passwords are hashed with `password_hash()` (bcrypt) and verified with
  `password_verify()`. Nothing anywhere stores or logs a plaintext password. A
  hash made with an older algorithm is transparently upgraded on the next
  successful sign-in.
- Password policy: at least 10 characters, with a lowercase letter, an
  uppercase letter and a digit; at most 200.
- A session is **a row in `user_sessions`**, not a self-contained token. The
  cookie carries a reference. Signing out revokes it server-side; so does
  suspending the account, and so does "sign out everywhere else". Each takes
  effect on the next request rather than whenever a token would have expired.
- The session cookie is `HttpOnly` (a script cannot read it), `SameSite=Lax`,
  and `Secure` whenever `SESSION_COOKIE_SECURE=true` — which it must be in
  production. There is no token in `localStorage`, so an XSS bug cannot walk
  away with a session.
- Sessions expire on absolute lifetime and on idle timeout, both configurable.
- A sign-in attempt for an unknown address still spends comparable time
  hashing, so the response time cannot be used to tell registered addresses
  from unregistered ones.
- Login and password reset answer identically whether or not an address is
  registered. Registration is the deliberate exception: the person just typed
  the address, so a duplicate has to be actionable.

## Authorization

Every read is scoped by user id **in the query itself**, not fetched and then
checked. A job, result, export or ticket belonging to another account is
answered **404** — indistinguishable from one that never existed, because
telling the difference is itself information.

Administrative actions go through one service, so the rules cannot be forgotten
by a new controller:

- Nobody can suspend, delete or demote their own account. One careless click
  must not lock the last administrator out.
- Only a SUPER_ADMIN grants SUPER_ADMIN, acts on a SUPER_ADMIN, or changes a
  system setting. Privilege cannot be escalated sideways by an ADMIN promoting
  somebody and then asking them for a favour.
- Wallet adjustments are capped, require a reason, and run through the ledger.
- Credits reserved against a running job cannot be clawed back; that job still
  has to settle.

## CSRF

The API authenticates with a cookie, and a browser attaches a cookie to a
request whatever page caused it. Unsafe methods therefore carry a double-submit
token: a random 32-byte value in a cookie that JavaScript **can** read, echoed
in an `X-CSRF-Token` header and compared with `hash_equals`.

Another origin can cause the cookie to be sent but cannot read it, so it cannot
produce the header. A mismatch is 419.

`SameSite=Lax` on the session cookie already blocks cross-site POST in current
browsers. This is the second lock: it holds for a browser that ignores
SameSite, and for the same-site-but-different-subdomain case that SameSite does
not cover at all.

`CSRF_PROTECTION=false` exists for a non-browser test harness. In production it
would let any site act as a signed-in user.

## Input handling

- **Every** query is a prepared statement with bound parameters. PDO runs with
  `ATTR_EMULATE_PREPARES => false`, so parameters are bound by the server and
  never interpolated into a string.
- A sort column cannot be bound as a parameter, so it is never taken from the
  request: each sortable endpoint maps a request key against an allow-list and
  falls back to its default for anything else.
- `LIKE` searches escape `%`, `_` and `!` with `ESCAPE '!'`, so a search for
  `a_b` means `a_b`.
- Validation runs server-side on every endpoint. The frontend's own checks are
  a convenience only.
- Rules that legitimately produce `false` are distinguished from rules that
  rejected a value by an object sentinel rather than by `false`, so a boolean
  field set to `false` reaches the database instead of being silently dropped.

## Output and information disclosure

- A response never carries a stack trace, an SQL fragment, a driver message, a
  filesystem path, a database name or a credential. Failures are logged in full
  server-side and answered with a written-for-people message and a stable error
  code.
- `display_errors` is off whenever `APP_ENV=production`.
- `X-Powered-By` is removed, so the PHP version is not advertised.
- Responses are `application/json` with `X-Content-Type-Options: nosniff`, so
  markup stored in a value is inert: a browser never parses it as markup and
  React escapes it on render. Text is stored exactly as it arrived; escaping it
  in the database would only corrupt legitimate content.
- CSV cells beginning `=`, `+`, `-` or `@` are prefixed with an apostrophe, so
  a spreadsheet renders them as text rather than executing them as a formula.
- A staff reply on a support ticket is attributed to "AccountCheck support".
  The answering administrator's name and address are never sent to the
  customer.

## Secrets

- No secret is ever placed in a frontend variable. Anything reaching
  `import.meta.env` is public; the frontend env file carries only the API URL.
- Provider credentials come from the environment, never from the database, and
  are never returned by any endpoint — including the admin panel. An admin
  compromise cannot exfiltrate an API key or point a checker at an attacker's
  endpoint.
- The application connects to MySQL as a dedicated user with privileges only on
  its own database. Never as `root`.
- `.env` is not in version control. `.env.example` contains placeholders only.
- Passwords, API secrets, session cookies and private tokens are never written
  to a log. Email addresses are masked in audit entries.

## Credits and money

- **The browser cannot modify a balance.** There is no endpoint that sets one.
  Every movement is a ledger entry written server-side.
- Credits are reserved at submission and settled exactly once at the job's
  terminal transition, inside a database transaction. A cancel racing a
  worker's completion cannot double-settle: one wins the transition and the
  other gets a conflict.
- The `wallets` table carries `CHECK` constraints — a balance cannot go
  negative and `reserved` cannot exceed `balance` — so the database refuses an
  invalid state whatever happens above it.
- Closing a record, storing its result and moving the counters happen in one
  transaction, so a record can never be charged without being counted or
  counted without being charged.
- No payment provider is integrated. Checkout refuses with 503 rather than
  pretending a purchase succeeded.

## Uploads and generated files

- Uploads are read as text and never stored, executed or served back. The only
  job of the file is to carry lines.
- The extension allow-list, the size cap and the UTF-8 check are all enforced
  server-side, and the temporary path is verified with `is_uploaded_file()` so
  a crafted request cannot name an arbitrary file on disk.
- `backend/storage/` holds logs, uploads and exports. Nothing under `backend/`
  except `public/` is web-reachable; the Nginx configuration in
  [DEPLOYMENT.md](DEPLOYMENT.md) enforces that too.
- Export files are deleted after `EXPORT_RETENTION_HOURS`. A list of somebody's
  addresses should not sit on disk indefinitely.

## Rate limiting

Fixed-window buckets on every credential path and every expensive operation,
keyed by user id where one exists and by client IP otherwise. Bucket keys are
hashed, so the table never stores a raw address. See the table in
[API.md](API.md#rate-limits).

While a login lockout holds, the correct password is refused too — otherwise
the lockout would only slow an attacker down until they guessed right.

## Logging and audit

- `audit_logs` records security-relevant events with the actor, their role, the
  target, the client IP and a severity.
- `activity_logs` records what a user did, for their own activity page.
- Both are written on the same path as the change, so an action cannot succeed
  without being recorded.

## Transport and headers

Serve over HTTPS only, and set `SESSION_COOKIE_SECURE=true`. Every response
carries:

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` (API responses) |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `no-referrer` |
| `Permissions-Policy` | camera, microphone, geolocation and the rest disabled |
| `Cross-Origin-Resource-Policy` | `same-site` |
| `Cache-Control` | `no-store, max-age=0` |

CORS uses an explicit allow-list from `CORS_ALLOWED_ORIGINS`. It is never `*`,
which would be incompatible with credentialed requests anyway. In production
the app and the API are served from one origin, so CORS does not come into it.

The frontend loads no third-party resource at all: fonts are served from the
same origin, so no visitor's address is sent anywhere else to read a page.

## Reporting a vulnerability

Report privately, not in a public issue. Each installation sets its own
security contact; if none is published, use a support ticket marked urgent.
Include what you did, what happened, and what you expected to happen.

Please do not test against an installation you do not own, and do not test
against any third-party platform on AccountCheck's behalf.
