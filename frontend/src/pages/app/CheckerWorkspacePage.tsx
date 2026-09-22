import { useCallback, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { ProgressBar } from '@/components/ProgressBar';
import { ResultBreakdownTiles, ResultsTable } from '@/components/ResultsTable';
import { useToast } from '@/components/ToastProvider';
import { Alert, ErrorState, LoadingState } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { useJobProgress } from '@/hooks/useJobProgress';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { getChecker } from '@/services/checkers';
import { cancelJob, reviewInput, startJob, startJobFromFile } from '@/services/jobs';
import { listJobResults } from '@/services/results';
import type { CheckerType, InputReview, JobResultsPage } from '@/types/api';
import { formatNumber } from '@/utils/format';

/**
 * The checker workspace: paste a list, review it, run it, watch it.
 *
 * One component serves every checker. What changes between Gmail and a platform
 * handle is the wording and the validation, and both come from the checker's
 * own capabilities, so adding a checker on the server adds a working workspace
 * here without a new page.
 *
 * The review step exists so nobody spends credits blind. Before a job is
 * created the server reports how many lines survived validation, how many were
 * duplicates, and exactly what the batch will cost. Only then is there a button
 * that spends anything.
 *
 * Once a job starts, the work happens on the server. This page polls for
 * progress, so closing the tab does not stop the job and coming back picks it
 * up where it is.
 */
export function CheckerWorkspacePage() {
  const { slug = '' } = useParams<{ slug: string }>();
  const toast = useToast();
  const { wallet, refresh } = useAuth();

  const checkerResource = useApiResource<CheckerType>((signal) => getChecker(slug, signal), [slug]);
  const checker = checkerResource.data;

  usePageMeta({
    title: checker?.label ?? 'Checker',
    noIndex: true,
    canonicalPath: `/checkers/${slug}`,
  });

  const [input, setInput] = useState('');
  const [review, setReview] = useState<InputReview | null>(null);
  const [reviewing, setReviewing] = useState(false);
  const [starting, setStarting] = useState(false);
  const [jobUuid, setJobUuid] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const onFinished = useCallback(() => {
    // The wallet changes when a job settles, so the header figure is refreshed
    // rather than left showing a stale reservation.
    void refresh();
  }, [refresh]);

  const { progress, error: progressError } = useJobProgress(jobUuid, { onFinished });

  const jobResults = useApiResource<JobResultsPage>(
    (signal) => listJobResults(jobUuid ?? '', { per_page: 25 }, signal),
    [jobUuid, progress?.is_finished ?? false, progress?.processed_items ?? 0],
    { enabled: jobUuid !== null },
  );

  if (checkerResource.loading && !checker) {
    return <LoadingState label="Loading checker…" />;
  }

  if (checkerResource.error || !checker) {
    return (
      <ErrorState
        title="That checker is not available"
        message={checkerResource.error ?? 'It may have been switched off.'}
        onRetry={checkerResource.reload}
      />
    );
  }

  const inputNoun = checker.input_kind === 'email' ? 'email addresses' : 'usernames';
  const available = wallet?.available ?? 0;
  const shortfall = review ? review.credits_required - available : 0;

  async function handleReview() {
    setFormError(null);

    if (input.trim() === '') {
      setFormError(`Paste some ${inputNoun} first, one per line.`);
      return;
    }

    setReviewing(true);

    try {
      setReview(await reviewInput(slug, input));
    } catch (error) {
      setFormError(error instanceof ApiError ? error.message : 'The list could not be checked.');
      setReview(null);
    } finally {
      setReviewing(false);
    }
  }

  async function handleStart() {
    setFormError(null);
    setStarting(true);

    try {
      const submission = await startJob(slug, input);
      setJobUuid(submission.job.uuid);
      setReview(null);
      await refresh();
      toast.success(`${formatNumber(submission.job.total_items)} records queued.`);
    } catch (error) {
      setFormError(error instanceof ApiError ? error.message : 'The job could not be started.');
    } finally {
      setStarting(false);
    }
  }

  async function handleFile(file: File) {
    setFormError(null);
    setStarting(true);

    try {
      const submission = await startJobFromFile(slug, file);
      setJobUuid(submission.job.uuid);
      setReview(null);
      setInput('');
      await refresh();
      toast.success(`${formatNumber(submission.job.total_items)} records queued from ${file.name}.`);
    } catch (error) {
      setFormError(error instanceof ApiError ? error.message : 'The file could not be queued.');
    } finally {
      setStarting(false);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  }

  async function handleCancel() {
    if (!jobUuid) {
      return;
    }

    try {
      await cancelJob(jobUuid);
      await refresh();
      toast.success('Job cancelled. You were only charged for the records already checked.');
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'The job could not be cancelled.');
    }
  }

  function startAnother() {
    setJobUuid(null);
    setInput('');
    setReview(null);
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">{checker.label}</h1>
          <p className="ac-page-header__subtitle">{checker.description}</p>
        </div>
        <div className="ac-page-header__actions">
          <Link to="/checkers" className="ac-btn ac-btn--secondary">
            <Icon name="chevronLeft" size={15} />
            All checkers
          </Link>
        </div>
      </header>

      {checker.mode === 'mock' && (
        <Alert tone="warning">
          <strong>Mock mode.</strong> Results are generated locally and are not real verifications. No
          outbound requests are made.
        </Alert>
      )}

      {checker.mode === 'production' && !checker.configured && (
        <Alert tone="warning">
          <strong>No authorized source configured.</strong> Every record will come back as
          <strong> Unavailable</strong>, and you will not be charged for it. Nothing is guessed and no
          unauthorized lookup is attempted.
        </Alert>
      )}

      {jobUuid ? (
        <div className="ac-stack">
          <Card
            title={
              progress?.is_finished
                ? progress.status === 'COMPLETED'
                  ? 'Finished'
                  : progress.status === 'CANCELLED'
                    ? 'Cancelled'
                    : 'Stopped'
                : 'Running'
            }
            subtitle={
              progress?.is_finished
                ? 'Everything below is the finished result of this job.'
                : 'The job runs on the server. You can close this page and come back to it.'
            }
            actions={
              <>
                {progress && !progress.is_finished && (
                  <button type="button" className="ac-btn ac-btn--danger ac-btn--sm" onClick={handleCancel}>
                    <Icon name="stop" size={15} />
                    Cancel
                  </button>
                )}
                <Link to={`/jobs/${jobUuid}`} className="ac-btn ac-btn--secondary ac-btn--sm">
                  Open job
                </Link>
                {progress?.is_finished && (
                  <button type="button" className="ac-btn ac-btn--primary ac-btn--sm" onClick={startAnother}>
                    <Icon name="plus" size={15} />
                    New check
                  </button>
                )}
              </>
            }
          >
            {progressError && <Alert tone="error">{progressError}</Alert>}

            {progress ? (
              <div className="ac-stack ac-stack--sm">
                <ProgressBar
                  value={progress.processed_items}
                  max={progress.total_items}
                  label={`${formatNumber(progress.processed_items)} of ${formatNumber(progress.total_items)} records`}
                />
                <p className="ac-muted">
                  {progress.status === 'QUEUED'
                    ? 'Waiting for a worker to pick this up.'
                    : progress.is_finished
                      ? `Finished — ${formatNumber(progress.credits_spent)} credits used.`
                      : `${formatNumber(progress.successful_items)} checked, ${formatNumber(progress.failed_items)} could not be checked.`}
                </p>
                {progress.error_message && <Alert tone="error">{progress.error_message}</Alert>}
              </div>
            ) : (
              <LoadingState label="Starting…" />
            )}
          </Card>

          {jobResults.data && <ResultBreakdownTiles breakdown={jobResults.data.breakdown} />}

          <Card title="Results" flush>
            <ResultsTable
              results={jobResults.data?.items ?? []}
              loading={jobResults.loading && !jobResults.data}
              emptyTitle="No results yet"
              emptyBody="The first results appear within a few seconds of the job starting."
            />
          </Card>

          {jobResults.data && jobResults.data.pagination.total > jobResults.data.items.length && (
            <p className="ac-muted">
              Showing the first {formatNumber(jobResults.data.items.length)} of{' '}
              {formatNumber(jobResults.data.pagination.total)} results.{' '}
              <Link to={`/jobs/${jobUuid}`}>Open the job</Link> to page through them all or export them.
            </p>
          )}
        </div>
      ) : (
        <div className="ac-grid ac-grid--halves">
          <Card
            title={`Paste your ${inputNoun}`}
            subtitle={`One per line. Up to ${formatNumber(checker.max_batch_size)} records per job.`}
          >
            <div className="ac-stack ac-stack--sm">
              <label className="ac-field__label" htmlFor="ac-checker-input">
                List
              </label>
              <textarea
                id="ac-checker-input"
                className="ac-textarea ac-textarea--tall"
                value={input}
                spellCheck={false}
                autoCapitalize="none"
                autoCorrect="off"
                placeholder={
                  checker.input_kind === 'email'
                    ? 'first.last@gmail.com\nsecond.person@gmail.com'
                    : 'firsthandle\nsecondhandle'
                }
                onChange={(event) => {
                  setInput(event.target.value);
                  setReview(null);
                }}
              />

              {formError && (
                <p className="ac-field__error" role="alert">
                  {formError}
                </p>
              )}

              <div className="ac-row ac-row--between">
                <button
                  type="button"
                  className="ac-btn ac-btn--secondary"
                  onClick={() => fileInputRef.current?.click()}
                  disabled={starting}
                >
                  <Icon name="upload" size={15} />
                  Upload .txt or .csv
                </button>

                <button
                  type="button"
                  className="ac-btn ac-btn--primary"
                  onClick={handleReview}
                  disabled={reviewing || starting}
                >
                  {reviewing ? 'Checking the list…' : 'Review list'}
                </button>
              </div>

              <input
                ref={fileInputRef}
                type="file"
                accept=".txt,.csv,text/plain,text/csv"
                className="ac-sr-only"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) {
                    void handleFile(file);
                  }
                }}
              />
            </div>
          </Card>

          <Card
            title="Before you run it"
            subtitle="Nothing is charged until you start the job."
          >
            {review ? (
              <div className="ac-stack ac-stack--sm">
                <dl className="ac-summary">
                  <div>
                    <dt>Lines read</dt>
                    <dd>{formatNumber(review.total_lines)}</dd>
                  </div>
                  <div>
                    <dt>Will be checked</dt>
                    <dd>{formatNumber(review.accepted_count)}</dd>
                  </div>
                  <div>
                    <dt>Duplicates removed</dt>
                    <dd>{formatNumber(review.duplicate_count)}</dd>
                  </div>
                  <div>
                    <dt>Could not be read</dt>
                    <dd>{formatNumber(review.invalid_count)}</dd>
                  </div>
                  <div>
                    <dt>Cost</dt>
                    <dd>
                      {formatNumber(review.credits_required)}{' '}
                      {review.credits_required === 1 ? 'credit' : 'credits'}
                    </dd>
                  </div>
                  <div>
                    <dt>Your balance</dt>
                    <dd>{formatNumber(available)} credits</dd>
                  </div>
                </dl>

                {review.over_limit && (
                  <Alert tone="error">
                    That is more than {formatNumber(review.max_batch_size)} records. Split the list and run
                    it as more than one job.
                  </Alert>
                )}

                {shortfall > 0 && !review.over_limit && (
                  <Alert tone="warning">
                    You need {formatNumber(shortfall)} more {shortfall === 1 ? 'credit' : 'credits'} to run
                    this. <Link to="/pricing">Top up</Link> and your list will still be here.
                  </Alert>
                )}

                {review.invalid_samples.length > 0 && (
                  <details className="ac-details">
                    <summary>
                      {formatNumber(review.invalid_count)} {review.invalid_count === 1 ? 'line' : 'lines'} will
                      be skipped
                    </summary>
                    <ul className="ac-skip-list">
                      {review.invalid_samples.slice(0, 10).map((entry, index) => (
                        <li key={`${entry.input}-${index}`}>
                          <code className="ac-mono">{entry.input}</code>
                          <span className="ac-muted"> — {entry.reason}</span>
                        </li>
                      ))}
                    </ul>
                  </details>
                )}

                <button
                  type="button"
                  className="ac-btn ac-btn--primary ac-btn--block"
                  onClick={handleStart}
                  disabled={
                    starting || review.accepted_count === 0 || review.over_limit || shortfall > 0
                  }
                >
                  <Icon name="play" size={15} />
                  {starting
                    ? 'Starting…'
                    : `Check ${formatNumber(review.accepted_count)} records for ${formatNumber(review.credits_required)} credits`}
                </button>
              </div>
            ) : (
              <div className="ac-stack ac-stack--sm">
                <p className="ac-muted">
                  Paste your list and choose <strong>Review list</strong>. You will see how many records
                  will actually be checked, how many duplicates were removed and exactly what it costs
                  before anything is spent.
                </p>
                <dl className="ac-summary">
                  <div>
                    <dt>Cost per record</dt>
                    <dd>
                      {formatNumber(checker.credit_cost)}{' '}
                      {checker.credit_cost === 1 ? 'credit' : 'credits'}
                    </dd>
                  </div>
                  <div>
                    <dt>Your balance</dt>
                    <dd>{formatNumber(available)} credits</dd>
                  </div>
                </dl>
              </div>
            )}
          </Card>
        </div>
      )}
    </>
  );
}
