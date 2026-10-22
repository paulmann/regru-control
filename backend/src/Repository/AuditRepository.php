<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\AuditEvent;
use RegRuControl\Support\Json;

final readonly class AuditRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function add(
        string $actorType,
        string $actor,
        string $action,
        string $target = '',
        string $result = 'ok',
        string $ip = '',
        string $userAgent = '',
        array $details = [],
        string $requestId = '',
    ): void {
        $this->database->insert('audit_log', [
            'at' => $this->now(),
            'actor_type' => $actorType,
            'actor' => $actor,
            'action' => $action,
            'target' => $target,
            'result' => $result,
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'details' => Json::encode($details),
            'request_id' => $requestId,
        ]);
    }

    /**
     * @param array{action?: string, actor?: string, limit?: int, offset?: int} $filters
     * @return array{items: list<AuditEvent>, total: int}
     */
    public function search(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (($filters['action'] ?? '') !== '') {
            $where[] = 'action = :action';
            $params['action'] = (string) $filters['action'];
        }

        if (($filters['actor'] ?? '') !== '') {
            $where[] = 'actor LIKE :actor';
            $params['actor'] = '%' . (string) $filters['actor'] . '%';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $limit = min(500, max(1, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $total = (int) $this->database->fetchValue('SELECT COUNT(*) FROM audit_log' . $whereSql, $params);
        $rows = $this->database->fetchAll(
            'SELECT * FROM audit_log' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return [
            'items' => array_map(static fn (array $row): AuditEvent => AuditEvent::fromRow($row), $rows),
            'total' => $total,
        ];
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM audit_log');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
