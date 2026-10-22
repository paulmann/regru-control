<?php

declare(strict_types=1);

namespace RegRuControl\Model;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $login,
        public string $role,
        public string $displayName,
        public bool $isActive,
        public ?string $lastLoginAt,
        public ?string $lastLoginIp,
        public string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            login: (string) $row['login'],
            role: (string) $row['role'],
            displayName: (string) ($row['display_name'] ?? ''),
            isActive: (int) ($row['is_active'] ?? 1) === 1,
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            lastLoginIp: isset($row['last_login_ip']) ? (string) $row['last_login_ip'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function name(): string
    {
        return $this->displayName !== '' ? $this->displayName : $this->login;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'login' => $this->login,
            'role' => $this->role,
            'display_name' => $this->displayName,
            'name' => $this->name(),
            'is_active' => $this->isActive,
            'last_login_at' => $this->lastLoginAt,
            'last_login_ip' => $this->lastLoginIp,
            'created_at' => $this->createdAt,
        ];
    }
}
