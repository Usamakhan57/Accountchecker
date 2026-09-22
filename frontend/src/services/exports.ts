import { download, request } from '@/services/apiClient';
import type { ExportFormat, ExportRecord, Paginated, ResultFilters } from '@/types/api';

/**
 * Result exports.
 *
 * Creating and downloading are separate calls. The server builds the file, so
 * a large export does not hold a request open while the browser waits, and the
 * same file can be fetched again without regenerating it.
 *
 * Exports expire; `download_path` is null once they have, and the UI should
 * offer to generate a new one rather than link to a file that is gone.
 */

/** Exports whatever the results table is currently showing. */
export function createExport(format: ExportFormat, filters: ResultFilters = {}): Promise<ExportRecord> {
  return request<ExportRecord>('/api/exports', {
    method: 'POST',
    body: {
      format,
      status: filters.status || undefined,
      checker: filters.checker || undefined,
      search: filters.search?.trim() || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
    },
  });
}

export function createJobExport(
  reference: string | number,
  format: ExportFormat,
  status?: string,
): Promise<ExportRecord> {
  return request<ExportRecord>(`/api/jobs/${encodeURIComponent(String(reference))}/export`, {
    method: 'POST',
    body: { format, status: status || undefined },
  });
}

export function listExports(page = 1, perPage = 25): Promise<Paginated<ExportRecord>> {
  return request<Paginated<ExportRecord>>('/api/exports', { query: { page, per_page: perPage } });
}

export function deleteExport(uuid: string): Promise<null> {
  return request<null>(`/api/exports/${encodeURIComponent(uuid)}`, { method: 'DELETE' });
}

/**
 * Fetches an export and hands it to the browser as a download.
 *
 * Fetched rather than linked so the session cookie and any error envelope are
 * handled the same way as every other call; a failed download surfaces as an
 * ApiError instead of navigating the user to a JSON error page.
 */
export async function downloadExport(record: ExportRecord): Promise<void> {
  const { blob, filename } = await download(`/api/exports/${encodeURIComponent(record.uuid)}/download`);

  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename || record.filename;
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Released on the next tick so the click has taken the URL first.
  window.setTimeout(() => URL.revokeObjectURL(url), 0);
}
