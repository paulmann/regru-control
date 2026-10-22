<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\I18n;
use RegRuControl\RegRu\RegRuAccountProvider;
use RegRuControl\Repository\RegRuAccountRepository;
use RegRuControl\Security\Crypto;

/**
 * Decrypts the stored REG.RU API password on demand and keeps it only in
 * process memory for the duration of a request.
 */
final class DatabaseAccountProvider implements RegRuAccountProvider
{
    /** @var array{username: string, password: string}|null */
    private ?array $cached = null;

    public function __construct(
        private readonly RegRuAccountRepository $repository,
        private readonly Crypto $crypto,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->repository->get() !== null;
    }

    public function credentials(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $row = $this->repository->get();

        if ($row === null) {
            throw new HttpException(
                409,
                'REGRU_ACCOUNT_NOT_CONFIGURED',
                I18n::t('regru.account_not_configured'),
            );
        }

        $username = (string) $row['username'];

        $this->cached = [
            'username' => $username,
            'password' => $this->crypto->open((string) $row['password_enc'], 'regru:password:' . $username),
        ];

        return $this->cached;
    }

    /**
     * Drops the in-memory copy after credentials change.
     */
    public function forget(): void
    {
        $this->cached = null;
    }
}
