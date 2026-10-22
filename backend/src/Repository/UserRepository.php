<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\User;

final readonly class UserRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * Full row including the password hash — only for authentication.
     *
     * @return array<string, mixed>|null
     */
    public function findAuthRow(string $login): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM users WHERE login = :login COLLATE NOCASE',
            ['login' => $login],
        );
    }

    public function findById(int $id): ?User
    {
        $row = $this->database->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        return $row === null ? null : User::fromRow($row);
    }

    public function create(string $login, string $passwordHash, string $role = 'admin', string $displayName = ''): int
    {
        $now = $this->now();

        return $this->database->insert('users', [
            'login' => $login,
            'password_hash' => $passwordHash,
            'role' => $role,
            'display_name' => $displayName,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->database->execute(
            'UPDATE users SET password_hash = :hash, updated_at = :now WHERE id = :id',
            ['hash' => $passwordHash, 'now' => $this->now(), 'id' => $id],
        );
    }

    public function recordSuccessfulLogin(int $id, string $ip): void
    {
        $this->database->execute(
            'UPDATE users SET last_login_at = :now, last_login_ip = :ip, failed_attempts = 0, locked_until = NULL, updated_at = :now WHERE id = :id',
            ['now' => $this->now(), 'ip' => $ip, 'id' => $id],
        );
    }

    public function recordFailedLogin(int $id, int $attempts, ?string $lockedUntil): void
    {
        $this->database->execute(
            'UPDATE users SET failed_attempts = :attempts, locked_until = :locked, updated_at = :now WHERE id = :id',
            ['attempts' => $attempts, 'locked' => $lockedUntil, 'now' => $this->now(), 'id' => $id],
        );
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM users');
    }

    /** @return list<User> */
    public function all(): array
    {
        return array_map(
            static fn (array $row): User => User::fromRow($row),
            $this->database->fetchAll('SELECT * FROM users ORDER BY id'),
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
