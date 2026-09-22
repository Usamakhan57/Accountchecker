import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Card } from '@/components/Card';
import { TicketStatusBadge } from '@/components/StatusBadge';
import { useToast } from '@/components/ToastProvider';
import { ErrorState, LoadingState } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { getTicket, replyToTicket, setTicketStatus } from '@/services/admin';
import { ApiError } from '@/services/apiClient';
import type { AdminTicketThread } from '@/types/api';
import { formatDateTime } from '@/utils/format';

/**
 * Answering one ticket.
 *
 * A reply is attributed to the team, not to the agent who wrote it: the
 * customer reads "AccountCheck support". The agent's account is recorded on the
 * row so the internal trail exists.
 */
export function AdminTicketPage() {
  const { id = '' } = useParams();
  const toast = useToast();

  const { data, error, reload, setData } = useApiResource<AdminTicketThread>(
    (signal) => getTicket(id, signal),
    [id],
  );

  usePageMeta({ title: data?.ticket.subject ?? 'Ticket', noIndex: true });

  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleReply(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);

    try {
      const thread = await replyToTicket(id, body.trim());
      setData(() => thread);
      setBody('');
      toast.success('Reply sent. They have been notified.');
    } catch (replyError) {
      toast.error(replyError instanceof ApiError ? replyError.message : 'That reply could not be sent.');
    } finally {
      setBusy(false);
    }
  }

  async function changeStatus(status: string, message: string) {
    setBusy(true);

    try {
      await setTicketStatus(id, status);
      toast.success(message);
      reload();
    } catch (statusError) {
      toast.error(statusError instanceof ApiError ? statusError.message : 'That could not be changed.');
    } finally {
      setBusy(false);
    }
  }

  if (error) {
    return <ErrorState message={error} onRetry={reload} />;
  }

  if (!data) {
    return <LoadingState label="Loading the ticket…" />;
  }

  const { ticket, messages } = data;

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">{ticket.subject}</h1>
          <p className="ac-page-header__subtitle">
            From <Link to={`/admin/users/${ticket.user.uuid}`}>{ticket.user.email}</Link> ·{' '}
            {formatDateTime(ticket.created_at)}
          </p>
        </div>
        <div className="ac-page-header__actions">
          <TicketStatusBadge status={ticket.status} />
          <Link to="/admin/support" className="ac-btn ac-btn--ghost">
            Back to the queue
          </Link>
        </div>
      </header>

      <Card flush title="Conversation">
        <ol className="ac-thread">
          {messages.map((message) => (
            <li key={message.id} className={message.is_staff ? 'ac-message ac-message--staff' : 'ac-message'}>
              <p className="ac-message__meta">
                <span className="ac-message__author">
                  {message.is_staff ? 'Support' : ticket.user.name}
                </span>
                <span className="ac-muted">{formatDateTime(message.created_at)}</span>
              </p>
              <p className="ac-message__body">{message.body}</p>
            </li>
          ))}
        </ol>
      </Card>

      <Card title="Reply" subtitle="They will see this as coming from AccountCheck support, not from you.">
        <form onSubmit={handleReply} className="ac-stack">
          <textarea
            className="ac-textarea ac-textarea--prose"
            rows={5}
            value={body}
            onChange={(event) => setBody(event.target.value)}
            minLength={2}
            maxLength={5000}
            required
            aria-label="Your reply"
          />
          <div className="ac-row">
            <button type="submit" className="ac-btn ac-btn--primary" disabled={busy}>
              {busy ? 'Sending…' : 'Send reply'}
            </button>
            <button
              type="button"
              className="ac-btn ac-btn--secondary"
              disabled={busy || ticket.status === 'RESOLVED'}
              onClick={() => void changeStatus('RESOLVED', 'Marked resolved. They have been notified.')}
            >
              Mark resolved
            </button>
            <button
              type="button"
              className="ac-btn ac-btn--ghost"
              disabled={busy || ticket.status === 'CLOSED'}
              onClick={() => void changeStatus('CLOSED', 'Ticket closed.')}
            >
              Close
            </button>
          </div>
        </form>
      </Card>
    </>
  );
}
