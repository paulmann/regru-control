<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\Logger;
use RegRuControl\Kernel\RequestContext;
use RegRuControl\Kernel\ValidationException;
use RegRuControl\Model\Domain;
use RegRuControl\Model\DnsRecord;
use RegRuControl\Model\Hosting;
use RegRuControl\Model\ServiceCategory;
use RegRuControl\Model\ServiceRecord;
use RegRuControl\RegRu\Contract\DomainApiInterface;
use RegRuControl\RegRu\Contract\ServicesApiInterface;
use RegRuControl\RegRu\Contract\ZoneApiInterface;
use RegRuControl\RegRu\RegRuApiException;
use RegRuControl\Repository\DnsRecordRepository;
use RegRuControl\Repository\DomainRepository;
use RegRuControl\Repository\HostingRepository;
use RegRuControl\Repository\ServiceRepository;
use RegRuControl\Repository\SettingsRepository;
use RegRuControl\Repository\SyncRunRepository;
use Throwable;

/**
 * Inventory synchronisation: REG.RU API -> local snapshot.
 *
 * Full sync is ~15 API calls (services list + one NS batch + zoned DNS batches
 * + hosting details batches), well inside the documented 1200/hour limit.
 * A failure of one stage degrades the run to `partial` instead of losing the
 * whole snapshot.
 */
