<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use AccountCheck\Services\BillingService;

/**
 * The pricing catalogue.
 *
 * The listing is public because prices are public. The payment state travels
 * with it so no surface can render a price without rendering the fact that
 * nothing can be bought here yet.
 *
 * `checkout` exists and always refuses. That is not a stub waiting to be
 * finished quietly: a credit balance may only move from a settled payment or an
 * administrator, so an endpoint that cannot settle a payment must add nothing
 * and say why.
 */
final class PlanController extends Controller
{
    public function __construct(private readonly BillingService $billing)
    {
    }

    public function index(Request $request): Response
    {
        return Response::success($this->billing->catalogue(), 'Plans');
    }

    public function show(Request $request): Response
    {
        return Response::success(
            $this->billing->find((string) $request->routeParam('slug', '')),
            'Plan details',
        );
    }

    public function checkout(Request $request): Response
    {
        // Always throws. The refusal carries the reason, so the interface can
        // show it rather than inventing a message of its own.
        $this->billing->checkout($this->user($request), (string) $request->routeParam('slug', ''));
    }
}
