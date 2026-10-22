<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Model\Bill;
use RegRuControl\RegRu\Contract\AccountApiInterface;
use RegRuControl\RegRu\Contract\BillingApiInterface;
use RegRuControl\Repository\SettingsRepository;
use RegRuControl\Support\Json;
use Throwable;

/**
 * Balance and bills. The balance is cached for a few minutes: the dashboard is
 * the most visited page and REG.RU counts every call against the hourly quota.
 */
final readonly class BillingService
{
    private const CACHE_KEY = 'billing.balance';
    private const CACHE_TTL = 300;

    public function __construct(
        private AccountApiInterface $accountApi,
        private BillingApiInterface $billingApi,
        private SettingsRepository $settings,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{currency: string, prepay: float, blocked: float, credit: float, cached: bool, fetched_at: ?string, error?: string}
     */
    public function balance(bool $force = false): array
    {
        if (!$force) {
            $cached = $this->cachedBalance();

            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $balance = $this->accountApi->balance();
            $this->storeBalance($balance);

            return $balance + ['cached' => false, 'fetched_at' => $this->now()];
        } catch (Throwable $exception) {
            $cached = $this->cachedBalance();

            if ($cached !== null) {
                return $cached + ['error' => $exception->getMessage()];
            }

            throw $exception;
        }
    }

    /**
     * @return list<Bill>
     */
    public function unpaid(): array
    {
        return $this->billingApi->notPayed();
    }

    /**
     * @return list<Bill>
     */
    public function bills(string $from, string $to, int $limit = 100, int $offset = 0): array
    {
        return $this->billingApi->forPeriod($from, $to, $limit, $offset);
    }

    /**
     * @return array{currency: string, prepay: float, blocked: float, credit: float, cached: bool, fetched_at: string}|null
     */
    private function cachedBalance(): ?array
    {
        $raw = $this->settings->get(self::CACHE_KEY);

        if ($raw === null) {
            return null;
        }

        $data = Json::tryDecode($raw);

        if ($data === null || !isset($data['ts'], $data['balance']) || !is_array($data['balance'])) {
            return null;
        }

        if (time() - (int) $data['ts'] > self::CACHE_TTL) {
            return null;
        }

        /** @var array{currency: string, prepay: float, blocked: float, credit: float} $balance */
        $balance = $data['balance'];

        return $balance + [
            'cached' => true,
            'fetched_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $data['ts']),
        ];
    }

    /**
     * @param array{currency: string, prepay: float, blocked: float, credit: float} $balance
     */
    private function storeBalance(array $balance): void
    {
        $this->settings->set(self::CACHE_KEY, Json::encode([
            'ts' => time(),
            'balance' => $balance,
        ]));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
