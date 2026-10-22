<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Contract;

/**
 * Account-level commands (identity, balance, statistics).
 */
interface AccountApiInterface
{
    public function nop(): bool;

    /**
     * @return array{login: string, user_id: string}
     */
    public function identity(): array;

    public function resellerNop(): bool;

    /**
     * @return array{currency: string, prepay: float, blocked: float, credit: float}
     */
    public function balance(string $currency = 'RUR'): array;

    /**
     * @return array<string, mixed>
     */
    public function statistics(?string $dateFrom = null, ?string $dateTill = null): array;
}
