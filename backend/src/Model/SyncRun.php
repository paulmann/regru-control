<?php

declare(strict_types=1);

namespace RegRuControl\Model;

final readonly class SyncRun
{
    /**
     * @param array<string, mixed> $stats
     */
    public function __construct(
        public int $id,
        public string $startedAt,
        public ?string $finishedAt,
        public string $status,
        public string $trigger,
        public string $actor,
        public array $stats,
        public ?string $error,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $stats = json_decode((string) ($row['stats'] ?? '{}'), true);

        return new self(
            id: (int) $row['id'],
            startedAt: (string) $row['started_at'],
            finishedAt: isset($row['finished_at']) ? (string) $row['finished_at'] : null,
            status: (string) $row['status'],
            trigger: (string) ($row['trigger'] ?? 'manual'),
            actor: (string) ($row['actor'] ?? ''),
            stats: is_array($stats) ? $stats : [],
            error: isset($row['error']) ? (string) $row['error'] : null,
        );
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function durationSeconds(): ?int
    {
        if ($this->finishedAt === null) {
            return null;
        }

        $start = strtotime($this->startedAt);
        $end = strtotime($this->finishedAt);

        if ($start === false || $end === false) {
            return null;
        }

        return max(0, $end - $start);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'actor' => $this->actor,
            'stats' => $this->stats,
            'error' => $this->error,
            'duration_seconds' => $this->durationSeconds(),
        ];
    }
}
