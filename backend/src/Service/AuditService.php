<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\RequestContext;
use RegRuControl\Model\AuditEvent;
use RegRuControl\Repository\AuditRepository;

/**
 * Writes user-visible audit events for every state change and every secret read.
 */
final readonly class AuditService
{
    public function __construct(
        private AuditRepository $repository,
        private RequestContext $context,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function record(string $action, string $target = '', array $details = [], string $result = 'ok'): void
    {
        $this->repository->add(
            actorType: $this->context->actorType(),
            actor: $this->context->actor() ?? 'system',
            action: $action,
            target: $target,
            result: $result,
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
            details: $details,
            requestId: $this->context->id(),
        );
    }

    /**
     * @param array{action?: string, actor?: string, limit?: int, offset?: int} $filters
     * @return array{items: list<AuditEvent>, total: int}
     */
    public function search(array $filters = []): array
    {
        return $this->repository->search($filters);
    }

    public function count(): int
    {
        return $this->repository->count();
    }
}
