<?php

declare(strict_types=1);

namespace RegRuControl\Database;

use RegRuControl\Kernel\Logger;
use RuntimeException;

/**
 * Forward-only SQL migrations.
 *
 * Each file in `backend/migrations` runs exactly once inside a transaction and
 * is recorded in `schema_migrations`; the file name is the version.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<string> applied versions
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = $this->appliedVersions();
        $files = $this->files();
        $new = [];

        foreach ($files as $version => $file) {
            if (isset($applied[$version])) {
                continue;
            }

            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException(sprintf('Cannot read migration "%s".', $file));
            }

            $this->database->transaction(function () use ($sql, $version): void {
                $this->database->pdo()->exec($sql);
                $this->database->execute(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :at)',
                    ['version' => $version, 'at' => gmdate('Y-m-d\TH:i:s\Z')],
                );
            });

            $this->logger->info('Migration applied', ['version' => $version]);
            $new[] = $version;
        }

        return $new;
    }

    /**
     * @return array<string, bool> version => applied
     */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->appliedVersions();
        $status = [];

        foreach (array_keys($this->files()) as $version) {
            $status[$version] = isset($applied[$version]);
        }

        return $status;
    }

    private function ensureTable(): void
    {
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )',
        );
    }

    /**
     * @return array<string, string> version => applied_at
     */
    private function appliedVersions(): array
    {
        $rows = $this->database->fetchAll('SELECT version, applied_at FROM schema_migrations');
        $versions = [];

        foreach ($rows as $row) {
            $versions[(string) $row['version']] = (string) $row['applied_at'];
        }

        return $versions;
    }

    /**
     * @return array<string, string> version => absolute file path
     */
    private function files(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $result = [];

        foreach ($files as $file) {
            $result[basename($file, '.sql')] = $file;
        }

        return $result;
    }
}
