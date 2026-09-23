import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import type { IconName } from '@/components/Icon';
import { JobStatusBadge } from '@/components/StatusBadge';
import { ProgressBar } from '@/components/ProgressBar';
import { StatTile } from '@/components/StatTile';
import { UsageChart } from '@/components/UsageChart';
import { Alert, EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { useToast } from '@/components/ToastProvider';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError, api } from '@/services/apiClient';
import type { DashboardStats } from '@/types/api';
import { formatNumber, formatRelative } from '@/utils/format';

const QUICK_ACTIONS: { to: string; label: string; description: string; icon: IconName }[] = [
  { to: '/checkers/gmail', label: 'Gmail checker', description: 'Validate and verify email addresses', icon: 'mail' },
  { to: '/checkers/platform', label: 'Platform checker', description: 'Check handles on supported platforms', icon: 'platform' },
  { to: '/checkers/duplicates', label: 'Duplicate finder', description: 'Free — no credits used', icon: 'duplicate' },
  { to: '/checkers/name-generator', label: 'Name generator', description: 'Free — no credits used', icon: 'sparkle' },
  { to: '/history', label: 'History', description: 'Every job you have run', icon: 'history' },
  { to: '/pricing', label: 'Add credits', description: 'Compare plans and top up', icon: 'wallet' },
];

export function DashboardPage() {
  const { user, refresh } = useAuth();
  const toast = useToast();

  usePageMeta({ title: 'Dashboard', noIndex: true });

  const dashboard = useApiResource<DashboardStats>(
    (signal) => api.get('/api/dashboard', undefined, signal),
    [],
  );

  async function resendVerification() {
    try {
      await api.post('/api/auth/resend-verification');
      toast.success('If your address still needs verifying, a new link is on its way.');
      await refresh();
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'The request could not be completed.');
    }
  }

  const { data, loading, error } = dashboard;
  const totals = data?.totals;
  const wallet = data?.wallet;

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">
            {user ? `Welcome back, ${user.name.split(' ')[0]}` : 'Dashboard'}
          </h1>
          <p className="ac-page-header__subtitle">
            Start a check, pick up a job you left running, or top up your credits.
          </p>
        </div>
        <div className="ac-page-header__actions">
          <Link to="/checkers" className="ac-btn ac-btn--primary">
            <Icon name="plus" size={16} />
            New check
          </Link>
        </div>
      </header>

      <div className="ac-stack ac-stack--lg">
        {user && !user.email_verified && (
          <Alert tone="warning">
            <strong>Verify your email address.</strong> We sent a link to {user.email}. Verifying keeps
            password recovery working.{' '}
            <button
              type="button"
              className="ac-btn ac-btn--ghost ac-btn--sm"
              onClick={resendVerification}
              style={{ padding: 0, minHeight: 'auto', color: 'inherit', textDecoration: 'underline' }}
            >
              Send it again
            </button>
          </Alert>
        )}

        {error && <ErrorState message={error} onRetry={dashboard.reload} />}

        <div className="ac-grid ac-grid--three">
          <StatTile
            label="Available credits"
            value={formatNumber(wallet?.available ?? 0)}
            meta={
              wallet && wallet.reserved > 0
                ? `${formatNumber(wallet.reserved)} reserved by running jobs`
                : 'Ready to spend'
            }
            tone="accent"
            loading={loading}
          />
          <StatTile
            label="Checks run"
            value={formatNumber(totals?.checks ?? 0)}
            meta={`${formatNumber(totals?.jobs ?? 0)} jobs in total`}
            loading={loading}
          />
          <StatTile
            label="Confirmed"
            value={formatNumber(totals?.valid ?? 0)}
            meta="Status VALID"
            tone="valid"
            loading={loading}
          />
          <StatTile
            label="Not valid"
            value={formatNumber(totals?.invalid ?? 0)}
            meta="Status INVALID"
            tone="invalid"
            loading={loading}
          />
          <StatTile
            label="Could not verify"
            value={formatNumber((totals?.unavailable ?? 0) + (totals?.unknown ?? 0) + (totals?.errors ?? 0))}
            meta="UNAVAILABLE, UNKNOWN or ERROR"
            tone="unavailable"
            loading={loading}
          />
          <StatTile
            label="Credits used"
            value={formatNumber(totals?.credits_spent ?? 0)}
            meta="Across every job"
            loading={loading}
          />
        </div>

        <div className="ac-grid ac-grid--halves">
          <Card title="Usage" subtitle="Checks per day over the last two weeks.">
            {loading && <div className="ac-skeleton" style={{ height: 140 }} aria-hidden="true" />}
            {data && <UsageChart data={data.usage_by_day} />}
          </Card>

          <Card
            title="Recent jobs"
            actions={
              <Link to="/jobs" className="ac-btn ac-btn--ghost ac-btn--sm">
                View all
              </Link>
            }
            flush
          >
            {loading && <TableSkeleton rows={4} columns={3} />}

            {data && data.recent_jobs.length === 0 && (
              <EmptyState
                title="No jobs yet"
                body="Start a check and it will show up here with live progress."
                action={
                  <Link to="/checkers" className="ac-btn ac-btn--primary ac-btn--sm">
                    Choose a checker
                  </Link>
                }
              />
            )}

            {data && data.recent_jobs.length > 0 && (
              <ul role="list">
                {data.recent_jobs.map((job) => (
                  <li
                    key={job.id}
                    style={{
                      padding: 'var(--ac-space-4) var(--ac-space-6)',
                      borderBottom: '1px solid var(--ac-border)',
                    }}
                  >
                    <div className="ac-row ac-row--between">
                      <Link to={`/jobs/${job.id}`} style={{ fontWeight: 600 }}>
                        {job.checker_label}
                      </Link>
                      <JobStatusBadge status={job.status} />
                    </div>

                    <div
                      className="ac-row ac-row--between"
                      style={{ marginTop: 'var(--ac-space-1)', fontSize: 'var(--ac-text-xs)', color: 'var(--ac-text-muted)' }}
                    >
                      <span>
                        {formatNumber(job.processed_items)} / {formatNumber(job.total_items)} processed
                      </span>
                      <span>{formatRelative(job.created_at)}</span>
                    </div>

                    {job.status === 'PROCESSING' && (
                      <div style={{ marginTop: 'var(--ac-space-2)' }}>
                        <ProgressBar
                          value={job.processed_items}
                          max={job.total_items}
                          label={`${job.checker_label} progress`}
                        />
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <Card title="Quick actions">
          <div className="ac-grid ac-grid--three">
            {QUICK_ACTIONS.map((action) => (
              <Link
                key={action.to}
                to={action.to}
                className="ac-card"
                style={{ padding: 'var(--ac-space-4)', textDecoration: 'none', color: 'inherit' }}
              >
                <span
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: 36,
                    height: 36,
                    borderRadius: 'var(--ac-radius-md)',
                    background: 'var(--ac-accent-soft)',
                    color: 'var(--ac-accent)',
                  }}
                >
                  <Icon name={action.icon} size={18} />
                </span>
                <p style={{ marginTop: 'var(--ac-space-3)', fontWeight: 650 }}>{action.label}</p>
                <p style={{ fontSize: 'var(--ac-text-xs)', color: 'var(--ac-text-muted)' }}>
                  {action.description}
                </p>
              </Link>
            ))}
          </div>
        </Card>
      </div>
    </>
  );
}
