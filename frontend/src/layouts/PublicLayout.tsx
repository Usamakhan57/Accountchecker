import { Link, NavLink, Outlet } from 'react-router-dom';
import { BrandMark } from '@/components/Brand';
import { useAuth } from '@/context/AuthContext';

const LINKS = [
  { to: '/features', label: 'Features' },
  { to: '/pricing', label: 'Pricing' },
  { to: '/faq', label: 'FAQ' },
  { to: '/contact', label: 'Contact' },
];

/**
 * Shell for the indexable marketing pages.
 */
export function PublicLayout() {
  const { user } = useAuth();

  return (
    <div className="ac-public">
      <a className="ac-skip-link" href="#ac-public-content">
        Skip to main content
      </a>

      <header className="ac-public__header">
        <Link to="/" className="ac-row" style={{ gap: '0.625rem', textDecoration: 'none' }}>
          <BrandMark size={26} />
          <span style={{ fontWeight: 700, letterSpacing: '-0.015em', color: 'var(--ac-navy-800)' }}>
            AccountCheck
          </span>
        </Link>

        <nav className="ac-public__nav" aria-label="Marketing">
          {LINKS.map((link) => (
            <NavLink key={link.to} to={link.to} className={({ isActive }) => (isActive ? 'is-active' : '')}>
              {link.label}
            </NavLink>
          ))}
        </nav>

        <div className="ac-row" style={{ marginLeft: 'auto', gap: '0.5rem' }}>
          {user ? (
            <Link to="/dashboard" className="ac-btn ac-btn--primary ac-btn--sm">
              Open dashboard
            </Link>
          ) : (
            <>
              <Link to="/login" className="ac-btn ac-btn--ghost ac-btn--sm">
                Sign in
              </Link>
              <Link to="/register" className="ac-btn ac-btn--primary ac-btn--sm">
                Create account
              </Link>
            </>
          )}
        </div>
      </header>

      <main className="ac-public__main" id="ac-public-content" tabIndex={-1}>
        <Outlet />
      </main>

      <footer className="ac-public__footer">
        <div className="ac-container ac-row ac-row--between" style={{ alignItems: 'flex-start' }}>
          <div>
            <p style={{ fontWeight: 650, color: 'var(--ac-text)' }}>AccountCheck</p>
            <p style={{ maxWidth: '46ch' }}>
              Batch verification for emails and platform handles, through authorized APIs only.
            </p>
          </div>
          <nav className="ac-row" style={{ gap: 'var(--ac-space-5)' }} aria-label="Footer">
            {LINKS.map((link) => (
              <Link key={link.to} to={link.to}>
                {link.label}
              </Link>
            ))}
          </nav>
        </div>
      </footer>
    </div>
  );
}
