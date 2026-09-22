import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Pagination } from '@/components/Pagination';
import { TicketStatusBadge } from '@/components/StatusBadge';
import { useToast } from '@/components/ToastProvider';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { listTickets, openTicket } from '@/services/support';
import type { Paginated, SupportTicket } from '@/types/api';
import { formatRelative, pluralize } from '@/utils/format';

/**
 * Support.
 *
 * The form opens a real ticket that a person answers. There is no chatbot here
 * and nothing that pretends to answer instantly.
 */
export function SupportPage() {
  usePageMeta({ title: 'Support', noIndex: true, canonicalPath: '/support' });

  const toast = useToast();
  const [page, setPage] = useState(1);
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [priority, setPriority] = useState<'LOW' | 'NORMAL' | 'HIGH'>('NORMAL');
  const [sending, setSending] = useState(false);

  const { data, loading, error, reload } = useApiResource<Paginated<SupportTicket>>(
    (signal) => listTickets({ page, per_page: 25 }, signal),
    [page],
  );

  const items = data?.items ?? [];

  async function handleOpen(event: React.FormEvent) {
    event.preventDefault();
    setSending(true);

    try {
      await openTicket({ subject: subject.trim(), body: body.trim(), priority });
      toast.success('Ticket opened. We will reply here.');
      setSubject('');
      setBody('');
      setPriority('NORMAL');
      setPage(1);
      reload();
    } catch (openError) {
      toast.error(openError instanceof ApiError ? openError.message : 'That ticket could not be opened.');
    } finally {
      setSending(false);
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Support</h1>
          <p className="ac-page-header__subtitle">
            Ask a question and we will answer in the thread. Replies also arrive as a notification.
          </p>
        </div>
      </header>

      <div className="ac-grid ac-grid--halves">
        <Card title="Ask a question">
          <form onSubmit={handleOpen} className="ac-stack">
            <div>
              <label className="ac-field__label" htmlFor="ac-ticket-subject">
                What is it about?
              </label>
              <input
                id="ac-ticket-subject"
                type="text"
                className="ac-input"
                value={subject}
                onChange={(event) => setSubject(event.target.value)}
                minLength={4}
                maxLength={160}
                required
                placeholder="A job that finished with unexpected results"
              />
            </div>

            <div>
              <label className="ac-field__label" htmlFor="ac-ticket-body">
                Tell us what happened
              </label>
              <textarea
                id="ac-ticket-body"
                className="ac-textarea ac-textarea--prose"
                rows={6}
                value={body}
                onChange={(event) => setBody(event.target.value)}
                minLength={10}
                maxLength={5000}
                required
                placeholder="Include the job, what you expected and what you saw. Do not include passwords."
              />
              <span className="ac-field__hint">
                Never send passwords or API keys. We will never ask you for them.
              </span>
            </div>

            <div>
              <label className="ac-field__label" htmlFor="ac-ticket-priority">
                How urgent is it?
              </label>
              <select
                id="ac-ticket-priority"
                className="ac-select"
                value={priority}
                onChange={(event) => setPriority(event.target.value as 'LOW' | 'NORMAL' | 'HIGH')}
              >
                <option value="LOW">Low, whenever you get to it</option>
                <option value="NORMAL">Normal</option>
                <option value="HIGH">High, it is blocking me</option>
              </select>
            </div>

            <button type="submit" className="ac-btn ac-btn--primary" disabled={sending}>
              {sending ? 'Opening…' : 'Open a ticket'}
            </button>
          </form>
        </Card>

        <Card
          flush
          title="Your tickets"
          footer={
            data && data.pagination.total > 0 ? (
              <Pagination pagination={data.pagination} onPageChange={setPage} disabled={loading} />
            ) : undefined
          }
        >
          {error ? (
            <ErrorState message={error} onRetry={reload} />
          ) : loading && !data ? (
            <TableSkeleton rows={4} columns={3} />
          ) : items.length === 0 ? (
            <EmptyState title="No tickets yet" body="Anything you ask will be listed here with our replies." />
          ) : (
            <ul className="ac-ticket-list">
              {items.map((ticket) => (
                <li key={ticket.uuid} className="ac-ticket">
                  <div className="ac-ticket__main">
                    <Link to={`/support/${ticket.uuid}`} className="ac-ticket__subject">
                      {ticket.subject}
                    </Link>
                    <span className="ac-muted">
                      {ticket.message_count} {pluralize(ticket.message_count, 'message')} ·{' '}
                      {ticket.last_reply_at
                        ? `last reply ${formatRelative(ticket.last_reply_at)}`
                        : `opened ${formatRelative(ticket.created_at)}`}
                    </span>
                  </div>
                  <TicketStatusBadge status={ticket.status} />
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  );
}
