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
use RegRuControl\Service\InventoryService;
use RegRuControl\Service\RegRuAccountService;
use RegRuControl\Service\SettingsService;
use RegRuControl\Support\Validator;

/**
 * Panel settings, REG.RU account, data export.
 */
final readonly class SettingsController
{
    public function __construct(
        private SettingsService $settings,
        private RegRuAccountService $account,
        private InventoryService $inventory,
    ) {
    }

    #[Route('GET', '/api/v1/settings', [AuthMiddleware::class])]
    public function show(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data($this->settings->view());
    }

    #[Route('PUT', '/api/v1/settings', [AuthMiddleware::class])]
    public function update(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        $validator = new Validator($request->body());
        $ttl = $validator->optionalInt('sync_ttl', 'Интервал синхронизации', 300, 86400);
        $validator->throwIfInvalid();

        return ApiPresenter::data($this->settings->update(array_filter([
            'sync_ttl' => $ttl,
        ], static fn ($value): bool => $value !== null)));
    }

    #[Route('PUT', '/api/v1/settings/account', [AuthMiddleware::class])]
    public function updateAccount(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        $validator = new Validator($request->body());
        $username = $validator->requiredString('username', 'Логин REG.RU', 1, 190);
        $password = $validator->requiredString('password', 'Пароль API', 1, 300);
        $currency = $validator->optionalString('currency', 'Валюта', 3, 3) ?? 'RUR';
        $validator->throwIfInvalid();

        return ApiPresenter::data($this->account->save($username, $password, $currency));
    }

    #[Route('POST', '/api/v1/settings/account/test', [AuthMiddleware::class])]
    public function testAccount(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        return ApiPresenter::data($this->account->verify());
    }

    #[Route('POST', '/api/v1/settings/export', [AuthMiddleware::class])]
    public function export(Request $request): Response
    {
        $auth = AuthContext::fromRequest($request);
        $body = $request->body();
        $includeSecrets = (bool) ($body['include_secrets'] ?? false);

        if ($includeSecrets) {
            $auth->require(TokenScope::SecretsRead);
            $auth->require(TokenScope::Admin);
            $confirm = (string) ($body['confirm'] ?? '');

            if ($confirm !== 'EXPORT') {
                throw new \RegRuControl\Kernel\ValidationException([
                    'confirm' => 'Для выгрузки с паролями введите слово EXPORT.',
                ]);
            }
        } else {
            $auth->require(TokenScope::Read);
        }

        return ApiPresenter::data($this->inventory->export($includeSecrets));
    }
}
