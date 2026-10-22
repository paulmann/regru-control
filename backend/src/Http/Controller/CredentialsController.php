<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Model\CredentialKind;
use RegRuControl\Model\TokenScope;
use RegRuControl\Service\CredentialService;
use RegRuControl\Support\Validator;

/**
 * Encrypted credential vault.
 */
final readonly class CredentialsController
{
    public function __construct(private CredentialService $credentials)
    {
    }

    #[Route('GET', '/api/v1/credentials', [AuthMiddleware::class])]
    public function index(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $kind = $request->queryString('kind');
        $items = $this->credentials->search(
            $kind === null ? null : CredentialKind::fromString($kind),
            (string) ($request->queryString('q') ?? ''),
        );

        return ApiPresenter::data(
            array_map(static fn ($credential): array => $credential->toArray(), $items),
            ['total' => count($items), 'counts' => $this->credentials->countsByKind()],
        );
    }

    #[Route('POST', '/api/v1/credentials', [AuthMiddleware::class])]
    public function create(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $validator = new Validator($request->body());
        $title = $validator->requiredString('title', 'Название', 1, 190);
        $kind = $validator->requiredString('kind', 'Тип', 1, 20);
        $host = $validator->optionalString('host', 'Хост', 0, 255) ?? '';
        $port = $validator->optionalInt('port', 'Порт', 1, 65535);
        $username = $validator->optionalString('username', 'Логин', 0, 255) ?? '';
        $password = $validator->requiredString('password', 'Пароль', 1, 500);
        $url = $validator->optionalString('url', 'Ссылка', 0, 500) ?? '';
        $notes = $validator->optionalString('notes', 'Заметки', 0, 4000) ?? '';
        $serviceId = $validator->optionalString('service_id', 'ID услуги', 0, 30);
        $dname = $validator->optionalString('dname', 'Домен', 0, 255);
        $validator->throwIfInvalid();

        $credential = $this->credentials->create([
            'title' => $title,
            'kind' => $kind,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'url' => $url,
            'notes' => $notes,
            'service_id' => $serviceId,
            'dname' => $dname,
        ]);

        return ApiPresenter::created($credential->toArray());
    }

    #[Route('PUT', '/api/v1/credentials/{id}', [AuthMiddleware::class])]
    public function update(Request $request, int $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $body = $request->body();
        $validator = new Validator($body);
        $update = [];

        foreach (['title', 'kind', 'host', 'url', 'notes', 'username', 'dname', 'service_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $update[$field] = $validator->optionalString($field, $field, 0, 4000);
            }
        }

        if (array_key_exists('port', $body)) {
            $update['port'] = $validator->optionalInt('port', 'Порт', 1, 65535);
        }

        if (array_key_exists('password', $body)) {
            $update['password'] = $validator->optionalString('password', 'Пароль', 1, 500);
        }

        $validator->throwIfInvalid();

        return ApiPresenter::data($this->credentials->update($id, $update)->toArray());
    }

    #[Route('DELETE', '/api/v1/credentials/{id}', [AuthMiddleware::class])]
    public function delete(Request $request, int $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $this->credentials->delete($id);

        return ApiPresenter::noContent();
    }

    #[Route('POST', '/api/v1/credentials/{id}/reveal', [AuthMiddleware::class])]
    public function reveal(Request $request, int $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::SecretsRead);

        return ApiPresenter::data($this->credentials->reveal($id));
    }

    #[Route('POST', '/api/v1/credentials/generate', [AuthMiddleware::class])]
    public function generate(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $length = (int) $request->body()['length'] ?? 24;

        return ApiPresenter::data(['password' => $this->credentials->generatePassword($length)]);
    }
}
