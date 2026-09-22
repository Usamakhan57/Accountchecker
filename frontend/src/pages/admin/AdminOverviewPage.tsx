import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { JobStatusBadge } from '@/components/StatusBadge';
import { StatTile } from '@/components/StatTile';
import { ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { getOverview } from '@/services/admin';
import type { AdminOverview } from '@/types/api';
import { formatDateTime, formatNumber, formatRelative } from '@/utils/format';

/**
 * The administrator's landing view.
 *
 * Every number is counted at request time. Where there is nothing to report the
 * page says so rather than showing a zero that could be mistaken for a reading.
 */
export function AdminOverviewPage() {
  usePageMeta({ title: 'Administration', noIndex: true, canonicalPath: '/admin' });

  const { data, loading, error, reload } = useApiResource<AdminOverview>((signal) => getOverview(signal), []);

  const worker = data?.health.worker;
  const workerOk = worker?.status === 'ok';

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Administration</h1>
          <p className="ac-page-header__subtitle">The state of the installation right now.</p>
        </div>
      </header>

      {error ? (
        <ErrorState message={error} onRetry={reload} />
      ) : (
        <>
          <div className="ac-grid ac-grid--stats">
            <StatTile
              label="Accounts"
              value={formatNumber(data?.totals.users.total ?? 0)}
              meta={`${formatNumber(data?.totals.users.new_this_week ?? 0)} joined this week`}
              loading={loading && !data}
            />
            <StatTile
              label="Records checked"
              value={formatNumber(data?.totals.jobs.checks ?? 0)}
              meta={`${formatNumber(data?.totals.jobs.total ?? 0)} jobs all told`}
              loading={loading && !data}
            />
            <StatTile
              label="Credits outstanding"
              value={formatNumber(data?.totals.credits.outstanding ?? 0)}
              meta={`${formatNumber(data?.totals.credits.reserved ?? 0)} held by running jobs`}
              loading={loading && !data}
            />
            <StatTile
              label="Suspended accounts"
              value={formatNumber(data?.totals.users.suspended ?? 0)}
              tone={(data?.totals.users.suspended ?? 0) > 0 ? 'invalid' : 'plain'}
              loading={loading && !data}
            />
          </div>

          <Card title="Queue and worker" subtitle="Read from the worker's own heartbeat, not inferred.">
            {loading && !data ? (
              <TableSkeleton rows={2} columns={4} />
            ) : (
              <div className="ac-grid ac-grid--stats">
                <StatTile
                  label="Worker"
                  value={workerOk ? 'Running' : 'Not running'}
                  meta={
                    // A worker removes its own heartbeat when it shuts down
                    // cleanly, so a missing row means "none running now", not
                    // "none ever".
                    worker?.seconds_since_heartbeat === null || worker?.seconds_since_heartbeat === undefined
                      ? 'No worker is checked in'
                      : `Last checked in ${worker.seconds_since_heartbeat}s ago`
                  }
                  tone={workerOk ? 'valid' : 'invalid'}
                />
                <StatTile
                  label="Records waiting"
                  value={formatNumber(data?.health.queue.pending_items ?? 0)}
                  meta={workerOk ? 'Being worked through' : 'Nothing is processing them'}
                />
                <StatTile
                  label="Stalled jobs"
                  value={formatNumber(data?.health.queue.stalled_jobs ?? 0)}
                  meta="Claimed but past their lease"
                  tone={(data?.health.queue.stalled_jobs ?? 0) > 0 ? 'invalid' : 'plain'}
                />
                <StatTile
                  label="Errors in the last hour"
                  value={formatNumber(data?.health.errors_last_hour ?? 0)}
                  tone={(data?.health.errors_last_hour ?? 0) > 0 ? 'unknown' : 'plain'}
                />
              </div>
            )}
          </Card>

          <div className="ac-grid ac-grid--halves">
            <Card
              flush
              title="Latest jobs"
              actions={
                <Link to="/admin/jobs" className="ac-btn ac-btn--ghost ac-btn--sm">
                  All jobs
                </Link>
              }
            >
              {loading && !data ? (
                <TableSkeleton rows={5} columns={3} />
              ) : (
                <div className="ac-table-wrap">
                  <table className="ac-table">
                    <tbody>
                      {(data?.recent_jobs ?? []).map((job) => (
                        <tr key={job.id}>
                          <td>
                            <Link to={`/admin/users/${job.user.uuid}`}>{job.user.email}</Link>
                          </td>
                          <td className="ac-muted">{job.checker_label}</td>
                          <td className="ac-table__numeric">{formatNumber(job.total_items)}</td>
                          <td>
                            <JobStatusBadge status={job.status} />
                          </td>
                          <td className="ac-muted">{formatRelative(job.created_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>

            <Card
              flush
              title="Recent admin activity"
              actions={
                <Link to="/admin/logs" className="ac-btn ac-btn--ghost ac-btn--sm">
                  Full log
                </Link>
              }
            >
              {loading && !data ? (
                <TableSkeleton rows={5} columns={3} />
              ) : (
                <div className="ac-table-wrap">
                  <table className="ac-table">
                    <tbody>
                      {(data?.recent_events ?? []).map((event) => (
                        <tr key={event.id}>
                          <td>{event.event}</td>
                          <td className="ac-muted">{event.actor_email ?? 'system'}</td>
                          <td className="ac-muted">{formatDateTime(event.created_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          </div>

          <Card flush title="Checker usage, last 30 days">
            {loading && !data ? (
              <TableSkeleton rows={6} columns={5} />
            ) : (
              <div className="ac-table-wrap">
                <table className="ac-table">
                  <thead>
                    <tr>
                      <th scope="col">Checker</th>
                      <th scope="col" className="ac-table__numeric">
                        Checked
                      </th>
                      <th scope="col" className="ac-table__numeric">
                        Valid
                      </th>
                      <th scope="col" className="ac-table__numeric">
                        Unavailable
                      </th>
                      <th scope="col" className="ac-table__numeric">
                        Errors
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {(data?.checker_usage ?? []).map((row) => (
                      <tr key={row.slug}>
                        <td>{row.label}</td>
                        <td className="ac-table__numeric">{formatNumber(row.checks)}</td>
                        <td className="ac-table__numeric">{formatNumber(row.valid)}</td>
                        <td className="ac-table__numeric">{formatNumber(row.unavailable)}</td>
                        <td className="ac-table__numeric">{formatNumber(row.errors)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </>
  );
}
