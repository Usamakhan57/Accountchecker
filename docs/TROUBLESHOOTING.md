# Troubleshooting

Symptoms, what causes them, and how to confirm before you change anything.

Start here — these three answer most questions in one line each:

```bash
curl -s https://your-domain/api/health
curl -s https://your-domain/api/health/database
curl -s https://your-domain/api/health/worker
```

And the logs:

```bash
tail -n 100 backend/storage/logs/app-$(date -u +%Y-%m-%d).log
sudo tail -n 100 /var/log/nginx/error.log
sudo journalctl -u accountcheck-worker -n 100 --no-pager
```

- [The API](#the-api)
- [Signing in](#signing-in)
- [Jobs and the worker](#jobs-and-the-worker)
- [Credits](#credits)
- [Checkers](#checkers)
- [Exports](#exports)
- [Email](#email)
- [The frontend](#the-frontend)
- [Performance](#performance)
- [Diagnostics that are safe to run](#diagnostics-that-are-safe-to-run)

## The API

### Every request returns 500

Look in `backend/storage/logs/`. The response deliberately carries no detail;
the log has the exception, the file and the line.

The usual causes, in order of likelihood:

1. **`backend/storage/` is not writable** by the PHP-FPM user. The logger
   cannot write, so the first failure cascades.
   `sudo chown -R www-data:www-data backend/storage && chmod -R 775 backend/storage`
2. **`backend/.env` is missing or unreadable.** Without it `DB_*` are empty and
   every request fails at the first query.
3. **A PHP extension is missing.** `php -m | grep -E 'pdo_mysql|mbstring|curl|json|openssl'`
   should print all five.

### 503 `DATABASE_UNAVAILABLE`

MySQL is down, unreachable, or refusing these credentials.

```bash
sudo systemctl status mysql
mysql -u accountcheck_app -p -h 127.0.0.1 accountcheck -e "SELECT 1"
```

If the CLI connects but PHP does not, the difference is almost always
`DB_HOST=localhost` versus `127.0.0.1`: `localhost` makes MySQL use a unix
socket, and the socket path PHP is compiled with may not be the one MySQL is
listening on. Use `127.0.0.1`.

### 404 on every `/api` path, but the app loads

Nginx is serving the SPA fallback for `/api` too. The `location /api` block
must come **before** the `location /` block that ends in
`try_files $uri /index.html`. See [DEPLOYMENT.md](DEPLOYMENT.md).

### 419 `CSRF_TOKEN_MISMATCH` on every write

The client is not echoing the token.

1. Confirm the cookie is issued: `curl -si https://your-domain/api/health | grep -i set-cookie`
   should show `accountcheck_csrf=…`.
2. If it is issued but the browser does not keep it, the API and the app are on
   different sites and the cookie is being dropped. Serve both from one origin
   (see [DEPLOYMENT.md](DEPLOYMENT.md)) rather than loosening `SameSite`.
3. If you are calling the API from a script, perform one `GET` first and send
   the cookie value back in `X-CSRF-Token`.

Do not set `CSRF_PROTECTION=false` to make this go away. It would let any site
act as a signed-in user.

### CORS errors in the browser console

`CORS_ALLOWED_ORIGINS` must list the exact origin, including scheme and port,
with no trailing slash. `https://app.example.com` and
`https://app.example.com/` are not the same string.

In production the app and the API share an origin, so a CORS error there means
the frontend is still pointed at a separate API host — check
`VITE_API_URL` in the build.

## Signing in

### "That email address and password do not match an account"

The same message covers a wrong password and an unknown address, on purpose.
To tell them apart, as an administrator:

```sql
SELECT id, email, status, email_verified_at FROM users WHERE email = 'person@example.com';
```

No row means the address is not registered. `status = 'SUSPENDED'` produces a
different error (`ACCOUNT_INACTIVE`), so it is not this one.

### 429 on sign-in, and the right password is refused too

That is the lockout working. Five failed attempts in 15 minutes locks the
bucket, and the correct password is refused while it holds — otherwise the
lockout would only slow an attacker down until they guessed right.

Wait it out, or clear that bucket:

```sql
DELETE FROM rate_limits WHERE bucket_key LIKE 'login:%';
```

Bucket keys are hashed, so you cannot target one address from SQL. Clearing all
login buckets is the blunt option; it is safe, and they refill on their own.

### Signed out on every page load

The session cookie is not coming back.

- `SESSION_COOKIE_SECURE=true` over plain HTTP: the browser will not send it.
  Either serve HTTPS or set it false for local work only.
- `SESSION_COOKIE_DOMAIN` set to something that does not match the host.
- The app and API on different sites with `SameSite=Lax`, which is exactly the
  case the same-origin deployment avoids.

### Signed out after a while

Working as configured. `SESSION_LIFETIME_MINUTES` is the absolute cap and
`SESSION_IDLE_TIMEOUT_MINUTES` the idle one.

## Jobs and the worker

### Jobs sit at QUEUED and never start

The worker is not running.

```bash
sudo systemctl status accountcheck-worker
curl -s https://your-domain/api/health/worker
```

If there is no recent heartbeat, start it and watch what it says:

```bash
sudo systemctl start accountcheck-worker
sudo journalctl -u accountcheck-worker -f
```

To see whether the queue itself is the problem, run one pass in the foreground:

```bash
php backend/worker.php --once
```

That processes one job and exits, printing what it did.

### A job is stuck at PROCESSING

A worker claimed it and died. The claim is a **lease**, not a lock: any worker
sweeps a claim older than `WORKER_LOCK_TTL_SECONDS` (default 300s) back into
the queue. Wait that long, or restart the worker to trigger the sweep
immediately.

Nothing is lost and nothing is double-charged. The credits are still reserved,
and settlement happens once, when the job reaches a terminal state.

To confirm what is actually held:

```sql
SELECT id, uuid, status, locked_at, worker_id,
       TIMESTAMPDIFF(SECOND, locked_at, UTC_TIMESTAMP()) AS held_seconds
FROM checker_jobs
WHERE status = 'PROCESSING' AND locked_at IS NOT NULL;
```

### The worker keeps exiting

By design: `WORKER_MAX_RUNTIME_SECONDS` (default 3600) makes it exit cleanly so
a long-lived process cannot accumulate problems. The systemd unit has
`Restart=always`, so it comes straight back. If it is exiting *immediately* and
repeatedly, read the journal — it is a configuration or database failure, not
the runtime limit.

### Progress stops climbing but the job is not finished

Records that fail transiently go back to the queue and are not counted as
processed, so progress genuinely pauses while they are retried
(`QUEUE_MAX_ITEM_ATTEMPTS`, default 3). After that they are closed out as
`ERROR`, which is not billable.

If it is stuck rather than slow, check the checker log:

```bash
grep -i 'checker' backend/storage/logs/app-$(date -u +%Y-%m-%d).log | tail -n 50
```

### 429 `TOO_MANY_ACTIVE_JOBS`

`QUEUE_MAX_ACTIVE_JOBS_PER_USER` (default 3) bounds what one account can have
running, so a single user cannot fill the queue for everyone. Wait for one to
finish, or raise the limit.

## Credits

### A balance looks wrong

Check it against its own ledger. This should return no rows:

```sql
SELECT w.user_id, w.balance, COALESCE(SUM(t.amount), 0) AS ledger
FROM wallets w
LEFT JOIN wallet_transactions t ON t.user_id = w.user_id
GROUP BY w.user_id, w.balance
HAVING w.balance <> ledger;
```

If it does return rows, the database was restored from an inconsistent dump —
see [BACKUP.md](BACKUP.md).

### Credits are "reserved" and not coming back

Reserved credits belong to a job that has not settled. Find it:

```sql
SELECT id, uuid, status, credits_reserved, credits_spent
FROM checker_jobs
WHERE user_id = ? AND status IN ('PENDING', 'QUEUED', 'PROCESSING');
```

Let it finish, or cancel it — cancelling settles it and releases the remainder.
Do **not** edit `wallets.reserved` by hand; the job still has to settle, and it
will settle against whatever it reserved.

### 402 `INSUFFICIENT_CREDITS` when the balance looks sufficient

The balance is not the spendable figure. **Available** is
`balance − reserved`, and that is what a new job is checked against. The wallet
page shows all three.

## Checkers

### Everything comes back `UNAVAILABLE`

The checker has no authorized verification source configured, so nothing is
attempted — and nothing is billed.

```bash
curl -s https://your-domain/api/checker/types -b cookies.txt | \
  python3 -c "import json,sys; [print(c['slug'], c['mode'], c['configured']) for c in json.load(sys.stdin)['data']]"
```

`configured: false` means the matching `*_VERIFY_API_URL` is empty in
`backend/.env`. Set it and reload PHP-FPM.

This is not a fault to work around. Where a platform offers no authorized
verification method, `UNAVAILABLE` is the correct answer — see
[SECURITY.md](SECURITY.md).

### Results look identical on every run

`CHECKER_MODE=mock`. The mock engine derives its outcome from a hash of the
slug and the input, so the same record always gives the same result. That is
what makes development and tests reproducible. Set `CHECKER_MODE=production`
and configure providers for real checks.

### 409 `CHECKER_DISABLED`

An administrator switched it off in the admin panel. The change takes effect
for the next request; a job already queued for it will fail with a message
saying the checker is no longer available, and no credits are used.

## Exports

### An export is empty

The filters matched nothing. `EXPORT_EMPTY` says so rather than producing a
file with only a header.

### `EXPORT_EXPIRED` or a download 404s

Generated files are deleted after `EXPORT_RETENTION_HOURS` (default 48). That
is deliberate — a list of somebody's addresses should not sit on disk. Generate
it again.

### 429 `EXPORT_RATE_LIMITED`

`EXPORT_MAX_PER_HOUR` (default 20).

### A spreadsheet shows a formula instead of text

It should not: cells beginning `=`, `+`, `-` or `@` are prefixed with an
apostrophe. If you see one, that is a bug worth reporting.

## Email

### No email arrives

With `MAIL_DRIVER=log` — the default — nothing is sent. The message is written
to `backend/storage/logs/` instead, which is what you want in development.

For real delivery set `MAIL_DRIVER=smtp` and the `MAIL_*` values, then check
the log for the SMTP failure. Delivery problems are almost always SPF, DKIM or
a reputation issue at the receiving end rather than the application.

### Someone cannot verify their address

Verification tokens last 24 hours, password reset tokens 60 minutes. Both are
single-use. `resend-verification` issues a fresh one.

## The frontend

### A blank page

Open the browser console.

- A 404 on the JS bundle means `try_files $uri /index.html` is missing from the
  Nginx `location /` block, or the build was not deployed.
- A module error after a deploy usually means a stale `index.html` is being
  served with new hashed assets. Make sure `index.html` is served with
  `Cache-Control: no-cache` while the hashed assets under `/assets/` are
  cached long-term.

### Styling is right but the fonts are wrong

The fonts are served from this origin, from `/fonts/`. If they 404, the build
output was copied without `dist/fonts/`. Deploy the whole `dist` directory.

### The app loads but every request fails

The app is calling an API that is not there. Check `VITE_API_URL` in the build:
for a same-origin deployment it should be empty, so requests go to `/api` on
the current host.

## Performance

### Pages are slow to load lists

Confirm the indexes are applied:

```bash
php backend/database/migrate.php --status
```

`0005_performance_indexes.sql` must show as applied. Without it, the admin
lists and the health count scan.

### The dashboard is slow for one account

That account has a lot of results. The per-account queries are indexed, but the
30-day per-checker aggregate on the admin page is proportional to the window by
nature. If it becomes a problem, narrow the window or precompute it.

### Everything is slow

Check whether MySQL has any memory to work with:

```sql
SHOW VARIABLES LIKE 'innodb_buffer_pool_size';
```

The default 128 MB is not enough once `checker_results` is large. On a
dedicated database server, 50–70% of RAM is the usual starting point.

## Diagnostics that are safe to run

All read-only.

```bash
# Queue depth and what is waiting
mysql accountcheck -e "
  SELECT status, COUNT(*) FROM checker_jobs GROUP BY status;
  SELECT status, COUNT(*) FROM checker_job_items GROUP BY status;"

# Workers seen recently
mysql accountcheck -e "
  SELECT worker_id, hostname, last_seen_at,
         TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS seconds_ago
  FROM worker_heartbeats ORDER BY last_seen_at DESC;"

# Recent security events
mysql accountcheck -e "
  SELECT created_at, event, actor_id, severity
  FROM audit_logs ORDER BY id DESC LIMIT 30;"

# Errors today
grep -i error backend/storage/logs/app-$(date -u +%Y-%m-%d).log | tail -n 40
```

If you are about to change something in the database to fix a symptom: take a
backup first ([BACKUP.md](BACKUP.md)), and prefer the admin panel, which routes
the change through the same rules and writes it to the audit log.
