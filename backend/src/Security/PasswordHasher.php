<?php

declare(strict_types=1);

namespace RegRuControl\Security;

use SensitiveParameter;

/**
 * Argon2id password hashing with sane, memory-hard parameters.
 */
final class PasswordHasher
{
    private const ALGORITHM = PASSWORD_ARGON2ID;

    private const OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
    ];

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, self::ALGORITHM, self::OPTIONS);
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM, self::OPTIONS);
    }
}
