import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Card } from '@/components/Card';
import { TextField } from '@/components/Field';
import { Alert } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';

interface LocationState {
  from?: string;
}

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  usePageMeta({ title: 'Sign in', noIndex: true });

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setFormError(null);
    setFieldErrors({});

    try {
      await login(email, password, remember);

      const destination = (location.state as LocationState | null)?.from ?? '/dashboard';
      navigate(destination, { replace: true });
    } catch (error) {
      if (error instanceof ApiError) {
        setFormError(error.message);
        setFieldErrors({
          email: error.fieldError('email') ?? '',
          password: error.fieldError('password') ?? '',
        });
      } else {
        setFormError('Something went wrong. Please try again.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Card title="Sign in" subtitle="Use the email address you registered with.">
      <form className="ac-stack" onSubmit={handleSubmit} noValidate>
        {formError && <Alert tone="error">{formError}</Alert>}

        <TextField
          label="Email address"
          type="email"
          name="email"
          autoComplete="email"
          required
          value={email}
          error={fieldErrors.email || undefined}
          onChange={(event) => setEmail(event.target.value)}
        />

        <TextField
          label="Password"
          type="password"
          name="password"
          autoComplete="current-password"
          required
          value={password}
          error={fieldErrors.password || undefined}
          onChange={(event) => setPassword(event.target.value)}
        />

        <div className="ac-row ac-row--between">
          <label className="ac-checkbox">
            <input
              type="checkbox"
              checked={remember}
              onChange={(event) => setRemember(event.target.checked)}
            />
            Keep me signed in
          </label>

          <Link to="/forgot-password" style={{ fontSize: 'var(--ac-text-sm)' }}>
            Forgot your password?
          </Link>
        </div>

        <button type="submit" className="ac-btn ac-btn--primary ac-btn--block" disabled={submitting}>
          {submitting ? <span className="ac-spinner" aria-hidden="true" /> : null}
          {submitting ? 'Signing in…' : 'Sign in'}
        </button>

        <p className="ac-muted" style={{ textAlign: 'center', fontSize: 'var(--ac-text-sm)' }}>
          No account yet? <Link to="/register">Create one</Link>
        </p>
      </form>
    </Card>
  );
}
