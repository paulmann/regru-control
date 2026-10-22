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
use RegRuControl\Service\AuditService;

/**
 * Audit journal: who did what, from which address.
 */
final readonly class AuditController
{
    public function __construct(private AuditService $audit)
    {
    }

    #[Route('GET', '/api/v1/audit', [AuthMiddleware::class])]
    public function index(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        $limit = min(500, max(1, $request->queryInt('limit', 100)));
        $offset = max(0, $request->queryInt('offset', 0));
        $result = $this->audit->search([
            'action' => (string) ($request->queryString('action') ?? ''),
            'actor' => (string) ($request->queryString('actor') ?? ''),
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return ApiPresenter::data(
            array_map(static fn ($event): array => $event->toArray(), $result['items']),
            ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        );
    }
}
