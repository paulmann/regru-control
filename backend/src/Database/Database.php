<?php

declare(strict_types=1);

namespace RegRuControl\Database;

use PDO;
use PDOStatement;
use RegRuControl\Kernel\Config;
use RuntimeException;

/**
 * Thin PDO/SQLite wrapper.
 *
 * SQLite is a deliberate choice: the panel is a single-writer admin tool with
 * predictable load, and a file database keeps backups, migrations and local
 * development trivial. WAL mode keeps readers from blocking the writer.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->statement($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->statement($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->statement($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        $this->statement($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function path(): string
    {
        $value = $this->config->string('db.path');

        return $value === ':memory:' ? ':memory:' : $this->config->path('db.path');
    }

    public function size(): int
    {
        $path = $this->path();

        if ($path === ':memory:' || !is_file($path)) {
            return 0;
        }

        return (int) filesize($path);
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function statement(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Cannot prepare SQL statement: ' . $sql);
        }

        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }

        $statement->execute();

        return $statement;
    }

    private function connect(): PDO
    {
        $path = $this->path();

        if ($path !== ':memory:') {
            $directory = dirname($path);

            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Cannot create database directory "%s".', $directory));
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if ($path !== ':memory:') {
            @chmod($path, 0660);
        }

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = ' . $this->config->int('db.busy_timeout', 5000));
        $pdo->exec('PRAGMA temp_store = MEMORY');

        return $pdo;
    }
}
