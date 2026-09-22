import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Alert, EmptyState } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { useCopyToClipboard } from '@/hooks/useDocumentClipboard';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { listCheckers } from '@/services/checkers';
import type { CheckerCatalogue } from '@/services/checkers';
import { findDuplicates } from '@/services/tools';
import type { DuplicateMode, DuplicateReport } from '@/types/api';
import { formatNumber } from '@/utils/format';

const MODES: { value: DuplicateMode; label: string; hint: string }[] = [
  { value: 'exact', label: 'Exact', hint: 'Lines must match character for character.' },
  { value: 'relaxed', label: 'Ignore case', hint: 'Capitalisation and stray punctuation are ignored.' },
  {
    value: 'checker',
    label: 'As a checker sees it',
    hint: 'Uses the checker’s own normalisation, so the count matches what you would be charged.',
  },
];

/**
 * Duplicate finder.
 *
 * Free, because nothing is verified: this is the user's own list being
 * compared with itself.
 *
 * The mode that earns the page its keep is "as a checker sees it". Submitting
 * First.Last@gmail.com and firstlast@gmail.com to the Gmail checker is one
 * address and one credit, so a duplicate finder that called them different
 * would be telling the user something the billing does not agree with.
 */
export function DuplicateCheckerPage() {
  usePageMeta({ title: 'Duplicate finder', noIndex: true, canonicalPath: '/checkers/duplicates' });

  const copy = useCopyToClipboard();
  const catalogue = useApiResource<CheckerCatalogue>((signal) => listCheckers(signal), []);

  const [input, setInput] = useState('');
  const [mode, setMode] = useState<DuplicateMode>('exact');
  const [checker, setChecker] = useState('gmail');
  const [report, setReport] = useState<DuplicateReport | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    setError(null);

    if (input.trim() === '') {
      setError('Paste a list first, one entry per line.');
      return;
    }

    setBusy(true);

    try {
      setReport(await findDuplicates(input, mode, checker));
    } catch (runError) {
      setError(runError instanceof ApiError ? runError.message : 'The list could not be analysed.');
      setReport(null);
    } finally {
      setBusy(false);
    }
  }

  const activeMode = MODES.find((m) => m.value === mode);

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Duplicate finder</h1>
          <p className="ac-page-header__subtitle">
            Clean a list before you spend credits on it. Free — nothing here is verified or charged.
          </p>
        </div>
      </header>

      <div className="ac-grid ac-grid--halves">
        <Card title="Your list" subtitle="One entry per line.">
          <div className="ac-stack ac-stack--sm">
            <label className="ac-field__label" htmlFor="ac-duplicate-input">
              List
            </label>
            <textarea
              id="ac-duplicate-input"
              className="ac-textarea ac-textarea--tall"
              value={input}
              spellCheck={false}
              autoCapitalize="none"
              autoCorrect="off"
              placeholder={'First.Last@gmail.com\nfirstlast@gmail.com\nsomeone.else@gmail.com'}
              onChange={(event) => {
                setInput(event.target.value);
                setReport(null);
              }}
            />

            <fieldset className="ac-choices">
              <legend className="ac-field__label">How should two entries be considered the same?</legend>
              {MODES.map((option) => (
                <label key={option.value} className="ac-choice">
                  <input
                    type="radio"
                    name="duplicate-mode"
                    value={option.value}
                    checked={mode === option.value}
                    onChange={() => {
                      setMode(option.value);
                      setReport(null);
                    }}
                  />
                  <span>
                    <span className="ac-choice__label">{option.label}</span>
                    <span className="ac-choice__hint">{option.hint}</span>
                  </span>
                </label>
              ))}
            </fieldset>

            {mode === 'checker' && (
              <label className="ac-row" style={{ gap: '0.5rem' }}>
                <span className="ac-muted" style={{ fontSize: 'var(--ac-text-xs)' }}>
                  Checker
                </span>
                <select
                  className="ac-select"
                  style={{ width: 'auto', minHeight: 36 }}
                  value={checker}
                  onChange={(event) => {
                    setChecker(event.target.value);
                    setReport(null);
                  }}
                >
                  {(catalogue.data?.items ?? []).map((item) => (
                    <option key={item.slug} value={item.slug}>
                      {item.label}
                    </option>
                  ))}
                </select>
              </label>
            )}

            {error && (
              <p className="ac-field__error" role="alert">
                {error}
              </p>
            )}

            <button type="button" className="ac-btn ac-btn--primary" onClick={run} disabled={busy}>
              <Icon name="duplicate" size={15} />
              {busy ? 'Comparing…' : 'Find duplicates'}
            </button>
          </div>
        </Card>

        <Card
          title="What is in your list"
          subtitle={activeMode?.hint}
          actions={
            report && report.unique.length > 0 ? (
              <button
                type="button"
                className="ac-btn ac-btn--secondary ac-btn--sm"
                onClick={() => void copy(report.unique.join('\n'), 'De-duplicated list copied.')}
              >
                <Icon name="copy" size={14} />
                Copy clean list
              </button>
            ) : undefined
          }
        >
          {report ? (
            <div className="ac-stack ac-stack--sm">
              <dl className="ac-summary">
                <div>
                  <dt>Lines read</dt>
                  <dd>{formatNumber(report.total_lines)}</dd>
                </div>
                <div>
                  <dt>Unique entries</dt>
                  <dd>{formatNumber(report.unique_count)}</dd>
                </div>
                <div>
                  <dt>Lines you can drop</dt>
                  <dd>{formatNumber(report.duplicate_count)}</dd>
                </div>
                <div>
                  <dt>Repeated entries</dt>
                  <dd>{formatNumber(report.repeated_values)}</dd>
                </div>
              </dl>

              {report.truncated && (
                <Alert tone="warning">
                  Only the first {formatNumber(report.max_lines)} lines were compared. Split the list to
                  check the rest.
                </Alert>
              )}

              {report.unreadable_count > 0 && (
                <Alert tone="info">
                  {formatNumber(report.unreadable_count)}{' '}
                  {report.unreadable_count === 1 ? 'line was' : 'lines were'} not a valid entry for that
                  checker, so {report.unreadable_count === 1 ? 'it was' : 'they were'} left out of the
                  comparison rather than grouped together.
                </Alert>
              )}

              {report.groups.length === 0 ? (
                <Alert tone="success">No duplicates. Your list is already clean.</Alert>
              ) : (
                <div className="ac-table-wrap">
                  <table className="ac-table">
                    <thead>
                      <tr>
                        <th scope="col">Entry</th>
                        <th scope="col" className="ac-table__numeric">
                          Times
                        </th>
                        <th scope="col">Lines it came from</th>
                      </tr>
                    </thead>
                    <tbody>
                      {report.groups.map((group) => (
                        <tr key={group.value}>
                          <td className="ac-table__mono">
                            <span className="ac-truncate" title={group.value}>
                              {group.value}
                            </span>
                          </td>
                          <td className="ac-table__numeric">{group.count}</td>
                          <td className="ac-muted" style={{ fontSize: 'var(--ac-text-xs)' }}>
                            {group.lines.join(', ')}
                            {group.count > group.lines.length && ` +${group.count - group.lines.length} more`}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {report.repeated_values > report.groups.length && (
                <p className="ac-muted">
                  Showing the {report.groups.length} most repeated entries of{' '}
                  {formatNumber(report.repeated_values)}.
                </p>
              )}

              <p className="ac-muted">
                Copy the clean list and <Link to="/checkers">run it through a checker</Link> to verify it.
              </p>
            </div>
          ) : (
            <EmptyState
              title="Nothing compared yet"
              body="Paste a list and choose Find duplicates. Nothing is sent to any platform and no credits are used."
            />
          )}
        </Card>
      </div>
    </>
  );
}
