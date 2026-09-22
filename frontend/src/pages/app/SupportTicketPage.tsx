import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Card } from '@/components/Card';
import { TicketStatusBadge } from '@/components/StatusBadge';
import { useToast } from '@/components/ToastProvider';
import { Alert, ErrorState, LoadingState } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { closeTicket, getTicket, replyToTicket } from '@/services/support';
import type { SupportThread } from '@/types/api';
import { formatDateTime } from '@/utils/format';

/**
 * One ticket and its conversation.
 *
 * Replies from us are attributed to the team rather than to an individual
 * agent, which is how the API returns them.
 */
export function SupportTicketPage() {
  const { id = '' } = useParams();
  const toast = useToast();

  const { data, error, reload, setData } = useApiResource<SupportThread>(
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
    } catch (replyError) {
      toast.error(replyError instanceof ApiError ? replyError.message : 'That reply could not be sent.');
    } finally {
      setBusy(false);
    }
  }

  async function handleClose() {
    setBusy(true);

    try {
      await closeTicket(id);
      toast.success('Ticket closed.');
      reload();
    } catch (closeError) {
      toast.error(closeError instanceof ApiError ? closeError.message : 'That ticket could not be closed.');
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
          <p className="ac-page-header__subtitle">Opened {formatDateTime(ticket.created_at)}</p>
        </div>
        <div className="ac-page-header__actions">
          <TicketStatusBadge status={ticket.status} />
          <Link to="/support" className="ac-btn ac-btn--ghost">
            All tickets
          </Link>
        </div>
      </header>

      {ticket.status === 'PENDING' && (
        <Alert tone="info">We have replied. Add anything else below, or close the ticket if it is sorted.</Alert>
      )}

      <Card flush title="Conversation">
        <ol className="ac-thread">
          {messages.map((message) => (
            <li key={message.id} className={message.is_staff ? 'ac-message ac-message--staff' : 'ac-message'}>
              <p className="ac-message__meta">
                <span className="ac-message__author">{message.author_name}</span>
                <span className="ac-muted">{formatDateTime(message.created_at)}</span>
              </p>
              <p className="ac-message__body">{message.body}</p>
            </li>
          ))}
        </ol>
      </Card>

      {ticket.can_reply ? (
        <Card title="Reply">
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
              placeholder="Add anything that would help us answer."
            />
            <div className="ac-row">
              <button type="submit" className="ac-btn ac-btn--primary" disabled={busy}>
                {busy ? 'Sending…' : 'Send reply'}
              </button>
              <button type="button" className="ac-btn ac-btn--secondary" onClick={handleClose} disabled={busy}>
                Close this ticket
              </button>
            </div>
          </form>
        </Card>
      ) : (
        <Alert tone="info">
          This ticket is closed. Open a new one from the <Link to="/support">support page</Link> and we will
          pick it up there.
        </Alert>
      )}
    </>
  );
}
