<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Endpoint;

use RegRuControl\RegRu\Contract\AccountApiInterface;
use RegRuControl\RegRu\RegRuClient;

/**
 * Account / user commands (`user/*`).
 */
final readonly class AccountApi implements AccountApiInterface
{
    public function __construct(private RegRuClient $client)
    {
    }

    public function nop(): bool
    {
        return $this->client->call('nop')->isSuccess();
    }

    /**
     * `nop` doubles as an identity probe: it returns the login and user id.
     *
     * @return array{login: string, user_id: string}
     */
    public function identity(): array
    {
        $answer = $this->client->answer('nop');

        return [
            'login' => (string) ($answer['login'] ?? ''),
            'user_id' => (string) ($answer['user_id'] ?? ''),
        ];
    }

    /**
     * Reseller-only variant of `nop`; useful to check whether partner-only
     * commands are available for the account.
     */
    public function resellerNop(): bool
    {
        return $this->client->call('reseller_nop')->isSuccess();
    }

    /**
     * @return array{currency: string, prepay: float, blocked: float, credit: float}
     */
    public function balance(string $currency = 'RUR'): array
    {
        $answer = $this->client->answer('user/get_balance', ['currency' => $currency]);

        return [
            'currency' => (string) ($answer['currency'] ?? $currency),
            'prepay' => (float) ($answer['prepay'] ?? 0),
            'blocked' => (float) ($answer['blocked'] ?? 0),
            'credit' => (float) ($answer['credit'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statistics(?string $dateFrom = null, ?string $dateTill = null): array
    {
        $input = array_filter([
            'date_from' => $dateFrom,
            'date_till' => $dateTill,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $this->client->answer('user/get_statistics', $input);
    }
}
