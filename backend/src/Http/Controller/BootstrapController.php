<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Meta;
use RegRuControl\Http\Middleware\OptionalAuthMiddleware;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\AppLocale;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\I18n;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\RegRu\RegRuCommandCatalog;
use RegRuControl\Security\Csrf;
use RegRuControl\Service\RegRuAccountService;

/**
 * Everything the SPA needs before rendering: current actor, CSRF token,
 * enumeration metadata and the REG.RU command catalog size.
 */
final readonly class BootstrapController
{
    public function __construct(
        private Csrf $csrf,
        private Config $config,
        private Clock $clock,
        private RegRuAccountService $account,
        private RegRuCommandCatalog $catalog,
    ) {
    }

    #[Route('GET', '/api/v1/bootstrap', [OptionalAuthMiddleware::class])]
    public function show(Request $request): Response
    {
        $auth = $request->attribute('auth');
        $auth = $auth instanceof AuthContext ? $auth : AuthContext::guest();

        return ApiPresenter::data([
            'user' => $auth->user?->toArray(),
            'api_token' => $auth->token === null ? null : [
                'id' => $auth->token->id,
                'name' => $auth->token->name,
                'scopes' => $auth->token->scopeValues(),
            ],
            'authenticated' => $auth->isAuthenticated(),
            'is_admin' => $auth->isAdmin(),
            'csrf_token' => $this->csrf->token(),
            'locale' => I18n::locale()->value,
            'locales' => array_map(static fn (AppLocale $locale): array => [
                'code' => $locale->value,
                'label' => $locale->label(),
                'native_label' => $locale->nativeLabel(),
            ], AppLocale::cases()),
            'app' => [
                'name' => $this->config->string('app.name'),
                'version' => $this->config->string('app.version'),
                'env' => $this->config->string('app.env'),
                'time' => $this->clock->now()->format('Y-m-d\TH:i:sP'),
                'url' => $this->config->string('app.url'),
            ],
            'account' => $auth->isAuthenticated() ? $this->account->status() : null,
            'enums' => Meta::enums(),
            'regru_commands' => $this->catalog->count(),
        ]);
    }
}
