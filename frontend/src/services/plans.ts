import { request } from '@/services/apiClient';
import type { Plan, PlanCatalogue } from '@/types/api';

/**
 * Pricing plans.
 *
 * The catalogue is public, so this works signed out. `startCheckout` is
 * included because the endpoint exists and the page calls it: with no payment
 * provider configured it always fails with PAYMENTS_NOT_CONFIGURED, and the
 * page shows that reason rather than a fabricated confirmation.
 */

export function listPlans(signal?: AbortSignal): Promise<PlanCatalogue> {
  return request<PlanCatalogue>('/api/plans', { signal });
}

export function getPlan(slug: string, signal?: AbortSignal): Promise<Plan> {
  return request<Plan>(`/api/plans/${encodeURIComponent(slug)}`, { signal });
}

export function startCheckout(slug: string): Promise<unknown> {
  return request(`/api/plans/${encodeURIComponent(slug)}/checkout`, { method: 'POST' });
}
