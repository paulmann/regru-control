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
use RegRuControl\Service\SyncService;
use RegRuControl\Support\Validator;

/**
 * Inventory synchronisation runs.
 */
final readonly class SyncController
{
    public function __construct(private SyncService $sync)
    {
    }

    #[Route('GET', '/api/v1/sync', [AuthMiddleware::class])]
    public function status(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data([
            'status' => $this->sync->status(),
            'history' => $this->sync->history(20),
        ]);
    }

    #[Route('POST', '/api/v1/sync', [AuthMiddleware::class])]
    public function run(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $validator = new Validator($request->body());
        $scope = $validator->requiredEnum('scope', 'Объём', SyncService::SCOPES);
        $trigger = $validator->optionalString('trigger', 'Источник', 0, 30) ?? 'manual';
        $validator->throwIfInvalid();

        return ApiPresenter::data($this->sync->run($scope, $trigger));
    }
}
