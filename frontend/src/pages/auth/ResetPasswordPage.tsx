import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Card } from '@/components/Card';
import { TextField } from '@/components/Field';
import { Alert } from '@/components/states';
import { useToast } from '@/components/ToastProvider';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError, api } from '@/services/apiClient';

export function ResetPasswordPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const toast = useToast();

  const token = searchParams.get('token') ?? '';

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();

  usePageMeta({ title: 'Choose a new password', noIndex: true });

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    setFieldError(undefined);

    try {
      await api.post('/api/auth/reset-password', {
        token,
        password,
        password_confirmation: confirmation,
      });

      toast.success('Your password has been changed. Sign in with the new one.');
      navigate('/login', { replace: true });
    } catch (caught) {
      if (caught instanceof ApiError) {
        setError(caught.message);
        setFieldError(caught.fieldError('password'));
      } else {
        setError('Something went wrong. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!token) {
    return (
      <Card title="That link is incomplete">
        <div className="ac-stack">
          <Alert tone="error">
            This page needs the reset token from the link in your email. Open the link directly, or
            request a new one.
          </Alert>
          <Link to="/forgot-password" className="ac-btn ac-btn--primary ac-btn--block">
            Request a new link
          </Link>
        </div>
      </Card>
    );
  }

  return (
    <Card title="Choose a new password" subtitle="Signing in on your other devices will be required again.">
      <form className="ac-stack" onSubmit={handleSubmit} noValidate>
        {error && <Alert tone="error">{error}</Alert>}

        <TextField
          label="New password"
          type="password"
          name="password"
          autoComplete="new-password"
          required
          value={password}
          error={fieldError}
          hint="At least 10 characters, with an uppercase letter, a lowercase letter and a number."
          onChange={(event) => setPassword(event.target.value)}
        />

        <TextField
          label="Confirm new password"
          type="password"
          name="password_confirmation"
          autoComplete="new-password"
          required
          value={confirmation}
          error={confirmation && confirmation !== password ? 'The two passwords do not match.' : undefined}
          onChange={(event) => setConfirmation(event.target.value)}
        />

        <button type="submit" className="ac-btn ac-btn--primary ac-btn--block" disabled={submitting}>
          {submitting ? 'Saving…' : 'Change password'}
        </button>
      </form>
    </Card>
  );
}
