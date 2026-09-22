import { useState } from 'react';
import type { FormEvent } from 'react';
import { Card } from '@/components/Card';
import { TextField } from '@/components/Field';
import { Alert, ErrorState, LoadingState } from '@/components/states';
import { useToast } from '@/components/ToastProvider';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError, api } from '@/services/apiClient';
import { formatDateTime } from '@/utils/format';

interface SessionRow {
  id: number;
  ip_address: string | null;
  user_agent: string;
  created_at: string;
  last_active_at: string;
  expires_at: string;
  is_current: boolean;
}

export function SettingsPage() {
  const { user, refresh } = useAuth();
  const toast = useToast();

  usePageMeta({ title: 'Settings', noIndex: true });

  const sessions = useApiResource<{ items: SessionRow[] }>(
    (signal) => api.get('/api/user/sessions', undefined, signal),
    [],
  );

  const [name, setName] = useState(user?.name ?? '');
  const [savingProfile, setSavingProfile] = useState(false);

  const [passwords, setPasswords] = useState({ current: '', next: '', confirmation: '' });
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [passwordFieldErrors, setPasswordFieldErrors] = useState<Record<string, string>>({});
  const [savingPassword, setSavingPassword] = useState(false);

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingProfile(true);

    try {
      await api.put('/api/user/profile', { name });
      await refresh();
      toast.success('Profile updated.');
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'The profile could not be saved.');
    } finally {
      setSavingProfile(false);
    }
  }

  async function changePassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingPassword(true);
    setPasswordError(null);
    setPasswordFieldErrors({});

    try {
      const result = await api.post<{ other_sessions_signed_out: number }>('/api/auth/change-password', {
        current_password: passwords.current,
        password: passwords.next,
        password_confirmation: passwords.confirmation,
      });

      setPasswords({ current: '', next: '', confirmation: '' });
      sessions.reload();
      toast.success(
        result.other_sessions_signed_out > 0
          ? `Password changed. ${result.other_sessions_signed_out} other session(s) signed out.`
          : 'Password changed.',
      );
    } catch (error) {
      if (error instanceof ApiError) {
        setPasswordError(error.message);
        setPasswordFieldErrors({
          current_password: error.fieldError('current_password') ?? '',
          password: error.fieldError('password') ?? '',
        });
      } else {
        setPasswordError('The password could not be changed.');
      }
    } finally {
      setSavingPassword(false);
    }
  }

  async function revokeOthers() {
    try {
      const result = await api.post<{ revoked: number }>('/api/user/sessions/revoke-others');
      sessions.reload();
      toast.success(
        result.revoked > 0 ? `${result.revoked} session(s) signed out.` : 'No other sessions were active.',
      );
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'The sessions could not be signed out.');
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Settings</h1>
          <p className="ac-page-header__subtitle">Your profile, your password and the devices signed in to your account.</p>
        </div>
      </header>

      <div className="ac-stack ac-stack--lg">
        <Card title="Profile">
          <form className="ac-stack" onSubmit={saveProfile}>
            <TextField
              label="Your name"
              value={name}
              required
              onChange={(event) => setName(event.target.value)}
            />
            <TextField
              label="Email address"
              value={user?.email ?? ''}
              disabled
              hint={
                user?.email_verified
                  ? 'Verified. Contact support to change the address on this account.'
                  : 'Not verified yet. Check your inbox for the verification link.'
              }
            />
            <div>
              <button type="submit" className="ac-btn ac-btn--primary" disabled={savingProfile}>
                {savingProfile ? 'Saving…' : 'Save profile'}
              </button>
            </div>
          </form>
        </Card>

        <Card title="Password" subtitle="Changing your password signs out every other device.">
          <form className="ac-stack" onSubmit={changePassword} noValidate>
            {passwordError && <Alert tone="error">{passwordError}</Alert>}

            <TextField
              label="Current password"
              type="password"
              autoComplete="current-password"
              required
              value={passwords.current}
              error={passwordFieldErrors.current_password || undefined}
              onChange={(event) => setPasswords((p) => ({ ...p, current: event.target.value }))}
            />
            <TextField
              label="New password"
              type="password"
              autoComplete="new-password"
              required
              value={passwords.next}
              error={passwordFieldErrors.password || undefined}
              hint="At least 10 characters, with an uppercase letter, a lowercase letter and a number."
              onChange={(event) => setPasswords((p) => ({ ...p, next: event.target.value }))}
            />
            <TextField
              label="Confirm new password"
              type="password"
              autoComplete="new-password"
              required
              value={passwords.confirmation}
              error={
                passwords.confirmation && passwords.confirmation !== passwords.next
                  ? 'The two passwords do not match.'
                  : undefined
              }
              onChange={(event) => setPasswords((p) => ({ ...p, confirmation: event.target.value }))}
            />
            <div>
              <button type="submit" className="ac-btn ac-btn--primary" disabled={savingPassword}>
                {savingPassword ? 'Changing…' : 'Change password'}
              </button>
            </div>
          </form>
        </Card>

        <Card
          title="Signed-in devices"
          subtitle="If you do not recognise one of these, change your password."
          actions={
            <button type="button" className="ac-btn ac-btn--secondary ac-btn--sm" onClick={revokeOthers}>
              Sign out other devices
            </button>
          }
          flush
        >
          {sessions.loading && <LoadingState label="Loading your sessions…" />}
          {sessions.error && <ErrorState message={sessions.error} onRetry={sessions.reload} />}

          {sessions.data && (
            <div className="ac-table-wrap">
              <table className="ac-table">
                <thead>
                  <tr>
                    <th scope="col">Device</th>
                    <th scope="col">IP address</th>
                    <th scope="col">Signed in</th>
                    <th scope="col">Last active</th>
                  </tr>
                </thead>
                <tbody>
                  {sessions.data.items.map((session) => (
                    <tr key={session.id}>
                      <td>
                        <span className="ac-truncate" style={{ maxWidth: '32ch', display: 'inline-block' }}>
                          {session.user_agent || 'Unknown device'}
                        </span>
                        {session.is_current && (
                          <span className="ac-badge ac-badge--info" style={{ marginLeft: 'var(--ac-space-2)' }}>
                            This device
                          </span>
                        )}
                      </td>
                      <td className="ac-table__mono">{session.ip_address ?? '—'}</td>
                      <td>{formatDateTime(session.created_at)}</td>
                      <td>{formatDateTime(session.last_active_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>
    </>
  );
}
