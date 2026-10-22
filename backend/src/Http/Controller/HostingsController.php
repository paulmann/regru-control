<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Model\TokenScope;
use RegRuControl\Service\HostingService;

/**
 * Hostings and servers with their addresses and panel credentials.
 */
final readonly class HostingsController
{
    public function __construct(
        private HostingService $hostings,
        private Clock $clock,
    ) {
    }

    #[Route('GET', '/api/v1/hostings', [AuthMiddleware::class])]
    public function index(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $today = $this->clock->now();
        $items = array_map(
            static fn ($hosting): array => $hosting->toArray($today),
            $this->hostings->all(),
        );

        return ApiPresenter::data($items, ['total' => count($items)]);
    }

    #[Route('GET', '/api/v1/hostings/{id}', [AuthMiddleware::class])]
    public function show(Request $request, string $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $hosting = $this->hostings->get($id);

        return ApiPresenter::data($hosting->toArray($this->clock->now(), true));
    }

    #[Route('POST', '/api/v1/hostings/sync', [AuthMiddleware::class])]
    public function sync(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        return ApiPresenter::data($this->hostings->syncDetails($request->boolInput('force', false)));
    }

    #[Route('POST', '/api/v1/hostings/{id}/reveal', [AuthMiddleware::class])]
    public function reveal(Request $request, string $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::SecretsRead);

        return ApiPresenter::data($this->hostings->panelCredentials($id));
    }
}
