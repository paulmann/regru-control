<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

final class Pipeline
{
    /**
     * @param list<Middleware|class-string<Middleware>> $middleware
     * @param callable(Request): Response $core
     */
    public static function handle(array $middleware, Request $request, callable $core, Container $container): Response
    {
        $next = $core;

        foreach (array_reverse($middleware) as $entry) {
            $instance = is_string($entry) ? $container->get($entry) : $entry;

            if (!$instance instanceof Middleware) {
                throw new \RuntimeException(sprintf('%s must implement %s.', $instance::class, Middleware::class));
            }

            $next = static fn (Request $current): Response => $instance->handle($current, $next);
        }

        return $next($request);
    }
}
