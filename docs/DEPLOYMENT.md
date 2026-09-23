# Deployment

Ubuntu 24.04, Nginx, PHP-FPM 8.2+, MySQL 8. Written for a server that may
already be hosting something else, so every step names exactly what it touches.

> **Nothing in this repository deploys itself.** `scripts/deploy.sh` builds,
> migrates and reloads AccountCheck's own services, and nothing else. It never
> edits a file outside the application directory, never touches Nginx, and
> never restarts a service that is not AccountCheck's. Every step below that
> changes the server is one you run deliberately.

- [Before you start](#before-you-start)
- [1. Packages](#1-packages)
- [2. Database](#2-database)
- [3. The application](#3-the-application)
- [4. Environment](#4-environment)
- [5. PHP-FPM](#5-php-fpm)
- [6. Nginx](#6-nginx)
- [7. HTTPS](#7-https)
- [8. The worker](#8-the-worker)
- [9. Firewall](#9-firewall)
- [10. Behind Cloudflare](#10-behind-cloudflare)
- [11. First deploy](#11-first-deploy)
- [Deploying an update](#deploying-an-update)
- [Rolling back](#rolling-back)
- [Production checklist](#production-checklist)

## Before you start

| | |
| --- | --- |
| OS | Ubuntu 24.04 LTS |
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `curl`, `json`, `openssl` |
| MySQL | 8.0+ (MariaDB 10.6+ also works) |
| Node | 18.18+ — **build tooling only.** The production backend is PHP; Node runs nothing at runtime. |
| DNS | An A record for your domain pointing at the server |

This guide installs into `/var/www/accountcheck` and runs as `www-data`. If
your server uses different paths or users, change them consistently in the
config templates under `deploy/`.

**If this server hosts other sites**, everything here is additive: a new
database, a new PHP-FPM pool with its own socket, a new Nginx site file. No
step modifies `nginx.conf`, the default `www` FPM pool, or another site's
configuration. Read each command before running it and confirm that is true.

## 1. Packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server \
    php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-curl php8.2-xml php8.2-zip \
    curl unzip git

# Node, for the build only.
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Check what you got:

```bash
php -v
php -m | grep -E 'pdo_mysql|mbstring|curl|json|openssl'
node --version
```

## 2. Database

```bash
sudo mysql_secure_installation
```

Then create the database and a **dedicated user**. The application must never
connect as `root`:

```sql
CREATE DATABASE accountcheck
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'accountcheck_app'@'localhost'
  IDENTIFIED BY 'a-long-random-password';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON accountcheck.* TO 'accountcheck_app'@'localhost';

FLUSH PRIVILEGES;
```

`CREATE`, `ALTER` and `INDEX` are there for the migration runner. `DROP` is
deliberately absent: the runner is additive and never needs it, and without it
a compromised application cannot drop a table.

If this MySQL instance serves other applications, note that the grant above is
scoped to `accountcheck.*` only. It gives no access to any other database.

While you are here, give InnoDB some memory. The default 128 MB is not enough
once `checker_results` is large:

```ini
# /etc/mysql/mysql.conf.d/accountcheck.cnf  (a new file; nothing existing is edited)
[mysqld]
innodb_buffer_pool_size = 1G
```

```bash
sudo systemctl restart mysql
```

## 3. The application

```bash
sudo mkdir -p /var/www/accountcheck
sudo chown "$USER":"$USER" /var/www/accountcheck
git clone https://github.com/Usamakhan57/Accountchecker.git /var/www/accountcheck
cd /var/www/accountcheck
```

```bash
sudo mkdir -p backend/storage/{logs,uploads,exports}
sudo chown -R www-data:www-data backend/storage
sudo chmod -R 770 backend/storage
```

`backend/storage` holds logs, uploads and generated exports. `770` rather than
`775`: an export is somebody's list of addresses and has no business being
world-readable.

The runtime needs no Composer packages at all — the only dependency is PHPUnit,
and that is development-only. There is nothing to install on the server.

## 4. Environment

```bash
cp .env.example backend/.env
chmod 640 backend/.env
sudo chown "$USER":www-data backend/.env
nano backend/.env
```

The settings that matter in production:

```ini
APP_ENV=production
APP_DEBUG=false                       # an error response would otherwise carry internals
APP_URL=https://your-domain
APP_KEY=                              # generate below
CORS_ALLOWED_ORIGINS=https://your-domain

DB_HOST=127.0.0.1                     # not "localhost" - see TROUBLESHOOTING.md
DB_NAME=accountcheck
DB_USER=accountcheck_app
DB_PASSWORD=a-long-random-password

SESSION_COOKIE_SECURE=true            # the cookie must not travel over plain HTTP
SESSION_COOKIE_SAMESITE=Lax
CSRF_PROTECTION=true

CHECKER_MODE=production               # mock makes no outbound calls
LOG_LEVEL=warning
```

Generate the key:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Configure an authorized provider for each checker you intend to run. Leaving a
`*_VERIFY_API_URL` empty is a valid choice: that checker then reports
`UNAVAILABLE` for every record and bills nothing, rather than attempting an
unauthorized lookup.

`backend/.env` is the one file on the server that contains every credential.
Keep it `640`, keep it out of version control, and back it up encrypted and
separately (see [BACKUP.md](BACKUP.md)).

## 5. PHP-FPM

A pool of its own, so the default `www` pool and anything using it are
untouched:

```bash
sudo cp deploy/php/accountcheck-fpm.conf /etc/php/8.2/fpm/pool.d/accountcheck.conf
sudo nano /etc/php/8.2/fpm/pool.d/accountcheck.conf   # check the paths
sudo php-fpm8.2 -t
sudo systemctl reload php8.2-fpm
```

The pool sets `open_basedir` to the application directory, disables the
shell-out functions the application never uses, turns `display_errors` off, and
gives the pool its own socket at
`/run/php/php8.2-fpm-accountcheck.sock`.

```bash
ls -l /run/php/php8.2-fpm-accountcheck.sock
```

## 6. Nginx

```bash
sudo cp deploy/nginx/accountcheck.conf /etc/nginx/sites-available/accountcheck
sudo nano /etc/nginx/sites-available/accountcheck   # replace REPLACE_ME_DOMAIN
```

Comment out the three `ssl_*` lines for now — the certificate does not exist
yet — then:

```bash
sudo ln -s /etc/nginx/sites-available/accountcheck /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

`nginx -t` before every reload. It parses every enabled site, so it also tells
you if you have broken one of the others.

Three things in that file are load-bearing:

- **`location /api` comes before `location /`.** Reversed, the SPA fallback
  answers the API too and every endpoint returns `index.html` with a 200.
- **The document root is `frontend/dist`.** Nothing under `backend/` is
  reachable except through the `index.php` block, which names the front
  controller explicitly.
- **`index.html` is served `no-cache` while `/assets/` is immutable.** The
  filenames under `/assets/` are content-hashed, so they never change meaning;
  `index.html` is what points at them, and a stale copy would load a deploy's
  worth of files that no longer exist.

The app and the API are on one origin deliberately. The session cookie is
HttpOnly with `SameSite=Lax`, so a genuinely cross-site API would not receive
it on a reload and the app would appear to sign the user out. One origin also
means CORS never comes into it.

## 7. HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain -d www.your-domain
```

Certbot fills in the `ssl_*` lines and adds the redirect. Confirm renewal
works:

```bash
sudo certbot renew --dry-run
systemctl list-timers | grep certbot
```

Keep the `/.well-known/acme-challenge/` location on port 80. Renewal needs it
over plain HTTP.

Once HTTPS is working and you intend to keep it, the HSTS header in the site
file takes effect. Be deliberate about that: a browser that has seen it will
refuse plain HTTP for this host until it expires, and there is no way to take
that back early.

## 8. The worker

Batch checking never runs inside a web request. Without the worker, jobs queue
and nothing happens.

```bash
sudo cp deploy/systemd/accountcheck-worker.service /etc/systemd/system/
sudo nano /etc/systemd/system/accountcheck-worker.service   # check the paths
sudo systemctl daemon-reload
sudo systemctl enable --now accountcheck-worker
sudo systemctl status accountcheck-worker
```

```bash
sudo journalctl -u accountcheck-worker -f
```

The unit exits cleanly after an hour and systemd restarts it, so a long-lived
PHP process cannot accumulate problems nobody sees. On `SIGTERM` it finishes
the current chunk and hands an unfinished job back to the queue, so a restart
loses no work — the credits stay reserved and the job settles once, when it
reaches a terminal state.

**More throughput:** run several. Work is claimed with atomic `UPDATE`s, so two
workers cannot take the same job or the same record. Copy the unit to
`accountcheck-worker@.service`, add `--worker-id=%i`, and enable
`accountcheck-worker@1`, `@2` and so on.

**A timer instead of a service:** `deploy/systemd/accountcheck-worker-once.*`
runs one pass every five minutes. Jobs then wait up to five minutes to start,
which is why the service is the better default. Do not run both.

## 9. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status verbose
```

> **Allow SSH before enabling UFW.** Enabling it without that rule ends your
> own session and locks you out. If this server already has firewall rules for
> another application, check `sudo ufw status numbered` first and confirm you
> are adding to them rather than replacing them.

MySQL should not be reachable from outside. Confirm it is on loopback only:

```bash
sudo ss -tlnp | grep 3306      # expect 127.0.0.1:3306, not 0.0.0.0:3306
```

## 10. Behind Cloudflare

Optional. If you use it:

- **SSL/TLS mode: Full (strict).** "Flexible" makes Cloudflare talk to your
  server over plain HTTP, which would send the session cookie in the clear on
  that leg.
- Keep the origin certificate valid — Full (strict) verifies it.
- Cloudflare terminates TLS, so the client address arrives in a header. The
  application already reads `CF-Connecting-IP` and `X-Forwarded-For`, which
  matters because rate limiting and the audit log are keyed by client address.
  Restrict those headers to Cloudflare's own ranges in Nginx, or a client could
  spoof them and walk around a rate limit.
- Do not let Cloudflare cache `/api`. Add a page rule bypassing cache for
  `your-domain/api/*`. API responses already say `Cache-Control: no-store`, but
  a cached authenticated response is not a mistake worth risking twice.
- Leave `/assets/` and `/fonts/` cacheable; they are content-hashed.

## 11. First deploy

```bash
cd /var/www/accountcheck
./scripts/deploy.sh --check      # says what it would do, changes nothing
./scripts/deploy.sh
```

The script refuses to run outside an AccountCheck checkout, and refuses to
deploy at all if `APP_ENV=production` with `APP_DEBUG=true`, an insecure
session cookie, CSRF off, a wildcard CORS origin, an empty `APP_KEY`, or `root`
as the database user. Those are the settings that are merely inconvenient in
development and a security problem in production.

Then seed the reference data — roles, permissions, checker types, plans and
system settings:

```bash
php backend/database/seed.php
```

Every insert is idempotent, so re-running it refreshes the reference rows
without touching user data.

**Creating the first administrator.** The seed deliberately creates no account
in production: a shipped administrator with a known password is a back door,
and one that exists on every install is a back door into every install. Instead,
register normally through the app and then promote that account once:

```bash
# Register at https://your-domain/register first, then:
sudo mysql accountcheck -e "
  UPDATE users
     SET role_id = (SELECT id FROM roles WHERE slug = 'SUPER_ADMIN'),
         email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
   WHERE email = 'you@your-domain';"
```

Sign out and back in, and the admin panel appears. This is the only time you
should need to change a role from SQL; every subsequent one goes through the
admin panel, which enforces the guard rails and writes to the audit log.

If you would rather not leave registration open afterwards, turn it off from
**Administration → Settings** (`registration_enabled`).

Check it is all up:

```bash
curl -s https://your-domain/api/health
curl -s https://your-domain/api/health/database
curl -s https://your-domain/api/health/worker
```

All three should report `"success":true`, and the worker one should show a
recent heartbeat.

## Deploying an update

```bash
cd /var/www/accountcheck
git pull
./scripts/deploy.sh
```

That builds the frontend, applies any pending migrations, fixes storage
permissions, reloads PHP-FPM and restarts the worker, then checks the health
endpoints.

It does **not** reload Nginx. The site configuration does not change between
deploys, and this server may be hosting something else; if you did change it,
run `sudo nginx -t && sudo systemctl reload nginx` yourself.

Take a database backup before a deploy that includes a migration. See
[BACKUP.md](BACKUP.md).

## Rolling back

The frontend and the application code roll back with git:

```bash
cd /var/www/accountcheck
git log --oneline -10
git checkout <previous-commit>
./scripts/deploy.sh --skip-build      # or rebuild
```

**Migrations do not roll back.** The runner is additive by design — it creates
and alters, never drops — so an older application usually runs fine against a
newer schema: the extra columns and indexes are simply unused. If a schema
change genuinely has to be undone, restore from a backup taken before it
([BACKUP.md](BACKUP.md)).

## Production checklist

Configuration:

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] `APP_KEY` generated, not the placeholder
- [ ] `SESSION_COOKIE_SECURE=true`
- [ ] `CSRF_PROTECTION=true`
- [ ] `CORS_ALLOWED_ORIGINS` lists exact origins, never `*`
- [ ] `backend/.env` is `640` and not in version control
- [ ] Database user is not `root` and has no `DROP`
- [ ] `LOG_LEVEL=warning` or `error`

Services:

- [ ] HTTPS working, renewal tested with `certbot renew --dry-run`
- [ ] `nginx -t` passes
- [ ] PHP-FPM running on its own pool and socket
- [ ] `accountcheck-worker` enabled and reporting a recent heartbeat
- [ ] UFW allows SSH and Nginx, MySQL on loopback only

Verification:

- [ ] All three `/api/health*` endpoints report success
- [ ] Sign in, queue a small job, watch it complete, export the results
- [ ] The first administrator was promoted from a real registration, not seeded
- [ ] `curl -sI https://your-domain | grep -i x-powered-by` returns nothing
- [ ] A nightly backup is scheduled **and one restore has been tested**

Untouched:

- [ ] No other site's Nginx configuration was modified
- [ ] No other database or MySQL user was modified
- [ ] The default PHP-FPM pool was left as it was
