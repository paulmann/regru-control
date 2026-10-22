<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\SyncRun;
use RegRuControl\Support\Json;

final readonly class SyncRunRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    public function start(string $trigger, string $actor): int
    {
        return $this->database->insert('sync_runs', [
            'started_at' => $this->now(),
            'status' => 'running',
            'trigger' => $trigger,
            'actor' => $actor,
            'stats' => '{}',
        ]);
    }

    /**
     * @param array<string, mixed> $stats
     */
    public function finish(int $id, string $status, array $stats = [], ?string $error = null): void
    {
        $this->database->execute(
            'UPDATE sync_runs SET finished_at = :now, status = :status, stats = :stats, error = :error WHERE id = :id',
            [
                'now' => $this->now(),
                'status' => $status,
                'stats' => Json::encode($stats),
                'error' => $error,
                'id' => $id,
            ],
        );
    }

    public function latest(): ?SyncRun
    {
        $row = $this->database->fetchOne('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1');

        return $row === null ? null : SyncRun::fromRow($row);
    }

    /** @return list<SyncRun> */
    public function recent(int $limit = 20): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM sync_runs ORDER BY id DESC LIMIT :limit',
            ['limit' => max(1, min(200, $limit))],
        );

        return array_map(static fn (array $row): SyncRun => SyncRun::fromRow($row), $rows);
    }

    public function isRunning(): bool
    {
        $status = $this->database->fetchValue(
            "SELECT status FROM sync_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1",
        );

        return $status === 'running';
    }

    /**
     * Marks stale runs (process died mid-sync) as failed.
     */
    public function recoverStale(int $olderThanSeconds = 1800): int
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - $olderThanSeconds);

        return $this->database->execute(
            "UPDATE sync_runs SET status = 'failed', error = 'Прервано (процесс завершился)', finished_at = :now
             WHERE status = 'running' AND started_at < :threshold",
            ['now' => $this->now(), 'threshold' => $threshold],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
