<?php

declare(strict_types=1);

namespace AccountCheck\Controllers\Admin;

use AccountCheck\Controllers\Controller;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Repositories\PlanRepository;
use AccountCheck\Services\AdminService;
use AccountCheck\Services\BillingService;

/**
 * Plan administration.
 *
 * Inactive plans are included here and nowhere else, so an administrator can
 * see what has been withdrawn. The payment state is returned alongside, because
 * editing a price is misleading without the reminder that nothing can be bought
 * on this installation yet.
 */
final class PlanController extends Controller
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly PlanRepository $plans,
        private readonly BillingService $billing,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::success([
            'items' => $this->plans->all(),
            'payments' => $this->billing->status(),
        ], 'Plans');
    }

    public function update(Request $request): Response
    {
        $input = $this->validate($request, [
            'name' => 'string|max:80',
            'description' => 'string|max:255',
            'price_cents' => 'integer',
            'credits' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        // Features arrive as a list, which the validator above does not cover;
        // AdminService trims and filters it.
        $features = $request->input('features');

        if (is_array($features)) {
            $input['features'] = $features;
        }

        $plan = $this->admin->updatePlan(
            $this->user($request),
            (string) $request->routeParam('slug', ''),
            $input,
            $request->ip(),
        );

        return Response::success(['plan' => $plan], 'Plan updated');
    }
}
