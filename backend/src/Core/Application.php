<?php

declare(strict_types=1);

namespace AccountCheck\Core;

use AccountCheck\Support\Logger;
use Throwable;

/**
 * HTTP kernel: resolves a route, runs its middleware, invokes the controller
 * and turns anything thrown along the way into a safe JSON error.
 */
final class Application
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (HttpException $e) {
            return $this->decorate($e->toResponse(), $request);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled application error', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            // The client never sees the class, file, line or driver message.
            return $this->decorate(
                Response::error('An unexpected error occurred. Please try again.', 'INTERNAL_ERROR', 500),
                $request,
            );
        }
    }

    private function dispatch(Request $request): Response
    {
        // CORS and security headers apply to every response, including errors
        // and preflight, so they run before routing.
        $global = $this->globalMiddleware();

        foreach ($global as $middlewareId) {
            $result = $this->resolveMiddleware($middlewareId)->handle($request);
            if ($result instanceof Response) {
                return $this->decorate($result, $request);
            }
        }

        $match = $this->router->match($request->method(), $request->path());

        if ($match === null) {
            $allowed = $this->router->allowedMethodsFor($request->path());

            if ($allowed !== []) {
                return $this->decorate(
                    Response::error('This method is not supported for that endpoint.', 'METHOD_NOT_ALLOWED', 405)
                        ->withHeader('Allow', implode(', ', $allowed)),
                    $request,
                );
            }

            return $this->decorate(
                Response::error('The requested endpoint does not exist.', 'NOT_FOUND', 404),
                $request,
            );
        }

        $request->withRouteParams($match['params']);

        foreach ($match['route']['middleware'] as $middlewareId) {
            $result = $this->resolveMiddleware($middlewareId)->handle($request);
            if ($result instanceof Response) {
                return $this->decorate($result, $request);
            }
        }

        [$class, $method] = $match['route']['handler'];
        $controller = $this->container->get($class);

        if (!is_object($controller) || !method_exists($controller, $method)) {
            throw new HttpException('The requested endpoint is unavailable.', 500, 'HANDLER_MISSING');
        }

        /** @var Response $response */
        $response = $controller->{$method}($request);

        return $this->decorate($response, $request);
    }

    /**
     * Re-applies the response-shaping middleware (CORS, security headers) to
     * whatever response came back, including short-circuited errors.
     */
    private function decorate(Response $response, Request $request): Response
    {
        /** @var list<class-string<ResponseDecorator>> $decorators */
        $decorators = $this->config->array('app.response_decorators');

        foreach ($decorators as $decoratorId) {
            $decorator = $this->container->get($decoratorId);
            if ($decorator instanceof ResponseDecorator) {
                $response = $decorator->decorate($response, $request);
            }
        }

        return $response;
    }

    /** @return list<string> */
    private function globalMiddleware(): array
    {
        /** @var list<string> $middleware */
        $middleware = $this->config->array('app.global_middleware');

        return $middleware;
    }

    private function resolveMiddleware(string $id): Middleware
    {
        $middleware = $this->container->get($id);

        if (!$middleware instanceof Middleware) {
            throw new HttpException('Request could not be processed.', 500, 'MIDDLEWARE_INVALID');
        }

        return $middleware;
    }
}
