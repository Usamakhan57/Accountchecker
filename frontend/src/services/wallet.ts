import { request } from '@/services/apiClient';
import type { Paginated, WalletSummary, WalletTransaction, WalletTransactionType } from '@/types/api';

/**
 * The credit wallet.
 *
 * Read-only, and deliberately so. There is no client call that changes a
 * balance: credits arrive from a settled purchase or from an administrator,
 * both server-side. Anything here that looked like it could set a balance would
 * be a bug in the API, not a missing function in this file.
 */

export function getWallet(signal?: AbortSignal): Promise<WalletSummary> {
  return request<WalletSummary>('/api/wallet', { signal });
}

export function listTransactions(
  options: { page?: number; per_page?: number; type?: WalletTransactionType | '' } = {},
  signal?: AbortSignal,
): Promise<Paginated<WalletTransaction>> {
  return request<Paginated<WalletTransaction>>('/api/wallet/transactions', {
    query: {
      page: options.page,
      per_page: options.per_page,
      type: options.type || undefined,
    },
    signal,
  });
}
