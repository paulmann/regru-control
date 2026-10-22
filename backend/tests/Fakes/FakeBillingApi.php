<?php

declare(strict_types=1);

namespace RegRuControl\Tests\Fakes;

use RegRuControl\RegRu\Contract\BillingApiInterface;
use RegRuControl\Model\Bill;

final class FakeBillingApi implements BillingApiInterface
{
    public function forPeriod(string $startDate, string $endDate, int $limit = 100, int $offset = 0, bool $includeInactive = false): array
    {
        return [Bill::fromApi([
            'bill_id' => '1001',
            'bill_date' => $startDate,
            'payment' => '100',
            'currency' => 'RUR',
            'pay_type' => 'prepay',
            'pay_status' => 'payed',
            'items' => [],
        ])];
    }

    public function notPayed(): array
    {
        return [];
    }

    public function payType(): array
    {
        return [];
    }
}
