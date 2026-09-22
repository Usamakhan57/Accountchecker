import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Pagination } from '@/components/Pagination';
import { StatTile } from '@/components/StatTile';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listTransactions } from '@/services/admin';
import type { AdminTransactionList } from '@/types/api';
import { formatDateTime, formatNumber } from '@/utils/format';

/**
 * The credit ledger across every account.
 *
 * Read-only on purpose. Credits are moved from an account's own page, where the
 * person being changed is on screen and a reason is required; a global ledger
 * is the wrong place to type an amount into.
 */
export function AdminWalletPage() {
  usePageMeta({ title: 'Credit ledger', noIndex: true, canonicalPath: '/admin/wallet' });

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [type, setType] = useState('');

  const { data, loading, error, reload } = useApiResource<AdminTransactionList>(
    (signal) => listTransactions({ page, per_page: perPage, type }, signal),
    [page, perPage, type],
  );

  const items = data?.items ?? [];

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Credit ledger</h1>
          <p className="ac-page-header__subtitle">
            Every credit movement on the installation. Adjustments are made from an account&rsquo;s own page.
          </p>
        </div>
      </header>

      {data && (
        <div className="ac-grid ac-grid--stats">
          <StatTile
            label="Credits outstanding"
            value={formatNumber(data.credits.outstanding)}
            meta="Held across every wallet"
          />
          <StatTile
            label="Held by running jobs"
            value={formatNumber(data.credits.reserved)}
            meta="Reserved, not yet spent"
          />
          <StatTile label="Ledger entries" value={formatNumber(data.pagination.total)} />
        </div>
      )}

      <Card
        flush
        title="Movements"
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
            <span className="ac-muted">Show</span>
            <select
              className="ac-select"
              style={{ width: 'auto' }}
              value={type}
              onChange={(event) => {
                setType(event.target.value);
                setPage(1);
              }}
            >
              <option value="">Everything</option>
              <option value="CREDIT">Added</option>
              <option value="DEBIT">Spent</option>
              <option value="REFUND">Refunded</option>
              <option value="ADJUSTMENT">Adjusted</option>
            </select>
          </label>
        </div>

        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={10} columns={5} />
        ) : items.length === 0 ? (
          <EmptyState title="No movements yet" body="Credit activity appears here as it happens." />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">Account</th>
                  <th scope="col">What happened</th>
                  <th scope="col" className="ac-table__numeric">
                    Credits
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Balance after
                  </th>
                </tr>
              </thead>
              <tbody>
                {items.map((row) => (
                  <tr key={row.id}>
                    <td className="ac-muted">{formatDateTime(row.created_at)}</td>
                    <td>
                      <Link to={`/admin/users/${row.user.uuid}`}>{row.user.email}</Link>
                    </td>
                    <td>{row.description}</td>
                    <td className={`ac-table__numeric ${row.amount < 0 ? 'ac-amount--out' : 'ac-amount--in'}`}>
                      {row.amount > 0 ? '+' : row.amount < 0 ? '−' : ''}
                      {formatNumber(Math.abs(row.amount))}
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
