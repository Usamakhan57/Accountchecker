import { request } from '@/services/apiClient';
import type { CheckerType } from '@/types/api';

/**
 * The checker catalogue.
 *
 * What the client learns about a checker is deliberately limited: what it is,
 * what it costs, how big a batch it takes, whether it is switched on and
 * whether an authorized verification source is configured for it. Provider
 * URLs and API keys stay on the server and are not part of any payload.
 */

export interface CheckerCatalogue {
  items: CheckerType[];
  /** How many enabled checkers have no authorized source configured. */
  unconfigured_count: number;
}

export function listCheckers(signal?: AbortSignal): Promise<CheckerCatalogue> {
  return request<CheckerCatalogue>('/api/checker/types', { signal });
}

export function getChecker(slug: string, signal?: AbortSignal): Promise<CheckerType> {
  return request<CheckerType>(`/api/checker/types/${encodeURIComponent(slug)}`, { signal });
}
