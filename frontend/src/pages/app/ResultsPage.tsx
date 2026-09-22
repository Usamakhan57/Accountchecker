import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { ExportButton } from '@/components/ExportButton';
import { Icon } from '@/components/Icon';
import { Pagination } from '@/components/Pagination';
import { ResultBreakdownTiles, ResultsTable } from '@/components/ResultsTable';
import { ErrorState } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listCheckers } from '@/services/checkers';
import type { CheckerCatalogue } from '@/services/checkers';
import { createExport } from '@/services/exports';
import { listResults } from '@/services/results';
import type { ResultFilters, ResultsPage as ResultsPageData, ResultStatus } from '@/types/api';

const STATUS_FILTERS: { value: ResultStatus | ''; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'VALID', label: 'Valid' },
  { value: 'INVALID', label: 'Invalid' },
  { value: 'UNKNOWN', label: 'Unknown' },
  { value: 'UNAVAILABLE', label: 'Unavailable' },
  { value: 'ERROR', label: 'Errors' },
];

const SORTS: { value: NonNullable<ResultFilters['sort']>; label: string }[] = [
  { value: 'checked_at', label: 'Most recent' },
  { value: 'input', label: 'Value' },
  { value: 'status', label: 'Status' },
  { value: 'response_time', label: 'Response time' },
];

/**
 * Every result the account has, across all its jobs.
 *
 * Filtering, sorting and paging all happen in the database. The export uses the
 * same filters as the table, so "export what I am looking at" means exactly
 * that, and it exports the whole filtered set rather than the page on screen.
 */
export function ResultsPage() {
  usePageMeta({ title: 'Results', noIndex: true, canonicalPath: '/results' });

  const [filters, setFilters] = useState<ResultFilters>({
    page: 1,
    per_page: 25,
    status: '',
    checker: '',
    search: '',
    sort: 'checked_at',
    direction: 'desc',
  });

  // What the table is looking at, minus paging: this is what the export sends.
  const exportFilters: ResultFilters = {
    status: filters.status,
    checker: filters.checker,
    search: filters.search,
    from: filters.from,
    to: filters.to,
  };

  const catalogue = useApiResource<CheckerCatalogue>((signal) => listCheckers(signal), []);

  const results = useApiResource<ResultsPageData>(
    (signal) => listResults(filters, signal),
    [
      filters.page,
      filters.per_page,
      filters.status,
      filters.checker,
      filters.search,
      filters.from,
      filters.to,
      filters.sort,
      filters.direction,
    ],
  );

  /** Any filter change returns to page one, so the view is never empty. */
  function update(patch: Partial<ResultFilters>) {
    setFilters((current) => ({ ...current, ...patch, page: patch.page ?? 1 }));
  }

  const total = results.data?.pagination.total ?? 0;

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Results</h1>
          <p className="ac-page-header__subtitle">
            Everything you have checked. Filter it down, then export exactly what you are looking at.
          </p>
        </div>
        <div className="ac-page-header__actions">
          <ExportButton onCreate={(format) => createExport(format, exportFilters)} disabled={total === 0} />
        </div>
      </header>

      {results.data && <ResultBreakdownTiles breakdown={results.data.breakdown} />}

      <Card
        flush
        title="All results"
        footer={
          results.data && (
            <Pagination
              pagination={results.data.pagination}
              onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
              onPerPageChange={(per_page) => update({ per_page })}
              disabled={results.loading}
            />
          )
        }
      >
        <div className="ac-filter-bar">
          <label className="ac-filter-bar__field">
            <span className="ac-sr-only">Search by value</span>
            <span className="ac-filter-bar__icon" aria-hidden="true">
              <Icon name="search" size={15} />
            </span>
            <input
              type="search"
              className="ac-input"
              placeholder="Starts with…"
              value={filters.search ?? ''}
              onChange={(event) => update({ search: event.target.value })}
            />
          </label>

          <label className="ac-row" style={{ gap: '0.4rem' }}>
            <span className="ac-sr-only">Checker</span>
            <select
              className="ac-select"
              style={{ width: 'auto', minHeight: 36 }}
              value={filters.checker ?? ''}
              onChange={(event) => update({ checker: event.target.value })}
            >
              <option value="">All checkers</option>
              {(catalogue.data?.items ?? []).map((checker) => (
                <option key={checker.slug} value={checker.slug}>
                  {checker.label}
                </option>
              ))}
            </select>
          </label>

          <label className="ac-row" style={{ gap: '0.4rem' }}>
            <span className="ac-sr-only">Status</span>
            <select
              className="ac-select"
              style={{ width: 'auto', minHeight: 36 }}
              value={filters.status ?? ''}
              onChange={(event) => update({ status: event.target.value as ResultStatus | '' })}
            >
              {STATUS_FILTERS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="ac-row" style={{ gap: '0.4rem' }}>
            <span className="ac-sr-only">Sort by</span>
            <select
              className="ac-select"
              style={{ width: 'auto', minHeight: 36 }}
              value={filters.sort}
              onChange={(event) => update({ sort: event.target.value as ResultFilters['sort'] })}
            >
              {SORTS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <button
            type="button"
            className="ac-btn ac-btn--ghost ac-btn--sm"
            onClick={() => update({ direction: filters.direction === 'asc' ? 'desc' : 'asc' })}
            aria-label={`Sort ${filters.direction === 'asc' ? 'descending' : 'ascending'}`}
          >
            {filters.direction === 'asc' ? 'Ascending' : 'Descending'}
          </button>
        </div>

        {results.error ? (
          <ErrorState message={results.error} onRetry={results.reload} />
        ) : (
          <ResultsTable
            results={results.data?.items ?? []}
            loading={results.loading && !results.data}
            showChecker
            emptyTitle="Nothing to show"
            emptyBody="Run a check, or loosen the filters above."
          />
        )}
      </Card>

      {total === 0 && !results.loading && (
        <p className="ac-muted">
          <Link to="/checkers">Start a check</Link> and its results will appear here.
        </p>
      )}
    </>
  );
}
