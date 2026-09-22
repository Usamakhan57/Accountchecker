import { useCallback, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Card } from '@/components/Card';
import { ExportButton } from '@/components/ExportButton';
import { Icon } from '@/components/Icon';
import { Pagination } from '@/components/Pagination';
import { ProgressBar } from '@/components/ProgressBar';
import { ResultBreakdownTiles, ResultsTable } from '@/components/ResultsTable';
import { JobStatusBadge } from '@/components/StatusBadge';
import { useToast } from '@/components/ToastProvider';
import { Alert, ErrorState, LoadingState } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { useJobProgress } from '@/hooks/useJobProgress';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { createJobExport } from '@/services/exports';
import { cancelJob } from '@/services/jobs';
import { listJobResults } from '@/services/results';
import type { JobResultsPage, ResultStatus } from '@/types/api';
import { formatDateTime, formatNumber } from '@/utils/format';

const STATUS_FILTERS: { value: ResultStatus | ''; label: string }[] = [
  { value: '', label: 'All results' },
  { value: 'VALID', label: 'Valid' },
  { value: 'INVALID', label: 'Invalid' },
  { value: 'UNKNOWN', label: 'Unknown' },
  { value: 'UNAVAILABLE', label: 'Unavailable' },
  { value: 'ERROR', label: 'Errors' },
];

/**
 * One job: its progress while it runs, its results once they exist.
 *
 * The results table pages against the server, so a five-thousand-record job
 * loads as fast as a five-record one. While the job is running the page polls
 * progress and reloads the visible page of results as the counters move.
 */
export function JobDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const toast = useToast();
  const { refresh } = useAuth();

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [status, setStatus] = useState<ResultStatus | ''>('');
  const [cancelling, setCancelling] = useState(false);

  const onFinished = useCallback(() => {
    void refresh();
  }, [refresh]);

  const { progress, error: progressError } = useJobProgress(id, { onFinished });

  const results = useApiResource<JobResultsPage>(
    (signal) => listJobResults(id, { page, per_page: perPage, status }, signal),
    [id, page, perPage, status, progress?.processed_items ?? 0, progress?.status ?? ''],
  );

  const job = results.data?.job;

  usePageMeta({
    title: job ? `${job.checker_label} job` : 'Job',
    noIndex: true,
    canonicalPath: `/jobs/${id}`,
  });

  async function handleCancel() {
    setCancelling(true);

    try {
      await cancelJob(id);
      toast.success('Job cancelled. You were only charged for the records already checked.');
      await refresh();
      results.reload();
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'The job could not be cancelled.');
    } finally {
      setCancelling(false);
    }
  }

  if (results.error && !results.data) {
    return <ErrorState title="Job not found" message={results.error} onRetry={results.reload} />;
  }

  if (!job) {
    return <LoadingState label="Loading job…" />;
  }

  const running = !progress?.is_finished && ['PENDING', 'QUEUED', 'PROCESSING'].includes(progress?.status ?? job.status);

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">{job.checker_label}</h1>
          <p className="ac-page-header__subtitle">
            Started {formatDateTime(job.started_at ?? job.created_at)} ·{' '}
            {formatNumber(job.total_items)} records · {job.source === 'UPLOAD' ? 'from a file' : 'pasted'}
          </p>
        </div>
        <div className="ac-page-header__actions">
          <Link to="/jobs" className="ac-btn ac-btn--secondary">
            <Icon name="chevronLeft" size={15} />
            All jobs
          </Link>
          {running && (
            <button
              type="button"
              className="ac-btn ac-btn--danger"
              onClick={handleCancel}
              disabled={cancelling}
            >
              <Icon name="stop" size={15} />
              {cancelling ? 'Cancelling…' : 'Cancel job'}
            </button>
          )}
        </div>
      </header>

      {progressError && <Alert tone="error">{progressError}</Alert>}
      {job.error_message && <Alert tone="error">{job.error_message}</Alert>}

      <Card
        title={<span className="ac-row" style={{ gap: '0.5rem' }}>
          Progress <JobStatusBadge status={progress?.status ?? job.status} />
        </span>}
      >
        <div className="ac-stack ac-stack--sm">
          <ProgressBar
            value={progress?.processed_items ?? job.processed_items}
            max={job.total_items}
            label={`${progress?.progress_percent ?? job.progress_percent}% complete`}
          />
          <dl className="ac-summary">
            <div>
              <dt>Checked</dt>
              <dd>
                {formatNumber(progress?.processed_items ?? job.processed_items)} of{' '}
                {formatNumber(job.total_items)}
              </dd>
            </div>
            <div>
              <dt>Credits {running ? 'held' : 'used'}</dt>
              <dd>
                {formatNumber(running ? job.credits_reserved : (progress?.credits_spent ?? job.credits_spent))}
              </dd>
            </div>
            <div>
              <dt>Finished</dt>
              <dd>{formatDateTime(progress?.completed_at ?? job.completed_at)}</dd>
            </div>
          </dl>
          {running && (
            <p className="ac-muted">
              This job runs on the server. You can close the page; it will keep going.
            </p>
          )}
        </div>
      </Card>

      {results.data && <ResultBreakdownTiles breakdown={results.data.breakdown} />}

      <Card
        title="Results"
        flush
        actions={
          <>
            <label className="ac-row" style={{ gap: '0.5rem' }}>
              <span className="ac-muted" style={{ fontSize: 'var(--ac-text-xs)' }}>
                Show
              </span>
              <select
                className="ac-select"
                style={{ width: 'auto', minHeight: 34 }}
                value={status}
                onChange={(event) => {
                  setStatus(event.target.value as ResultStatus | '');
                  setPage(1);
                }}
              >
                {STATUS_FILTERS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <ExportButton
              onCreate={(format) => createJobExport(id, format, status || undefined)}
              disabled={(results.data?.pagination.total ?? 0) === 0}
            />
          </>
        }
        footer={
          results.data && (
            <Pagination
              pagination={results.data.pagination}
              onPageChange={setPage}
              onPerPageChange={(size) => {
                setPerPage(size);
                setPage(1);
              }}
              disabled={results.loading}
            />
          )
        }
      >
        <ResultsTable
          results={results.data?.items ?? []}
          loading={results.loading && !results.data}
          emptyTitle={running ? 'No results yet' : 'Nothing matches that filter'}
          emptyBody={
            running
              ? 'Results appear here as the job works through your list.'
              : 'Try a different status filter.'
          }
        />
      </Card>
    </>
  );
}
