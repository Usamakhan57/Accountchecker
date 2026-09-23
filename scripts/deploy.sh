#!/usr/bin/env bash
#
# AccountCheck deployment.
#
#   scripts/deploy.sh            deploy the current checkout
#   scripts/deploy.sh --check    say what it would do, change nothing
#   scripts/deploy.sh --skip-build   use an existing frontend/dist
#
# What this script does, and only this:
#
#   - verifies it is in an AccountCheck checkout and that the environment is
#     configured for production;
#   - installs frontend dependencies and builds the SPA;
#   - runs the database migrations, which are additive and idempotent;
#   - fixes ownership and permissions on backend/storage;
#   - reloads PHP-FPM and restarts the AccountCheck worker;
#   - checks the health endpoints and reports.
#
# What it does NOT do, by design: it never touches Nginx, never edits a file
# outside this directory, never drops or truncates anything, never writes to
# another site's configuration, and never restarts a service that is not
# AccountCheck's own. Changing Nginx, MySQL or the firewall is a deliberate,
# reviewed step - see docs/DEPLOYMENT.md - not something a deploy does.
#
# It is safe to run twice.

set -euo pipefail

CHECK_ONLY=0
SKIP_BUILD=0

for argument in "$@"; do
    case "$argument" in
        --check)      CHECK_ONLY=1 ;;
        --skip-build) SKIP_BUILD=1 ;;
        -h|--help)    sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) printf 'Unrecognised option: %s\n' "$argument" >&2; exit 2 ;;
    esac
done

# ---------------------------------------------------------------------------
# Output helpers.
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
    BOLD=$(printf '\033[1m'); GREEN=$(printf '\033[32m')
    YELLOW=$(printf '\033[33m'); RED=$(printf '\033[31m'); RESET=$(printf '\033[0m')
else
    BOLD=''; GREEN=''; YELLOW=''; RED=''; RESET=''
fi

step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
ok()   { printf '    %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
warn() { printf '    %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
die()  { printf '\n%sDeployment stopped:%s %s\n\n' "$RED" "$RESET" "$1" >&2; exit 1; }

would() {
    if [ "$CHECK_ONLY" -eq 1 ]; then
        printf '    %s·%s would: %s\n' "$YELLOW" "$RESET" "$1"
        return 0
    fi
    return 1
}

# ---------------------------------------------------------------------------
# Locate the application, and refuse to run anywhere else.
#
# This is the guard that makes everything below safe: every path the script
# touches is built from APP_DIR, and APP_DIR is only accepted if it looks like
# this application.
# ---------------------------------------------------------------------------
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

for marker in backend/public/index.php backend/worker.php frontend/package.json backend/database/migrate.php; do
    [ -f "$APP_DIR/$marker" ] || die "$APP_DIR does not look like an AccountCheck checkout (no $marker)."
done

grep -q '"name": "accountcheck-frontend"' "$APP_DIR/frontend/package.json" \
    || die "$APP_DIR/frontend/package.json is not AccountCheck's."

step "AccountCheck deployment"
ok "Application directory: $APP_DIR"
[ "$CHECK_ONLY" -eq 1 ] && warn "--check: nothing will be changed."

# ---------------------------------------------------------------------------
# Preflight.
# ---------------------------------------------------------------------------
step "Checking prerequisites"

command -v php  >/dev/null || die "php is not installed."
command -v node >/dev/null || die "node is not installed (build tooling only; the backend is PHP)."
command -v npm  >/dev/null || die "npm is not installed."

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP 8.2+ required; found $PHP_VERSION."
ok "PHP $PHP_VERSION"

MISSING_EXTENSIONS=""
for extension in pdo_mysql mbstring curl json openssl; do
    php -m | grep -qix "$extension" || MISSING_EXTENSIONS="$MISSING_EXTENSIONS $extension"
done
[ -z "$MISSING_EXTENSIONS" ] || die "Missing PHP extensions:$MISSING_EXTENSIONS"
ok "PHP extensions present"

ok "Node $(node --version)"

[ -f "$APP_DIR/backend/.env" ] || die "backend/.env is missing. Copy .env.example and fill it in."
ok "backend/.env found"

# ---------------------------------------------------------------------------
# Environment sanity.
#
# These are the settings that are merely inconvenient in development and are a
# security problem in production. Getting them wrong is easy and the
# consequence is invisible, so the script says so plainly.
# ---------------------------------------------------------------------------
step "Checking the production environment"

env_value() {
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$APP_DIR/backend/.env" \
        | head -n 1 | sed 's/[[:space:]]*#.*$//' | tr -d '"'"'" | xargs || true
}

APP_ENV_VALUE=$(env_value APP_ENV)
PROBLEMS=0
complain() { printf '    %s✗%s %s\n' "$RED" "$RESET" "$1"; PROBLEMS=$((PROBLEMS + 1)); }

if [ "$APP_ENV_VALUE" = "production" ]; then
    ok "APP_ENV=production"

    [ "$(env_value APP_DEBUG)" = "false" ] \
        && ok "APP_DEBUG=false" \
        || complain "APP_DEBUG must be false in production: an error response would carry internals."

    [ "$(env_value SESSION_COOKIE_SECURE)" = "true" ] \
        && ok "SESSION_COOKIE_SECURE=true" \
        || complain "SESSION_COOKIE_SECURE must be true: the session cookie would travel over plain HTTP."

    CSRF_VALUE=$(env_value CSRF_PROTECTION)
    [ "$CSRF_VALUE" != "false" ] \
        && ok "CSRF protection on" \
        || complain "CSRF_PROTECTION=false lets any site act as a signed-in user."

    ORIGINS=$(env_value CORS_ALLOWED_ORIGINS)
    case "$ORIGINS" in
        *'*'*) complain "CORS_ALLOWED_ORIGINS contains '*'. List exact origins." ;;
        *)     ok "CORS origins are explicit" ;;
    esac

    [ -n "$(env_value APP_KEY)" ] && ok "APP_KEY set" || complain "APP_KEY is empty."

    DB_USER_VALUE=$(env_value DB_USER)
    [ "$DB_USER_VALUE" != "root" ] \
        && ok "Database user is not root" \
        || complain "DB_USER is root. Use a dedicated user with rights on this database only."
