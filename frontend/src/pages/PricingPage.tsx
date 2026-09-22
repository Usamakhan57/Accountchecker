import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { Alert, ErrorState, LoadingState } from '@/components/states';
import { useAuth } from '@/context/AuthContext';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { ApiError } from '@/services/apiClient';
import { listPlans, startCheckout } from '@/services/plans';
import type { PlanCatalogue } from '@/types/api';
import { formatMoney, formatNumber } from '@/utils/format';

/**
 * Formats a per-credit rate.
 *
 * A credit costs a fraction of a cent, so the usual currency formatter rounds
 * it to nothing. Four decimal places is what makes the difference between the
 * tiers visible, and that difference is the only thing that actually separates
 * them in this build.
 */
function formatRate(cents: number, currency: string): string {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    minimumFractionDigits: 4,
    maximumFractionDigits: 4,
  }).format(cents / 100);
}

/**
 * Plans and pricing.
 *
 * Shown to visitors and to signed-in users alike, which is why it sits outside
 * both layouts and picks its chrome from the session.
 *
 * No payment provider is configured in this build, and the page says so at the
 * top rather than burying it behind a button that fails. The buttons are still
 * live: pressing one calls the real endpoint and shows the real refusal, so
 * nothing here is a control that quietly does nothing.
 */
export function PricingPage() {
  usePageMeta({
    title: 'Pricing',
    description:
      'Credit plans for AccountCheck. Every check costs a published number of credits, and credits are only ever spent on records that were actually checked.',
    canonicalPath: '/pricing',
  });

  const { user } = useAuth();
  const catalogue = useApiResource<PlanCatalogue>((signal) => listPlans(signal), []);
  const [pending, setPending] = useState<string | null>(null);
  const [outcome, setOutcome] = useState<{ tone: 'warning' | 'error'; message: string } | null>(null);

  const plans = catalogue.data?.items ?? [];
  const payments = catalogue.data?.payments;

  async function handleChoose(slug: string) {
    setPending(slug);
    setOutcome(null);

    try {
      await startCheckout(slug);
      // Unreachable while no provider is configured. If a build ever gets
      // here, it means a driver settled a payment, and the balance the user
      // sees next comes from the server rather than from this page.
      setOutcome(null);
    } catch (error) {
      setOutcome({
        tone: error instanceof ApiError && error.code === 'PAYMENTS_NOT_CONFIGURED' ? 'warning' : 'error',
        message: error instanceof ApiError ? error.message : 'That could not be completed.',
      });
    } finally {
      setPending(null);
    }
  }

  return (
    <section className="ac-pricing">
      <div className="ac-container">
        <header className="ac-pricing__intro">
          <h1>Plans</h1>
          <p>
            Every check costs a published number of credits. Credits are reserved when a job starts and
            settled when it finishes, so you are charged for records that were actually checked and nothing
            else.
          </p>
        </header>

        {payments && payments.configured === false && (
          <div className="ac-pricing__notice">
            <Alert tone="warning">
              <strong>Payment is not set up on this installation yet.</strong>
              <p style={{ marginTop: '0.375rem' }}>
                {payments.reason} The prices below are what each plan will cost. To get credits in the
                meantime,{' '}
                {payments.contact_email ? (
                  <a href={`mailto:${payments.contact_email}`}>email {payments.contact_email}</a>
                ) : (
                  <Link to={user ? '/support' : '/contact'}>contact us</Link>
                )}{' '}
                and an administrator can add them to your account.
              </p>
            </Alert>
          </div>
        )}

        {outcome && (
          <div className="ac-pricing__notice">
            <Alert tone={outcome.tone}>{outcome.message}</Alert>
          </div>
        )}

        {catalogue.error ? (
          <ErrorState message={catalogue.error} onRetry={catalogue.reload} />
        ) : catalogue.loading && !catalogue.data ? (
          <LoadingState label="Loading plans…" />
        ) : (
          <div className="ac-plan-grid">
            {plans.map((plan) => (
              <article className={`ac-plan${plan.is_free ? '' : ' ac-plan--paid'}`} key={plan.slug}>
                <header className="ac-plan__header">
                  <h2 className="ac-plan__name">{plan.name}</h2>
                  <p className="ac-plan__price">
                    {plan.is_free ? 'Free' : formatMoney(plan.price_cents, plan.currency)}
                    {!plan.is_free && <span className="ac-plan__period">one-off</span>}
                  </p>
                  <p className="ac-plan__credits">{formatNumber(plan.credits)} credits</p>
                  {plan.cents_per_credit !== null && (
                    <p className="ac-plan__rate">
                      {formatRate(plan.cents_per_credit, plan.currency)} per credit
                    </p>
                  )}
                  <p className="ac-plan__description">{plan.description}</p>
                </header>

                <ul className="ac-plan__features">
                  {plan.features.map((feature) => (
                    <li key={feature}>
                      <Icon name="check" size={15} />
                      <span>{feature}</span>
                    </li>
                  ))}
                </ul>

                <footer className="ac-plan__footer">
                  {plan.is_free ? (
                    user ? (
                      <Link to="/checkers" className="ac-btn ac-btn--secondary ac-btn--block">
                        You have this
                      </Link>
                    ) : (
                      <Link to="/register" className="ac-btn ac-btn--primary ac-btn--block">
                        Create an account
                      </Link>
                    )
                  ) : user ? (
                    <button
                      type="button"
                      className="ac-btn ac-btn--primary ac-btn--block"
                      onClick={() => void handleChoose(plan.slug)}
                      disabled={pending !== null}
                    >
                      {pending === plan.slug ? 'Checking…' : `Get ${formatNumber(plan.credits)} credits`}
                    </button>
                  ) : (
                    <Link to="/register" className="ac-btn ac-btn--primary ac-btn--block">
                      Create an account
                    </Link>
                  )}
                </footer>
              </article>
            ))}
          </div>
        )}

        <div className="ac-pricing__foot">
          <h2>How credits are spent</h2>
          <ul>
            <li>
              A check costs the credits published on its checker. Duplicates are removed before a job is
              created, so the same record is never charged twice in one batch.
            </li>
            <li>
              Credits are reserved when a job starts and settled when it ends. A cancelled job settles only
              the records it had already checked.
            </li>
            <li>
              A record that could not be checked through an authorized source is reported as unavailable and
              costs nothing.
            </li>
            <li>Credits do not expire, and every movement is listed in your wallet.</li>
            <li>
              Plans differ by how many credits they include and what a credit costs. Every account gets the
              same checkers, the same batch limit and the same tools.
            </li>
          </ul>
        </div>
      </div>
    </section>
  );
}
