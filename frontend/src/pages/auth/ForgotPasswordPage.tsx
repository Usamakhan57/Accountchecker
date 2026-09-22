import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { TextField } from '@/components/Field';
import { Alert } from '@/components/states';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError, api } from '@/services/apiClient';

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  usePageMeta({ title: 'Reset your password', noIndex: true });

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await api.post('/api/auth/forgot-password', { email });
      // The server answers identically whether or not the address exists, and
      // so does this screen — it must not become an account oracle.
      setSent(true);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Something went wrong. Please try again.');
    } finally {
      setSubmitting(false);
    }
  }

  if (sent) {
    return (
      <Card title="Check your inbox">
        <div className="ac-stack">
          <Alert tone="success">
            If an account exists for {email}, a reset link is on its way. The link is valid for 60
            minutes and can be used once.
          </Alert>
          <p className="ac-muted" style={{ fontSize: 'var(--ac-text-sm)' }}>
            Nothing arrived? Check your spam folder, then{' '}
            <button
              type="button"
              className="ac-btn ac-btn--ghost ac-btn--sm"
              onClick={() => setSent(false)}
              style={{ padding: 0, minHeight: 'auto' }}
            >
              try a different address
            </button>
            .
          </p>
          <Link to="/login" className="ac-btn ac-btn--secondary ac-btn--block">
            Back to sign in
          </Link>
        </div>
      </Card>
    );
  }

  return (
    <Card
      title="Reset your password"
      subtitle="Enter your email address and we will send you a link to choose a new password."
    >
      <form className="ac-stack" onSubmit={handleSubmit} noValidate>
        {error && <Alert tone="error">{error}</Alert>}

        <TextField
          label="Email address"
          type="email"
          name="email"
          autoComplete="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
        />

        <button type="submit" className="ac-btn ac-btn--primary ac-btn--block" disabled={submitting}>
          {submitting ? 'Sending…' : 'Send reset link'}
        </button>

        <p className="ac-muted" style={{ textAlign: 'center', fontSize: 'var(--ac-text-sm)' }}>
          <Link to="/login">Back to sign in</Link>
        </p>
      </form>
    </Card>
  );
}
