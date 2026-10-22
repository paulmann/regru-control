<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Contract;

use RegRuControl\Model\Bill;

/**
 * Billing commands.
 */
interface BillingApiInterface
{
    /**
     * @return list<Bill>
     */
    public function forPeriod(string $startDate, string $endDate, int $limit = 100, int $offset = 0, bool $includeInactive = false): array;

    /**
     * @return list<Bill>
     */
    public function notPayed(): array;

    /**
     * @return array<string, mixed>
     */
    public function payType(): array;
}
