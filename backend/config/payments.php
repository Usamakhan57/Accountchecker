<?php

declare(strict_types=1);

use AccountCheck\Core\Env;

/**
 * Payment configuration.
 *
 * No payment provider is integrated. This file exists so that the state is
 * explicit and readable rather than implied by the absence of code: with no
 * driver configured, the checkout endpoint says so and refuses, and the pricing
 * page tells the user how to actually get credits. Nothing anywhere pretends a
 * purchase happened.
 *
 * Adding a provider means implementing a driver that, on a verified webhook
 * from the provider, credits the wallet server-side. Until then the honest
 * answer is NOT CONFIGURED.
 */
return [
    // '' means no provider. A provider name here would also need a driver
    // implementation; an unknown name is treated as unconfigured.
    'driver' => Env::string('PAYMENT_DRIVER', ''),

    // Drivers that this build can actually fulfil. Empty by design.
    'supported_drivers' => [],

    // Currency plans are priced in. Display only until a provider exists.
    'currency' => Env::string('PAYMENT_CURRENCY', 'USD'),

    // Where a user is told to go while self-service checkout is unavailable.
    // An address here is shown on the pricing page; without one the page points
    // at the support form instead.
    'sales_email' => Env::string('PAYMENT_SALES_EMAIL', ''),
];
