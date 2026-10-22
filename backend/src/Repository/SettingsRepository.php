<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;

final readonly class SettingsRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->database->fetchValue('SELECT value FROM settings WHERE key = :key', ['key' => $key]);

        return is_string($value) ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->database->execute(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :now)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            ['key' => $key, 'value' => $value, 'now' => $this->now()],
        );
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = $this->database->fetchAll('SELECT key, value FROM settings');
        $settings = [];

        foreach ($rows as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }

        return $settings;
    }

    public function forget(string $key): void
    {
        $this->database->execute('DELETE FROM settings WHERE key = :key', ['key' => $key]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
