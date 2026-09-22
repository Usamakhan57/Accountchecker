import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Pagination } from '@/components/Pagination';
import { StatTile } from '@/components/StatTile';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listUsers } from '@/services/admin';
import type { AdminUserList } from '@/types/api';
import { formatNumber, formatRelative } from '@/utils/format';

/**
 * The account list.
 *
 * Read-only: every change to an account happens on that account's own page,
 * where the person being changed is on screen. A list is the wrong place for a
 * suspend button.
 */
export function AdminUsersPage() {
  usePageMeta({ title: 'Users', noIndex: true, canonicalPath: '/admin/users' });

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('');
  const [role, setRole] = useState('');

  const { data, loading, error, reload } = useApiResource<AdminUserList>(
    (signal) => listUsers({ page, per_page: perPage, search: query, status, role }, signal),
    [page, perPage, query, status, role],
  );

  const items = data?.items ?? [];

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Users</h1>
          <p className="ac-page-header__subtitle">Open an account to change its status, role or credits.</p>
        </div>
      </header>

      {data && (
        <div className="ac-grid ac-grid--stats">
          <StatTile label="Active" value={formatNumber(data.counts.ACTIVE ?? 0)} tone="valid" />
          <StatTile label="Suspended" value={formatNumber(data.counts.SUSPENDED ?? 0)} tone="invalid" />
          <StatTile label="Pending" value={formatNumber(data.counts.PENDING ?? 0)} />
        </div>
      )}

      <Card
        flush
        title="Accounts"
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
        <form
          className="ac-filter-bar"
          onSubmit={(event) => {
            event.preventDefault();
            setQuery(search.trim());
            setPage(1);
          }}
        >
          <div className="ac-filter-bar__field">
            <span className="ac-filter-bar__icon">
              <Icon name="search" size={14} />
            </span>
            <input
              type="search"
              className="ac-input"
              placeholder="Name, email or account id"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              aria-label="Search accounts"
            />
          </div>

          <select
            className="ac-select"
            style={{ width: 'auto' }}
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
            aria-label="Filter by status"
          >
            <option value="">Any status</option>
            <option value="ACTIVE">Active</option>
            <option value="SUSPENDED">Suspended</option>
            <option value="PENDING">Pending</option>
          </select>

          <select
            className="ac-select"
            style={{ width: 'auto' }}
            value={role}
            onChange={(event) => {
              setRole(event.target.value);
              setPage(1);
            }}
            aria-label="Filter by role"
          >
            <option value="">Any role</option>
            {(data?.roles ?? []).map((option) => (
              <option key={option.slug} value={option.slug}>
                {option.name}
              </option>
            ))}
          </select>

          <button type="submit" className="ac-btn ac-btn--secondary ac-btn--sm">
            Search
          </button>
        </form>

        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={8} columns={6} />
        ) : items.length === 0 ? (
          <EmptyState title="No accounts match" body="Try a different search or clear the filters." />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">Account</th>
                  <th scope="col">Role</th>
                  <th scope="col">Status</th>
                  <th scope="col" className="ac-table__numeric">
                    Credits
                  </th>
                  <th scope="col">Last seen</th>
                </tr>
              </thead>
              <tbody>
                {items.map((user) => (
                  <tr key={user.uuid}>
                    <td>
                      <Link to={`/admin/users/${user.uuid}`}>{user.name}</Link>
                      <span className="ac-muted" style={{ display: 'block', fontSize: 'var(--ac-text-xs)' }}>
                        {user.email}
                      </span>
                    </td>
                    <td>{user.role === 'USER' ? <span className="ac-muted">User</span> : user.role}</td>
                    <td>
                      <span className={`ac-badge ac-badge--${user.status === 'ACTIVE' ? 'valid' : 'invalid'}`}>
                        {user.status === 'ACTIVE' ? 'Active' : user.status === 'SUSPENDED' ? 'Suspended' : 'Pending'}
                      </span>
                    </td>
                    <td className="ac-table__numeric">
                      {formatNumber(user.wallet_balance)}
                      {user.wallet_reserved > 0 && (
                        <span className="ac-muted"> ({formatNumber(user.wallet_reserved)} held)</span>
                      )}
                    </td>
                    <td className="ac-muted">
                      {user.last_login_at ? formatRelative(user.last_login_at) : 'Never signed in'}
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
