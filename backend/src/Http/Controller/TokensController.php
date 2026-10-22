<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Model\TokenScope;
use RegRuControl\Service\TokenService;
use RegRuControl\Support\Validator;

/**
 * API tokens for integrations and AI agents.
 */
final readonly class TokensController
{
    public function __construct(private TokenService $tokens)
    {
    }

    #[Route('GET', '/api/v1/tokens', [AuthMiddleware::class])]
    public function index(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        return ApiPresenter::data(array_map(static fn ($token): array => $token->toArray(), $this->tokens->all()));
    }

    #[Route('POST', '/api/v1/tokens', [AuthMiddleware::class])]
    public function create(Request $request): Response
    {
        $auth = AuthContext::fromRequest($request);
        $auth->require(TokenScope::Admin);

        $validator = new Validator($request->body());
        $name = $validator->requiredString('name', 'Название', 2, 100);
        $scopes = $validator->requiredList('scopes', 'Права', 10);
        $ttl = $validator->optionalInt('ttl_days', 'Срок действия', 0, 3650);
        $validator->throwIfInvalid();

        $result = $this->tokens->create(
            $name,
            array_values(array_map('strval', $scopes)),
            $ttl,
            $auth->user?->id,
        );

        return ApiPresenter::created([
            'token' => $result['token']->toArray(),
            'plain' => $result['plain'],
            'warning' => 'Сохраните токен сейчас — повторно он не показывается.',
        ]);
    }

    #[Route('DELETE', '/api/v1/tokens/{id}', [AuthMiddleware::class])]
    public function revoke(Request $request, int $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        if (!$this->tokens->revoke($id)) {
            throw HttpException::notFound('Токен не найден или уже отозван.');
        }

        return ApiPresenter::noContent();
    }
}
