import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Modal } from '@/components/Modal';
import { Pagination } from '@/components/Pagination';
import { JobStatusBadge } from '@/components/StatusBadge';
import { useToast } from '@/components/ToastProvider';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { cancelJob, listJobs } from '@/services/admin';
import { ApiError } from '@/services/apiClient';
import type { AdminJob, AdminJobList } from '@/types/api';
import { formatDateTime, formatNumber } from '@/utils/format';

const CANCELLABLE = ['PENDING', 'QUEUED', 'PROCESSING'];

/**
 * Every job on the installation.
 *
 * Cancelling here runs the same path the owner's own cancel runs, so the
 * credits settle against what was actually checked. An administrator cannot
 * produce a billing outcome the user could not.
 */
export function AdminJobsPage() {
  usePageMeta({ title: 'All jobs', noIndex: true, canonicalPath: '/admin/jobs' });

  const toast = useToast();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [status, setStatus] = useState('');
  const [confirm, setConfirm] = useState<AdminJob | null>(null);
  const [busy, setBusy] = useState(false);

  const { data, loading, error, reload } = useApiResource<AdminJobList>(
    (signal) => listJobs({ page, per_page: perPage, status }, signal),
    [page, perPage, status],
  );

  const items = data?.items ?? [];

  async function handleCancel() {
    if (!confirm) {
      return;
    }

    setBusy(true);

    try {
      await cancelJob(confirm.id);
      toast.success('Job cancelled. Unspent credits went back to the account.');
      setConfirm(null);
      reload();
    } catch (cancelError) {
      toast.error(cancelError instanceof ApiError ? cancelError.message : 'That job could not be cancelled.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">All jobs</h1>
          <p className="ac-page-header__subtitle">
            {data ? `${formatNumber(data.queue_depth)} waiting to be picked up.` : 'Across every account.'}
          </p>
        </div>
      </header>

      <Card
        flush
        title="Jobs"
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
        <div className="ac-filter-bar">
          <label className="ac-row" style={{ gap: '0.5rem', fontSize: 'var(--ac-text-sm)' }}>
            <span className="ac-muted">Status</span>
            <select
              className="ac-select"
              style={{ width: 'auto' }}
              value={status}
              onChange={(event) => {
                setStatus(event.target.value);
                setPage(1);
              }}
            >
              <option value="">Any</option>
              <option value="QUEUED">Queued</option>
              <option value="PROCESSING">Processing</option>
              <option value="COMPLETED">Completed</option>
              <option value="FAILED">Failed</option>
              <option value="CANCELLED">Cancelled</option>
            </select>
          </label>
        </div>

        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={8} columns={6} />
        ) : items.length === 0 ? (
          <EmptyState title="No jobs match" body="Try a different status." />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">Account</th>
                  <th scope="col">Checker</th>
                  <th scope="col">Status</th>
                  <th scope="col" className="ac-table__numeric">
                    Progress
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
                  <tr key={job.id}>
                    <td>
                      <Link to={`/admin/users/${job.user.uuid}`}>{job.user.email}</Link>
                    </td>
                    <td className="ac-muted">{job.checker_label}</td>
                    <td>
                      <JobStatusBadge status={job.status} />
                    </td>
                    <td className="ac-table__numeric">
                      {formatNumber(job.processed_items)} / {formatNumber(job.total_items)}
                    </td>
                    <td className="ac-table__numeric">
                      {formatNumber(job.credits_spent)}
                      {job.credits_reserved > job.credits_spent && CANCELLABLE.includes(job.status) && (
                        <span className="ac-muted"> of {formatNumber(job.credits_reserved)}</span>
                      )}
                    </td>
                    <td className="ac-muted">{formatDateTime(job.created_at)}</td>
                    <td>
                      {CANCELLABLE.includes(job.status) && (
                        <button
                          type="button"
                          className="ac-btn ac-btn--ghost ac-btn--sm"
                          onClick={() => setConfirm(job)}
                        >
                          Cancel
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={confirm !== null}
        title="Cancel this job?"
        onClose={() => setConfirm(null)}
        footer={
          <>
            <button type="button" className="ac-btn ac-btn--secondary" onClick={() => setConfirm(null)} disabled={busy}>
              Leave it running
            </button>
            <button type="button" className="ac-btn ac-btn--danger" onClick={handleCancel} disabled={busy}>
              {busy ? 'Cancelling…' : 'Cancel the job'}
            </button>
          </>
        }
      >
        {confirm && (
          <p>
            {confirm.user.email} will be charged for the {formatNumber(confirm.processed_items)} records
            already checked, and the rest of their {formatNumber(confirm.credits_reserved)} reserved credits
            goes back to them. Records not yet checked will not be checked.
          </p>
        )}
      </Modal>
    </>
  );
}
