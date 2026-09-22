import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Pagination } from '@/components/Pagination';
import { TicketStatusBadge } from '@/components/StatusBadge';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listTickets } from '@/services/admin';
import type { AdminTicket, Paginated } from '@/types/api';
import { formatRelative } from '@/utils/format';

/**
 * The support queue.
 *
 * Ordered by who has waited longest for an answer, so the top of the list is
 * the ticket most overdue a reply rather than the newest one.
 */
export function AdminSupportPage() {
  usePageMeta({ title: 'Support queue', noIndex: true, canonicalPath: '/admin/support' });

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');

  const { data, loading, error, reload } = useApiResource<Paginated<AdminTicket>>(
    (signal) => listTickets({ page, per_page: 25, status }, signal),
    [page, status],
  );

  const items = data?.items ?? [];

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Support queue</h1>
          <p className="ac-page-header__subtitle">Longest wait first, open tickets before closed ones.</p>
        </div>
      </header>

      <Card
        flush
        title="Tickets"
        footer={
          data && <Pagination pagination={data.pagination} onPageChange={setPage} disabled={loading} />
        }
      >
        <div className="ac-filter-bar">
          <label className="ac-row" style={{ gap: '0.5rem', fontSize: 'var(--ac-text-sm)' }}>
            <span className="ac-muted">Status</span>
            <select
              className="ac-select"
              style={{ width: 'auto' }}
              value={status}
              onChange={(event) => {
                setStatus(event.target.value);
                setPage(1);
              }}
            >
              <option value="">Any</option>
              <option value="OPEN">Open</option>
              <option value="PENDING">Awaiting the customer</option>
              <option value="RESOLVED">Resolved</option>
              <option value="CLOSED">Closed</option>
            </select>
          </label>
        </div>

        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={8} columns={5} />
        ) : items.length === 0 ? (
          <EmptyState title="Nothing waiting" body="No tickets match this filter." />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">Subject</th>
                  <th scope="col">From</th>
                  <th scope="col">Status</th>
                  <th scope="col">Priority</th>
                  <th scope="col">Waiting since</th>
                </tr>
              </thead>
              <tbody>
                {items.map((ticket) => (
                  <tr key={ticket.uuid}>
                    <td>
                      <Link to={`/admin/support/${ticket.uuid}`}>{ticket.subject}</Link>
                    </td>
                    <td className="ac-muted">{ticket.user.email}</td>
                    <td>
                      <TicketStatusBadge status={ticket.status} />
                    </td>
                    <td className={ticket.priority === 'HIGH' ? 'ac-amount--out' : 'ac-muted'}>
                      {ticket.priority === 'HIGH' ? 'High' : ticket.priority === 'LOW' ? 'Low' : 'Normal'}
                    </td>
                    <td className="ac-muted">
                      {ticket.last_reply_at ? formatRelative(ticket.last_reply_at) : formatRelative(ticket.created_at)}
                    </td>
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
