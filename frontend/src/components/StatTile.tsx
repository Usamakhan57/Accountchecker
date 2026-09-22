import type { ReactNode } from 'react';

type StatTone = 'accent' | 'valid' | 'invalid' | 'unknown' | 'unavailable' | 'plain';

interface StatTileProps {
  label: string;
  value: ReactNode;
  meta?: ReactNode;
  tone?: StatTone;
  loading?: boolean;
}

export function StatTile({ label, value, meta, tone = 'plain', loading = false }: StatTileProps) {
  const toneClass = tone === 'plain' ? '' : ` ac-stat--${tone}`;

  return (
    <div className={`ac-stat${toneClass}`}>
      <span className="ac-stat__label">{label}</span>
      {loading ? (
        <span className="ac-skeleton" style={{ height: '1.9rem', width: '4.5rem' }} aria-hidden="true" />
      ) : (
        <span className="ac-stat__value">{value}</span>
      )}
      {meta && !loading && <span className="ac-stat__meta">{meta}</span>}
    </div>
  );
}
