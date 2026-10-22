<?php

declare(strict_types=1);

namespace RegRuControl\Http\Middleware;

use RegRuControl\Http\AuthContext;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\Middleware;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Support\RateLimiter;

/**
 * Per-token (and per-IP for unnamed clients) API rate limit.
 */
final readonly class ApiRateLimitMiddleware implements Middleware
{
    public function __construct(
        private RateLimiter $limiter,
        private Config $config,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $auth = $request->attribute('auth');

        if (!$auth instanceof AuthContext || !$auth->isAuthenticated()) {
            return $next($request);
        }

        $key = $auth->token !== null ? 'token:' . $auth->token->id : 'ip:' . $request->ip();
        $limit = max(1, $this->config->int('security.api_rate_limit', 600));
        $window = max(1, $this->config->int('security.api_rate_window', 60));

        if (!$this->limiter->hit('api', $key, $limit, $window)) {
            throw HttpException::tooManyRequests(
                'Слишком много запросов. Попробуйте через минуту.',
                ['limit' => $limit, 'window_seconds' => $window],
                $this->limiter->retryAfter($window),
            );
        }

        return $next($request);
    }
}