else
    warn "APP_ENV is '$APP_ENV_VALUE', not 'production'. Production checks skipped."
fi

[ "$PROBLEMS" -eq 0 ] || die "$PROBLEMS environment problem(s) above. Fix them and run again."

# .env must not be readable by everyone, whatever else is true.
if [ "$(stat -c '%a' "$APP_DIR/backend/.env")" -gt 640 ]; then
    if ! would "chmod 640 backend/.env"; then
        chmod 640 "$APP_DIR/backend/.env"
        ok "Tightened permissions on backend/.env"
    fi
fi

# ---------------------------------------------------------------------------
# Frontend.
# ---------------------------------------------------------------------------
step "Building the frontend"

if [ "$SKIP_BUILD" -eq 1 ]; then
    [ -f "$APP_DIR/frontend/dist/index.html" ] || die "--skip-build, but frontend/dist/index.html does not exist."
    warn "Skipping the build; using the existing frontend/dist."
elif ! would "npm ci && npm run build in frontend/"; then
    (
        cd "$APP_DIR/frontend"
        # npm ci, not npm install: it installs exactly the lockfile and fails
        # if package.json and the lockfile disagree, so a deploy cannot quietly
        # pick up a different version of anything.
        if [ -f package-lock.json ]; then
            npm ci --no-audit --no-fund
        else
            warn "No package-lock.json; falling back to npm install."
            npm install --no-audit --no-fund
        fi
        npm run build
    ) || die "The frontend build failed. Nothing has been deployed."

    [ -f "$APP_DIR/frontend/dist/index.html" ] || die "The build produced no frontend/dist/index.html."
    ok "Built $(find "$APP_DIR/frontend/dist" -type f | wc -l) file(s), $(du -sh "$APP_DIR/frontend/dist" | cut -f1)"
fi

# ---------------------------------------------------------------------------
# Database.
# ---------------------------------------------------------------------------
step "Applying database migrations"

php "$APP_DIR/backend/database/migrate.php" --status || die "Could not reach the database. Check DB_* in backend/.env."

if ! would "apply any pending migrations"; then
    # The runner is additive and idempotent: it creates and alters, never
    # drops or truncates, and skips anything already recorded.
    php "$APP_DIR/backend/database/migrate.php" || die "A migration failed. The database is unchanged past the last successful one."
    ok "Migrations applied"
