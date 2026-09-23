<?php

declare(strict_types=1);

namespace AccountCheck\Tests;

use AccountCheck\Core\Container;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base for tests that need the container but not the database.
 *
 * The container is built once per process. Building it per test would re-read
 * the config files and re-open a connection for every case, which is slow and
 * tells us nothing.
 */
abstract class TestCase extends BaseTestCase
{
    private static ?Container $sharedContainer = null;

    protected function container(): Container
    {
        if (self::$sharedContainer === null) {
            self::$sharedContainer = require dirname(__DIR__) . '/bootstrap/app.php';
        }

        return self::$sharedContainer;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function make(string $id): object
    {
        /** @var T $service */
        $service = $this->container()->get($id);

        return $service;
    }

    /**
     * Resolves a service from a container built from scratch.
     *
     * Several services cache per instance the way a request-scoped object
     * should - the checker registry resolves a slug once and keeps it. A test
     * that changes such a setting has to ask a new container for the service,
     * which is what the next request would get.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function makeFresh(string $id): object
    {
        /** @var Container $container */
        $container = require dirname(__DIR__) . '/bootstrap/app.php';

        /** @var T $service */
        $service = $container->get($id);

        return $service;
    }
}
