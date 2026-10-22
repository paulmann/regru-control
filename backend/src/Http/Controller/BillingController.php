<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Kernel\ValidationException;
use RegRuControl\Model\TokenScope;
use RegRuControl\Service\BillingService;

/**
 * Balance, unpaid invoices and invoice history.
 */
final readonly class BillingController
{
    public function __construct(private BillingService $billing)
    {
    }

    #[Route('GET', '/api/v1/billing', [AuthMiddleware::class])]
    public function balance(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data($this->billing->balance($request->boolInput('refresh', false)));
    }

    #[Route('GET', '/api/v1/billing/unpaid', [AuthMiddleware::class])]
    public function unpaid(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $items = array_map(static fn ($bill): array => $bill->toArray(), $this->billing->unpaid());

        return ApiPresenter::data($items, ['total' => count($items)]);
    }

    #[Route('GET', '/api/v1/billing/bills', [AuthMiddleware::class])]
    public function bills(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $from = (string) ($request->queryString('from') ?? gmdate('Y-m-d', strtotime('-90 days')));
        $to = (string) ($request->queryString('to') ?? gmdate('Y-m-d'));

        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw new ValidationException([$field => 'Дата в формате ГГГГ-ММ-ДД.']);
            }
        }

        $limit = min(500, max(1, $request->queryInt('limit', 100)));
        $offset = max(0, $request->queryInt('offset', 0));
        $bills = $this->billing->bills($from, $to, $limit, $offset);

        return ApiPresenter::data(
            array_map(static fn ($bill): array => $bill->toArray(), $bills),
            ['from' => $from, 'to' => $to, 'limit' => $limit, 'offset' => $offset],
        );
    }
}
