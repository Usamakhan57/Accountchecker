<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Core\Config;
use AccountCheck\Core\HttpException;
use AccountCheck\Models\AuthenticatedUser;
use AccountCheck\Repositories\ActivityLogRepository;
use AccountCheck\Repositories\PlanRepository;

/**
 * Plans, and what happens when someone tries to buy one.
 *
 * No payment provider is integrated in this build, and this service is where
 * that fact is stated rather than hidden. `status()` reports it, the pricing
 * page renders it, and `checkout()` refuses with an explanation instead of
 * producing a confirmation for a payment that never happened.
 *
 * The rule this protects is the one that matters for a credit system: credits
 * only ever appear from a settled payment or from an administrator, both
 * server-side. A checkout call that cannot settle a payment must therefore add
 * nothing, and must say so.
 */
final class BillingService
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly ActivityLogRepository $activity,
        private readonly Config $config,
    ) {
    }

    /**
     * Whether self-service purchase can actually complete.
     *
     * A driver name that has no implementation behind it counts as
     * unconfigured: a half-wired provider must not read as a working one.
     *
     * @return array{configured: bool, driver: string|null, currency: string, reason: string, contact_email: string|null}
     */
    public function status(): array
    {
        $driver = strtolower(trim($this->config->string('payments.driver', '')));
        $supported = array_map(
            static fn (mixed $name): string => strtolower(trim((string) $name)),
            $this->config->array('payments.supported_drivers'),
        );

        $configured = $driver !== '' && in_array($driver, $supported, true);
        $email = trim($this->config->string('payments.sales_email', ''));

        return [
            'configured' => $configured,
            'driver' => $configured ? $driver : null,
            'currency' => strtoupper($this->config->string('payments.currency', 'USD')),
            'reason' => $configured
                ? ''
                : 'Online payment is not configured on this installation, so plans cannot be bought here yet.',
            'contact_email' => $email === '' ? null : $email,
        ];
    }

    /**
     * The plans a visitor may see, with the payment state alongside them.
     *
     * The state travels with the list deliberately: a price with no way to pay
     * it is misleading on its own, and every surface that renders a price needs
     * to render the caveat too.
     *
     * @return array{items: list<array<string, mixed>>, payments: array<string, mixed>}
     */
    public function catalogue(): array
    {
        $plans = array_map(
            fn (array $plan): array => $this->present($plan),
            $this->plans->active(),
        );

        return [
            'items' => $plans,
            'payments' => $this->status(),
        ];
    }

    /** @return array<string, mixed> */
    public function find(string $slug): array
    {
        $plan = $this->plans->findBySlug($slug);

        if ($plan === null || $plan['is_active'] !== true) {
            throw HttpException::notFound('That plan does not exist.', 'PLAN_NOT_FOUND');
        }

        return $this->present($plan);
    }

    /**
     * Records a purchase request that cannot be fulfilled.
     *
     * There is no code path here that adds credits, and there is not meant to
     * be one until a provider exists to confirm a payment first. What this does
     * is log the interest so an administrator can follow it up, then return a
     * refusal the interface can show as it is.
     *
     */
    public function checkout(AuthenticatedUser $user, string $slug): never
    {
        $plan = $this->find($slug);
        $status = $this->status();

        if ($status['configured'] === true) {
            // A driver was configured without an implementation to match. Fail
            // loudly rather than silently doing nothing.
            throw new HttpException(
                'Checkout is unavailable. Please contact support.',
                503,
                'PAYMENT_DRIVER_MISSING',
            );
        }

        $this->activity->record(
            $user->id,
            'billing.checkout_unavailable',
            'plan',
            (int) $plan['id'],
            ['plan' => $plan['slug']],
        );

        throw new HttpException(
            $status['reason'] . ' Your request has been noted, and an administrator can add credits to your account in the meantime.',
            503,
            'PAYMENTS_NOT_CONFIGURED',
        );
    }

    /**
     * Adds the derived figures the pricing page shows.
     *
     * Derived on the server so every surface shows the same number, and so a
     * rounding choice is made once rather than in each client.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function present(array $plan): array
    {
        $credits = (int) $plan['credits'];
        $cents = (int) $plan['price_cents'];

        $plan['is_free'] = $cents === 0;
        // Cost of one credit, in minor units, to two decimals. Null when the
        // plan is free, because dividing by nothing is not a price.
        $plan['cents_per_credit'] = ($cents > 0 && $credits > 0)
            ? round($cents / $credits, 3)
            : null;

        return $plan;
    }
}
