import { lazy, Suspense } from 'react';
import { Route, Routes } from 'react-router-dom';
import { LoadingState } from '@/components/states';
import { PublicLayout } from '@/layouts/PublicLayout';
import { NotFoundPage } from '@/pages/NotFoundPage';

/**
 * Application routes.
 *
 * Every page is code-split, so the first paint loads the shell and one route
 * rather than the whole product. Guards wrap the authenticated and admin
 * branches; the API enforces the same rules independently.
 */

const HomePage = lazy(() => import('@/pages/public/HomePage').then((m) => ({ default: m.HomePage })));
const FeaturesPage = lazy(() => import('@/pages/public/FeaturesPage').then((m) => ({ default: m.FeaturesPage })));
const FaqPage = lazy(() => import('@/pages/public/FaqPage').then((m) => ({ default: m.FaqPage })));
const ContactPage = lazy(() => import('@/pages/public/ContactPage').then((m) => ({ default: m.ContactPage })));

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

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
