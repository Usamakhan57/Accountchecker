import { useState } from 'react';
import { Card } from '@/components/Card';
import { useToast } from '@/components/ToastProvider';
import { Alert, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listCheckers, updateChecker } from '@/services/admin';
import { ApiError } from '@/services/apiClient';
import type { AdminChecker } from '@/types/api';
import { formatNumber } from '@/utils/format';

/**
 * Checker settings.
 *
 * Three things are editable: whether it runs, what a check costs and how large
 * a batch may be. Provider credentials are not among them and are not shown:
 * they come from the environment, and a web form is the wrong place for a
 * secret. "Source" reports whether a credential is present, which is what an
 * administrator actually needs to know.
 */
export function AdminCheckersPage() {
  usePageMeta({ title: 'Checkers', noIndex: true, canonicalPath: '/admin/checkers' });

  const toast = useToast();
  const { data, loading, error, reload } = useApiResource<{ items: AdminChecker[] }>(
    (signal) => listCheckers(signal),
    [],
  );

  const [busy, setBusy] = useState<string | null>(null);
  const items = data?.items ?? [];
  const unconfigured = items.filter((checker) => !checker.configured && checker.is_enabled);

  async function apply(slug: string, changes: Parameters<typeof updateChecker>[1], message: string) {
    setBusy(slug);

    try {
      await updateChecker(slug, changes);
      toast.success(message);
      reload();
    } catch (updateError) {
      toast.error(updateError instanceof ApiError ? updateError.message : 'That could not be changed.');
    } finally {
      setBusy(null);
    }
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">Checkers</h1>
          <p className="ac-page-header__subtitle">
            What runs, what it costs and how many records a batch may hold.
          </p>
        </div>
      </header>

      {unconfigured.length > 0 && (
        <Alert tone="warning">
          {unconfigured.length === 1
            ? `${unconfigured[0].label} is switched on but has no authorized source configured.`
            : `${unconfigured.length} checkers are switched on with no authorized source configured.`}{' '}
          Records sent to them are reported as unavailable and cost nothing, which is correct but is probably
          not what you intended.
        </Alert>
      )}

      <Card flush title="Configured checkers">
        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={6} columns={6} />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">Checker</th>
                  <th scope="col">Source</th>
                  <th scope="col" className="ac-table__numeric">
                    Cost
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Batch limit
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Checked, 30 days
                  </th>
                  <th scope="col">Runs</th>
                </tr>
              </thead>
              <tbody>
                {items.map((checker) => (
                  <tr key={checker.slug}>
                    <td>{checker.label}</td>
                    <td>
                      <span
                        className={`ac-badge ac-badge--${checker.configured ? 'valid' : 'unavailable'}`}
                        title={checker.mode}
                      >
                        {checker.configured ? checker.mode : 'Not configured'}
                      </span>
                    </td>
                    <td className="ac-table__numeric">
                      <input
                        type="number"
                        className="ac-input ac-input--inline"
                        defaultValue={checker.credit_cost}
                        min={0}
                        max={1000}
                        disabled={busy === checker.slug}
                        aria-label={`Credit cost for ${checker.label}`}
                        onBlur={(event) => {
                          const value = Number.parseInt(event.target.value, 10);

                          if (Number.isFinite(value) && value !== checker.credit_cost) {
                            void apply(checker.slug, { credit_cost: value }, `${checker.label} now costs ${value} credits.`);
                          }
                        }}
                      />
                    </td>
                    <td className="ac-table__numeric">
                      <input
                        type="number"
                        className="ac-input ac-input--inline"
                        defaultValue={checker.max_batch_size}
                        min={1}
                        max={5000}
                        disabled={busy === checker.slug}
                        aria-label={`Batch limit for ${checker.label}`}
                        onBlur={(event) => {
                          const value = Number.parseInt(event.target.value, 10);

                          if (Number.isFinite(value) && value !== checker.max_batch_size) {
                            void apply(
                              checker.slug,
                              { max_batch_size: value },
                              `${checker.label} batches are now capped at ${formatNumber(value)}.`,
                            );
                          }
                        }}
                      />
                    </td>
                    <td className="ac-table__numeric">{formatNumber(checker.usage_30d)}</td>
                    <td>
                      <button
                        type="button"
                        className={`ac-btn ac-btn--sm ${checker.is_enabled ? 'ac-btn--secondary' : 'ac-btn--primary'}`}
                        disabled={busy === checker.slug}
                        onClick={() =>
                          void apply(
                            checker.slug,
                            { is_enabled: !checker.is_enabled },
                            checker.is_enabled
                              ? `${checker.label} switched off. Nobody can start a new job on it.`
                              : `${checker.label} switched on.`,
                          )
                        }
                      >
                        {checker.is_enabled ? 'On' : 'Off'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <p className="ac-muted" style={{ fontSize: 'var(--ac-text-sm)', maxWidth: '72ch' }}>
        Switching a checker off stops new jobs immediately. Jobs already running are left to finish, so
        nobody is charged for a reservation that never produced results.
      </p>
    </>
  );
}
