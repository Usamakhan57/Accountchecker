import { Link, Outlet } from 'react-router-dom';
import { BrandMark } from '@/components/Brand';

/**
 * Shell for the sign-in, registration and password routes.
 */
export function AuthLayout() {
  return (
    <div className="ac-auth">
      <div className="ac-auth__panel">
        <div className="ac-auth__card">
          <Link to="/" className="ac-auth__brand" style={{ textDecoration: 'none' }}>
            <BrandMark size={32} />
            <span className="ac-auth__brand-name">AccountCheck</span>
          </Link>

          <Outlet />

          <p
            className="ac-muted"
            style={{ marginTop: 'var(--ac-space-6)', textAlign: 'center', fontSize: 'var(--ac-text-xs)' }}
          >
            AccountCheck verifies records only through authorized APIs and permitted data sources.
          </p>
        </div>
      </div>
    </div>
  );
}
