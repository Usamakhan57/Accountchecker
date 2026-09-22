import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Pagination } from '@/components/Pagination';
import { JobStatusBadge } from '@/components/StatusBadge';
import { StatTile } from '@/components/StatTile';
import { UsageChart } from '@/components/UsageChart';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { getWallet, listTransactions } from '@/services/wallet';
import type { Paginated, WalletSummary, WalletTransaction, WalletTransactionType } from '@/types/api';
import { formatDateTime, formatNumber, pluralize } from '@/utils/format';

/**
 * The credit wallet.
 *
 * Nothing on this page can change a balance, and that is the point: credits are
 * added by a settled purchase or by an administrator, both server-side. The page
 * reads three figures that are easy to confuse, so it names them plainly —
 * balance is what you own, reserved is what a running job is holding, available
 * is what you can spend right now.
 */

const TYPE_FILTERS: { value: WalletTransactionType | ''; label: string }[] = [
  { value: '', label: 'Everything' },
  { value: 'CREDIT', label: 'Added' },
  { value: 'DEBIT', label: 'Spent' },
  { value: 'REFUND', label: 'Refunded' },
  { value: 'ADJUSTMENT', label: 'Adjusted' },
];

const TYPE_LABELS: Record<WalletTransactionType, string> = {
  CREDIT: 'Added',
  DEBIT: 'Spent',
  REFUND: 'Refunded',
  ADJUSTMENT: 'Adjusted',
};

function signed(amount: number): string {
  return `${amount > 0 ? '+' : amount < 0 ? '−' : ''}${formatNumber(Math.abs(amount))}`;
}

export function WalletPage() {
  usePageMeta({ title: 'Wallet', noIndex: true, canonicalPath: '/wallet' });

  const { setWallet } = useAuth();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [type, setType] = useState<WalletTransactionType | ''>('');

  const summary = useApiResource<WalletSummary>((signal) => getWallet(signal), []);
  const ledger = useApiResource<Paginated<WalletTransaction>>(
    (signal) => listTransactions({ page, per_page: perPage, type }, signal),
    [page, perPage, type],
  );

  // The sidebar carries the same balance. Reading it here is the freshest the
  // browser will get it, so the two agree rather than drifting apart until the
  // next full refresh. The setter is held in a ref rather than listed as a
  // dependency: it is rebuilt whenever auth state changes, and writing to auth
  // state is exactly what this effect does, so depending on it would loop.
  const wallet = summary.data?.wallet;
  const publishWallet = useRef(setWallet);
  publishWallet.current = setWallet;

  useEffect(() => {
    if (wallet) {
      publishWallet.current(wallet);
    }
  }, [wallet]);

  const holds = summary.data?.holds ?? [];
  const totals = summary.data?.totals;
  const rows = ledger.data?.items ?? [];

  const spend = (summary.data?.spend_by_day ?? []).map((point) => ({
    date: point.date,
    checks: point.credits,
  }));

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Wallet</h1>
          <p className="ac-page-header__subtitle">
            Your credits, what is holding them and where they have gone.
          </p>
        </div>
        <div className="ac-page-header__actions">
          <Link to="/pricing" className="ac-btn ac-btn--primary">
            <Icon name="pricing" size={15} />
            Add credits
          </Link>
        </div>
      </header>

      {summary.error ? (
        <ErrorState message={summary.error} onRetry={summary.reload} />
      ) : (
        <>
          <div className="ac-grid ac-grid--stats">
            <StatTile
              label="Available to spend"
              value={formatNumber(wallet?.available ?? 0)}
              meta="What a new job can use"
              tone="accent"
              loading={summary.loading && !summary.data}
            />
            <StatTile
              label="Held by running jobs"
              value={formatNumber(wallet?.reserved ?? 0)}
              meta={
                holds.length === 0
                  ? 'Nothing is running'
                  : `Across ${holds.length} ${pluralize(holds.length, 'job')}`
              }
              loading={summary.loading && !summary.data}
            />
            <StatTile
              label="Total balance"
              value={formatNumber(wallet?.balance ?? 0)}
              meta="Available plus held"
              loading={summary.loading && !summary.data}
            />
          </div>

          {holds.length > 0 && (
            <Card
              title="Credits on hold"
              subtitle="Reserved when a job started. Whatever a job does not use comes back when it finishes."
            >
              <ul className="ac-hold-list">
                {holds.map((hold) => (
                  <li key={hold.uuid} className="ac-hold">
                    <div className="ac-hold__main">
                      <Link to={`/jobs/${hold.uuid}`} className="ac-hold__title">
                        {hold.checker_label}
                      </Link>
                      <span className="ac-muted">
                        {formatNumber(hold.processed_items)} of {formatNumber(hold.total_items)} records
                        checked, {formatNumber(hold.credits_spent)} spent so far
                      </span>
                    </div>
                    <JobStatusBadge status={hold.status} />
                    <span className="ac-hold__amount">{formatNumber(hold.credits_reserved)} cr</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {totals && (
            <Card title="Credits over the last 30 days" subtitle="What you spent, by day.">
              <UsageChart data={spend} />
              <div className="ac-grid ac-grid--stats" style={{ marginTop: 'var(--ac-space-4)' }}>
                <StatTile label="Added" value={formatNumber(totals.added)} tone="valid" />
                <StatTile label="Spent" value={formatNumber(totals.spent)} />
                <StatTile label="Refunded" value={formatNumber(totals.refunded)} tone="unknown" />
                <StatTile label="Adjusted" value={signed(totals.adjusted)} />
              </div>
            </Card>
          )}
        </>
      )}

      <Card
        flush
        title="Credit history"
        subtitle="Every movement, newest first. This is the record your invoices are built from."
        footer={
          ledger.data && (
            <Pagination
              pagination={ledger.data.pagination}
              onPageChange={setPage}
              onPerPageChange={(size) => {
                setPerPage(size);
                setPage(1);
              }}
              disabled={ledger.loading}
            />
          )
        }
      >
        <div className="ac-filter-bar">
          <label className="ac-row" style={{ gap: '0.5rem', fontSize: 'var(--ac-text-sm)' }}>
            <span className="ac-muted">Show</span>
            <select
              className="ac-select"
              style={{ width: 'auto' }}
              value={type}
              onChange={(event) => {
                setType(event.target.value as WalletTransactionType | '');
                setPage(1);
              }}
            >
              {TYPE_FILTERS.map((option) => (
                <option key={option.value || 'all'} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        {ledger.error ? (
          <ErrorState message={ledger.error} onRetry={ledger.reload} />
        ) : ledger.loading && !ledger.data ? (
          <TableSkeleton rows={6} columns={5} />
        ) : rows.length === 0 ? (
          <EmptyState
            title={type ? 'Nothing of that kind yet' : 'No credit movements yet'}
            body={
              type
                ? 'Try showing everything instead.'
                : 'Credits you add and credits a job spends are both listed here.'
            }
          />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">What happened</th>
                  <th scope="col">Kind</th>
                  <th scope="col" className="ac-table__numeric">
                    Credits
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Balance after
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td className="ac-muted">{formatDateTime(row.created_at)}</td>
                    <td>{row.description}</td>
                    <td>{TYPE_LABELS[row.type]}</td>
                    <td
                      className={`ac-table__numeric ${row.amount < 0 ? 'ac-amount--out' : 'ac-amount--in'}`}
                    >
                      {signed(row.amount)}
                    </td>
                    <td className="ac-table__numeric">{formatNumber(row.balance_after)}</td>
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
