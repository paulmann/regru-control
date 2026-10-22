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
use RegRuControl\RegRu\Endpoint\DnssecApi;
use RegRuControl\Service\AuditService;
use RegRuControl\Service\DnsService;
use RegRuControl\Support\Validator;

/**
 * DNS zone editor backed by `zone/*`.
 */
final readonly class DnsController
{
    public function __construct(
        private DnsService $dns,
        private DnssecApi $dnssec,
        private AuditService $audit,
    ) {
    }

    #[Route('GET', '/api/v1/domains/{name}/dns', [AuthMiddleware::class])]
    public function show(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $refresh = $request->boolInput('refresh', false);
        $listing = $this->dns->listing($name, $refresh);

        return ApiPresenter::data([
            'dname' => $name,
            'dns_managed' => $listing['dns_managed'],
            'soa' => $listing['soa'],
            'synced_at' => $listing['synced_at'],
            'source' => $listing['source'],
            'records' => array_map(static fn ($record): array => $record->toArray(), $listing['records']),
        ]);
    }

    #[Route('POST', '/api/v1/domains/{name}/dns/records', [AuthMiddleware::class])]
    public function add(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::DnsWrite);

        $validator = new Validator($request->body());
        $subname = $validator->requiredString('subname', 'Поддомен', 1, 255);
        $rectype = $validator->requiredString('rectype', 'Тип записи', 1, 10);
        $content = $validator->requiredString('content', 'Содержимое', 1, 1000);
        $prio = $validator->optionalInt('prio', 'Приоритет', 0, 65535) ?? 0;
        $validator->throwIfInvalid();

        $result = $this->dns->add($name, $subname, $rectype, $content, $prio);

        return ApiPresenter::created([
            'records' => array_map(static fn ($record): array => $record->toArray(), $result['records']),
        ], ['answer' => $result['answer']]);
    }

    #[Route('PUT', '/api/v1/domains/{name}/dns/records', [AuthMiddleware::class])]
    public function update(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::DnsWrite);

        $body = $request->body();
        $old = $body['old'] ?? null;

        if (!is_array($old)) {
            throw HttpException::badRequest('Не передана исходная запись (old).');
        }

        $validator = new Validator($body);
        $subname = $validator->requiredString('subname', 'Поддомен', 1, 255);
        $rectype = $validator->requiredString('rectype', 'Тип записи', 1, 10);
        $content = $validator->requiredString('content', 'Содержимое', 1, 1000);
        $prio = $validator->optionalInt('prio', 'Приоритет', 0, 65535) ?? 0;
        $validator->throwIfInvalid();

        $result = $this->dns->update($name, $old, $subname, $rectype, $content, $prio);

        return ApiPresenter::data([
            'records' => array_map(static fn ($record): array => $record->toArray(), $result['records']),
        ], ['answer' => $result['answer']]);
    }

    #[Route('DELETE', '/api/v1/domains/{name}/dns/records', [AuthMiddleware::class])]
    public function delete(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::DnsWrite);

        $body = $request->body();
        $record = $body['record'] ?? null;

        if (!is_array($record)) {
            throw HttpException::badRequest('Не передана запись для удаления (record).');
        }

        $result = $this->dns->delete($name, $record);

        return ApiPresenter::data([
            'records' => array_map(static fn ($item): array => $item->toArray(), $result['records']),
        ], ['answer' => $result['answer']]);
    }

    #[Route('PUT', '/api/v1/domains/{name}/dns/soa', [AuthMiddleware::class])]
    public function soa(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::DnsWrite);

        $validator = new Validator($request->body());
        $ttl = $validator->requiredString('ttl', 'TTL', 1, 20);
        $minimumTtl = $validator->requiredString('minimum_ttl', 'Minimum TTL', 1, 20);
        $validator->throwIfInvalid();

        return ApiPresenter::data($this->dns->updateSoa($name, $ttl, $minimumTtl));
    }

    #[Route('GET', '/api/v1/domains/{name}/dnssec', [AuthMiddleware::class])]
    public function dnssecStatus(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        return ApiPresenter::data([
            'dname' => $name,
            'status' => $this->dnssec->status($name),
        ]);
    }

    #[Route('POST', '/api/v1/domains/{name}/dnssec', [AuthMiddleware::class])]
    public function dnssecToggle(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        $validator = new Validator($request->body());
        $enabled = $validator->requiredBool('enabled', 'DNSSEC');
        $validator->throwIfInvalid();

        $result = $enabled ? $this->dnssec->enable($name) : $this->dnssec->disable($name);
        $this->audit->record('dns.dnssec', $name, ['enabled' => $enabled]);

        return ApiPresenter::data([
            'dname' => $name,
            'enabled' => $enabled,
            'result' => $result,
        ]);
    }
    #[Route('POST', '/api/v1/domains/{name}/dns/clear', [AuthMiddleware::class])]
    public function clear(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        $validator = new Validator($request->body());
        $confirm = $validator->requiredString('confirm', 'Подтверждение', 1, 20);
        $validator->throwIfInvalid();

        if ($confirm !== 'DELETE') {
            throw new \RegRuControl\Kernel\ValidationException([
                'confirm' => 'Введите слово DELETE, чтобы удалить все записи зоны.',
            ]);
        }

        $this->dns->deleteAll($name);

        return ApiPresenter::data(['cleared' => true]);
    }
}
