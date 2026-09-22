# AccountCheck

Batch account and data verification, built as a React + Vite single-page app on
top of a PHP REST API with a MySQL-backed job queue and a CLI worker.

AccountCheck verifies records **only through official and authorized APIs and
publicly permitted data sources**. Where a platform offers no authorized
verification method, a check reports `UNAVAILABLE` rather than attempting a
workaround. See [docs/SECURITY.md](docs/SECURITY.md).

## Architecture

```
React + Vite (static)  ─┐
                        ├─ Nginx ─┬─ /            → frontend/dist
PHP REST API (PHP-FPM) ─┘         └─ /api         → backend/public/index.php
                                                      │
                                                      ▼
                                                   MySQL 8
                                                      │
                                              database job queue
                                                      │
                                                      ▼
                                          php worker.php (CLI)
                                                      │
                                                      ▼
                                       authorized checker APIs
```

## Requirements

| Component | Version |
| --- | --- |
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `curl`, `json`, `openssl` |
| MySQL | 8.0+ |
| Node.js | 18.18+ (build tooling only — **not** the production backend) |
| Composer | 2.x (dev dependencies only; the runtime has none) |

## Repository layout

```
backend/      PHP REST API, queue, worker, tests
  bootstrap/  autoloader + container wiring
  config/     configuration files (read from the environment)
  database/   schema.sql and numbered migrations
  public/     the only web-reachable directory (index.php)
  routes/     API route table
  src/        Controllers, Services, Repositories, Checkers, Middleware, …
  storage/    logs, uploads, exports (writable, never web-reachable)
  worker.php  CLI queue worker
frontend/     React 18 + TypeScript + Vite single-page app
docs/         API, security, deployment, backup and troubleshooting guides
scripts/      deployment and maintenance scripts
```

## Getting started

```bash
# 1. Backend environment
cp .env.example backend/.env
#    then set DB_* and, if you have them, the authorized provider credentials

# 2. Database
mysql -u root -p -e "CREATE DATABASE accountcheck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php backend/database/migrate.php

# 3. API (development)
php -S 127.0.0.1:8080 -t backend/public backend/public/index.php

# 4. Worker (separate shell)
php backend/worker.php

# 5. Frontend
cp frontend/.env.example frontend/.env
cd frontend && npm install && npm run dev
```

The API answers at `http://127.0.0.1:8080/api/health`; the app runs at
`http://localhost:5173`.

## Checker modes

`CHECKER_MODE=mock` (the default, and what the test suite uses) runs a
deterministic local engine: no outbound requests, stable results for the same
input. `CHECKER_MODE=production` requires authorized provider credentials per
checker; a checker with none configured reports `UNAVAILABLE`.

## Checks

```bash
cd frontend && npm run build      # type-check and production build
cd frontend && npm run lint
cd backend  && composer test      # PHPUnit
cd backend  && composer lint      # php -l over every source file
```

## Documentation

- [docs/API.md](docs/API.md) — endpoints, payloads and error codes
- [docs/SECURITY.md](docs/SECURITY.md) — the security model and its boundaries
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Ubuntu 24.04 / Nginx / PHP-FPM
- [docs/BACKUP.md](docs/BACKUP.md) — backup and restore
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) — common failures

## Deployment

Nothing here deploys itself. `scripts/deploy.sh` is a reviewed, idempotent
script that operates only inside the application directory; see
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the full server preparation.
