import { Link, useLocation } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import type { IconName } from '@/components/Icon';
import { Alert, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listCheckers } from '@/services/checkers';
import type { CheckerCatalogue } from '@/services/checkers';
import type { CheckerType } from '@/types/api';
import { formatNumber } from '@/utils/format';

const CHECKER_ICONS: Record<string, IconName> = {
  gmail: 'mail',
  instagram: 'platform',
  facebook: 'platform',
  x: 'platform',
  tiktok: 'platform',
  threads: 'platform',
};

interface CheckersPageProps {
  /** Narrows the catalogue to one category, for the platform entry in the nav. */
  category?: CheckerType['category'];
  title?: string;
  subtitle?: string;
}

/**
 * The checker catalogue.
 *
 * Each card states what a check costs and whether an authorized verification
 * source is configured, before the user commits any credits. A checker without
 * one is shown rather than hidden, and says plainly what it will do.
 */
export function CheckersPage({ category, title, subtitle }: CheckersPageProps) {
  const location = useLocation();

  usePageMeta({
    title: title ?? 'Checkers',
    noIndex: true,
    canonicalPath: location.pathname,
  });

  const catalogue = useApiResource<CheckerCatalogue>((signal) => listCheckers(signal), []);
  const { data, loading, error, reload } = catalogue;

  const items = (data?.items ?? []).filter((item) => !category || item.category === category);
  const mockMode = items.some((item) => item.mode === 'mock');

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">{title ?? 'Checkers'}</h1>
          <p className="ac-page-header__subtitle">
            {subtitle ?? 'Pick a checker to start a batch. Every job runs in the background, so you can leave the page.'}
          </p>
        </div>
      </header>

      {mockMode && (
        <Alert tone="warning">
          <strong>Mock mode.</strong> This environment returns deterministic sample results and makes no
          outbound calls. Nothing shown here is a real verification.
        </Alert>
      )}

      {error && <ErrorState message={error} onRetry={reload} />}

      {loading && !data ? (
        <Card>
          <TableSkeleton rows={4} columns={3} />
        </Card>
      ) : (
        <div className="ac-checker-grid">
          {items.map((checker) => (
            <CheckerCard key={checker.slug} checker={checker} />
          ))}
        </div>
      )}
    </>
  );
}

function CheckerCard({ checker }: { checker: CheckerType }) {
  const unavailable = !checker.configured && checker.mode === 'production';

  return (
    <article className="ac-checker-card">
      <div className="ac-checker-card__head">
        <span className="ac-checker-card__icon" aria-hidden="true">
          <Icon name={CHECKER_ICONS[checker.slug] ?? 'checkers'} size={20} />
        </span>
        <div>
          <h2 className="ac-checker-card__title">{checker.label}</h2>
          <p className="ac-checker-card__meta">
            {formatNumber(checker.credit_cost)} {checker.credit_cost === 1 ? 'credit' : 'credits'} per record ·
            up to {formatNumber(checker.max_batch_size)} per job
          </p>
        </div>
      </div>

      <p className="ac-checker-card__body">{checker.description}</p>

      {unavailable ? (
        <p className="ac-checker-card__notice">
          No authorized verification source is configured for this checker yet. Jobs will report
          <strong> Unavailable</strong> and you will not be charged for them.
        </p>
      ) : null}

      <div className="ac-checker-card__foot">
        <Link
          to={`/checkers/${checker.slug}`}
          className="ac-btn ac-btn--primary ac-btn--sm"
          aria-label={`Open the ${checker.label}`}
        >
          Open checker
          <Icon name="chevronRight" size={14} />
        </Link>
        {checker.mode === 'mock' && <span className="ac-badge ac-badge--neutral">Mock mode</span>}
      </div>
    </article>
  );
}

/** The platform-only view the navigation rail links to. */
export function PlatformCheckersPage() {
  return (
    <CheckersPage
      category="platform"
      title="Platform checkers"
      subtitle="Check handles on supported platforms through their authorized APIs."
    />
  );
}
