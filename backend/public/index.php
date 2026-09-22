<?php

declare(strict_types=1);

/**
 * AccountCheck API front controller.
 *
 * Nginx rewrites every /api request to this file; see docs/DEPLOYMENT.md.
 * Nothing else in the backend tree is web-reachable.
 */

use AccountCheck\Core\Application;
use AccountCheck\Core\Container;
use AccountCheck\Core\Request;

/** @var Container $container */
$container = require dirname(__DIR__) . '/bootstrap/app.php';

$application = $container->get(Application::class);

$application->handle(Request::fromGlobals())->send();
