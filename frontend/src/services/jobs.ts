import { request } from '@/services/apiClient';
import type {
  InputReview,
  Job,
  JobDetail,
  JobProgress,
  JobStatus,
  JobSubmission,
  Paginated,
} from '@/types/api';

/**
 * Batch job endpoints.
 *
 * Submitting a batch returns as soon as the server has written the rows; the
 * checking itself happens in a separate worker process. Nothing here waits for
 * a job to finish — the workspace starts a job, then polls `progress` until it
 * reports `is_finished`.
 *
 * A job is addressed by either its numeric id or its uuid, so a URL can carry
 * the uuid without exposing how many jobs the platform has run.
 */

export interface JobFilters {
  page?: number;
  per_page?: number;
  status?: JobStatus | '';
  checker?: string;
}

/**
 * Reviews a pasted list without creating a job or touching the wallet.
 *
 * Runs the same preparation the submission does, so the record count and the
 * credit cost shown here are the ones that will actually be charged.
 */
export function reviewInput(slug: string, input: string, signal?: AbortSignal): Promise<InputReview> {
  return request<InputReview>(`/api/checker/${encodeURIComponent(slug)}/validate`, {
    method: 'POST',
    body: { input },
    signal,
  });
}

/** Queues a pasted list. Resolves once the job exists, not once it is done. */
export function startJob(
  slug: string,
  input: string,
  options: { outputFormat?: 'csv' | 'txt' } = {},
): Promise<JobSubmission> {
  return request<JobSubmission>(`/api/checker/${encodeURIComponent(slug)}/start`, {
    method: 'POST',
    body: { input, output_format: options.outputFormat },
  });
}

/**
 * Queues an uploaded .txt or .csv list.
 *
 * Sent as multipart so the file never has to be read into a string in the
 * browser; the server reads it, takes the lines and discards it.
 */
export function startJobFromFile(slug: string, file: File): Promise<JobSubmission> {
  const formData = new FormData();
  formData.append('file', file);

  return request<JobSubmission>(`/api/checker/${encodeURIComponent(slug)}/start`, {
    method: 'POST',
    formData,
  });
}

export function listJobs(filters: JobFilters = {}, signal?: AbortSignal): Promise<Paginated<Job>> {
  return request<Paginated<Job>>('/api/jobs', {
    query: {
      page: filters.page,
      per_page: filters.per_page,
      status: filters.status || undefined,
      checker: filters.checker || undefined,
    },
    signal,
  });
}

export function getJob(reference: string | number, signal?: AbortSignal): Promise<JobDetail> {
  return request<JobDetail>(`/api/jobs/${encodeURIComponent(String(reference))}`, { signal });
}

export function getJobProgress(reference: string | number, signal?: AbortSignal): Promise<JobProgress> {
  return request<JobProgress>(`/api/jobs/${encodeURIComponent(String(reference))}/progress`, { signal });
}

export function cancelJob(reference: string | number): Promise<Job> {
  return request<Job>(`/api/jobs/${encodeURIComponent(String(reference))}/cancel`, { method: 'POST' });
}
