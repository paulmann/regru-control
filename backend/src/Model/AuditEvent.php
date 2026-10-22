<?php

declare(strict_types=1);

namespace RegRuControl\Model;

final readonly class AuditEvent
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public int $id,
        public string $at,
        public string $actorType,
        public string $actor,
        public string $action,
        public string $target,
        public string $result,
        public string $ip,
        public array $details,
        public string $requestId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $details = json_decode((string) ($row['details'] ?? '{}'), true);

        return new self(
            id: (int) $row['id'],
            at: (string) $row['at'],
            actorType: (string) $row['actor_type'],
            actor: (string) $row['actor'],
            action: (string) $row['action'],
            target: (string) ($row['target'] ?? ''),
            result: (string) ($row['result'] ?? 'ok'),
            ip: (string) ($row['ip'] ?? ''),
            details: is_array($details) ? $details : [],
            requestId: (string) ($row['request_id'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'at' => $this->at,
            'actor_type' => $this->actorType,
            'actor' => $this->actor,
            'action' => $this->action,
            'target' => $this->target,
            'result' => $this->result,
            'ip' => $this->ip,
            'details' => $this->details,
            'request_id' => $this->requestId,
        ];
    }
}
