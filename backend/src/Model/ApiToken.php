<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use DateTimeImmutable;

final readonly class ApiToken
{
    /**
     * @param list<TokenScope> $scopes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $prefix,
        public array $scopes,
        public ?int $createdBy,
        public string $createdAt,
        public ?string $lastUsedAt,
        public ?string $expiresAt,
        public ?string $revokedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $rawScopes = json_decode((string) ($row['scopes'] ?? '[]'), true);
        $scopes = [];

        if (is_array($rawScopes)) {
            foreach ($rawScopes as $scope) {
                if (!is_string($scope)) {
                    continue;
                }

                $case = TokenScope::tryFrom($scope);

                if ($case !== null) {
                    $scopes[] = $case;
                }
            }
        }

        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            prefix: (string) $row['prefix'],
            scopes: $scopes,
            createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
            createdAt: (string) $row['created_at'],
            lastUsedAt: isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
            expiresAt: isset($row['expires_at']) ? (string) $row['expires_at'] : null,
            revokedAt: isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && strtotime($this->expiresAt) !== false && strtotime($this->expiresAt) < $now->getTimestamp();
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    public function hasScope(TokenScope $required): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope->implies($required)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function scopeValues(): array
    {
        return array_map(static fn (TokenScope $scope): string => $scope->value, $this->scopes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'scopes' => $this->scopeValues(),
            'scope_labels' => array_map(static fn (TokenScope $scope): string => $scope->label(), $this->scopes),
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
            'expires_at' => $this->expiresAt,
            'revoked_at' => $this->revokedAt,
            'active' => !$this->isRevoked(),
        ];
    }
}
