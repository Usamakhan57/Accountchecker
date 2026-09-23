# AccountCheck API

Every endpoint lives under `/api`. The API speaks JSON only, authenticates with
an HttpOnly session cookie, and never returns an internal detail — no stack
trace, no SQL, no path, no provider credential.

- [Envelope](#envelope)
- [Authentication](#authentication)
- [CSRF](#csrf)
- [Paging, sorting and filtering](#paging-sorting-and-filtering)
- [Rate limits](#rate-limits)
- [Endpoints](#endpoints)
- [Error codes](#error-codes)

## Envelope

Every JSON response has the same two shapes. A client can tell success from
failure without looking at the status code, and can render a failure without
knowing anything about HTTP.

Success:

```json
{
  "success": true,
  "message": "Your jobs",
  "data": { }
}
```

Failure:

```json
{
  "success": false,
  "message": "You do not have enough credits for this job.",
  "error_code": "INSUFFICIENT_CREDITS",
  "errors": {
    "credits_required": ["120"],
    "credits_available": ["45"]
  }
}
```

`message` is written for a person and is safe to show as-is. `error_code` is
the stable string to branch on. `errors` is present only when a failure has
per-field detail; a validation failure (422) keys it by field name.

Export downloads are the one exception: they return the file itself with a
`Content-Disposition` header. A failure on that route still returns the JSON
envelope.

## Authentication

Sign-in sets an HttpOnly, SameSite=Lax session cookie, `Secure` whenever the
API is served over HTTPS. There is no bearer token and no token in local
storage: a script on the page cannot read the cookie, so an XSS bug cannot
walk away with a session.

The session is a row in `user_sessions`, not a self-contained token. Signing
out revokes it server-side, and so does suspending the account — both take
effect on the next request rather than whenever a token would have expired.

Send `credentials: 'include'` on every request. The client does this for you.

| Status | Meaning |
| --- | --- |
| 401 `UNAUTHENTICATED` | No session, or it expired, was revoked, or went idle past `SESSION_IDLE_TIMEOUT_MINUTES`. |
| 403 `ACCOUNT_INACTIVE` | The account exists but is suspended. |
| 403 `PERMISSION_DENIED` | Signed in, but the role lacks the permission. |

Anything belonging to another account answers **404**, never 403. A job id
that is not yours is indistinguishable from one that never existed, because
telling the difference is itself information.

## CSRF

Because the API authenticates with a cookie, the browser attaches it to a
request whatever page caused it. Every unsafe method therefore has to prove it
came from our own code.

1. Any read issues a `accountcheck_csrf` cookie. It is deliberately **not**
   HttpOnly — our script has to read it.
2. Echo the value in an `X-CSRF-Token` header on every `POST`, `PUT`, `PATCH`
   and `DELETE`.
3. The two are compared with `hash_equals`.

Another origin can cause the cookie to be sent but cannot read it, so it cannot
produce the header. A mismatch is **419 `CSRF_TOKEN_MISMATCH`**; the remedy is
to perform one read and retry, which the bundled client does once
automatically.

`GET`, `HEAD` and `OPTIONS` are exempt, because they must not change state.

## Paging, sorting and filtering

Every list endpoint is paged at the database. Nothing ever returns an unbounded
collection — a 5,000-record job is read a page at a time like everything else.

| Parameter | Default | Notes |
| --- | --- | --- |
| `page` | 1 | 1-based. |
| `per_page` | 25 | Capped at 200 whatever is asked for. |
| `sort` | per endpoint | Resolved against an allow-list; an unknown key falls back to the default rather than reaching the SQL. |
| `direction` | per endpoint | `asc` or `desc`. |

A paged response carries its own pagination block:

```json
{
  "items": [],
  "pagination": {
    "page": 1, "per_page": 25, "total": 4137,
    "total_pages": 166, "has_more": true
  }
}
```

Result filters (`/api/results`, `/api/jobs/{id}/results`, exports):

| Filter | Values |
| --- | --- |
| `status` | `VALID`, `INVALID`, `UNKNOWN`, `UNAVAILABLE`, `ERROR` |
| `checker` | a checker slug |
| `search` | prefix match on the normalized input; `%` and `_` are literals |
| `from`, `to` | `YYYY-MM-DD`, both inclusive |

## Rate limits

Exceeding a limit is **429 `RATE_LIMITED`**; the message says how long to wait.

| Bucket | Limit | Keyed by |
| --- | --- | --- |
| `login` | 5 / 15 min | IP **and** email address, separately |
| `register` | 5 / hour | IP |
| `password_reset` | 5 / hour | IP |
| `checker_start` | 30 / 10 min | user |
| `support` | 20 / hour | user |
| `tools` | 60 / min | user |
| `api` | 300 / min | user or IP |

A successful sign-in clears the login buckets. While a login lockout holds,
the correct password is refused too — otherwise the lockout would only slow an
attacker down until they guessed right.

## Endpoints

`A` marks an endpoint that needs a session; `ADM` one that needs an
administrator.

### Health

| Method | Path | |
| --- | --- | --- |
| GET | `/api/health` | Liveness. Also how a client obtains its first CSRF token. |
| GET | `/api/health/database` | Database reachability. |
| GET | `/api/health/worker` | Last worker heartbeat, queue depth and backlog. |

### Auth

| Method | Path | | |
| --- | --- | --- | --- |
| POST | `/api/auth/register` | | `name`, `email`, `password` |
| POST | `/api/auth/login` | | `email`, `password`, `remember` |
| POST | `/api/auth/logout` | | Revokes the session server-side. |
| POST | `/api/auth/forgot-password` | | `email`. Always succeeds, whether or not the address is registered. |
| POST | `/api/auth/reset-password` | | `token`, `password` |
| POST | `/api/auth/verify-email` | | `token` |
| POST | `/api/auth/change-password` | A | `current_password`, `password` |
| POST | `/api/auth/resend-verification` | A | |

Registration is the one place that says an address is already taken: the person
just typed it, so a duplicate has to be actionable. Login and password reset
answer identically either way.

### User

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/user/me` | A | The signed-in user, their wallet and their permissions. |
| PUT | `/api/user/profile` | A | `name` |
| GET | `/api/user/sessions` | A | Active sessions, current one marked. |
| POST | `/api/user/sessions/revoke-others` | A | Signs out everywhere else. |
| GET | `/api/user/activity` | A | Paged activity log. |
| GET | `/api/dashboard` | A | Totals, usage by day, recent jobs. |

### Checkers

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/checker/types` | A | The catalogue, with `enabled`, `configured`, `mode`, `credit_cost` and `max_batch_size` per checker. |
| GET | `/api/checker/types/{slug}` | A | One checker. |
| POST | `/api/checker/{slug}/validate` | A | Dry-runs a list: counts valid, invalid and duplicate lines, and samples the invalid ones. Costs nothing and queues nothing. |
| POST | `/api/checker/{slug}/start` | A | Queues a job. |

`configured: false` means no authorized verification source is set up for that
checker. Every check would report `UNAVAILABLE`, so the workspace says so
before anyone spends a credit.

**Starting a job** takes either `input` (a newline-separated list, up to 2 MB)
or a `file` upload (`.txt` or `.csv`, up to `UPLOAD_MAX_BYTES`), plus an
optional `output_format` of `csv` or `txt`. It returns as soon as the rows are
written — nothing is checked inside the request:

```json
{
  "job": { "uuid": "…", "status": "QUEUED", "total_items": 4980, "credits_reserved": 4980 },
  "skipped": { "invalid": 14, "duplicate": 6 },
  "mode": "production",
  "configured": true
}
```

Credits are **reserved**, not spent. See [Credits](#credits).

### Jobs

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/jobs` | A | Paged; filter by `status` and `checker`. |
| GET | `/api/jobs/{id}` | A | One job with its status breakdown. |
| GET | `/api/jobs/{id}/progress` | A | The polling endpoint. |
| POST | `/api/jobs/{id}/cancel` | A | Stops the remaining records and settles. |
| GET | `/api/jobs/{id}/results` | A | Paged results for that job. |
| POST | `/api/jobs/{id}/export` | A | Queues an export of that job. |

`{id}` is the job's UUID.

**Progress** is deliberately small — the workspace hits it every couple of
seconds:

```json
{
  "status": "PROCESSING", "total_items": 4980, "processed_items": 2140,
  "successful_items": 1502, "failed_items": 638, "progress_percent": 42,
  "credits_reserved": 4980, "credits_spent": 1502, "is_finished": false
}
```

Poll until `is_finished`. A client never needs to know the status vocabulary.
Stop polling when it turns true; the bundled hook also pauses in a hidden tab
and backs off on an error.

### Results, history and exports

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/results` | A | Every result the account owns, paged and filtered. |
| GET | `/api/history` | A | Job history. |
| DELETE | `/api/history/{id}` | A | |
| DELETE | `/api/history` | A | Clears it. |
| GET | `/api/exports` | A | |
| POST | `/api/exports` | A | `format` (`csv`\|`txt`) plus the result filters. |
| GET | `/api/exports/{uuid}/download` | A | The file. |
| DELETE | `/api/exports/{uuid}` | A | |

Exports are generated by streaming the rows in keyset order, so the cost of a
chunk is the same at the first row and the hundred-thousandth, and the file is
never held in memory. Generated files are deleted after
`EXPORT_RETENTION_HOURS`: a list of somebody's addresses should not sit on
disk indefinitely.

CSV cells beginning `=`, `+`, `-` or `@` are prefixed with an apostrophe, so a
spreadsheet renders them as text instead of executing them as a formula.

### Wallet

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/wallet` | A | Balance, reserved, available, plus current holds. |
| GET | `/api/wallet/transactions` | A | The ledger, paged; filter by `type`. |

There is **no endpoint that sets a balance**. The browser cannot move credits.
Every movement is a ledger entry written server-side inside a transaction.

### Plans

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/plans` | | The catalogue. |
| GET | `/api/plans/{slug}` | | One plan. |
| POST | `/api/plans/{slug}/checkout` | A | |

No payment provider is integrated in this build. With `PAYMENT_DRIVER` empty,
checkout answers **503 `PAYMENTS_NOT_CONFIGURED`** and the wallet is untouched;
a `PAYMENT_DRIVER` naming a driver that does not exist answers **503
`PAYMENT_DRIVER_MISSING`**. Neither pretends a purchase succeeded.

### Notifications and support

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/notifications` | A | |
| POST | `/api/notifications/{id}/read` | A | |
| POST | `/api/notifications/read-all` | A | |
| DELETE | `/api/notifications/{id}` | A | |
| GET | `/api/support/tickets` | A | |
| POST | `/api/support/tickets` | A | `subject`, `body`, `priority` |
| GET | `/api/support/tickets/{id}` | A | The ticket and its whole conversation. |
| POST | `/api/support/tickets/{id}/reply` | A | `body` |
| POST | `/api/support/tickets/{id}/close` | A | |

An account may have 5 open tickets at once. A staff reply is attributed to
"AccountCheck support"; the answering administrator's name and address are
never sent to the customer.

### Free tools

| Method | Path | | |
| --- | --- | --- | --- |
| POST | `/api/tools/duplicates` | A | Finds duplicates in a pasted list. |
| POST | `/api/tools/name-generator` | A | Generates handle suggestions. |

Both run entirely locally, cost no credits and start no job. The only bound is
`TOOLS_MAX_LINES`.

### Admin

| Method | Path | | |
| --- | --- | --- | --- |
| GET | `/api/admin/overview` | ADM | Platform totals, health, recent jobs. |
| GET | `/api/admin/users` | ADM | |
| GET | `/api/admin/users/{id}` | ADM | |
| PUT | `/api/admin/users/{id}/status` | ADM | `status` |
| PUT | `/api/admin/users/{id}/role` | ADM | `role` |
| POST | `/api/admin/users/{id}/wallet` | ADM | `amount`, `reason` |
| GET | `/api/admin/jobs` | ADM | Every account's jobs. |
| POST | `/api/admin/jobs/{id}/cancel` | ADM | |
| GET | `/api/admin/wallet/transactions` | ADM | The whole ledger. |
| GET | `/api/admin/checkers` | ADM | With 30-day usage. |
| PUT | `/api/admin/checkers/{slug}` | ADM | `is_enabled`, `credit_cost`, `max_batch_size` |
| GET | `/api/admin/plans` | ADM | |
| PUT | `/api/admin/plans/{slug}` | ADM | |
| GET | `/api/admin/support` | ADM | Every ticket, oldest-waiting first. |
| GET | `/api/admin/support/{id}` | ADM | |
| POST | `/api/admin/support/{id}/reply` | ADM | |
| PUT | `/api/admin/support/{id}/status` | ADM | |
| GET | `/api/admin/logs` | ADM | The audit log. |
| GET | `/api/admin/settings` | ADM | |
| PUT | `/api/admin/settings` | ADM | **SUPER_ADMIN only.** |

The rules that bound all of this are enforced in one place, not per endpoint:

- Nobody can suspend or demote their own account.
- Only a SUPER_ADMIN grants SUPER_ADMIN, acts on a SUPER_ADMIN, or changes a
  system setting.
- A wallet adjustment is capped at 1,000,000 credits, needs a reason, and runs
  through the ledger like any other movement.
- Credits reserved against a running job cannot be taken: that job still has to
  settle.
- Provider credentials are not editable from the panel and are not returned by
  it. They live in the environment, so an admin compromise cannot exfiltrate an
  API key or point a checker at somebody else's endpoint.

Every administrative change is written to the audit log with the actor
attached.

## Credits

Credits follow a reserve/settle model rather than charging up front.

```
submit    reserve  N × credit_cost   the credits become unavailable but are
                                     still the user's; no ledger entry
run       ...                        the worker records each result
finish    settle   charge what was actually checked, release the rest
```

So a cancelled or failed job costs only what it really did, and a job can never
start that the wallet could not cover. Settlement happens exactly once, by
whichever caller wins the job's terminal transition — a cancel racing a
worker's completion cannot double-settle.

**`UNAVAILABLE` and `ERROR` are not billable.** Billable and "successfully
checked" are the same set by design: the user pays for records an authorized
source answered for, and for nothing else.

## Error codes

| Code | Status | |
| --- | --- | --- |
| `VALIDATION_FAILED` | 422 | `errors` is keyed by field. |
| `INVALID_INPUT` | 400 | |
| `UNAUTHENTICATED` | 401 | No session, or it expired or was revoked. |
| `INVALID_CREDENTIALS` | 401 | Wrong password, or no such account — identical either way. |
| `ACCOUNT_INACTIVE` | 403 | |
| `PERMISSION_DENIED` | 403 | |
| `SUPER_ADMIN_REQUIRED` | 403 | |
| `SELF_ACTION_REFUSED` | 400 | An administrator acting on their own account. |
| `REGISTRATION_DISABLED` | 403 | |
| `RESET_TOKEN_INVALID` | 400 | Also covers an expired or already-used token. |
| `VERIFICATION_TOKEN_INVALID` | 400 | |
| `NOT_FOUND` | 404 | |
| `JOB_NOT_FOUND` | 404 | Also what another account's job returns. |
| `CHECKER_NOT_FOUND` | 404 | |
| `TICKET_NOT_FOUND` | 404 | |
| `EXPORT_NOT_FOUND` | 404 | |
| `USER_NOT_FOUND` | 404 | |
| `SETTING_NOT_FOUND` | 404 | |
| `METHOD_NOT_ALLOWED` | 405 | |
| `CONFLICT` | 409 | |
| `JOB_NOT_CANCELLABLE` | 409 | It already finished. |
| `CHECKER_DISABLED` | 409 | |
| `TICKET_CLOSED` | 409 | |
| `INSUFFICIENT_CREDITS` | 402 / 409 | 402 when a job cannot be afforded; 409 when an adjustment would take credits a job is holding. |
| `BATCH_TOO_LARGE` | 422 | Over `max_batch_size`. |
| `NO_VALID_INPUT` | 422 | Nothing in the list could be checked. |
| `TOO_MANY_ACTIVE_JOBS` | 429 | |
| `TOO_MANY_OPEN_TICKETS` | 409 | |
| `RATE_LIMITED` | 429 | |
| `EXPORT_RATE_LIMITED` | 429 | |
| `UPLOAD_TOO_LARGE` | 413 | |
| `UPLOAD_TYPE_NOT_ALLOWED` | 400 | |
| `UPLOAD_NOT_TEXT` | 400 | Not valid UTF-8. |
| `CSRF_TOKEN_MISMATCH` | 419 | Perform one read and retry. |
| `PAYMENTS_NOT_CONFIGURED` | 503 | No payment provider in this build. |
| `PAYMENT_DRIVER_MISSING` | 503 | `PAYMENT_DRIVER` names a driver that does not exist. |
| `DATABASE_UNAVAILABLE` | 503 | |
| `WORKER_STATUS_UNAVAILABLE` | 503 | |
| `INTERNAL_ERROR` | 500 | Details are in the server log, never in the response. |
