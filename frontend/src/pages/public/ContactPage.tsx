import { Link } from 'react-router-dom';
import { usePageMeta } from '@/hooks/usePageMeta';
import { useAuth } from '@/context/AuthContext';

export function ContactPage() {
  const { user } = useAuth();

  usePageMeta({
    title: 'Contact',
    description: 'How to reach the AccountCheck team, and how to open a support ticket from inside the app.',
    canonicalPath: '/contact',
  });

  return (
    <section style={{ padding: 'var(--ac-space-12) 0' }}>
      <div className="ac-container" style={{ maxWidth: 760 }}>
        <h1>Contact</h1>
        <p style={{ marginTop: 'var(--ac-space-3)', color: 'var(--ac-text-muted)' }}>
          Support runs through tickets inside the app, so every message is attached to your account and its job
          history. That gives whoever answers the context they need without you having to describe it.
        </p>

        <div className="ac-card" style={{ marginTop: 'var(--ac-space-8)' }}>
          <div className="ac-card__body ac-stack">
            <div>
              <h2 style={{ fontSize: 'var(--ac-text-md)' }}>Open a support ticket</h2>
              <p style={{ marginTop: 'var(--ac-space-2)', fontSize: 'var(--ac-text-sm)', color: 'var(--ac-text-muted)' }}>
                Account questions, billing, a job that did not finish, or a checker reporting UNAVAILABLE when you
                expect it to be configured.
              </p>
            </div>

            {user ? (
              <Link to="/support" className="ac-btn ac-btn--primary" style={{ alignSelf: 'flex-start' }}>
                Go to support
              </Link>
            ) : (
              <div className="ac-row">
                <Link to="/login" className="ac-btn ac-btn--primary">
                  Sign in to open a ticket
                </Link>
                <Link to="/register" className="ac-btn ac-btn--secondary">
                  Create an account
                </Link>
              </div>
            )}
          </div>
        </div>

        <div className="ac-card" style={{ marginTop: 'var(--ac-space-4)' }}>
          <div className="ac-card__body">
            <h2 style={{ fontSize: 'var(--ac-text-md)' }}>Reporting a security issue</h2>
            <p style={{ marginTop: 'var(--ac-space-2)', fontSize: 'var(--ac-text-sm)', color: 'var(--ac-text-muted)' }}>
              Send security reports to the address configured for this deployment rather than opening a public
              ticket, and include enough detail to reproduce the issue. See docs/SECURITY.md in the repository for
              the disclosure process this deployment follows.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
