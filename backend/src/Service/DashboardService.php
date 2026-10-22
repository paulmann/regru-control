<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use DateTimeImmutable;
use RegRuControl\Kernel\Clock;
use RegRuControl\Repository\ApiTokenRepository;
use RegRuControl\Repository\CredentialRepository;
use RegRuControl\Repository\DnsRecordRepository;
use RegRuControl\Repository\DomainRepository;
use RegRuControl\Repository\HostingRepository;
use RegRuControl\Repository\ServiceRepository;
use RegRuControl\Repository\SyncRunRepository;
use RegRuControl\Service\RegRuAccountService;
use Throwable;

/**
 * Aggregates the dashboard: counts, expiring domains, hostings, balance and
 * sync state. Everything is local except the (cached) balance.
 */
final readonly class DashboardService
{
    public function __construct(
        private DomainRepository $domains,
        private ServiceRepository $services,
        private HostingRepository $hostings,
        private CredentialRepository $credentials,
        private DnsRecordRepository $dnsRecords,
        private ApiTokenRepository $tokens,
        private SyncRunRepository $runs,
        private BillingService $billing,
        private RegRuAccountService $account,
        private Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $now = $this->clock->now();
        $latestRun = $this->runs->latest();

        $balance = null;
        $balanceError = null;

        if ($this->account->status()['configured']) {
            try {
                $balance = $this->billing->balance();
            } catch (Throwable $exception) {
                $balanceError = $exception->getMessage();
            }
        }

        return [
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'account' => $this->account->status(),
            'counts' => [
                'domains' => $this->domains->count(),
                'domains_expiring_30' => $this->domains->countExpiring(30),
                'hostings' => $this->hostings->count(),
                'services' => $this->services->count(),
                'credentials' => $this->credentials->count(),
                'dns_records' => $this->dnsRecords->count(),
                'api_tokens' => $this->tokens->countActive(),
            ],
            'categories' => $this->services->countsByCategory(),
            'balance' => $balance,
            'balance_error' => $balanceError,
            'expiring' => array_map(
                static fn ($domain): array => $domain->toArray($now),
                $this->domains->expiring(60, 12),
            ),
            'hostings' => array_map(
                static fn ($hosting): array => $hosting->toArray($now),
                array_slice($this->hostings->all(), 0, 8),
            ),
            'sync' => [
                'latest' => $latestRun?->toArray(),
                'running' => $this->runs->isRunning(),
            ],
        ];
    }

    private function today(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
