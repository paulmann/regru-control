<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\ApiToken;
use RegRuControl\Support\Json;

final readonly class ApiTokenRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public function create(string $name, string $prefix, string $hash, array $scopes, ?int $createdBy, ?string $expiresAt): int
    {
        return $this->database->insert('api_tokens', [
            'name' => $name,
            'prefix' => $prefix,
            'token_hash' => $hash,
            'scopes' => Json::encode(array_values($scopes)),
            'created_by' => $createdBy,
            'created_at' => $this->now(),
            'expires_at' => $expiresAt,
        ]);
    }

    /** @return list<ApiToken> */
    public function all(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM api_tokens ORDER BY revoked_at IS NOT NULL, id DESC');

        return array_map(static fn (array $row): ApiToken => ApiToken::fromRow($row), $rows);
    }

    public function find(int $id): ?ApiToken
    {
        $row = $this->database->fetchOne('SELECT * FROM api_tokens WHERE id = :id', ['id' => $id]);

        return $row === null ? null : ApiToken::fromRow($row);
    }

    /** @return array<string, mixed>|null raw row */
    public function findByHash(string $hash): ?array
    {
        return $this->database->fetchOne('SELECT * FROM api_tokens WHERE token_hash = :hash', ['hash' => $hash]);
    }

    public function revoke(int $id): bool
    {
        return $this->database->execute(
            'UPDATE api_tokens SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
            ['now' => $this->now(), 'id' => $id],
        ) > 0;
    }

    public function touch(int $id): void
    {
        $this->database->execute(
            'UPDATE api_tokens SET last_used_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $id],
        );
    }

    public function countActive(): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM api_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now)',
            ['now' => $this->now()],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
