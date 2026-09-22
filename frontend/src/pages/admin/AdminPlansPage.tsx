import { useState } from 'react';
import { Card } from '@/components/Card';
import { useToast } from '@/components/ToastProvider';
import { Alert, ErrorState, TableSkeleton } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listPlans, updatePlan } from '@/services/admin';
import { ApiError } from '@/services/apiClient';
import type { PaymentStatus, Plan } from '@/types/api';
import { formatMoney, formatNumber } from '@/utils/format';

/**
 * Plan administration.
 *
 * Inactive plans appear here and nowhere else, so a withdrawn plan is still
 * visible to the person who withdrew it. The payment state is shown at the top
 * because editing a price means nothing while nothing can be bought.
 */
export function AdminPlansPage() {
  usePageMeta({ title: 'Plans', noIndex: true, canonicalPath: '/admin/plans' });

  const toast = useToast();
  const { data, loading, error, reload } = useApiResource<{ items: Plan[]; payments: PaymentStatus }>(
    (signal) => listPlans(signal),
    [],
  );

  const [busy, setBusy] = useState<string | null>(null);
  const items = data?.items ?? [];

  async function apply(slug: string, changes: Parameters<typeof updatePlan>[1], message: string) {
    setBusy(slug);

    try {
      await updatePlan(slug, changes);
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
          <h1 className="ac-page-header__title">Plans</h1>
          <p className="ac-page-header__subtitle">Prices and credit allowances, including withdrawn plans.</p>
        </div>
      </header>

      {data && data.payments.configured === false && (
        <Alert tone="warning">
          No payment provider is configured, so nothing on this installation can be bought. Prices here are
          what a plan will cost once a provider is wired up; credits reach an account through an
          administrator in the meantime.
        </Alert>
      )}

      <Card flush title="Pricing">
        {error ? (
          <ErrorState message={error} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={4} columns={5} />
        ) : (
          <div className="ac-table-wrap">
            <table className="ac-table">
              <thead>
                <tr>
                  <th scope="col">Plan</th>
                  <th scope="col" className="ac-table__numeric">
                    Price (cents)
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Credits
                  </th>
                  <th scope="col" className="ac-table__numeric">
                    Works out at
                  </th>
                  <th scope="col">Listed</th>
                </tr>
              </thead>
              <tbody>
                {items.map((plan) => (
                  <tr key={plan.slug}>
                    <td>
                      {plan.name}
                      <span className="ac-muted" style={{ display: 'block', fontSize: 'var(--ac-text-xs)' }}>
                        {plan.description}
                      </span>
                    </td>
                    <td className="ac-table__numeric">
                      <input
                        type="number"
                        className="ac-input ac-input--inline"
                        defaultValue={plan.price_cents}
                        min={0}
                        disabled={busy === plan.slug}
                        aria-label={`Price in cents for ${plan.name}`}
                        onBlur={(event) => {
                          const value = Number.parseInt(event.target.value, 10);

                          if (Number.isFinite(value) && value !== plan.price_cents) {
                            void apply(
                              plan.slug,
                              { price_cents: value },
                              `${plan.name} is now ${formatMoney(value, plan.currency)}.`,
                            );
                          }
                        }}
                      />
                    </td>
                    <td className="ac-table__numeric">
                      <input
                        type="number"
                        className="ac-input ac-input--inline"
                        defaultValue={plan.credits}
                        min={0}
                        disabled={busy === plan.slug}
                        aria-label={`Credits included in ${plan.name}`}
                        onBlur={(event) => {
                          const value = Number.parseInt(event.target.value, 10);

                          if (Number.isFinite(value) && value !== plan.credits) {
                            void apply(
                              plan.slug,
                              { credits: value },
                              `${plan.name} now includes ${formatNumber(value)} credits.`,
                            );
                          }
                        }}
                      />
                    </td>
                    <td className="ac-table__numeric ac-muted">
                      {plan.cents_per_credit === null
                        ? '—'
                        : `${formatMoney(plan.price_cents, plan.currency)} / ${formatNumber(plan.credits)}`}
                    </td>
                    <td>
                      <button
                        type="button"
                        className={`ac-btn ac-btn--sm ${plan.is_active ? 'ac-btn--secondary' : 'ac-btn--primary'}`}
                        disabled={busy === plan.slug}
                        onClick={() =>
                          void apply(
                            plan.slug,
                            { is_active: !plan.is_active },
                            plan.is_active
                              ? `${plan.name} removed from the pricing page.`
                              : `${plan.name} is on the pricing page.`,
                          )
                        }
                      >
                        {plan.is_active ? 'Listed' : 'Hidden'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}
