import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Card } from '@/components/Card';
import { TextField } from '@/components/Field';
import { Alert } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';

/** Mirrors the server's password rules so the requirements are visible up front. */
const PASSWORD_RULES = [
  { label: 'At least 10 characters', test: (value: string) => value.length >= 10 },
  { label: 'A lowercase letter', test: (value: string) => /[a-z]/.test(value) },
  { label: 'An uppercase letter', test: (value: string) => /[A-Z]/.test(value) },
  { label: 'A number', test: (value: string) => /\d/.test(value) },
];

export function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState({ name: '', email: '', password: '', passwordConfirmation: '' });
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  usePageMeta({ title: 'Create an account', noIndex: true });

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setFormError(null);
    setFieldErrors({});

    try {
      await register({
        name: form.name,
        email: form.email,
        password: form.password,
        password_confirmation: form.passwordConfirmation,
      });

      navigate('/dashboard', { replace: true });
    } catch (error) {
      if (error instanceof ApiError) {
        setFormError(error.isValidation ? 'Check the highlighted fields.' : error.message);
        setFieldErrors({
          name: error.fieldError('name') ?? '',
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
    <Card title="Create your account" subtitle="You get starter credits to try the checkers.">
      <form className="ac-stack" onSubmit={handleSubmit} noValidate>
        {formError && <Alert tone="error">{formError}</Alert>}

        <TextField
          label="Your name"
          name="name"
          autoComplete="name"
          required
          value={form.name}
          error={fieldErrors.name || undefined}
          onChange={(event) => update('name', event.target.value)}
        />

        <TextField
          label="Email address"
          type="email"
          name="email"
          autoComplete="email"
          required
          value={form.email}
          error={fieldErrors.email || undefined}
          onChange={(event) => update('email', event.target.value)}
        />

        <TextField
          label="Password"
          type="password"
          name="password"
          autoComplete="new-password"
          required
          value={form.password}
          error={fieldErrors.password || undefined}
          onChange={(event) => update('password', event.target.value)}
        />

        <ul
          role="list"
          className="ac-stack ac-stack--sm"
          style={{ fontSize: 'var(--ac-text-xs)', marginTop: 'calc(var(--ac-space-2) * -1)' }}
        >
          {PASSWORD_RULES.map((rule) => {
            const met = rule.test(form.password);

            return (
              <li
                key={rule.label}
                style={{ color: met ? 'var(--ac-valid-text)' : 'var(--ac-text-muted)' }}
              >
                <span aria-hidden="true">{met ? '✓' : '○'}</span> {rule.label}
              </li>
            );
          })}
        </ul>

        <TextField
          label="Confirm password"
          type="password"
          name="password_confirmation"
          autoComplete="new-password"
          required
          value={form.passwordConfirmation}
          error={
            form.passwordConfirmation && form.passwordConfirmation !== form.password
              ? 'The two passwords do not match.'
              : undefined
          }
          onChange={(event) => update('passwordConfirmation', event.target.value)}
        />

        <button type="submit" className="ac-btn ac-btn--primary ac-btn--block" disabled={submitting}>
          {submitting ? <span className="ac-spinner" aria-hidden="true" /> : null}
          {submitting ? 'Creating your account…' : 'Create account'}
        </button>

        <p className="ac-muted" style={{ textAlign: 'center', fontSize: 'var(--ac-text-sm)' }}>
          Already registered? <Link to="/login">Sign in</Link>
        </p>
      </form>
    </Card>
  );
}
