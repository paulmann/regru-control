<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use RegRuControl\Http\Middleware\ApiRateLimitMiddleware;
use RegRuControl\Http\Middleware\CsrfMiddleware;
use Throwable;

/**
 * Application kernel: negotiates the locale, wires routes once, dispatches
 * requests and decorates every response with security headers and a request id.
 */
final class App
{
    /** @var list<class-string> */
    private array $controllers;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly RequestContext $context,
        private readonly ErrorHandler $errorHandler,
        private readonly Router $router,
        private readonly LocaleNegotiator $locales,
        private readonly Translator $translator,
    ) {
        /** @var list<class-string> $controllers */
        $controllers = require APP_ROOT . '/backend/config/controllers.php';
        $this->controllers = $controllers;

        foreach ($this->controllers as $controller) {
            $this->router->registerController($controller);
        }
    }

    public function handle(Request $request): Response
    {
        $this->context->reset();

        $locale = $this->locales->negotiate($request);
        I18n::init($this->translator->withLocale($locale));

        $started = microtime(true);

        try {
            $response = Pipeline::handle(
                [CsrfMiddleware::class, ApiRateLimitMiddleware::class],
                $request,
                fn (Request $current): Response => $this->router->dispatch($current),
                $this->container,
            );
        } catch (Throwable $exception) {
            $response = $this->errorHandler->handle($exception, $request);
        }

        $response = SecurityHeaders::apply($response, $this->config, $request);
        $response = $response
            ->withHeader('X-Request-Id', $this->context->id())
            ->withHeader('Content-Language', $locale->value)
            ->withHeader('Vary', 'Accept-Language, Cookie')
            ->withHeader('Server-Timing', sprintf('app;dur=%.1f', (microtime(true) - $started) * 1000))
            ->forMethod($request->method);

        return $response;
    }

    public function run(): never
    {
        $this->handle(Request::fromGlobals())->send();

        exit;
    }

    /** @return list<string> */
    public function routes(): array
    {
        return $this->router->routeList();
    }
}
