import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Modal } from '@/components/Modal';
import { JobStatusBadge } from '@/components/StatusBadge';
import { StatTile } from '@/components/StatTile';
import { useToast } from '@/components/ToastProvider';
import { Alert, ErrorState, LoadingState } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { adjustWallet, setUserRole, setUserStatus } from '@/services/admin';
import { getUser } from '@/services/admin';
import type { AdminUserDetail } from '@/types/api';
import { formatDateTime, formatNumber, formatRelative } from '@/utils/format';

/**
 * One account, and everything an administrator can do to it.
 *
 * The three controls here change somebody else's account, so each states what
 * it will do before it does it, and the credit adjustment requires a reason
 * that the account holder will see in their own credit history.
 *
 * The server refuses an administrator acting on their own account. The page
 * disables those controls too, so the refusal is visible before the click
 * rather than after it.
 */
export function AdminUserDetailPage() {
  const { id = '' } = useParams();
  const toast = useToast();
  const { user: actor } = useAuth();

  const { data, error, reload } = useApiResource<AdminUserDetail>(
    (signal) => getUser(id, signal),
    [id],
  );

  usePageMeta({ title: data?.user.name ?? 'Account', noIndex: true });

  const [adjustOpen, setAdjustOpen] = useState(false);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const account = data?.user;
  const isSelf = actor !== null && account !== undefined && actor.uuid === account.uuid;

  async function run(action: () => Promise<unknown>, success: string) {
    setBusy(true);

    try {
      await action();
      toast.success(success);
      reload();
      return true;
    } catch (actionError) {
      toast.error(actionError instanceof ApiError ? actionError.message : 'That could not be done.');
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function handleAdjust() {
    const parsed = Number.parseInt(amount, 10);

    if (!Number.isFinite(parsed) || parsed === 0) {
      toast.error('Enter a number of credits to add or remove.');
      return;
    }

    const done = await run(
      () => adjustWallet(id, parsed, reason.trim()),
      parsed > 0 ? `${formatNumber(parsed)} credits added.` : `${formatNumber(Math.abs(parsed))} credits removed.`,
    );

    if (done) {
      setAdjustOpen(false);
      setAmount('');
      setReason('');
    }
  }

  if (error) {
    return <ErrorState message={error} onRetry={reload} />;
  }

  if (!data || !account) {
    return <LoadingState label="Loading the account…" />;
  }

  const suspended = account.status === 'SUSPENDED';

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">{account.name}</h1>
          <p className="ac-page-header__subtitle">
            {account.email} · joined {formatDateTime(account.created_at)}
          </p>
        </div>
        <div className="ac-page-header__actions">
          <Link to="/admin/users" className="ac-btn ac-btn--ghost">
            Back to users
          </Link>
        </div>
      </header>

      {isSelf && (
        <Alert tone="info">
          This is your own account. You cannot change your own status or role, so that a careless click
          cannot lock you out of the installation.
        </Alert>
      )}

      <div className="ac-grid ac-grid--stats">
        <StatTile label="Available" value={formatNumber(data.wallet.available)} tone="accent" />
        <StatTile
          label="Held by running jobs"
          value={formatNumber(data.wallet.reserved)}
          meta={data.holds.length === 0 ? 'Nothing running' : `${data.holds.length} job(s)`}
        />
        <StatTile label="Spent all told" value={formatNumber(data.wallet_totals.spent)} />
        <StatTile
          label="Status"
          value={suspended ? 'Suspended' : account.status === 'ACTIVE' ? 'Active' : 'Pending'}
          tone={suspended ? 'invalid' : 'valid'}
          meta={account.last_login_at ? `Last seen ${formatRelative(account.last_login_at)}` : 'Never signed in'}
        />
      </div>

      <div className="ac-grid ac-grid--halves">
        <Card title="Account" subtitle="Changes here are recorded in the audit log with your name on them.">
          <div className="ac-stack">
            <div>
              <label className="ac-label" htmlFor="ac-admin-role">
                Role
              </label>
              <select
                id="ac-admin-role"
                className="ac-select"
                value={account.role}
                disabled={busy || isSelf}
                onChange={(event) =>
                  void run(() => setUserRole(id, event.target.value), 'Role updated.')
                }
              >
                {data.roles.map((role) => (
                  <option key={role.slug} value={role.slug}>
                    {role.name}
                  </option>
                ))}
              </select>
              <p className="ac-help">
                A staff role opens the administration panel. Only a super administrator can grant or remove
                that role.
              </p>
            </div>

            <div>
              <p className="ac-label">Access</p>
              <p className="ac-help" style={{ marginTop: 0 }}>
                {suspended
                  ? 'This account cannot sign in. Reactivating restores it immediately; nothing was deleted.'
                  : 'Suspending signs this account out and blocks it from signing in again. Their jobs, results and credits are kept.'}
              </p>
              <button
                type="button"
                className={`ac-btn ${suspended ? 'ac-btn--primary' : 'ac-btn--danger'}`}
                disabled={busy || isSelf}
                onClick={() =>
                  void run(
                    () => setUserStatus(id, suspended ? 'ACTIVE' : 'SUSPENDED'),
                    suspended ? 'Account reactivated.' : 'Account suspended.',
                  )
                }
              >
                {suspended ? 'Reactivate this account' : 'Suspend this account'}
              </button>
            </div>
          </div>
        </Card>

        <Card
          title="Credits"
          subtitle="An adjustment is written to their credit history with the reason you give."
          actions={
            <button type="button" className="ac-btn ac-btn--primary ac-btn--sm" onClick={() => setAdjustOpen(true)}>
              Adjust credits
            </button>
          }
        >
          <dl className="ac-summary">
            <div>
              <dt>Added</dt>
              <dd>{formatNumber(data.wallet_totals.added)}</dd>
            </div>
            <div>
              <dt>Spent</dt>
              <dd>{formatNumber(data.wallet_totals.spent)}</dd>
            </div>
            <div>
              <dt>Refunded</dt>
              <dd>{formatNumber(data.wallet_totals.refunded)}</dd>
            </div>
            <div>
              <dt>Adjusted</dt>
              <dd>{formatNumber(data.wallet_totals.adjusted)}</dd>
            </div>
          </dl>

          {data.holds.length > 0 && (
            <ul className="ac-hold-list" style={{ marginTop: 'var(--ac-space-4)' }}>
              {data.holds.map((hold) => (
                <li key={hold.uuid} className="ac-hold">
                  <div className="ac-hold__main">
                    <span className="ac-hold__title">{hold.checker_label}</span>
                    <span className="ac-muted">
                      {formatNumber(hold.processed_items)} of {formatNumber(hold.total_items)} checked
                    </span>
                  </div>
                  <span className="ac-hold__amount">{formatNumber(hold.credits_reserved)} cr</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card flush title="Recent jobs">
        {data.recent_jobs.length === 0 ? (
          <p className="ac-muted" style={{ padding: 'var(--ac-space-4)' }}>
            This account has not run anything yet.
          </p>
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
                    Credits
                  </th>
                  <th scope="col">Started</th>
                </tr>
              </thead>
              <tbody>
                {data.recent_jobs.map((job) => (
                  <tr key={job.id}>
                    <td>{job.checker_label}</td>
                    <td>
                      <JobStatusBadge status={job.status} />
                    </td>
                    <td className="ac-table__numeric">{formatNumber(job.total_items)}</td>
                    <td className="ac-table__numeric">{formatNumber(job.credits_spent)}</td>
                    <td className="ac-muted">{formatDateTime(job.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={adjustOpen}
        title="Adjust credits"
        onClose={() => setAdjustOpen(false)}
        footer={
          <>
            <button type="button" className="ac-btn ac-btn--secondary" onClick={() => setAdjustOpen(false)} disabled={busy}>
              Cancel
            </button>
            <button type="button" className="ac-btn ac-btn--primary" onClick={handleAdjust} disabled={busy}>
              {busy ? 'Applying…' : 'Apply adjustment'}
            </button>
          </>
        }
      >
        <p className="ac-help" style={{ marginTop: 0 }}>
          A positive number adds credits, a negative number removes them. Credits held by a running job
          cannot be removed. This appears in {account.name}&rsquo;s own credit history with your reason.
        </p>

        <label className="ac-label" htmlFor="ac-adjust-amount">
          Credits
        </label>
        <input
          id="ac-adjust-amount"
          type="number"
          className="ac-input"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          placeholder="500, or -100 to remove"
        />

        <label className="ac-label" htmlFor="ac-adjust-reason" style={{ marginTop: 'var(--ac-space-3)' }}>
          Reason
        </label>
        <input
          id="ac-adjust-reason"
          type="text"
          className="ac-input"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          placeholder="Refund for job that failed on our side"
          maxLength={255}
        />

        <p className="ac-help">
          Current balance {formatNumber(data.wallet.balance)}, of which {formatNumber(data.wallet.available)}{' '}
          is available.
        </p>
      </Modal>
    </>
  );
}
