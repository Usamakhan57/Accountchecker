# Backup and restore

What is worth backing up, how to take a backup that actually restores, and how
to verify it before you need it.

- [What to back up](#what-to-back-up)
- [Taking a backup](#taking-a-backup)
- [Automating it](#automating-it)
- [Restoring](#restoring)
- [Verifying a backup](#verifying-a-backup)
- [Retention and where to keep it](#retention-and-where-to-keep-it)

## What to back up

| | Back up? | Why |
| --- | --- | --- |
| MySQL database | **Yes** | Accounts, wallets, jobs, results, tickets, the audit log. Everything that cannot be rebuilt. |
| `backend/.env` | **Yes, separately** | Credentials. Keep it out of the database backup and out of version control. |
| `backend/storage/logs/` | Optional | Useful for an incident, not needed to restore service. |
| `backend/storage/exports/` | **No** | Generated files, deleted after `EXPORT_RETENTION_HOURS` by design. A user regenerates one in seconds. |
| `backend/storage/uploads/` | **No** | Uploads are read as text and never kept. |
| `frontend/dist/` | **No** | Rebuilt from source. |
| `vendor/`, `node_modules/` | **No** | Reinstalled from the lockfiles. |

The database is the only thing whose loss is not recoverable from the
repository. Treat everything else as rebuildable.

## Taking a backup

A dump that restores cleanly needs `--single-transaction` (so InnoDB tables are
consistent without locking the site), and `--routines --triggers --events` (so
nothing schema-side is silently dropped).

```bash
mysqldump \
  --single-transaction \
  --quick \
  --routines --triggers --events \
  --default-character-set=utf8mb4 \
  --user=accountcheck_backup --password \
  accountcheck \
  | gzip -9 > accountcheck-$(date -u +%Y%m%dT%H%M%SZ).sql.gz
```

`--quick` streams row by row instead of buffering a table in memory, which
matters once `checker_results` is large.

Use a **dedicated backup user**, not the application user and certainly not
`root`:

```sql
CREATE USER 'accountcheck_backup'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER
  ON accountcheck.* TO 'accountcheck_backup'@'localhost';
FLUSH PRIVILEGES;
```

Back up the environment file separately, encrypted:

```bash
gpg --symmetric --cipher-algo AES256 \
    --output env-$(date -u +%Y%m%d).gpg backend/.env
```

An unencrypted copy of `.env` is a copy of every credential the installation
has. Never put it next to the database dump, and never in the repository.

## Automating it

Put the credentials in a `~/.my.cnf` readable only by the backup user rather
than on the command line, where `ps` would show them:

```ini
[mysqldump]
user=accountcheck_backup
password=a-strong-password
```

```bash
chmod 600 ~/.my.cnf
```

Then a cron entry, nightly:

```cron
15 3 * * * /usr/local/bin/accountcheck-backup.sh >> /var/log/accountcheck-backup.log 2>&1
```

A backup script should fail loudly. At minimum: exit non-zero if `mysqldump`
fails, and check the resulting file is non-trivial in size before deleting
anything older.

```bash
#!/usr/bin/env bash
set -euo pipefail

DEST=/var/backups/accountcheck
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
FILE="$DEST/accountcheck-$STAMP.sql.gz"

mkdir -p "$DEST"
mysqldump --single-transaction --quick --routines --triggers --events \
          --default-character-set=utf8mb4 accountcheck | gzip -9 > "$FILE"

# A dump that is suspiciously small did not work, whatever the exit code said.
if [ "$(stat -c %s "$FILE")" -lt 10240 ]; then
    echo "Backup $FILE is too small to be real" >&2
    exit 1
fi

# Only prune once this run has produced something valid.
find "$DEST" -name 'accountcheck-*.sql.gz' -mtime +30 -delete
echo "Backed up to $FILE"
```

## Restoring

Restoring is destructive. Read this whole section first.

**1. Stop what writes to the database.**

```bash
sudo systemctl stop accountcheck-worker
sudo systemctl stop php8.2-fpm
```

Stopping the worker first matters: a worker mid-job would otherwise write
progress into a database you are replacing underneath it.

**2. Take a dump of the current state first**, even if you believe it is
broken. It costs a minute and it is the only way back if the restore turns out
to be the wrong one.

**3. Restore into a fresh database and swap**, rather than over the live one:

```bash
gunzip -c accountcheck-20260101T031500Z.sql.gz > /tmp/restore.sql

mysql -e "CREATE DATABASE accountcheck_restore
          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql accountcheck_restore < /tmp/restore.sql

# Check it before it becomes live.
mysql accountcheck_restore -e "SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM checker_jobs;"
```

If it looks right, point the application at it by changing `DB_NAME` in
`backend/.env`, or rename the databases. Keep the old one until you are sure.

**4. Apply any migrations the dump predates.**

```bash
php backend/database/migrate.php --status
php backend/database/migrate.php
```

The runner is additive and idempotent: it creates and alters, never drops or
truncates, and skips anything already recorded in `migrations`.

**5. Recover jobs that were running.**

A job claimed by a worker that no longer exists is held by a lease, not a lock.
The worker sweeps anything older than `WORKER_LOCK_TTL_SECONDS` back into the
queue on its own, so starting the worker is enough:

```bash
sudo systemctl start php8.2-fpm
sudo systemctl start accountcheck-worker
```

Nothing is lost and nothing is double-charged: the credits for an unfinished
job are still reserved, and settlement happens once, when the job reaches a
terminal state.

**6. Check it is actually serving.**

```bash
curl -s https://your-domain/api/health
curl -s https://your-domain/api/health/database
curl -s https://your-domain/api/health/worker
```

## Verifying a backup

A backup nobody has restored is a guess. Restore the most recent one into a
scratch database on a schedule — monthly is a reasonable floor — and check that
the row counts are plausible and that the application starts against it.

```bash
mysql -e "CREATE DATABASE accountcheck_verify
          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c latest.sql.gz | mysql accountcheck_verify

mysql accountcheck_verify -e "
  SELECT 'users' t, COUNT(*) n FROM users
  UNION ALL SELECT 'wallets', COUNT(*) FROM wallets
  UNION ALL SELECT 'jobs', COUNT(*) FROM checker_jobs
  UNION ALL SELECT 'results', COUNT(*) FROM checker_results
  UNION ALL SELECT 'ledger', COUNT(*) FROM wallet_transactions;"

mysql -e "DROP DATABASE accountcheck_verify;"
```

One more check worth running, because it is the invariant that matters most —
every wallet balance should equal the sum of its ledger:

```sql
SELECT w.user_id, w.balance, COALESCE(SUM(t.amount), 0) AS ledger
FROM wallets w
LEFT JOIN wallet_transactions t ON t.user_id = w.user_id
GROUP BY w.user_id, w.balance
HAVING w.balance <> ledger;
```

That should return no rows. If it does, the restore is incomplete — a dump
taken without `--single-transaction` can cut between a balance update and its
ledger entry.

## Retention and where to keep it

- Keep at least 30 daily backups, and one monthly for a year.
- Keep a copy **off the server**. A backup on the same disk as the database
  protects against a bad deploy and nothing else.
- Encrypt anything that leaves the server. The database contains email
  addresses and the lists people checked.
- Restrict the backup directory: `chmod 700`, owned by the backup user.
- Rotate the backup user's password when anyone with access to it leaves.
