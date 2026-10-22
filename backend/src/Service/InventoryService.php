<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Model\Domain;
use RegRuControl\Repository\CredentialRepository;
use RegRuControl\Repository\DnsRecordRepository;
use RegRuControl\Repository\DomainRepository;
use RegRuControl\Repository\HostingRepository;
use RegRuControl\Repository\ServiceRepository;
use RegRuControl\Repository\SyncRunRepository;
use RegRuControl\Security\Crypto;

/**
 * Machine-readable inventory for the owner and for AI agents.
 *
 * `inventory()` is compact and contains no secrets; `export()` can optionally
 * include decrypted passwords and is meant for a deliberate, audited backup.
 */
final readonly class InventoryService
{
    public function __construct(
        private DomainRepository $domains,
        private ServiceRepository $services,
        private HostingRepository $hostings,
        private CredentialRepository $credentials,
        private DnsRecordRepository $dnsRecords,
        private SyncRunRepository $runs,
        private BillingService $billing,
        private RegRuAccountService $account,
        private Crypto $crypto,
        private Clock $clock,
    ) {
    }

    /**
     * Compact inventory: what exists, where it lives, how to reach it.
     * Passwords are never included; only `password_present` flags.
     *
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        $now = $this->clock->now();
        $latest = $this->runs->latest();
        $account = $this->account->status();

        $balance = null;

        if ($account['configured']) {
            try {
                $balance = $this->billing->balance();
            } catch (\Throwable) {
                $balance = null;
            }
        }

        $hostings = [];

        foreach ($this->hostings->all() as $hosting) {
            $row = $this->hostings->findRow($hosting->serviceId);
            $hostings[] = $hosting->toArray($now) + [
                'panel_password_present' => is_string($row['panel_password_enc'] ?? null) && $row['panel_password_enc'] !== '',
            ];
        }

        $credentials = [];

        foreach ($this->credentials->all() as $credential) {
            $credentials[] = [
                'id' => $credential->id,
                'title' => $credential->title,
                'kind' => $credential->kind->value,
                'host' => $credential->host,
                'username' => $credential->username,
                'url' => $credential->url,
                'dname' => $credential->dname,
                'service_id' => $credential->serviceId,
                'password_present' => $credential->hasPassword,
                'updated_at' => $credential->updatedAt,
            ];
        }

        return [
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'account' => [
                'configured' => $account['configured'],
                'username' => $account['username'],
            ],
            'counts' => [
                'domains' => $this->domains->count(),
                'hostings' => $this->hostings->count(),
                'services' => $this->services->count(),
                'credentials' => $this->credentials->count(),
                'dns_records' => $this->dnsRecords->count(),
            ],
            'balance' => $balance,
            'domains' => array_map(
                static fn (Domain $domain): array => $domain->toArray($now),
                $this->domains->all(),
            ),
            'hostings' => $hostings,
            'credentials' => $credentials,
            'sync' => [
                'latest' => $latest?->toArray(),
                'running' => $this->runs->isRunning(),
            ],
        ];
    }

    /**
     * Full export, optionally with decrypted secrets.
     *
     * @return array<string, mixed>
     */
    public function export(bool $includeSecrets = false): array
    {
        $now = $this->clock->now();
        $data = $this->inventory();

        $data['services'] = array_map(
            static fn ($service): array => $service->toArray($now),
            $this->services->all(),
        );

        if ($includeSecrets) {
            $credentials = [];

            foreach ($this->credentials->all() as $credential) {
                $row = $this->credentials->findRow((int) $credential->id);
                $entry = $credential->toArray();
                $entry['password'] = $row !== null && is_string($row['password_enc'] ?? null) && $row['password_enc'] !== ''
                    ? $this->crypto->open((string) $row['password_enc'], 'credential:' . $credential->id)
                    : null;
                $credentials[] = $entry;
            }

            $data['credentials'] = $credentials;
            $hostings = [];

            foreach ($this->hostings->all() as $hosting) {
                $entry = $hosting->toArray($now);
                $row = $this->hostings->findRow($hosting->serviceId);

                if ($row !== null && is_string($row['panel_password_enc'] ?? null) && $row['panel_password_enc'] !== '') {
                    $entry['panel_password'] = $this->crypto->open((string) $row['panel_password_enc'], 'hosting:panel:' . $hosting->serviceId);
                }

                $hostings[] = $entry;
            }

            $data['hostings'] = $hostings;
        }

        $data['export'] = [
            'format' => 'regru-control/export-v1',
            'include_secrets' => $includeSecrets,
            'exported_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ];

        return $data;
    }
}
