<?php

declare(strict_types=1);

namespace AccountCheck\Core;

use Closure;
use RuntimeException;

/**
 * Service container with explicit factories plus constructor autowiring.
 *
 * Autowiring covers the common case (a controller asking for its services);
 * anything needing configuration is bound explicitly in bootstrap/app.php.
 */
final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @param Closure(self): mixed $factory */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new RuntimeException('Circular service dependency detected.');
        }

        $this->resolving[$id] = true;

        try {
            $resolved = isset($this->factories[$id])
                ? ($this->factories[$id])($this)
                : $this->autowire($id);
        } finally {
            unset($this->resolving[$id]);
        }

        $this->instances[$id] = $resolved;

        return $resolved;
    }

    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new RuntimeException('Service is not registered.');
        }

        $reflection = new \ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException('Service cannot be instantiated.');
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException('Service dependency cannot be resolved.');
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