final readonly class SyncService
{
    public const SCOPES = ['all', 'services', 'details', 'dns'];

    public function __construct(
        private ServicesApiInterface $servicesApi,
        private DomainApiInterface $domainApi,
        private ZoneApiInterface $zoneApi,
        private ServiceRepository $services,
        private DomainRepository $domains,
        private HostingRepository $hostings,
        private DnsRecordRepository $dnsRecords,
        private HostingService $hostingService,
        private SyncRunRepository $runs,
        private SettingsRepository $settings,
        private AuditService $audit,
        private Logger $logger,
        private Config $config,
        private Clock $clock,
        private RequestContext $context,
    ) {
    }

    /**
     * @return array{run_id: int, status: string, stats: array<string, mixed>, errors: list<string>}
     */
    public function run(string $scope = 'all', string $trigger = 'manual'): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new ValidationException(['scope' => 'Допустимо: all, services, details, dns.']);
        }

        $this->runs->recoverStale();

        if ($this->runs->isRunning()) {
            throw HttpException::conflict('Синхронизация уже выполняется. Дождитесь завершения.');
        }

        $runId = $this->runs->start($trigger, $this->actor());
        $stats = [];
        $errors = [];

        try {
            if ($scope === 'all' || $scope === 'services') {
                $stats['inventory'] = $this->syncInventory($errors);
            }

            if ($scope === 'all' || $scope === 'details') {
                $stats['details'] = $this->hostingService->syncDetails(false);

                foreach ($stats['details']['errors'] as $error) {
                    $errors[] = 'details: ' . $error;
                }
            }

            if ($scope === 'all' || $scope === 'dns') {
                $stats['dns'] = $this->syncDns($errors);
            }

            $status = $errors === [] ? 'ok' : 'partial';
            $this->runs->finish($runId, $status, $stats, $errors === [] ? null : implode("\n", $errors));
            $this->audit->record('sync.run', $scope, ['status' => $status, 'stats' => $stats, 'errors' => count($errors)]);

            return ['run_id' => $runId, 'status' => $status, 'stats' => $stats, 'errors' => $errors];
        } catch (Throwable $exception) {
            $this->runs->finish($runId, 'failed', $stats, $exception->getMessage());
            $this->audit->record('sync.run', $scope, ['status' => 'failed', 'error' => $exception->getMessage()], 'failed');
            $this->logger->exception($exception, ['scope' => $scope]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $latest = $this->runs->latest();
        $lastSuccess = null;

        foreach ($this->runs->recent(20) as $run) {
            if ($run->status === 'ok' || $run->status === 'partial') {
                $lastSuccess = $run;

                break;
            }
        }

        $ttl = $this->ttl();
        $isStale = true;

        if ($lastSuccess?->finishedAt !== null) {
            $finished = strtotime($lastSuccess->finishedAt);

            if ($finished !== false) {
                $isStale = (time() - $finished) > $ttl;
            }
        }

        return [
            'running' => $this->runs->isRunning(),
            'latest' => $latest?->toArray(),
            'last_success' => $lastSuccess?->toArray(),
            'ttl_seconds' => $ttl,
            'stale' => $isStale,
            'counts' => [
                'services' => $this->services->count(),
                'domains' => $this->domains->count(),
                'hostings' => $this->hostings->count(),
                'dns_records' => $this->dnsRecords->count(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 20): array
    {
        return array_map(static fn ($run): array => $run->toArray(), $this->runs->recent($limit));
    }

    /**
     * @param list<string> $errors
     * @return array<string, int>
     */
    private function syncInventory(array &$errors): array
    {
        $rawServices = $this->servicesApi->list();
        $now = $this->clock->now()->format('Y-m-d\TH:i:s\Z');

        $records = [];

        foreach ($rawServices as $raw) {
            $record = ServiceRecord::fromApi($raw, $now);

            if ($record->serviceId !== '') {
                $records[] = $record;
            }
        }

        $this->services->replaceAll($records);

        $domainRecords = array_values(array_filter(
            $records,
            static fn (ServiceRecord $record): bool => $record->category() === ServiceCategory::Domain && $record->dname !== '',
        ));

        $nssMap = [];

        if ($domainRecords !== []) {
            try {
                $nssMap = $this->domainApi->getNss(array_map(
                    static fn (ServiceRecord $record): string => $record->dname,
                    $domainRecords,
                ));
            } catch (Throwable $exception) {
                $errors[] = 'NS: ' . $exception->getMessage();
            }
        }

        $domains = [];

        foreach ($domainRecords as $record) {
            $nss = [];

            foreach ($nssMap[$record->dname] ?? [] as $entry) {
                if (isset($entry['ns']) && is_string($entry['ns'])) {
                    $nss[] = rtrim($entry['ns'], '.');
                }
            }

            $domains[] = new Domain(
                dname: $record->dname,
                serviceId: $record->serviceId,
                state: $record->state,
                creationDate: $record->creationDate,
                expirationDate: $record->expirationDate,
                nss: $nss,
                dnsManaged: $this->usesRegruDns($nss),
                autorenew: null,
                updatedAt: $now,
            );
        }

        $this->domains->replaceAll($domains);

        // service/get_list omits autorenew_flag; service/get_info returns it.
        $autorenewFlags = $this->fetchAutorenewFlags(array_map(
            static fn (ServiceRecord $record): string => $record->serviceId,
            $records,
        ));

        foreach ($domains as $domain) {
            if (array_key_exists($domain->serviceId, $autorenewFlags)) {
                $this->domains->setAutorenew($domain->dname, $autorenewFlags[$domain->serviceId]);
            }
        }

        $hostings = [];

        foreach ($records as $record) {
            if ($record->category() !== ServiceCategory::Hosting && $record->category() !== ServiceCategory::Server) {
                continue;
            }

            $hostings[] = new Hosting(
                serviceId: $record->serviceId,
                servtype: $record->servtype,
                type: $record->type,
                dname: $record->dname,
                serverIp: null,
                panelUrl: null,
                panelLogin: null,
                hasPanelPassword: false,
                nss: [],
                plan: $record->subtype !== '' ? $record->subtype : null,
                state: $record->state,
                expirationDate: $record->expirationDate,
                details: null,
                updatedAt: $now,
            );
        }

        $this->hostings->replaceAll($hostings);

        return [
            'services' => count($records),
            'domains' => count($domains),
            'hostings' => count($hostings),
            'dns_managed' => count(array_filter($domains, static fn (Domain $domain): bool => $domain->dnsManaged)),
            'autorenew' => count($autorenewFlags),
        ];
    }

    /**
     * @param list<string> $errors
     * @return array<string, int>
     */
    private function syncDns(array &$errors): array
    {
        $managed = $this->domains->dnsManaged();
        $syncedDomains = 0;
        $syncedRecords = 0;

        foreach (array_chunk($managed, 10) as $chunk) {
            $dnames = array_map(static fn (Domain $domain): string => $domain->dname, $chunk);

            try {
                $zones = $this->zoneApi->records($dnames);
            } catch (RegRuApiException $exception) {
                $errors[] = 'DNS: ' . $exception->getMessage();

                continue;
            }

            foreach ($dnames as $dname) {
                $zone = $zones[$dname] ?? null;

                if ($zone === null) {
                    continue;
                }

                if ($zone['error'] !== null) {
                    if ($zone['error'] === 'DOMAIN_IS_NOT_USE_REGRU_NSS') {
                        $this->domains->setDnsManaged($dname, false);
                    } else {
                        $errors[] = sprintf('DNS %s: %s', $dname, $zone['error']);
                    }

                    continue;
                }

                $records = [];

                foreach ($zone['rrs'] as $rr) {
                    $record = DnsRecord::fromApi($rr, $dname);

                    if ($record->rectype !== '' && $record->content !== '') {
                        $records[$record->key()] = $record;
                    }
                }

                $this->dnsRecords->replaceForDomain($dname, array_values($records));
                $syncedDomains++;
                $syncedRecords += count($records);
            }
        }

        return ['domains' => $syncedDomains, 'records' => $syncedRecords];
    }

    /**
     * @param list<string> $nss
     */
    private function usesRegruDns(array $nss): bool
    {
        $normalized = array_map(static fn (string $ns): string => strtolower(rtrim($ns, '.')), $nss);

        return in_array('ns1.reg.ru', $normalized, true) || in_array('ns2.reg.ru', $normalized, true);
    }


    /**
     * @param list<string> $serviceIds
     * @return array<string, bool> service_id => autorenew enabled
     */
    private function fetchAutorenewFlags(array $serviceIds): array
    {
        $flags = [];

        foreach (array_chunk($serviceIds, 50) as $chunk) {
            try {
                $entries = $this->servicesApi->info(array_map(
                    static fn (string $id): array => ['service_id' => $id],
                    $chunk,
                ));
            } catch (Throwable $exception) {
                $this->logger->warning('autorenew flags unavailable', ['error' => $exception->getMessage()]);

                continue;
            }

            foreach ($entries as $entry) {
                if (!isset($entry['service_id'])) {
                    continue;
                }

                $flags[(string) $entry['service_id']] = (int) ($entry['autorenew_flag'] ?? 0) === 1;
            }
        }

        return $flags;
    }

    private function ttl(): int
    {
        $stored = $this->settings->get('sync.ttl');

        return $stored !== null && is_numeric($stored) ? (int) $stored : $this->config->int('sync.ttl', 3600);
    }

    private function actor(): string
    {
        return $this->context->actor() ?? 'system';
    }
}
