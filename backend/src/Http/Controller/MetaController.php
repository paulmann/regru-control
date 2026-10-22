<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Http\Meta;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Model\TokenScope;
use RegRuControl\RegRu\RegRuClient;
use RegRuControl\RegRu\RegRuCommandCatalog;
use RegRuControl\Service\AuditService;
use RegRuControl\Service\DomainService;
use RegRuControl\Support\Json;

/**
 * Reference data and the raw REG.RU API console.
 *
 * The catalog is generated from the official documentation, so every documented
 * command (including partner-only and rarely used ones) is reachable through
 * `POST /api/v1/regru/call` without waiting for a typed wrapper.
 */
final readonly class MetaController
{
    private const COMMAND_PATTERN = '/^[a-z0-9_]+(\/[a-z0-9_]+)?$/';

    public function __construct(
        private RegRuCommandCatalog $catalog,
        private DomainService $domains,
        private RegRuClient $client,
        private AuditService $audit,
    ) {
    }

    #[Route('GET', '/api/v1/meta', [AuthMiddleware::class])]
    public function meta(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data([
            'enums' => Meta::enums(),
            'regru' => [
                'catalog' => $this->catalog->meta(),
                'groups' => $this->catalog->grouped(),
            ],
        ]);
    }

    #[Route('GET', '/api/v1/meta/commands', [AuthMiddleware::class])]
    public function commands(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data([
            'meta' => $this->catalog->meta(),
            'commands' => $this->catalog->all(),
            'groups' => $this->catalog->grouped(),
        ]);
    }

    #[Route('GET', '/api/v1/meta/prices', [AuthMiddleware::class])]
    public function prices(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data($this->domains->prices());
    }

    #[Route('POST', '/api/v1/regru/call', [AuthMiddleware::class])]
    public function rawCall(Request $request): Response
    {
        $auth = AuthContext::fromRequest($request);
        $auth->require(TokenScope::RegruRaw);

        $body = $request->body();
        $command = trim((string) ($body['command'] ?? ''));
        $input = $body['input'] ?? [];

        if ($command === '' || preg_match(self::COMMAND_PATTERN, $command) !== 1) {
            throw HttpException::badRequest('Некорректное имя команды REG.RU API.');
        }

        if (!is_array($input)) {
            throw HttpException::badRequest('Поле input должно быть объектом.');
        }

        $known = $this->catalog->has($command);
        $this->audit->record('regru.raw_call', $command, [
            'known_command' => $known,
            'input_keys' => array_keys($input),
        ]);

        if ($command === 'domain/download_certificate') {
            $bytes = $this->client->download($command, $input);

            return ApiPresenter::data([
                'command' => $command,
                'content_type' => 'application/pdf',
                'base64' => base64_encode($bytes),
            ], ['known_command' => $known]);
        }

        $response = $this->client->call($command, $input);

        return ApiPresenter::data([
            'command' => $command,
            'raw' => $response->raw,
        ], ['known_command' => $known]);
    }

    #[Route('GET', '/api/v1/openapi.yaml')]
    public function openapi(Request $request): Response
    {
        $file = APP_ROOT . '/docs/openapi.yaml';

        if (!is_file($file)) {
            throw HttpException::notFound('Спецификация OpenAPI ещё не собрана.');
        }

        $yaml = file_get_contents($file);

        if ($yaml === false) {
            throw HttpException::notFound('Не удалось прочитать спецификацию OpenAPI.');
        }

        return Response::text($yaml, 200, ['Content-Type' => 'application/yaml; charset=utf-8']);
    }

    #[Route('GET', '/api/v1/meta/health-report')]
    public function notImplementedReport(Request $request): Response
    {
        throw HttpException::notFound();
    }
}
