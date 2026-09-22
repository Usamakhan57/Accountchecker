import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Alert, LoadingState } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError, api } from '@/services/apiClient';

type VerificationState = 'checking' | 'verified' | 'failed';

export function VerifyEmailPage() {
  const [searchParams] = useSearchParams();
  const { user, refresh } = useAuth();
  const token = searchParams.get('token') ?? '';

  const [state, setState] = useState<VerificationState>(token ? 'checking' : 'failed');
  const [message, setMessage] = useState(
    token ? '' : 'This page needs the verification token from the link in your email.',
  );

  // StrictMode mounts effects twice in development; the token is single-use, so
  // the second run would always report failure.
  const attempted = useRef(false);

  usePageMeta({ title: 'Verify your email', noIndex: true });

  useEffect(() => {
    if (!token || attempted.current) {
      return;
    }

    attempted.current = true;

    api
      .post('/api/auth/verify-email', { token })
      .then(async () => {
        setState('verified');
        if (user) {
          await refresh();
        }
      })
      .catch((error: unknown) => {
        setState('failed');
        setMessage(
          error instanceof ApiError ? error.message : 'The verification link could not be processed.',
        );
      });
  }, [token, user, refresh]);

  if (state === 'checking') {
    return (
      <Card title="Verifying your email">
        <LoadingState label="Checking your verification link…" />
      </Card>
    );
  }

  if (state === 'verified') {
    return (
      <Card title="Email verified">
        <div className="ac-stack">
          <Alert tone="success">Your email address is confirmed.</Alert>
          <Link to={user ? '/dashboard' : '/login'} className="ac-btn ac-btn--primary ac-btn--block">
            {user ? 'Go to the dashboard' : 'Sign in'}
          </Link>
        </div>
      </Card>
    );
  }

  return (
    <Card title="That link did not work">
      <div className="ac-stack">
        <Alert tone="error">{message}</Alert>
        <p className="ac-muted" style={{ fontSize: 'var(--ac-text-sm)' }}>
          Verification links expire after 24 hours and can be used once. Sign in and request a new one
          from your profile.
        </p>
        <Link to="/login" className="ac-btn ac-btn--secondary ac-btn--block">
          Back to sign in
        </Link>
      </div>
    </Card>
  );
}
