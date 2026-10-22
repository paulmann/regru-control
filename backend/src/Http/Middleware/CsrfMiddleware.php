<?php

declare(strict_types=1);

namespace RegRuControl\Http\Middleware;

use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\I18n;
use RegRuControl\Kernel\Middleware;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Security\Csrf;

/**
 * CSRF protection for cookie-authenticated state changes.
 *
 * Bearer-token API calls are exempt: they are not sent automatically by the
 * browser, so they cannot be forged cross-site.
 */
final readonly class CsrfMiddleware implements Middleware
{
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const HEADER = 'x-csrf-token';

    public function __construct(private Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method, self::UNSAFE_METHODS, true)) {
            return $next($request);
        }

        if ($request->bearerToken() !== null) {
            return $next($request);
        }

        if (!$this->csrf->validate($request->header(self::HEADER))) {
            throw new HttpException(
                403,
                'CSRF_FAILED',
                I18n::t('error.csrf_failed'),
            );
        }

        return $next($request);
    }
}
