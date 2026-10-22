<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * Middleware contract: inspect/modify the request, call `$next`, optionally
 * decorate the response. Return early to short-circuit (auth, CSRF, limits).
 */
interface Middleware
{
    public function handle(Request $request, callable $next): Response;
}
