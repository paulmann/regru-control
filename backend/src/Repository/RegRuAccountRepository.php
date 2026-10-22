<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;

final readonly class RegRuAccountRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(): ?array
    {
        return $this->database->fetchOne('SELECT * FROM regru_account WHERE id = 1');
    }

    public function save(string $username, string $encryptedPassword, string $currency = 'RUR'): void
    {
        $now = $this->now();

        $this->database->execute(
            'INSERT INTO regru_account (id, username, password_enc, currency, updated_at)
             VALUES (1, :username, :password, :currency, :now)
             ON CONFLICT (id) DO UPDATE SET
                username = excluded.username,
                password_enc = excluded.password_enc,
                currency = excluded.currency,
                updated_at = excluded.updated_at',
            [
                'username' => $username,
                'password' => $encryptedPassword,
                'currency' => $currency,
                'now' => $now,
            ],
        );
    }

    public function markVerified(?string $error): void
    {
        $this->database->execute(
            'UPDATE regru_account SET last_verified_at = :now, last_error = :error WHERE id = 1',
            ['now' => $this->now(), 'error' => $error],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
