<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Endpoint;

use RegRuControl\Model\Bill;
use RegRuControl\RegRu\Contract\BillingApiInterface;
use RegRuControl\RegRu\RegRuClient;

/**
 * Billing commands (`bill/*`).
 */
final readonly class BillingApi implements BillingApiInterface
{
    public function __construct(private RegRuClient $client)
    {
    }

    /**
     * @return list<Bill>
     */
    public function forPeriod(string $startDate, string $endDate, int $limit = 100, int $offset = 0, bool $includeInactive = false): array
    {
        $response = $this->client->call('bill/get_for_period', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => $limit,
            'offset' => $offset,
            'all' => $includeInactive ? 1 : 0,
        ]);

        return array_map(static fn (array $bill): Bill => Bill::fromApi($bill), $response->bills());
    }

    /**
     * @return list<Bill>
     */
    public function notPayed(): array
    {
        $response = $this->client->call('bill/get_not_payed');

        return array_map(static fn (array $bill): Bill => Bill::fromApi($bill), $response->bills());
    }

    /**
     * @return array<string, mixed>
     */
    public function payType(): array
    {
        return $this->client->answer('bill/change_pay_type');
    }
}
