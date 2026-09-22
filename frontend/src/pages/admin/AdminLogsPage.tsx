import { useState } from 'react';
import { Card } from '@/components/Card';
import { Pagination } from '@/components/Pagination';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listLogs } from '@/services/admin';
import type { AuditList } from '@/types/api';
import { formatDateTime } from '@/utils/format';

const SEVERITY_TONE: Record<string, string> = {
  INFO: 'neutral',
  NOTICE: 'info',
  WARNING: 'unknown',
  CRITICAL: 'invalid',
};

/**
 * The audit log.
 *
 * Read-only. There is no delete control here and no endpoint behind one: a log
 * an administrator can edit is not a log.
 */
export function AdminLogsPage() {
  usePageMeta({ title: 'Audit log', noIndex: true, canonicalPath: '/admin/logs' });

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [event, setEvent] = useState('');
  const [severity, setSeverity] = useState('');

  const { data, loading, error, reload } = useApiResource<AuditList>(
    (signal) => listLogs({ page, per_page: perPage, event, severity }, signal),
    [page, perPage, event, severity],
  );

  const items = data?.items ?? [];

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Audit log</h1>
          <p className="ac-page-header__subtitle">
            Who did what, and from where. Entries cannot be edited or removed from here.
          </p>
        </div>
      </header>

      <Card
        flush
        title="Events"
        footer={
          data && (
            <Pagination
              pagination={data.pagination}
              onPageChange={setPage}
              onPerPageChange={(size) => {
                setPerPage(size);
                setPage(1);
              }}
              disabled={loading}
            />
          )
        }
      >
        <div className="ac-filter-bar">
          <label className="ac-row" style={{ gap: '0.5rem', fontSize: 'var(--ac-text-sm)' }}>
            <span className="ac-muted">Event</span>
            <select
              className="ac-select"
              style={{ width: 'auto' }}
              value={event}
              onChange={(changed) => {
                setEvent(changed.target.value);
                setPage(1);
              }}
            >
              <option value="">Everything</option>
              {(data?.events ?? []).map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </label>

          <label className="ac-row" style={{ gap: '0.5rem', fontSize: 'var(--ac-text-sm)' }}>
            <span className="ac-muted">Severity</span>
            <select
              className="ac-select"
              style={{ width: 'auto' }}
              value={severity}
              onChange={(changed) => {
                setSeverity(changed.target.value);
                setPage(1);
              }}
            >
              <option value="">Any</option>
              <option value="INFO">Info</option>
              <option value="NOTICE">Notice</option>
              <option value="WARNING">Warning</option>
              <option value="CRITICAL">Critical</option>
            </select>
          </label>
        </div>

        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={10} columns={5} />
        ) : items.length === 0 ? (
          <EmptyState title="Nothing logged yet" body="Administrative actions and security events appear here." />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">Event</th>
                  <th scope="col">Who</th>
                  <th scope="col">Details</th>
                  <th scope="col">From</th>
                </tr>
              </thead>
              <tbody>
                {items.map((entry) => (
                  <tr key={entry.id}>
                    <td className="ac-muted">{formatDateTime(entry.created_at)}</td>
                    <td>
                      <span className={`ac-badge ac-badge--${SEVERITY_TONE[entry.severity] ?? 'neutral'}`}>
                        {entry.severity}
                      </span>{' '}
                      {entry.event}
                    </td>
                    <td className="ac-muted">{entry.actor_email ?? 'system'}</td>
                    <td className="ac-muted" style={{ fontSize: 'var(--ac-text-xs)' }}>
                      {entry.context ? JSON.stringify(entry.context) : '—'}
                    </td>
                    <td className="ac-muted">{entry.ip_address ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}
