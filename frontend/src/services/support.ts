import { request } from '@/services/apiClient';
import type { Paginated, SupportThread, SupportTicket, TicketStatus } from '@/types/api';

/**
 * Support tickets.
 *
 * Every call is scoped to the signed-in account on the server. A ticket that is
 * not yours is reported as missing rather than forbidden, so an id cannot be
 * confirmed by the shape of the refusal.
 */

export function listTickets(
  options: { page?: number; per_page?: number; status?: TicketStatus | '' } = {},
  signal?: AbortSignal,
): Promise<Paginated<SupportTicket>> {
  return request<Paginated<SupportTicket>>('/api/support/tickets', {
    query: { page: options.page, per_page: options.per_page, status: options.status || undefined },
    signal,
  });
}

export function openTicket(input: {
  subject: string;
  body: string;
  priority?: 'LOW' | 'NORMAL' | 'HIGH';
}): Promise<SupportThread> {
  return request<SupportThread>('/api/support/tickets', { method: 'POST', body: input });
}

export function getTicket(uuid: string, signal?: AbortSignal): Promise<SupportThread> {
  return request<SupportThread>(`/api/support/tickets/${encodeURIComponent(uuid)}`, { signal });
}

export function replyToTicket(uuid: string, body: string): Promise<SupportThread> {
  return request<SupportThread>(`/api/support/tickets/${encodeURIComponent(uuid)}/reply`, {
    method: 'POST',
    body: { body },
  });
}

export function closeTicket(uuid: string): Promise<{ ticket: SupportTicket }> {
  return request<{ ticket: SupportTicket }>(`/api/support/tickets/${encodeURIComponent(uuid)}/close`, {
    method: 'POST',
  });
}
