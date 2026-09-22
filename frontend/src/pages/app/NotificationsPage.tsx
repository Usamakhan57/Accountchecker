import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Pagination } from '@/components/Pagination';
import { useToast } from '@/components/ToastProvider';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { dismiss, listNotifications, markAllRead, markRead } from '@/services/notifications';
import type { NotificationList } from '@/types/api';
import { formatRelative } from '@/utils/format';

/**
 * Notifications.
 *
 * Written by the application when something happens to your own work, never by
 * a request, so there is nothing here that could notify anybody else.
 */
export function NotificationsPage() {
  usePageMeta({ title: 'Notifications', noIndex: true, canonicalPath: '/notifications' });

  const toast = useToast();
  const { refresh } = useAuth();
  const [page, setPage] = useState(1);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [busy, setBusy] = useState(false);

  const { data, loading, error, reload } = useApiResource<NotificationList>(
    (signal) => listNotifications({ page, per_page: 25, unread: unreadOnly }, signal),
    [page, unreadOnly],
  );

  const items = data?.items ?? [];

  // The bell in the top bar reads its count from the session, so anything that
  // changes the unread count refreshes it too rather than leaving the two
  // disagreeing until the next page load.
  async function act(action: () => Promise<unknown>, message?: string) {
    setBusy(true);

    try {
      await action();
      if (message) {
        toast.success(message);
      }
      reload();
      await refresh();
    } catch (actionError) {
      toast.error(actionError instanceof ApiError ? actionError.message : 'That could not be done.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Notifications</h1>
          <p className="ac-page-header__subtitle">
            {data && data.unread_count > 0
              ? `${data.unread_count} unread.`
              : 'Job results and support replies land here.'}
          </p>
        </div>
        <div className="ac-page-header__actions">
          <button
            type="button"
            className="ac-btn ac-btn--secondary"
            disabled={busy || (data?.unread_count ?? 0) === 0}
            onClick={() => void act(markAllRead, 'All marked as read.')}
          >
            Mark all as read
          </button>
        </div>
      </header>

      <Card
        flush
        footer={
          data && (
            <Pagination pagination={data.pagination} onPageChange={setPage} disabled={loading} />
          )
        }
      >
        <div className="ac-filter-bar">
          <label className="ac-row" style={{ gap: '0.5rem', fontSize: 'var(--ac-text-sm)' }}>
            <input
              type="checkbox"
              checked={unreadOnly}
              onChange={(event) => {
                setUnreadOnly(event.target.checked);
                setPage(1);
              }}
            />
            <span>Unread only</span>
          </label>
        </div>

        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={6} columns={2} />
        ) : items.length === 0 ? (
          <EmptyState
            title={unreadOnly ? 'Nothing unread' : 'No notifications yet'}
            body={
              unreadOnly
                ? 'Everything here has been read.'
                : 'When a job finishes or support replies, you will see it here.'
            }
          />
        ) : (
          <ul className="ac-notification-list">
            {items.map((item) => (
              <li key={item.id} className={item.read_at ? 'ac-notification' : 'ac-notification is-unread'}>
                <div className="ac-notification__body">
                  <p className="ac-notification__title">
                    {!item.read_at && <span className="ac-notification__dot" aria-label="Unread" />}
                    {item.link ? <Link to={item.link}>{item.title}</Link> : item.title}
                  </p>
                  <p className="ac-notification__text">{item.body}</p>
                  <p className="ac-notification__meta">{formatRelative(item.created_at)}</p>
                </div>

                <div className="ac-notification__actions">
                  {!item.read_at && (
                    <button
                      type="button"
                      className="ac-btn ac-btn--ghost ac-btn--sm"
                      disabled={busy}
                      onClick={() => void act(() => markRead(item.id))}
                    >
                      Mark read
                    </button>
                  )}
                  <button
                    type="button"
                    className="ac-btn ac-btn--ghost ac-btn--sm"
                    disabled={busy}
                    aria-label={`Remove "${item.title}"`}
                    onClick={() => void act(() => dismiss(item.id))}
                  >
                    <Icon name="trash" size={14} />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  );
}
