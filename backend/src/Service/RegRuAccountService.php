<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\RegRu\Contract\AccountApiInterface;
use RegRuControl\Repository\RegRuAccountRepository;
use RegRuControl\Security\Crypto;
use Throwable;

/**
 * Manages the REG.RU API account: stores credentials encrypted, verifies them
 * and reports the current connection state.
 */
final readonly class RegRuAccountService
{
    public function __construct(
        private RegRuAccountRepository $repository,
        private DatabaseAccountProvider $provider,
        private Crypto $crypto,
        private AccountApiInterface $accountApi,
        private AuditService $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $row = $this->repository->get();

        return [
            'configured' => $row !== null,
            'username' => $row === null ? null : (string) $row['username'],
            'currency' => $row === null ? 'RUR' : (string) $row['currency'],
            'last_verified_at' => $row['last_verified_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @return array{ok: bool, balance?: array<string, mixed>, error?: string} */
    public function verify(): array
    {
        try {
            $this->accountApi->nop();
            $balance = $this->accountApi->balance($this->currency());
            $this->repository->markVerified(null);

            return ['ok' => true, 'balance' => $balance];
        } catch (Throwable $exception) {
            $this->repository->markVerified($exception->getMessage());

            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, balance?: array<string, mixed>, error?: string}
     */
    public function save(string $username, string $password, string $currency = 'RUR'): array
    {
        $encrypted = $this->crypto->seal($password, 'regru:password:' . $username);
        $this->repository->save($username, $encrypted, strtoupper($currency));
        $this->provider->forget();

        $result = $this->verify();
        $this->audit->record('account.update', $username, ['verified' => $result['ok']], $result['ok'] ? 'ok' : 'failed');

        return $result;
    }

    public function currency(): string
    {
        $row = $this->repository->get();

        return $row === null ? 'RUR' : strtoupper((string) $row['currency']);
    }
}
