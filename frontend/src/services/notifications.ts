import { request } from '@/services/apiClient';
import type { NotificationList } from '@/types/api';

/**
 * Notifications.
 *
 * Read and dismiss only. Notifications are written by the application when
 * something happens to your own work; there is no endpoint that creates one, so
 * nothing here can notify anybody.
 */

export function listNotifications(
  options: { page?: number; per_page?: number; unread?: boolean } = {},
  signal?: AbortSignal,
): Promise<NotificationList> {
  return request<NotificationList>('/api/notifications', {
    query: { page: options.page, per_page: options.per_page, unread: options.unread ? 1 : undefined },
    signal,
  });
}

export function markRead(id: number): Promise<{ unread_count: number }> {
  return request<{ unread_count: number }>(`/api/notifications/${id}/read`, { method: 'POST' });
}

export function markAllRead(): Promise<{ marked: number; unread_count: number }> {
  return request<{ marked: number; unread_count: number }>('/api/notifications/read-all', { method: 'POST' });
}

export function dismiss(id: number): Promise<{ unread_count: number }> {
  return request<{ unread_count: number }>(`/api/notifications/${id}`, { method: 'DELETE' });
}
