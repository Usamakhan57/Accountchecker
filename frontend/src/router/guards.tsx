import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { LoadingState } from '@/components/states';
import { useAuth } from '@/context/AuthContext';

/**
 * Route guards.
 *
 * These control what the browser renders. They are a convenience, never a
 * security boundary: every endpoint enforces authentication and role checks
 * server-side regardless of what the client decides to show.
 */

function SessionGate() {
  return (
    <div style={{ display: 'grid', placeItems: 'center', minHeight: '100vh' }}>
      <LoadingState label="Checking your session…" />
    </div>
  );
}

export function RequireAuth() {
  const { user, initializing } = useAuth();
  const location = useLocation();

  if (initializing) {
    return <SessionGate />;
  }

  if (!user) {
    // Remember where they were headed so sign-in can return them there.
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }

  return <Outlet />;
}

export function RequireAdmin() {
  const { user, initializing, isAdmin } = useAuth();

  if (initializing) {
    return <SessionGate />;
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (!isAdmin) {
    return <Navigate to="/dashboard" replace />;
  }

  return <Outlet />;
}

/** Keeps a signed-in user off the sign-in and registration screens. */
export function RedirectIfAuthenticated() {
  const { user, initializing } = useAuth();

  if (initializing) {
    return <SessionGate />;
  }

  return user ? <Navigate to="/dashboard" replace /> : <Outlet />;
}
