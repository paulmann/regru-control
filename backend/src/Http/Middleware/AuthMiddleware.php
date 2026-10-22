<?php

declare(strict_types=1);

namespace RegRuControl\Http\Middleware;

use RegRuControl\Http\AuthContext;
use RegRuControl\Kernel\Logger;
use RegRuControl\Kernel\Middleware;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\RequestContext;
use RegRuControl\Kernel\Response;
use RegRuControl\Service\AuthService;
use RegRuControl\Service\TokenService;

/**
 * Authenticates the request from a Bearer token or the session cookie and
 * publishes an `AuthContext` request attribute.
 */
final readonly class AuthMiddleware implements Middleware
{
    public function __construct(
        private AuthService $authService,
        private TokenService $tokenService,
        private RequestContext $context,
        private Logger $logger,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $context = $this->resolve($request);

        if (!$context->isAuthenticated()) {
            throw \RegRuControl\Kernel\HttpException::unauthorized();
        }

        $this->context->setActor($context->actorName, $context->actorType, $request->ip(), $request->userAgent());

        return $next($request->withAttribute('auth', $context));
    }

    /**
     * Public endpoints (bootstrap, health) use this: no exception for guests.
     */
    public function resolve(Request $request): AuthContext
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null) {
            $token = $this->tokenService->authenticate($bearer);

            if ($token === null) {
                $this->logger->warning('Rejected API token', ['ip' => $request->ip()]);

                return AuthContext::guest();
            }

            return AuthContext::forToken($token, $token->scopes);
        }

        $user = $this->authService->currentUser();

        if ($user === null) {
            return AuthContext::guest();
        }

        return AuthContext::forUser($user);
    }
}
