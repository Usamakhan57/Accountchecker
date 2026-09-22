import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import type { IconName } from '@/components/Icon';
import { StatTile } from '@/components/StatTile';
import { Alert } from '@/components/states';
import { useToast } from '@/components/ToastProvider';
import { useAuth } from '@/context/AuthContext';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError, api } from '@/services/apiClient';
import { formatDateTime, formatNumber } from '@/utils/format';

const QUICK_ACTIONS: { to: string; label: string; description: string; icon: IconName }[] = [
  { to: '/checkers/gmail', label: 'Gmail checker', description: 'Validate and verify email addresses', icon: 'mail' },
  { to: '/checkers/platform', label: 'Platform checker', description: 'Check handles on supported platforms', icon: 'platform' },
  { to: '/checkers/duplicates', label: 'Duplicate finder', description: 'Free — no credits used', icon: 'duplicate' },
  { to: '/checkers/name-generator', label: 'Name generator', description: 'Free — no credits used', icon: 'sparkle' },
];

export function DashboardPage() {
  const { user, wallet, refresh } = useAuth();
  const toast = useToast();

  usePageMeta({ title: 'Dashboard', noIndex: true });

  async function resendVerification() {
    try {
      await api.post('/api/auth/resend-verification');
      toast.success('If your address still needs verifying, a new link is on its way.');
      await refresh();
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'The request could not be completed.');
    }
  }

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

        <div className="ac-grid ac-grid--stats">
          <StatTile
            label="Available credits"
            value={formatNumber(wallet?.available ?? 0)}
            meta={
              wallet && wallet.reserved > 0
                ? `${formatNumber(wallet.reserved)} reserved by running jobs`
                : 'Ready to spend'
            }
            tone="accent"
          />
          <StatTile label="Wallet balance" value={formatNumber(wallet?.balance ?? 0)} meta="Total credits owned" />
          <StatTile label="Account" value={user?.role === 'USER' ? 'Standard' : (user?.role ?? '—')} meta={user?.email} />
          <StatTile
            label="Member since"
            value={user?.created_at ? formatDateTime(user.created_at).split(',')[0] : '—'}
            meta={user?.last_login_at ? `Last signed in ${formatDateTime(user.last_login_at)}` : 'First session'}
          />
        </div>

        <Card title="Start a check" subtitle="Pick a checker to open its workspace.">
          <div className="ac-grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))' }}>
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
