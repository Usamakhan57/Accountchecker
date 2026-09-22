import { request } from '@/services/apiClient';
import type {
  HistoryPage,
  JobResultsPage,
  ResultFilters,
  ResultsPage,
} from '@/types/api';

/**
 * Results and history.
 *
 * Every list here is paged, filtered and sorted by the server. Nothing pulls a
 * whole result set into the browser: a single job can hold five thousand rows
 * and a busy account many times that, so the table asks for one page at a time
 * and the filters travel with the request rather than running over local data.
 */

function toQuery(filters: ResultFilters): Record<string, string | number | undefined> {
  return {
    page: filters.page,
    per_page: filters.per_page,
    status: filters.status || undefined,
    checker: filters.checker || undefined,
    search: filters.search?.trim() || undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
    sort: filters.sort,
    direction: filters.direction,
  };
}

export function listResults(filters: ResultFilters = {}, signal?: AbortSignal): Promise<ResultsPage> {
  return request<ResultsPage>('/api/results', { query: toQuery(filters), signal });
}

export function listJobResults(
  reference: string | number,
  filters: ResultFilters = {},
  signal?: AbortSignal,
): Promise<JobResultsPage> {
  return request<JobResultsPage>(`/api/jobs/${encodeURIComponent(String(reference))}/results`, {
    query: toQuery(filters),
    signal,
  });
}

export function listHistory(
  filters: { page?: number; per_page?: number; checker?: string; status?: string } = {},
  signal?: AbortSignal,
): Promise<HistoryPage> {
  return request<HistoryPage>('/api/history', {
    query: {
      page: filters.page,
      per_page: filters.per_page,
      checker: filters.checker || undefined,
      status: filters.status || undefined,
    },
    signal,
  });
}

export function deleteHistoryEntry(id: number): Promise<null> {
  return request<null>(`/api/history/${id}`, { method: 'DELETE' });
}

/** Clears the user's history. Jobs, results and the credit ledger are kept. */
export function clearHistory(): Promise<{ removed: number }> {
  return request<{ removed: number }>('/api/history', { method: 'DELETE' });
}
