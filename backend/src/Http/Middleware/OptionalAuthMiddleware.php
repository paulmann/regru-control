<?php

declare(strict_types=1);

namespace RegRuControl\Http\Middleware;

use RegRuControl\Http\AuthContext;
use RegRuControl\Kernel\Middleware;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;

/**
 * Resolves the actor if credentials are present, but lets guests through.
 * Used by the bootstrap endpoint that must work on the login page.
 */
final readonly class OptionalAuthMiddleware implements Middleware
{
    public function __construct(private AuthMiddleware $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $context = $this->auth->resolve($request);

        return $next($request->withAttribute('auth', $context));
    }
}
