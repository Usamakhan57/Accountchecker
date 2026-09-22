import { Link } from 'react-router-dom';
import { usePageMeta } from '@/hooks/usePageMeta';

export function NotFoundPage() {
  usePageMeta({ title: 'Page not found', noIndex: true });

  return (
    <div
      style={{
        display: 'grid',
        placeItems: 'center',
        minHeight: '100vh',
        padding: 'var(--ac-space-6)',
        textAlign: 'center',
      }}
    >
      <div style={{ maxWidth: 420 }}>
        <p className="ac-mono" style={{ color: 'var(--ac-accent)', fontWeight: 600 }}>
          404
        </p>
        <h1 style={{ marginTop: 'var(--ac-space-2)' }}>That page does not exist</h1>
        <p style={{ marginTop: 'var(--ac-space-3)', color: 'var(--ac-text-muted)' }}>
          The link may be out of date, or the page may have moved.
        </p>
        <div className="ac-row" style={{ marginTop: 'var(--ac-space-6)', justifyContent: 'center' }}>
          <Link to="/" className="ac-btn ac-btn--secondary">
            Go to the home page
          </Link>
          <Link to="/dashboard" className="ac-btn ac-btn--primary">
            Go to the dashboard
          </Link>
        </div>
      </div>
    </div>
  );
}
