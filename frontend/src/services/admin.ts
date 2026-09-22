import { request } from '@/services/apiClient';
import type {
  AdminChecker,
  AdminJobList,
  AdminOverview,
  AdminTicket,
  AdminTicketThread,
  AdminTransactionList,
  AdminUserDetail,
  AdminUserList,
  AuditList,
  Paginated,
  PaymentStatus,
  Plan,
  SystemSetting,
} from '@/types/api';

/**
 * The administration API.
 *
 * Everything here is behind AdminMiddleware on the server, which answers a
 * non-administrator with 404. The client-side route guard mirrors that, but the
 * server is the boundary: these functions would fail for a non-admin whatever
 * the browser decided to render.
 */

export function getOverview(signal?: AbortSignal): Promise<AdminOverview> {
  return request<AdminOverview>('/api/admin/overview', { signal });
}

export function listUsers(
  options: { page?: number; per_page?: number; search?: string; status?: string; role?: string } = {},
  signal?: AbortSignal,
): Promise<AdminUserList> {
  return request<AdminUserList>('/api/admin/users', {
    query: {
      page: options.page,
      per_page: options.per_page,
      search: options.search || undefined,
      status: options.status || undefined,
      role: options.role || undefined,
    },
    signal,
  });
}

export function getUser(id: string, signal?: AbortSignal): Promise<AdminUserDetail> {
  return request<AdminUserDetail>(`/api/admin/users/${encodeURIComponent(id)}`, { signal });
}

export function setUserStatus(id: string, status: string) {
  return request(`/api/admin/users/${encodeURIComponent(id)}/status`, {
    method: 'PUT',
    body: { status },
  });
}

export function setUserRole(id: string, role: string) {
  return request(`/api/admin/users/${encodeURIComponent(id)}/role`, {
    method: 'PUT',
    body: { role },
  });
}

/** Signed: positive adds credits, negative removes them. A reason is required. */
export function adjustWallet(id: string, amount: number, reason: string) {
  return request(`/api/admin/users/${encodeURIComponent(id)}/wallet`, {
    method: 'POST',
    body: { amount, reason },
  });
}

export function listJobs(
  options: { page?: number; per_page?: number; status?: string; checker?: string } = {},
  signal?: AbortSignal,
): Promise<AdminJobList> {
  return request<AdminJobList>('/api/admin/jobs', {
    query: {
      page: options.page,
      per_page: options.per_page,
      status: options.status || undefined,
      checker: options.checker || undefined,
    },
    signal,
  });
}

export function cancelJob(id: number) {
  return request(`/api/admin/jobs/${id}/cancel`, { method: 'POST' });
}

export function listTransactions(
  options: { page?: number; per_page?: number; type?: string } = {},
  signal?: AbortSignal,
): Promise<AdminTransactionList> {
  return request<AdminTransactionList>('/api/admin/wallet/transactions', {
    query: { page: options.page, per_page: options.per_page, type: options.type || undefined },
    signal,
  });
}

export function listCheckers(signal?: AbortSignal): Promise<{ items: AdminChecker[] }> {
  return request<{ items: AdminChecker[] }>('/api/admin/checkers', { signal });
}

export function updateChecker(
  slug: string,
  changes: { is_enabled?: boolean; credit_cost?: number; max_batch_size?: number },
) {
  return request(`/api/admin/checkers/${encodeURIComponent(slug)}`, { method: 'PUT', body: changes });
}

export function listPlans(signal?: AbortSignal): Promise<{ items: Plan[]; payments: PaymentStatus }> {
  return request<{ items: Plan[]; payments: PaymentStatus }>('/api/admin/plans', { signal });
}

export function updatePlan(
  slug: string,
  changes: { name?: string; description?: string; price_cents?: number; credits?: number; is_active?: boolean },
) {
  return request(`/api/admin/plans/${encodeURIComponent(slug)}`, { method: 'PUT', body: changes });
}

export function listLogs(
  options: { page?: number; per_page?: number; event?: string; severity?: string } = {},
  signal?: AbortSignal,
): Promise<AuditList> {
  return request<AuditList>('/api/admin/logs', {
    query: {
      page: options.page,
      per_page: options.per_page,
      event: options.event || undefined,
      severity: options.severity || undefined,
    },
    signal,
  });
}

export function listSettings(
  signal?: AbortSignal,
): Promise<{ items: SystemSetting[]; can_edit: boolean }> {
  return request<{ items: SystemSetting[]; can_edit: boolean }>('/api/admin/settings', { signal });
}

export function updateSetting(key: string, value: string) {
  return request('/api/admin/settings', { method: 'PUT', body: { key, value } });
}

/* Support queue ----------------------------------------------------------- */

export function listTickets(
  options: { page?: number; per_page?: number; status?: string } = {},
  signal?: AbortSignal,
): Promise<Paginated<AdminTicket>> {
  return request<Paginated<AdminTicket>>('/api/admin/support', {
    query: { page: options.page, per_page: options.per_page, status: options.status || undefined },
    signal,
  });
}

export function getTicket(uuid: string, signal?: AbortSignal): Promise<AdminTicketThread> {
  return request<AdminTicketThread>(`/api/admin/support/${encodeURIComponent(uuid)}`, { signal });
}

export function replyToTicket(uuid: string, body: string): Promise<AdminTicketThread> {
  return request<AdminTicketThread>(`/api/admin/support/${encodeURIComponent(uuid)}/reply`, {
    method: 'POST',
    body: { body },
  });
}

export function setTicketStatus(uuid: string, status: string) {
  return request(`/api/admin/support/${encodeURIComponent(uuid)}/status`, {
    method: 'PUT',
    body: { status },
  });
}
