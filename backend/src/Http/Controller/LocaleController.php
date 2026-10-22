<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Kernel\AppLocale;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\I18n;
use RegRuControl\Kernel\LocaleNegotiator;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Security\Csrf;

/**
 * Language negotiation and switching.
 *
 * The SPA calls `POST /api/v1/locale` when the user picks a language; the
 * choice is stored in a cookie and also honoured for API clients through
 * `Accept-Language`.
 */
final readonly class LocaleController
{
    public function __construct(private Csrf $csrf)
    {
    }

    #[Route('GET', '/api/v1/locales')]
    public function index(Request $request): Response
    {
        $locales = [];

        foreach (AppLocale::cases() as $locale) {
            $locales[] = [
                'code' => $locale->value,
                'label' => $locale->label(),
                'native_label' => $locale->nativeLabel(),
                'html_lang' => $locale->htmlLang(),
                'default' => $locale->isDefault(),
            ];
        }

        return ApiPresenter::data([
            'locale' => I18n::locale()->value,
            'locales' => $locales,
        ]);
    }

    #[Route('POST', '/api/v1/locale')]
    public function update(Request $request): Response
    {
        $body = $request->json();
        $locale = AppLocale::fromTag(isset($body['locale']) ? (string) $body['locale'] : null);

        if ($locale === null) {
            throw HttpException::badRequest('Unsupported locale.', ['supported' => array_map(
                static fn (AppLocale $case): string => $case->value,
                AppLocale::cases(),
            )]);
        }

        $secure = (bool) ($request->server['HTTPS'] ?? false) === true || $request->header('x-forwarded-proto') === 'https';
        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=31536000; SameSite=Lax%s',
            LocaleNegotiator::COOKIE,
            $locale->value,
            $secure ? '; Secure' : '',
        );

        return ApiPresenter::data([
            'locale' => $locale->value,
            'html_lang' => $locale->htmlLang(),
            'csrf_token' => $this->csrf->token(),
        ], [], 200)->withHeader('Set-Cookie', $cookie);
    }
}
