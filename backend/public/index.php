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

// The PHP version is not something a client needs, and a version number is a
// free hint to anyone matching known bugs against a host. header_remove works
// whatever expose_php says in the ini.
header_remove('X-Powered-By');

/** @var Container $container */
$container = require dirname(__DIR__) . '/bootstrap/app.php';

$application = $container->get(Application::class);

$application->handle(Request::fromGlobals())->send();
