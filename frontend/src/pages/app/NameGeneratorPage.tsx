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
import { generateNames } from '@/services/tools';
import type { NameStyle, NameSuggestions } from '@/types/api';
import { formatNumber } from '@/utils/format';

const STYLES: { value: NameStyle; label: string; example: string }[] = [
  { value: 'plain', label: 'The words themselves', example: 'orbit' },
  { value: 'numbers', label: 'With numbers', example: 'orbit42' },
  { value: 'years', label: 'With years', example: 'orbit2026' },
  { value: 'separators', label: 'Joined together', example: 'orbit_lumen' },
  { value: 'prefixes', label: 'With a prefix', example: 'theorbit' },
  { value: 'suffixes', label: 'With a suffix', example: 'orbithq' },
];

/**
 * Username generator.
 *
 * It generates names. It says nothing about whether any of them are free,
 * because that is a question only an authorized source can answer — which is
 * what the checkers are for. The page says so plainly and sends the user
 * there, rather than implying an availability it has not checked.
 *
 * Choosing a platform applies that platform's published handle rules, so the
 * list comes back already filtered to names it would accept.
 */
export function NameGeneratorPage() {
  usePageMeta({ title: 'Name generator', noIndex: true, canonicalPath: '/checkers/name-generator' });

  const copy = useCopyToClipboard();
  const catalogue = useApiResource<CheckerCatalogue>((signal) => listCheckers(signal), []);

  const [words, setWords] = useState('');
  const [styles, setStyles] = useState<NameStyle[]>(['plain', 'numbers', 'separators']);
  const [platform, setPlatform] = useState('');
  const [count, setCount] = useState(60);
  const [result, setResult] = useState<NameSuggestions | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const platforms = (catalogue.data?.items ?? []).filter((item) => item.input_kind === 'username');

  function toggleStyle(style: NameStyle) {
    setStyles((current) =>
      current.includes(style) ? current.filter((s) => s !== style) : [...current, style],
    );
  }

  async function run(seed?: number) {
    setError(null);

    if (words.trim() === '') {
      setError('Give at least one word to build names from.');
      return;
    }

    setBusy(true);

    try {
      setResult(await generateNames({ words, styles, count, platform, seed }));
    } catch (runError) {
      setError(runError instanceof ApiError ? runError.message : 'Names could not be generated.');
      setResult(null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Name generator</h1>
          <p className="ac-page-header__subtitle">
            Build a list of handle ideas to check. Free — nothing here is verified or charged.
          </p>
        </div>
      </header>

      <div className="ac-grid ac-grid--halves">
        <Card title="What should they be based on?">
          <div className="ac-stack ac-stack--sm">
            <label className="ac-field__label" htmlFor="ac-generator-words">
              Words
            </label>
            <input
              id="ac-generator-words"
              className="ac-input"
              value={words}
              placeholder="orbit, lumen"
              autoCapitalize="none"
              autoCorrect="off"
              onChange={(event) => setWords(event.target.value)}
            />
            <span className="ac-field__hint">
              Up to eight words, separated by commas. Anything that is not a letter or a number is
              dropped, since no platform allows it in a handle.
            </span>

            <fieldset className="ac-choices">
              <legend className="ac-field__label">Variations</legend>
              {STYLES.map((style) => (
                <label key={style.value} className="ac-choice">
                  <input
                    type="checkbox"
                    checked={styles.includes(style.value)}
                    onChange={() => toggleStyle(style.value)}
                  />
                  <span>
                    <span className="ac-choice__label">{style.label}</span>
                    <span className="ac-choice__hint ac-mono">{style.example}</span>
                  </span>
                </label>
              ))}
            </fieldset>

            <div className="ac-grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
              <label className="ac-field">
                <span className="ac-field__label">Platform rules</span>
                <select
                  className="ac-select"
                  value={platform}
                  onChange={(event) => setPlatform(event.target.value)}
                >
                  <option value="">No platform rules</option>
                  {platforms.map((item) => (
                    <option key={item.slug} value={item.slug}>
                      {item.label}
                    </option>
                  ))}
                </select>
              </label>

              <label className="ac-field">
                <span className="ac-field__label">How many</span>
                <select
                  className="ac-select"
                  value={count}
                  onChange={(event) => setCount(Number(event.target.value))}
                >
                  {[20, 60, 120, 250, 500].map((option) => (
                    <option key={option} value={option}>
                      {option}
                    </option>
                  ))}
                </select>
              </label>
            </div>

            {error && (
              <p className="ac-field__error" role="alert">
                {error}
              </p>
            )}

            <button type="button" className="ac-btn ac-btn--primary" onClick={() => void run()} disabled={busy}>
              <Icon name="sparkle" size={15} />
              {busy ? 'Generating…' : 'Generate names'}
            </button>
          </div>
        </Card>

        <Card
          title="Suggestions"
          subtitle={result ? `${formatNumber(result.count)} names` : undefined}
          actions={
            result && result.names.length > 0 ? (
              <>
                <button
                  type="button"
                  className="ac-btn ac-btn--ghost ac-btn--sm"
                  onClick={() => void run()}
                  disabled={busy}
                >
                  <Icon name="refresh" size={14} />
                  More
                </button>
                <button
                  type="button"
                  className="ac-btn ac-btn--secondary ac-btn--sm"
                  onClick={() => void copy(result.names.join('\n'), 'Names copied.')}
                >
                  <Icon name="copy" size={14} />
                  Copy all
                </button>
              </>
            ) : undefined
          }
        >
          {result ? (
            <div className="ac-stack ac-stack--sm">
              <Alert tone="info">{result.notice}</Alert>

              {result.shortened_words.length > 0 && (
                <Alert tone="warning">
                  {result.shortened_words
                    .map((entry) => `“${entry.word}” was shortened to “${entry.shortened}”`)
                    .join('; ')}{' '}
                  to fit the {result.rules?.max_length}-character limit.
                </Alert>
              )}

              {result.names.length === 0 ? (
                <EmptyState
                  title="Nothing fitted those rules"
                  body={
                    result.rules
                      ? `That platform allows ${result.rules.allowed}, between ${result.rules.min_length} and ${result.rules.max_length} characters. Try a shorter word or more variations.`
                      : 'Try another word or turn on more variations.'
                  }
                />
              ) : (
                <>
                  <ul className="ac-name-grid" role="list">
                    {result.names.map((name) => (
                      <li key={name}>
                        <button
                          type="button"
                          className="ac-name-chip"
                          onClick={() => void copy(name, `“${name}” copied.`)}
                          title="Copy this name"
                        >
                          {name}
                        </button>
                      </li>
                    ))}
                  </ul>

                  <p className="ac-muted">
                    Copy the list and <Link to="/checkers/platform">run it through a checker</Link> to find
                    out which are actually available.
                    {result.rules && ` Filtered to ${result.rules.allowed}, up to ${result.rules.max_length} characters.`}
                  </p>
                </>
              )}
            </div>
          ) : (
            <EmptyState
              title="No names yet"
              body="Give a word or two and choose Generate. Nothing is sent to any platform and no credits are used."
            />
          )}
        </Card>
      </div>
    </>
  );
}
