<?php

declare(strict_types=1);

namespace RegRuControl\Tests\Fakes;

use RegRuControl\RegRu\Contract\AccountApiInterface;

final class FakeAccountApi implements AccountApiInterface
{
    public bool $available = true;

    public float $prepay = 1234.56;

    public function nop(): bool
    {
        return $this->available;
    }

    public function identity(): array
    {
        return ['login' => 'test', 'user_id' => '42'];
    }

    public function resellerNop(): bool
    {
        return $this->available;
    }

    public function balance(string $currency = 'RUR'): array
    {
        return ['currency' => $currency, 'prepay' => $this->prepay, 'blocked' => 0.0, 'credit' => 0.0];
    }

    public function statistics(?string $dateFrom = null, ?string $dateTill = null): array
    {
        return ['active_domains_cnt' => '3'];
    }
}
