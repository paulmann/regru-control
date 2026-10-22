<?php

declare(strict_types=1);

namespace RegRuControl\RegRu;

use RegRuControl\Support\Json;

/**
 * Catalog of every documented REG.RU API2 command.
 *
 * The JSON file is generated from the official documentation
 * (https://www.reg.ru/reseller/api2doc) and committed, so the panel can show
 * full API coverage and validate raw-call command names without network access.
 */
final class RegRuCommandCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $commands = null;

    /** @var array<string, mixed>|null */
    private ?array $meta = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $this->load();

        return $this->meta ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $this->load();

        return array_values($this->commands ?? []);
    }

    /**
     * @return array<string, list<array<string, mixed>>> group label => commands
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->all() as $command) {
            $groups[(string) $command['group_label']][] = $command;
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $command): ?array
    {
        $this->load();

        return $this->commands[$command] ?? null;
    }

    public function has(string $command): bool
    {
        return $this->find($command) !== null;
    }

    public function count(): int
    {
        $this->load();

        return count($this->commands ?? []);
    }

    private function load(): void
    {
        if ($this->commands !== null) {
            return;
        }

        $raw = is_file($this->file) ? file_get_contents($this->file) : false;
        $decoded = $raw === false ? null : Json::tryDecode($raw);

        if ($decoded === null) {
            $this->commands = [];
            $this->meta = [];

            return;
        }

        $this->meta = is_array($decoded['_meta'] ?? null) ? $decoded['_meta'] : [];
        $this->commands = [];

        foreach ($decoded['commands'] ?? [] as $command) {
            if (is_array($command) && isset($command['command'])) {
                $this->commands[(string) $command['command']] = $command;
            }
        }
    }
}
