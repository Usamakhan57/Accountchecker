import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ApiError, api, setUnauthenticatedHandler } from '@/services/apiClient';
import type { Role, User, Wallet } from '@/types/api';

interface SessionPayload {
  user: User;
  wallet: Wallet;
  unread_notifications: number;
}

interface AuthState {
  user: User | null;
  wallet: Wallet | null;
  unreadNotifications: number;
  /** True until the first session probe resolves, so routes can hold render. */
  initializing: boolean;
}

interface AuthApi extends AuthState {
  login: (email: string, password: string, remember: boolean) => Promise<void>;
  register: (input: { name: string; email: string; password: string; password_confirmation: string }) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
  setWallet: (wallet: Wallet) => void;
  setUnreadNotifications: (count: number) => void;
  hasRole: (...roles: Role[]) => boolean;
  isAdmin: boolean;
}

const AuthContext = createContext<AuthApi | null>(null);

const ADMIN_ROLES: Role[] = ['ADMIN', 'MANAGER', 'SUPER_ADMIN'];

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({
    user: null,
    wallet: null,
    unreadNotifications: 0,
    initializing: true,
  });

  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const applySession = useCallback((payload: SessionPayload) => {
    if (!mounted.current) {
      return;
    }

    setState({
      user: payload.user,
      wallet: payload.wallet,
      unreadNotifications: payload.unread_notifications ?? 0,
      initializing: false,
    });
  }, []);

  const clearSession = useCallback(() => {
    if (!mounted.current) {
      return;
    }

    setState({ user: null, wallet: null, unreadNotifications: 0, initializing: false });
  }, []);

  const refresh = useCallback(async () => {
    try {
      applySession(await api.get<SessionPayload>('/api/user/me'));
    } catch (error) {
      // 401 simply means "not signed in"; anything else leaves the app signed
      // out too, because without a session there is nothing to show.
      if (!(error instanceof ApiError)) {
        throw error;
      }
      clearSession();
    }
  }, [applySession, clearSession]);

  // One session probe on mount. The HttpOnly cookie is not readable from JS,
  // so asking the API is the only way to know whether a session exists.
  useEffect(() => {
    void refresh();
  }, [refresh]);

  // Any 401 anywhere in the app drops local state, so a expired session cannot
  // leave stale user data on screen.
  useEffect(() => {
    setUnauthenticatedHandler(clearSession);

    return () => setUnauthenticatedHandler(null);
  }, [clearSession]);

  const login = useCallback(
    async (email: string, password: string, remember: boolean) => {
      applySession(await api.post<SessionPayload>('/api/auth/login', { email, password, remember }));
    },
    [applySession],
  );

  const register = useCallback(
    async (input: { name: string; email: string; password: string; password_confirmation: string }) => {
      applySession(await api.post<SessionPayload>('/api/auth/register', input));
    },
    [applySession],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/api/auth/logout');
    } finally {
      clearSession();
    }
  }, [clearSession]);

  const value = useMemo<AuthApi>(
    () => ({
      ...state,
      login,
      register,
      logout,
      refresh,
      setWallet: (wallet: Wallet) => setState((current) => ({ ...current, wallet })),
      setUnreadNotifications: (count: number) =>
        setState((current) => ({ ...current, unreadNotifications: count })),
      hasRole: (...roles: Role[]) => (state.user ? roles.includes(state.user.role) : false),
      isAdmin: state.user ? ADMIN_ROLES.includes(state.user.role) : false,
    }),
    [state, login, register, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthApi {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used inside an AuthProvider.');
  }

  return context;
}
