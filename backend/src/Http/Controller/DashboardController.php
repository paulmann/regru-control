<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Model\TokenScope;
use RegRuControl\Service\DashboardService;
use RegRuControl\Service\InventoryService;

/**
 * Dashboard aggregates and the compact agent inventory.
 */
final readonly class DashboardController
{
    public function __construct(
        private DashboardService $dashboard,
        private InventoryService $inventory,
    ) {
    }

    #[Route('GET', '/api/v1/dashboard', [AuthMiddleware::class])]
    public function index(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data($this->dashboard->summary());
    }

    #[Route('GET', '/api/v1/inventory', [AuthMiddleware::class])]
    public function inventory(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data($this->inventory->inventory());
    }
}
