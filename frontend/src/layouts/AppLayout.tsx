import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { BrandMark } from '@/components/Brand';
import { Icon } from '@/components/Icon';
import type { IconName } from '@/components/Icon';
import { useAuth } from '@/context/AuthContext';
import { formatNumber } from '@/utils/format';

interface NavItem {
  to: string;
  label: string;
  icon: IconName;
  end?: boolean;
}

interface NavSection {
  label: string;
  items: NavItem[];
}

const USER_NAV: NavSection[] = [
  {
    label: 'Workspace',
    items: [
      { to: '/dashboard', label: 'Dashboard', icon: 'dashboard' },
      { to: '/checkers', label: 'Checkers', icon: 'checkers', end: true },
      { to: '/jobs', label: 'Jobs', icon: 'jobs' },
      { to: '/results', label: 'Results', icon: 'results' },
      { to: '/history', label: 'History', icon: 'history' },
    ],
  },
  {
    label: 'Tools',
    items: [
      { to: '/checkers/gmail', label: 'Gmail checker', icon: 'mail' },
      { to: '/checkers/platform', label: 'Platform checker', icon: 'platform' },
      { to: '/checkers/duplicates', label: 'Duplicate finder', icon: 'duplicate' },
      { to: '/checkers/name-generator', label: 'Name generator', icon: 'sparkle' },
    ],
  },
  {
    label: 'Account',
    items: [
      { to: '/wallet', label: 'Wallet', icon: 'wallet' },
      { to: '/pricing', label: 'Plans', icon: 'pricing' },
      { to: '/support', label: 'Support', icon: 'support' },
      { to: '/settings', label: 'Settings', icon: 'settings' },
    ],
  },
];

const ADMIN_NAV: NavSection = {
  label: 'Administration',
  items: [
    { to: '/admin', label: 'Overview', icon: 'admin', end: true },
    { to: '/admin/users', label: 'Users', icon: 'users' },
    { to: '/admin/jobs', label: 'Jobs', icon: 'jobs' },
    { to: '/admin/wallet', label: 'Wallet', icon: 'wallet' },
    { to: '/admin/plans', label: 'Plans', icon: 'pricing' },
    { to: '/admin/checkers', label: 'Checkers', icon: 'checkers' },
    { to: '/admin/logs', label: 'Logs', icon: 'logs' },
    { to: '/admin/settings', label: 'Settings', icon: 'settings' },
  ],
};

/**
 * Signed-in application shell: navigation rail, top bar and the routed page.
 *
 * On narrow viewports the rail becomes a drawer; it closes on navigation so a
 * tap on a link does not leave the overlay covering the page.
 */
export function AppLayout() {
  const { user, wallet, unreadNotifications, isAdmin, logout } = useAuth();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();

  useEffect(() => {
    setDrawerOpen(false);
  }, [location.pathname]);

  const sections = isAdmin ? [...USER_NAV, ADMIN_NAV] : USER_NAV;

  async function handleSignOut() {
    await logout();
    navigate('/login', { replace: true });
  }

  return (
    <div className="ac-shell">
      <a className="ac-skip-link" href="#ac-main-content">
        Skip to main content
      </a>

      {drawerOpen && (
        <button
          type="button"
          className="ac-sidebar__scrim"
          aria-label="Close navigation"
          onClick={() => setDrawerOpen(false)}
        />
      )}

      <nav
        className={drawerOpen ? 'ac-sidebar is-open' : 'ac-sidebar'}
        aria-label="Primary"
        id="ac-primary-nav"
      >
        <Link to="/dashboard" className="ac-sidebar__brand">
          <BrandMark size={26} />
          <span className="ac-sidebar__brand-name">AccountCheck</span>
        </Link>

        <div className="ac-sidebar__nav">
          {sections.map((section) => (
            <div className="ac-sidebar__section" key={section.label}>
              <p className="ac-sidebar__section-label">{section.label}</p>
              <ul role="list">
                {section.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={item.end}
                      className={({ isActive }) =>
                        isActive ? 'ac-sidebar__link is-active' : 'ac-sidebar__link'
                      }
                    >
                      <Icon name={item.icon} size={18} className="ac-sidebar__link-icon" />
                      {item.label}
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="ac-sidebar__footer">
          <Link to="/wallet" className="ac-sidebar__wallet">
            <span className="ac-sidebar__wallet-label">Wallet balance</span>
            <span className="ac-sidebar__wallet-value">
              {wallet ? `${formatNumber(wallet.available)} cr` : '—'}
            </span>
          </Link>
        </div>
      </nav>

      <div className="ac-main">
        <header className="ac-topbar">
          <button
            type="button"
            className="ac-topbar__toggle"
            onClick={() => setDrawerOpen((open) => !open)}
            aria-expanded={drawerOpen}
            aria-controls="ac-primary-nav"
          >
            <Icon name={drawerOpen ? 'close' : 'menu'} size={18} title="Toggle navigation" />
          </button>

          <div className="ac-topbar__heading">
            <p className="ac-topbar__title">{user?.name ?? 'AccountCheck'}</p>
            <p className="ac-topbar__breadcrumb">{user?.email ?? ''}</p>
          </div>

          <div className="ac-topbar__actions">
            <Link to="/notifications" className="ac-icon-btn" aria-label="Notifications">
              <Icon name="bell" size={19} />
              {unreadNotifications > 0 && (
                <span className="ac-icon-btn__dot">
                  {unreadNotifications > 99 ? '99+' : unreadNotifications}
                </span>
              )}
            </Link>

            <Link to="/profile" className="ac-icon-btn" aria-label="Your profile">
              <Icon name="user" size={19} />
            </Link>

            <button type="button" className="ac-icon-btn" onClick={handleSignOut} aria-label="Sign out">
              <Icon name="logout" size={19} />
            </button>
          </div>
        </header>

        <main className="ac-content" id="ac-main-content" tabIndex={-1}>
          <Outlet />
        </main>
      </div>
    </div>
  );
}
