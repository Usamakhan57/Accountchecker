import { lazy, Suspense } from 'react';
import { Route, Routes } from 'react-router-dom';
import { LoadingState } from '@/components/states';
import { AppLayout } from '@/layouts/AppLayout';
import { AdaptiveLayout } from '@/layouts/AdaptiveLayout';
import { AuthLayout } from '@/layouts/AuthLayout';
import { PublicLayout } from '@/layouts/PublicLayout';
import { NotFoundPage } from '@/pages/NotFoundPage';
import { RedirectIfAuthenticated, RequireAdmin, RequireAuth } from '@/router/guards';

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
const PricingPage = lazy(() => import('@/pages/PricingPage').then((m) => ({ default: m.PricingPage })));

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
const WalletPage = lazy(() => import('@/pages/app/WalletPage').then((m) => ({ default: m.WalletPage })));
const AdminOverviewPage = lazy(() =>
  import('@/pages/admin/AdminOverviewPage').then((m) => ({ default: m.AdminOverviewPage })),
);
const AdminUsersPage = lazy(() =>
  import('@/pages/admin/AdminUsersPage').then((m) => ({ default: m.AdminUsersPage })),
);
const AdminUserDetailPage = lazy(() =>
  import('@/pages/admin/AdminUserDetailPage').then((m) => ({ default: m.AdminUserDetailPage })),
);
const AdminJobsPage = lazy(() =>
  import('@/pages/admin/AdminJobsPage').then((m) => ({ default: m.AdminJobsPage })),
);
const AdminWalletPage = lazy(() =>
  import('@/pages/admin/AdminWalletPage').then((m) => ({ default: m.AdminWalletPage })),
);
const AdminPlansPage = lazy(() =>
  import('@/pages/admin/AdminPlansPage').then((m) => ({ default: m.AdminPlansPage })),
);
const AdminCheckersPage = lazy(() =>
  import('@/pages/admin/AdminCheckersPage').then((m) => ({ default: m.AdminCheckersPage })),
);
const AdminSupportPage = lazy(() =>
  import('@/pages/admin/AdminSupportPage').then((m) => ({ default: m.AdminSupportPage })),
);
const AdminTicketPage = lazy(() =>
  import('@/pages/admin/AdminTicketPage').then((m) => ({ default: m.AdminTicketPage })),
);
const AdminLogsPage = lazy(() =>
  import('@/pages/admin/AdminLogsPage').then((m) => ({ default: m.AdminLogsPage })),
);
const AdminSettingsPage = lazy(() =>
  import('@/pages/admin/AdminSettingsPage').then((m) => ({ default: m.AdminSettingsPage })),
);
const NotificationsPage = lazy(() =>
  import('@/pages/app/NotificationsPage').then((m) => ({ default: m.NotificationsPage })),
);
const SupportPage = lazy(() => import('@/pages/app/SupportPage').then((m) => ({ default: m.SupportPage })));
const SupportTicketPage = lazy(() =>
  import('@/pages/app/SupportTicketPage').then((m) => ({ default: m.SupportTicketPage })),
);
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

        {/* Pricing is reachable signed in or signed out and is the same page
            either way, so its shell is chosen from the session. */}
        <Route element={<AdaptiveLayout />}>
          <Route path="pricing" element={<PricingPage />} />
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
            <Route path="wallet" element={<WalletPage />} />
            <Route path="notifications" element={<NotificationsPage />} />
            <Route path="support" element={<SupportPage />} />
            <Route path="support/:id" element={<SupportTicketPage />} />

            <Route path="settings" element={<SettingsPage />} />
            <Route path="profile" element={<SettingsPage />} />

            {/* The panel. RequireAdmin decides what the browser renders; the
                API rejects a non-administrator regardless, answering 404 so
                the surface is not discoverable. */}
            <Route element={<RequireAdmin />}>
              <Route path="admin" element={<AdminOverviewPage />} />
              <Route path="admin/users" element={<AdminUsersPage />} />
              <Route path="admin/users/:id" element={<AdminUserDetailPage />} />
              <Route path="admin/jobs" element={<AdminJobsPage />} />
              <Route path="admin/wallet" element={<AdminWalletPage />} />
              <Route path="admin/plans" element={<AdminPlansPage />} />
              <Route path="admin/checkers" element={<AdminCheckersPage />} />
              <Route path="admin/support" element={<AdminSupportPage />} />
              <Route path="admin/support/:id" element={<AdminTicketPage />} />
              <Route path="admin/logs" element={<AdminLogsPage />} />
              <Route path="admin/settings" element={<AdminSettingsPage />} />
            </Route>
          </Route>
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
