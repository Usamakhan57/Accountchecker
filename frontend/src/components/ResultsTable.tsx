import { ResultStatusBadge } from '@/components/StatusBadge';
import { EmptyState, TableSkeleton } from '@/components/states';
import type { CheckResult } from '@/types/api';
import { formatDateTime, formatDuration } from '@/utils/format';

interface ResultsTableProps {
  results: CheckResult[];
  loading?: boolean;
  /** Hidden when the table already sits inside one job. */
  showChecker?: boolean;
  emptyTitle?: string;
  emptyBody?: string;
}

/**
 * The results table.
 *
 * Two columns exist because the distinction matters to the reader: the input is
 * the line they submitted, the checked value is what was actually looked up
 * after normalisation. Seeing both is how someone understands why
 * "First.Last+news@gmail.com" and "firstlast@gmail.com" are the same address.
 *
 * The detail column carries the reason a status was reached, which is the only
 * thing that separates "an authorized source said no" from "no authorized
 * source could be used" — a difference this product must never blur.
 */
export function ResultsTable({
  results,
  loading,
  showChecker = false,
  emptyTitle = 'No results yet',
  emptyBody = 'Results appear here as soon as a job starts running.',
}: ResultsTableProps) {
  if (loading) {
    return <TableSkeleton rows={6} columns={showChecker ? 6 : 5} />;
  }

  if (results.length === 0) {
    return <EmptyState title={emptyTitle} body={emptyBody} />;
  }

  return (
    <div className="ac-table-wrap">
      <table className="ac-table">
        <thead>
          <tr>
            <th scope="col">Input</th>
            <th scope="col">Checked value</th>
            {showChecker && <th scope="col">Checker</th>}
            <th scope="col">Status</th>
            <th scope="col">Detail</th>
            <th scope="col" className="ac-table__numeric">
              Time
            </th>
            <th scope="col">Checked</th>
          </tr>
        </thead>
        <tbody>
          {results.map((result) => (
            <tr key={result.id}>
              <td className="ac-table__mono">
                <span className="ac-truncate" title={result.input}>
                  {result.input}
                </span>
              </td>
              <td className="ac-table__mono">
                <span className="ac-truncate" title={result.normalized_input}>
                  {result.normalized_input}
                </span>
              </td>
              {showChecker && <td>{result.checker_label}</td>}
              <td>
                <ResultStatusBadge status={result.status} />
              </td>
              <td>
                <span className="ac-muted ac-truncate" title={result.reason ?? undefined}>
                  {result.reason ?? '—'}
                </span>
              </td>
              <td className="ac-table__numeric">{formatDuration(result.response_time_ms)}</td>
              <td className="ac-muted">{formatDateTime(result.checked_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * The counts above a results table.
 *
 * UNAVAILABLE gets its own tile rather than being folded into a failure count,
 * because it means nothing was checked — not that anything was wrong with the
 * record.
 */
export function ResultBreakdownTiles({ breakdown }: { breakdown: Record<string, number> }) {
  const tiles: { key: string; label: string; tone: string }[] = [
    { key: 'VALID', label: 'Valid', tone: 'valid' },
    { key: 'INVALID', label: 'Invalid', tone: 'invalid' },
    { key: 'UNKNOWN', label: 'Unknown', tone: 'unknown' },
    { key: 'UNAVAILABLE', label: 'Unavailable', tone: 'unavailable' },
    { key: 'ERROR', label: 'Errors', tone: 'invalid' },
  ];

  return (
    <div className="ac-grid ac-grid--stats">
      {tiles.map((tile) => (
        <div key={tile.key} className={`ac-stat ac-stat--${tile.tone}`}>
          <p className="ac-stat__label">{tile.label}</p>
          <p className="ac-stat__value">{(breakdown[tile.key] ?? 0).toLocaleString()}</p>
        </div>
      ))}
    </div>
  );
}
