import { lazy, Suspense } from 'react';
import { Route, Routes } from 'react-router-dom';
import { LoadingState } from '@/components/states';
import { AppLayout } from '@/layouts/AppLayout';
import { AuthLayout } from '@/layouts/AuthLayout';
import { PublicLayout } from '@/layouts/PublicLayout';
import { NotFoundPage } from '@/pages/NotFoundPage';
import { RedirectIfAuthenticated, RequireAuth } from '@/router/guards';

/**
 * Application routes.
 *
 * Every page is code-split, so the first paint loads the shell and one route
 * rather than the whole product. Guards wrap the authenticated branch; the API
 * enforces the same rules independently, so a guard is a convenience and never
 * the boundary.
 */

const HomePage = lazy(() => import('@/pages/public/HomePage').then((m) => ({ default: m.HomePage })));
const FeaturesPage = lazy(() => import('@/pages/public/FeaturesPage').then((m) => ({ default: m.FeaturesPage })));
const FaqPage = lazy(() => import('@/pages/public/FaqPage').then((m) => ({ default: m.FaqPage })));
const ContactPage = lazy(() => import('@/pages/public/ContactPage').then((m) => ({ default: m.ContactPage })));

const LoginPage = lazy(() => import('@/pages/auth/LoginPage').then((m) => ({ default: m.LoginPage })));
const RegisterPage = lazy(() => import('@/pages/auth/RegisterPage').then((m) => ({ default: m.RegisterPage })));
const ForgotPasswordPage = lazy(() =>
  import('@/pages/auth/ForgotPasswordPage').then((m) => ({ default: m.ForgotPasswordPage })),
);
const ResetPasswordPage = lazy(() =>
  import('@/pages/auth/ResetPasswordPage').then((m) => ({ default: m.ResetPasswordPage })),
);
const VerifyEmailPage = lazy(() =>
  import('@/pages/auth/VerifyEmailPage').then((m) => ({ default: m.VerifyEmailPage })),
);

const DashboardPage = lazy(() => import('@/pages/app/DashboardPage').then((m) => ({ default: m.DashboardPage })));
const CheckersPage = lazy(() => import('@/pages/app/CheckersPage').then((m) => ({ default: m.CheckersPage })));
const PlatformCheckersPage = lazy(() =>
  import('@/pages/app/CheckersPage').then((m) => ({ default: m.PlatformCheckersPage })),
);
const CheckerWorkspacePage = lazy(() =>
  import('@/pages/app/CheckerWorkspacePage').then((m) => ({ default: m.CheckerWorkspacePage })),
);
const DuplicateCheckerPage = lazy(() =>
  import('@/pages/app/DuplicateCheckerPage').then((m) => ({ default: m.DuplicateCheckerPage })),
);
const NameGeneratorPage = lazy(() =>
  import('@/pages/app/NameGeneratorPage').then((m) => ({ default: m.NameGeneratorPage })),
);
const JobsPage = lazy(() => import('@/pages/app/JobsPage').then((m) => ({ default: m.JobsPage })));
const JobDetailPage = lazy(() => import('@/pages/app/JobDetailPage').then((m) => ({ default: m.JobDetailPage })));
const ResultsPage = lazy(() => import('@/pages/app/ResultsPage').then((m) => ({ default: m.ResultsPage })));
const HistoryPage = lazy(() => import('@/pages/app/HistoryPage').then((m) => ({ default: m.HistoryPage })));
const SettingsPage = lazy(() => import('@/pages/app/SettingsPage').then((m) => ({ default: m.SettingsPage })));

function RouteFallback() {
  return (
    <div style={{ display: 'grid', placeItems: 'center', minHeight: '50vh' }}>
      <LoadingState />
    </div>
  );
}

export function AppRoutes() {
  return (
    <Suspense fallback={<RouteFallback />}>
      <Routes>
        <Route element={<PublicLayout />}>
          <Route index element={<HomePage />} />
          <Route path="features" element={<FeaturesPage />} />
          <Route path="faq" element={<FaqPage />} />
          <Route path="contact" element={<ContactPage />} />
        </Route>

        <Route element={<AuthLayout />}>
          {/* Verification and reset work whether or not a session exists, so
              they sit outside RedirectIfAuthenticated. */}
          <Route path="reset-password" element={<ResetPasswordPage />} />
          <Route path="verify-email" element={<VerifyEmailPage />} />

          <Route element={<RedirectIfAuthenticated />}>
            <Route path="login" element={<LoginPage />} />
            <Route path="register" element={<RegisterPage />} />
            <Route path="forgot-password" element={<ForgotPasswordPage />} />
          </Route>
        </Route>

        <Route element={<RequireAuth />}>
          <Route element={<AppLayout />}>
            <Route path="dashboard" element={<DashboardPage />} />

            <Route path="checkers" element={<CheckersPage />} />
            {/* A static segment, so it is matched ahead of :slug and the
                navigation rail's platform entry resolves to the picker rather
                than to a checker called "platform". */}
            <Route path="checkers/platform" element={<PlatformCheckersPage />} />
            {/* Free tools. Static segments, so they resolve ahead of :slug. */}
            <Route path="checkers/duplicates" element={<DuplicateCheckerPage />} />
            <Route path="checkers/name-generator" element={<NameGeneratorPage />} />
            <Route path="checkers/:slug" element={<CheckerWorkspacePage />} />

            <Route path="jobs" element={<JobsPage />} />
            <Route path="jobs/:id" element={<JobDetailPage />} />
            <Route path="results" element={<ResultsPage />} />
            <Route path="history" element={<HistoryPage />} />
            <Route path="settings" element={<SettingsPage />} />
            <Route path="profile" element={<SettingsPage />} />
          </Route>
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
