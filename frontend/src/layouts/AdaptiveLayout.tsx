import { AppLayout } from '@/layouts/AppLayout';
import { PublicLayout } from '@/layouts/PublicLayout';
import { useAuth } from '@/context/AuthContext';
import { LoadingState } from '@/components/states';

/**
 * Chrome chosen by session, for a page that belongs to both sides.
 *
 * Pricing is linked from the marketing navigation and from the workspace
 * sidebar, and it is the same page in both places. Rather than duplicating the
 * route, it renders inside whichever shell fits the visitor: a signed-in user
 * stays in the workspace instead of being thrown out to the marketing site.
 */
export function AdaptiveLayout() {
  const { user, initializing } = useAuth();

  if (initializing) {
    return (
      <div style={{ display: 'grid', placeItems: 'center', minHeight: '60vh' }}>
        <LoadingState />
      </div>
    );
  }

  return user ? <AppLayout /> : <PublicLayout />;
}
