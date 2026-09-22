import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Pagination } from '@/components/Pagination';
import { ProgressBar } from '@/components/ProgressBar';
import { JobStatusBadge } from '@/components/StatusBadge';
import { useToast } from '@/components/ToastProvider';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { cancelJob, listJobs } from '@/services/jobs';
import type { Job, JobStatus, Paginated } from '@/types/api';
import { formatNumber, formatRelative } from '@/utils/format';

const STATUS_OPTIONS: { value: JobStatus | ''; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'QUEUED', label: 'Queued' },
  { value: 'PROCESSING', label: 'Running' },
  { value: 'COMPLETED', label: 'Completed' },
  { value: 'FAILED', label: 'Failed' },
  { value: 'CANCELLED', label: 'Cancelled' },
];

const RUNNING: JobStatus[] = ['PENDING', 'QUEUED', 'PROCESSING'];

/**
 * Every job the account has run.
 *
 * Jobs are paged in the database, so this page holds one window of rows no
 * matter how many jobs exist. Running jobs show live counters; the list
 * refreshes itself while any of them are still going and stops once they are
 * all finished.
 */
export function JobsPage() {
  usePageMeta({ title: 'Jobs', noIndex: true, canonicalPath: '/jobs' });

  const toast = useToast();
  const { refresh } = useAuth();

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [status, setStatus] = useState<JobStatus | ''>('');
  const [cancelling, setCancelling] = useState<string | null>(null);

  const jobs = useApiResource<Paginated<Job>>(
    (signal) => listJobs({ page, per_page: perPage, status }, signal),
    [page, perPage, status],
  );

  const { data, loading, error, reload } = jobs;
  const items = data?.items ?? [];

  async function handleCancel(job: Job) {
    setCancelling(job.uuid);

    try {
      await cancelJob(job.uuid);
      toast.success('Job cancelled. Unused credits have been returned.');
      await refresh();
      reload();
    } catch (cancelError) {
      toast.error(cancelError instanceof ApiError ? cancelError.message : 'The job could not be cancelled.');
    } finally {
      setCancelling(null);
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Jobs</h1>
          <p className="ac-page-header__subtitle">
            Everything you have queued. Jobs keep running whether or not this page is open.
          </p>
        </div>
        <div className="ac-page-header__actions">
          <button type="button" className="ac-btn ac-btn--secondary" onClick={reload}>
            <Icon name="refresh" size={15} />
            Refresh
          </button>
          <Link to="/checkers" className="ac-btn ac-btn--primary">
            <Icon name="plus" size={16} />
            New check
          </Link>
        </div>
      </header>

      <Card
        flush
        actions={
          <label className="ac-row" style={{ gap: '0.5rem' }}>
            <span className="ac-muted" style={{ fontSize: 'var(--ac-text-xs)' }}>
              Status
            </span>
            <select
              className="ac-select"
              style={{ width: 'auto', minHeight: 34 }}
              value={status}
              onChange={(event) => {
                setStatus(event.target.value as JobStatus | '');
                setPage(1);
              }}
            >
              {STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        }
        title="Your jobs"
        footer={
          data && (
            <Pagination
              pagination={data.pagination}
              onPageChange={setPage}
              onPerPageChange={(size) => {
                setPerPage(size);
                setPage(1);
              }}
              disabled={loading}
            />
          )
        }
      >
        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={6} columns={6} />
        ) : items.length === 0 ? (
          <EmptyState
            title="No jobs yet"
            body="Start a check and it will appear here while it runs."
            action={
              <Link to="/checkers" className="ac-btn ac-btn--primary ac-btn--sm">
                Pick a checker
              </Link>
            }
          />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">Checker</th>
                  <th scope="col">Status</th>
                  <th scope="col">Progress</th>
                  <th scope="col" className="ac-table__numeric">
                    Records
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Credits
                  </th>
                  <th scope="col">Started</th>
                  <th scope="col">
                    <span className="ac-sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {items.map((job) => (
                  <tr key={job.uuid}>
                    <td>
                      <Link to={`/jobs/${job.uuid}`}>{job.checker_label}</Link>
                    </td>
                    <td>
                      <JobStatusBadge status={job.status} />
                    </td>
                    <td style={{ minWidth: 160 }}>
                      <ProgressBar
                        value={job.processed_items}
                        max={job.total_items}
                        label={`${job.progress_percent}% complete`}
                      />
                      <span className="ac-muted" style={{ fontSize: 'var(--ac-text-xs)' }}>
                        {formatNumber(job.processed_items)} / {formatNumber(job.total_items)}
                      </span>
                    </td>
                    <td className="ac-table__numeric">{formatNumber(job.total_items)}</td>
                    <td className="ac-table__numeric">
                      {RUNNING.includes(job.status)
                        ? `${formatNumber(job.credits_reserved)} held`
                        : formatNumber(job.credits_spent)}
                    </td>
                    <td className="ac-muted">{formatRelative(job.started_at ?? job.created_at)}</td>
                    <td>
                      {RUNNING.includes(job.status) ? (
                        <button
                          type="button"
                          className="ac-btn ac-btn--ghost ac-btn--sm"
                          onClick={() => void handleCancel(job)}
                          disabled={cancelling === job.uuid}
                        >
                          <Icon name="stop" size={14} />
                          {cancelling === job.uuid ? 'Cancelling…' : 'Cancel'}
                        </button>
                      ) : (
                        <Link to={`/jobs/${job.uuid}`} className="ac-btn ac-btn--ghost ac-btn--sm">
                          View
                          <Icon name="chevronRight" size={14} />
                        </Link>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}