fi

# ---------------------------------------------------------------------------
# Storage.
# ---------------------------------------------------------------------------
step "Preparing storage"

WEB_USER="${ACCOUNTCHECK_WEB_USER:-www-data}"

if ! would "create backend/storage/{logs,uploads,exports} and set ownership to $WEB_USER"; then
    for directory in logs uploads exports; do
        mkdir -p "$APP_DIR/backend/storage/$directory"
    done

    if id -u "$WEB_USER" >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/backend/storage"
        ok "Storage owned by $WEB_USER"
    else
        warn "Not root, or $WEB_USER does not exist; leaving ownership alone."
    fi

    # Writable by owner and group, readable by nobody else: an export is
    # somebody's list of addresses.
    chmod -R 770 "$APP_DIR/backend/storage"
    ok "Storage permissions set"
fi

# Nothing under backend/ except public/ should be reachable, and the deploy
# should not leave a development dependency tree on a production box.
if [ -d "$APP_DIR/backend/vendor" ] && [ "$APP_ENV_VALUE" = "production" ]; then
    warn "backend/vendor exists. The runtime needs no packages; it is dev-only (PHPUnit)."
fi

# ---------------------------------------------------------------------------
# Services.
#
# Only AccountCheck's own. Nginx is never touched: reloading it is a manual,
# reviewed step, because this server may be hosting something else.
# ---------------------------------------------------------------------------
step "Reloading services"

if ! command -v systemctl >/dev/null; then
    warn "systemd not available; reload PHP-FPM and restart the worker yourself."
else
    FPM_UNIT="php${PHP_VERSION}-fpm"

    if systemctl list-unit-files | grep -q "^${FPM_UNIT}\."; then
        if ! would "systemctl reload $FPM_UNIT"; then
            # Reload, not restart: opcache runs with validate_timestamps off,
            # so it has to be told, but in-flight requests should still finish.
            sudo systemctl reload "$FPM_UNIT" && ok "Reloaded $FPM_UNIT" \
                || warn "Could not reload $FPM_UNIT. New code may not be live until you do."
        fi
    else
        warn "$FPM_UNIT is not installed. If PHP-FPM runs under another name, reload it yourself."
    fi

    if systemctl list-unit-files | grep -q '^accountcheck-worker\.service'; then
        if ! would "systemctl restart accountcheck-worker"; then
            # SIGTERM finishes the current chunk and hands an unfinished job
            # back to the queue, so no work is lost across a restart.
            sudo systemctl restart accountcheck-worker && ok "Restarted the worker" \
                || warn "Could not restart accountcheck-worker."
        fi
    else
        warn "accountcheck-worker.service is not installed; see docs/DEPLOYMENT.md."
    fi
fi

# ---------------------------------------------------------------------------
# Verify.
# ---------------------------------------------------------------------------
step "Checking the deployment"

if [ "$CHECK_ONLY" -eq 1 ]; then
    printf '\n%sCheck complete. Nothing was changed.%s\n\n' "$BOLD" "$RESET"
    exit 0
fi

APP_URL_VALUE=$(env_value APP_URL)

if [ -n "$APP_URL_VALUE" ] && command -v curl >/dev/null; then
    for endpoint in health health/database health/worker; do
        RESPONSE=$(curl -fsS --max-time 10 "${APP_URL_VALUE%/}/api/$endpoint" 2>/dev/null || true)

        case "$RESPONSE" in
            '')             warn "/api/$endpoint did not answer." ;;
            *'"success":true'*) ok "/api/$endpoint" ;;
            *)              warn "/api/$endpoint answered, but not healthy: $RESPONSE" ;;
        esac
    done
else
    warn "APP_URL is empty or curl is unavailable; check /api/health yourself."
fi

printf '\n%sDeployed.%s\n\n' "$GREEN$BOLD" "$RESET"
printf 'If this is a first install, the server configuration is still manual:\n'
printf '  deploy/nginx/accountcheck.conf     the site\n'
printf '  deploy/php/accountcheck-fpm.conf   the PHP-FPM pool\n'
printf '  deploy/systemd/                    the worker unit\n'
printf 'See docs/DEPLOYMENT.md.\n\n'
