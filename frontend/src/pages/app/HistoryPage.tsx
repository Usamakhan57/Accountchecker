import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Modal } from '@/components/Modal';
import { Pagination } from '@/components/Pagination';
import { JobStatusBadge } from '@/components/StatusBadge';
import { StatTile } from '@/components/StatTile';
import { useToast } from '@/components/ToastProvider';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { clearHistory, deleteHistoryEntry, listHistory } from '@/services/results';
import type { HistoryPage as HistoryPageData } from '@/types/api';
import { formatDateTime, formatNumber } from '@/utils/format';

/**
 * Search history: one entry per finished job.
 *
 * Clearing history removes this record of what was run. The jobs, the results
 * and the credit ledger stay, because those are the accounting — so the page
 * says so before anything is cleared.
 */
export function HistoryPage() {
  usePageMeta({ title: 'History', noIndex: true, canonicalPath: '/history' });

  const toast = useToast();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [confirmClear, setConfirmClear] = useState(false);
  const [clearing, setClearing] = useState(false);

  const history = useApiResource<HistoryPageData>(
    (signal) => listHistory({ page, per_page: perPage }, signal),
    [page, perPage],
  );

  const { data, loading, error, reload } = history;
  const items = data?.items ?? [];

  async function handleClear() {
    setClearing(true);

    try {
      const { removed } = await clearHistory();
      toast.success(`${formatNumber(removed)} ${removed === 1 ? 'entry' : 'entries'} cleared.`);
      setConfirmClear(false);
      setPage(1);
      reload();
    } catch (clearError) {
      toast.error(clearError instanceof ApiError ? clearError.message : 'History could not be cleared.');
    } finally {
      setClearing(false);
    }
  }

  async function handleDelete(id: number) {
    try {
      await deleteHistoryEntry(id);
      reload();
    } catch (deleteError) {
      toast.error(deleteError instanceof ApiError ? deleteError.message : 'That entry could not be removed.');
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">History</h1>
          <p className="ac-page-header__subtitle">Every job you have finished, newest first.</p>
        </div>
        <div className="ac-page-header__actions">
          <button
            type="button"
            className="ac-btn ac-btn--secondary"
            onClick={() => setConfirmClear(true)}
            disabled={items.length === 0}
          >
            <Icon name="trash" size={15} />
            Clear history
          </button>
        </div>
      </header>

      {data && (
        <div className="ac-grid ac-grid--stats">
          <StatTile label="Jobs run" value={formatNumber(data.totals.jobs)} />
          <StatTile label="Records checked" value={formatNumber(data.totals.records)} />
          <StatTile label="Credits used" value={formatNumber(data.totals.credits)} />
        </div>
      )}

      <Card
        flush
        title="Past jobs"
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
            title="No history yet"
            body="Finished jobs are listed here."
            action={
              <Link to="/checkers" className="ac-btn ac-btn--primary ac-btn--sm">
                Start a check
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
                  <th scope="col" className="ac-table__numeric">
                    Records
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Checked
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Credits
                  </th>
                  <th scope="col">Finished</th>
                  <th scope="col">
                    <span className="ac-sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {items.map((entry) => (
                  <tr key={entry.id}>
                    <td>
                      {entry.job_id ? (
                        <Link to={`/jobs/${entry.job_id}`}>{entry.checker_label || entry.checker_slug}</Link>
                      ) : (
                        entry.checker_label || entry.checker_slug
                      )}
                    </td>
                    <td>
                      <JobStatusBadge status={entry.status} />
                    </td>
                    <td className="ac-table__numeric">{formatNumber(entry.total_items)}</td>
                    <td className="ac-table__numeric">{formatNumber(entry.successful_items)}</td>
                    <td className="ac-table__numeric">{formatNumber(entry.credits_spent)}</td>
                    <td className="ac-muted">{formatDateTime(entry.created_at)}</td>
                    <td>
                      <button
                        type="button"
                        className="ac-btn ac-btn--ghost ac-btn--sm"
                        onClick={() => void handleDelete(entry.id)}
                        aria-label={`Remove the ${entry.checker_label} entry from your history`}
                      >
                        <Icon name="trash" size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={confirmClear}
        title="Clear your history?"
        onClose={() => setConfirmClear(false)}
        footer={
          <>
            <button
              type="button"
              className="ac-btn ac-btn--secondary"
              onClick={() => setConfirmClear(false)}
              disabled={clearing}
            >
              Keep it
            </button>
            <button type="button" className="ac-btn ac-btn--danger" onClick={handleClear} disabled={clearing}>
              {clearing ? 'Clearing…' : 'Clear history'}
            </button>
          </>
        }
      >
        <p>
          This removes your record of what you have run. Your jobs, your results and your credit history
          are kept, so nothing about your billing changes.
        </p>
      </Modal>
    </>
  );
}
