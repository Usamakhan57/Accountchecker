<?php

declare(strict_types=1);

use AccountCheck\Core\Env;

/**
 * Security settings that are not specific to sessions or the database.
 */
return [
    'csrf' => [
        // Off only for a test harness that drives the API without a browser.
        // Never set this false in production: the API authenticates with a
        // cookie, so without it any site could make a request as a signed-in
        // user.
        'enabled' => Env::bool('CSRF_PROTECTION', true),

        // Readable by JavaScript by design; see CsrfMiddleware.
        'cookie_name' => Env::string('CSRF_COOKIE_NAME', 'accountcheck_csrf'),

        // Paths that change state without a browser session behind them.
        // Deliberately short: everything else needs a token.
        'exempt' => [
            // Signing out must work even from a page whose token has been lost,
            // otherwise a user can be left unable to end their own session. It
            // destroys a session and creates nothing, so forging it is a
            // nuisance rather than an attack.
            '/api/auth/logout',
        ],
    ],

    // Removes the PHP version from responses. The server may also be doing this
    // via expose_php; doing it here means it holds whatever the ini says.
    'hide_server_signature' => true,
];
