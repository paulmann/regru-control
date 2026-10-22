<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Model\TokenScope;
use RegRuControl\RegRu\Contract\DomainApiInterface;
use RegRuControl\Repository\DnsRecordRepository;
use RegRuControl\Service\DomainService;
use RegRuControl\Support\Validator;

/**
 * Domain list, details, renewals and delegation.
 */
final readonly class DomainsController
{
    public function __construct(
        private DomainService $domains,
        private DomainApiInterface $domainApi,
        private DnsRecordRepository $dns,
        private Clock $clock,
    ) {
    }

    #[Route('GET', '/api/v1/domains', [AuthMiddleware::class])]
    public function index(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $filters = [
            'q' => $request->queryString('q', '') ?? '',
            'tld' => $request->queryString('tld', '') ?? '',
            'state' => $request->queryString('state', '') ?? '',
            'expiring_in' => $request->queryInt('expiring_in', 0),
            'sort' => $request->queryString('sort', 'expiration') ?? 'expiration',
            'dir' => $request->queryString('dir', 'asc') ?? 'asc',
            'page' => $request->queryInt('page', 1),
            'per_page' => $request->queryInt('per_page', 50),
        ];

        $result = $this->domains->search($filters);
        $today = $this->clock->now();
        $recordCounts = [];

        foreach ($this->dns->domainsWithRecords() as $row) {
            $recordCounts[$row['dname']] = $row['cnt'];
        }

        return ApiPresenter::page(
            array_map(
                static fn ($domain): array => $domain->toArray($today) + [
                    'dns_records' => $recordCounts[$domain->dname] ?? 0,
                ],
                $result['items'],
            ),
            $result['total'],
            $result['page'],
            $result['per_page'],
            ['filters' => $filters],
        );
    }

    #[Route('GET', '/api/v1/domains/{name}', [AuthMiddleware::class])]
    public function show(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $domain = $this->domains->get($name);
        $today = $this->clock->now();

        return ApiPresenter::data($domain->toArray($today) + [
            'dns_records' => $this->dns->countForDomain($name),
            'dns_synced_at' => $this->dns->syncedAt($name),
        ]);
    }

    #[Route('POST', '/api/v1/domains/{name}/renew', [AuthMiddleware::class])]
    public function renew(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $validator = new Validator($request->body());
        $period = $validator->requiredInt('period', 'Период', 1, 10);
        $validator->throwIfInvalid();

        return ApiPresenter::data($this->domains->renew($name, $period));
    }

    #[Route('POST', '/api/v1/domains/{name}/autorenew', [AuthMiddleware::class])]
    public function autorenew(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $validator = new Validator($request->body());
        $enabled = $validator->requiredBool('enabled', 'Автопродление');
        $validator->throwIfInvalid();

        return ApiPresenter::data($this->domains->setAutorenew($name, $enabled));
    }

    #[Route('PUT', '/api/v1/domains/{name}/ns', [AuthMiddleware::class])]
    public function updateNs(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Write);

        $body = $request->body();
        $nss = $body['nss'] ?? [];
        $undelegate = (bool) ($body['undelegate'] ?? false);

        if (!is_array($nss)) {
            throw \RegRuControl\Kernel\HttpException::badRequest('Поле nss должно быть массивом имён DNS-серверов.');
        }

        $ipts = [];

        if (is_array($body['ips'] ?? null)) {
            foreach ($body['ips'] as $host => $ip) {
                if (is_string($host) && is_string($ip)) {
                    $ipts[$host] = $ip;
                }
            }
        }

        return ApiPresenter::data($this->domains->updateNss(
            $name,
            array_values(array_map('strval', $nss)),
            $ipts,
            $undelegate,
        ));
    }

    #[Route('POST', '/api/v1/domains/{name}/refresh', [AuthMiddleware::class])]
    public function refresh(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $domain = $this->domains->refreshNameservers($name);

        return ApiPresenter::data($domain->toArray($this->clock->now()));
    }

    #[Route('POST', '/api/v1/domains/{name}/certificate', [AuthMiddleware::class])]
    public function certificate(Request $request, string $name): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Admin);

        $this->domains->get($name);
        $pdf = $this->domainApi->downloadCertificate($name);

        return (new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s-certificate.pdf"', $name),
        ]));
    }
}
