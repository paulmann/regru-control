<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use RegRuControl\Support\Json;
use Throwable;

/**
 * Converts any throwable into a safe response.
 *
 * Production hides internal details behind a correlation id; development shows
 * the exception class, message, file and trace so the loop stays fast.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly RequestContext $context,
    ) {
    }

    public function handle(Throwable $exception, Request $request): Response
    {
        $this->logger->exception($exception, [
            'path' => $request->path,
            'method' => $request->method,
            'ip' => $request->ip(),
        ]);

        if ($exception instanceof HttpException) {
            return $exception->toResponse($this->context->id());
        }

        $debug = $this->config->isDebug();

        $details = [];

        if ($debug) {
            $details = [
                'exception' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }

        $message = $debug ? $exception->getMessage() : 'Внутренняя ошибка сервера.';

        if ($request->isApi()) {
            return (new Response(
                500,
                Json::encode([
                    'error' => [
                        'code' => 'INTERNAL_ERROR',
                        'message' => $message,
                        'details' => $details,
                    ],
                    'request_id' => $this->context->id(),
                ]),
                ['Content-Type' => 'application/json; charset=utf-8'],
            ));
        }

        $body = '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;background:#0d1117;color:#e6edf3;'
            . 'display:grid;place-items:center;min-height:100vh;margin:0}main{max-width:560px;padding:32px}'
            . 'h1{font-size:22px;margin:0 0 12px}p{color:#8b949e}code{color:#4da3ff}</style>'
            . '<main><h1>Что-то пошло не так</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Код обращения: <code>' . htmlspecialchars($this->context->id(), ENT_QUOTES, 'UTF-8') . '</code></p></main></html>';

        return Response::html($body, 500);
    }
}
