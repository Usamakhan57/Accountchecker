import type { ReactNode } from 'react';
import { Icon } from './Icon';

/**
 * Loading, empty and error states.
 *
 * Every list and panel in the app renders one of these three rather than a
 * blank area, so a screen is never ambiguous about whether it is still working.
 */

export function Spinner({ label }: { label?: string }) {
  return (
    <span className="ac-row" style={{ gap: '0.5rem' }}>
      <span className="ac-spinner" aria-hidden="true" />
      {label && <span className="ac-muted">{label}</span>}
    </span>
  );
}

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="ac-empty" role="status" aria-live="polite">
      <span className="ac-spinner" aria-hidden="true" />
      <p className="ac-empty__body">{label}</p>
    </div>
  );
}

export function TableSkeleton({ rows = 5, columns = 5 }: { rows?: number; columns?: number }) {
  return (
    <div className="ac-stack ac-stack--sm" style={{ padding: 'var(--ac-space-4)' }} aria-hidden="true">
      {Array.from({ length: rows }, (_, rowIndex) => (
        <div key={rowIndex} className="ac-row" style={{ gap: 'var(--ac-space-4)', flexWrap: 'nowrap' }}>
          {Array.from({ length: columns }, (_, columnIndex) => (
            <span
              key={columnIndex}
              className="ac-skeleton"
              style={{ height: '0.85rem', flex: columnIndex === 0 ? '2 1 0' : '1 1 0' }}
            />
          ))}
        </div>
      ))}
    </div>
  );
}

interface EmptyStateProps {
  title: string;
  body?: ReactNode;
  action?: ReactNode;
}

export function EmptyState({ title, body, action }: EmptyStateProps) {
  return (
    <div className="ac-empty">
      <span
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 44,
          height: 44,
          borderRadius: 'var(--ac-radius-lg)',
          background: 'var(--ac-slate-100)',
          color: 'var(--ac-text-subtle)',
        }}
      >
        <Icon name="results" size={22} />
      </span>
      <p className="ac-empty__title">{title}</p>
      {body && <p className="ac-empty__body">{body}</p>}
      {action}
    </div>
  );
}

interface ErrorStateProps {
  title?: string;
  message: string;
  onRetry?: () => void;
}

export function ErrorState({ title = 'Something went wrong', message, onRetry }: ErrorStateProps) {
  return (
    <div className="ac-empty" role="alert">
      <span
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 44,
          height: 44,
          borderRadius: 'var(--ac-radius-lg)',
          background: 'var(--ac-error-surface)',
          color: 'var(--ac-error-text)',
        }}
      >
        <Icon name="alert" size={22} />
      </span>
      <p className="ac-empty__title">{title}</p>
      <p className="ac-empty__body">{message}</p>
      {onRetry && (
        <button type="button" className="ac-btn ac-btn--secondary ac-btn--sm" onClick={onRetry}>
          <Icon name="refresh" size={15} />
          Try again
        </button>
      )}
    </div>
  );
}

export function Alert({
  tone = 'info',
  children,
}: {
  tone?: 'info' | 'error' | 'success' | 'warning';
  children: ReactNode;
}) {
  const icon = tone === 'error' || tone === 'warning' ? 'alert' : tone === 'success' ? 'check' : 'info';

  return (
    <div className={`ac-alert ac-alert--${tone}`} role={tone === 'error' ? 'alert' : 'status'}>
      <Icon name={icon} size={17} style={{ flexShrink: 0, marginTop: 1 }} />
      <div>{children}</div>
    </div>
  );
}
