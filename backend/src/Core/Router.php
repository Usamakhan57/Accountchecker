<?php

declare(strict_types=1);

namespace AccountCheck\Core;

/**
 * Small regex router with per-route middleware.
 *
 * Routes are declared as "/api/jobs/{id}"; each {param} becomes a named capture
 * restricted to a safe character class, so path values never carry slashes.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string>}> */
    private array $routes = [];

    /** @var list<string> */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    /**
     * @param list<string> $middleware
     * @param callable(self): void $routes
     */
    public function group(string $prefix, array $middleware, callable $routes): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $routes($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function patch(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @return array{route: array{handler: array{0: class-string, 1: string}, middleware: list<string>}, params: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $normalised = '/' . trim($path, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $normalised, $matches) === 1) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                return [
                    'route' => ['handler' => $route['handler'], 'middleware' => $route['middleware']],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * Distinguishes "no such path" from "wrong verb" so the API can answer 405.
     *
     * @return list<string>
     */
    public function allowedMethodsFor(string $path): array
    {
        $normalised = '/' . trim($path, '/');
        $methods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $normalised) === 1) {
                $methods[] = $route['method'];
            }
        }

        return array_values(array_unique($methods));
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $pattern = '/' . trim($this->groupPrefix . $path, '/');

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $this->compile($pattern),
            'handler' => $handler,
            'middleware' => array_values(array_merge($this->groupMiddleware, $middleware)),
        ];
    }

    private function compile(string $pattern): string
    {
        // Escape the literal parts first, then swap the (now escaped) {param}
        // placeholders for named captures that cannot span a path separator.
        $escaped = preg_quote($pattern, '#');
        $regex = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[A-Za-z0-9_.-]+)',
            $escaped,
        );

        return '#^' . ($regex ?? $escaped) . '$#';
    }
}
