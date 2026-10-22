<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Http\ApiPresenter;
use RegRuControl\Http\AuthContext;
use RegRuControl\Http\Middleware\AuthMiddleware;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Model\ServiceCategory;
use RegRuControl\Model\ServiceType;
use RegRuControl\Model\TokenScope;
use RegRuControl\RegRu\Contract\ServicesApiInterface;
use RegRuControl\Repository\ServiceRepository;
use RegRuControl\Service\HostingService;
use Throwable;

/**
 * Every REG.RU service (domains, hostings, servers, licenses, add-ons).
 */
final readonly class ServicesController
{
    public function __construct(
        private ServiceRepository $services,
        private ServicesApiInterface $servicesApi,
        private HostingService $hostings,
        private Clock $clock,
    ) {
    }

    #[Route('GET', '/api/v1/services', [AuthMiddleware::class])]
    public function index(Request $request): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $type = ServiceType::fromApi($request->queryString('servtype'));
        $categoryValue = $request->queryString('category');
        $category = $categoryValue === null ? null : ServiceCategory::tryFrom($categoryValue);

        $items = $this->services->all($type, $category);
        $today = $this->clock->now();
        $query = trim((string) ($request->queryString('q') ?? ''));

        $rows = array_map(static fn ($service): array => $service->toArray($today), $items);

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
                return str_contains(mb_strtolower((string) $row['dname']), $needle)
                    || str_contains(mb_strtolower((string) $row['type_label']), $needle)
                    || str_contains((string) $row['service_id'], $needle);
            }));
        }

        return ApiPresenter::data($rows, ['total' => count($rows)]);
    }

    #[Route('GET', '/api/v1/services/{id}', [AuthMiddleware::class])]
    public function show(Request $request, string $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $service = $this->services->find($id);

        if ($service === null) {
            throw HttpException::notFound('Услуга не найдена. Запустите синхронизацию.');
        }

        $payload = $service->toArray($this->clock->now());

        try {
            $checks = $this->servicesApi->checkReadonly([$service->serviceId]);
            $payload['readonly'] = $checks[0] ?? null;
        } catch (Throwable $exception) {
            $payload['readonly_error'] = $exception->getMessage();
        }

        if ($service->type?->category() === ServiceCategory::Hosting || $service->type?->category() === ServiceCategory::Server) {
            $hosting = $this->hostings->get($service->serviceId);
            $payload['hosting'] = $hosting->toArray($this->clock->now(), true);
        }

        return ApiPresenter::data($payload);
    }

    #[Route('GET', '/api/v1/services/{id}/readonly', [AuthMiddleware::class])]
    public function readonly(Request $request, string $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $service = $this->services->find($id);

        if ($service === null) {
            throw HttpException::notFound('Услуга не найдена.');
        }

        $checks = $this->servicesApi->checkReadonly([$service->serviceId]);

        return ApiPresenter::data($checks[0] ?? null);
    }

    #[Route('GET', '/api/v1/services/{id}/bills', [AuthMiddleware::class])]
    public function bills(Request $request, string $id): Response
    {
        AuthContext::fromRequest($request)->require(TokenScope::Read);

        $service = $this->services->find($id);

        if ($service === null) {
            throw HttpException::notFound('Услуга не найдена.');
        }

        if ($service->dname === '') {
            return ApiPresenter::data([]);
        }

        return ApiPresenter::data($this->servicesApi->bills($service->dname));
    }
}
