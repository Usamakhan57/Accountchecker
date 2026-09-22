import type { Pagination as PaginationMeta } from '@/types/api';
import { Icon } from './Icon';

interface PaginationProps {
  pagination: PaginationMeta;
  onPageChange: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  disabled?: boolean;
}

const PER_PAGE_OPTIONS = [25, 50, 100, 200];

/**
 * Server-side pagination controls.
 *
 * The page never holds more than one page of rows: jobs can produce thousands
 * of results, so the table asks the API for a window rather than filtering a
 * full set in the browser.
 */
export function Pagination({ pagination, onPageChange, onPerPageChange, disabled }: PaginationProps) {
  const { page, per_page: perPage, total, total_pages: totalPages } = pagination;

  const first = total === 0 ? 0 : (page - 1) * perPage + 1;
  const last = Math.min(page * perPage, total);

  return (
    <div className="ac-pagination">
      <p className="ac-muted" aria-live="polite">
        {total === 0 ? 'No records' : `Showing ${first.toLocaleString()}–${last.toLocaleString()} of ${total.toLocaleString()}`}
      </p>

      <div className="ac-pagination__controls">
        {onPerPageChange && (
          <label className="ac-row" style={{ gap: '0.375rem', fontSize: 'var(--ac-text-xs)' }}>
            <span className="ac-muted">Rows</span>
            <select
              className="ac-select"
              style={{ width: 'auto', minHeight: 32, paddingBlock: 0 }}
              value={perPage}
              disabled={disabled}
              onChange={(event) => onPerPageChange(Number(event.target.value))}
            >
              {PER_PAGE_OPTIONS.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </label>
        )}

        <button
          type="button"
          className="ac-btn ac-btn--secondary ac-btn--sm"
          onClick={() => onPageChange(page - 1)}
          disabled={disabled || page <= 1}
        >
          <Icon name="chevronLeft" size={14} />
          Previous
        </button>

        <span className="ac-muted" style={{ fontSize: 'var(--ac-text-xs)' }}>
          Page {page} of {Math.max(1, totalPages)}
        </span>

        <button
          type="button"
          className="ac-btn ac-btn--secondary ac-btn--sm"
          onClick={() => onPageChange(page + 1)}
          disabled={disabled || page >= totalPages}
        >
          Next
          <Icon name="chevronRight" size={14} />
        </button>
      </div>
    </div>
  );
}
