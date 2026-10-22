<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Model\CredentialKind;
use RegRuControl\Model\Hosting;
use RegRuControl\Model\ServiceCategory;
use RegRuControl\Model\ServiceRecord;
use RegRuControl\Model\ServiceType;
use RegRuControl\RegRu\Contract\ServicesApiInterface;
use RegRuControl\Repository\HostingRepository;
use RegRuControl\Repository\ServiceRepository;
use RegRuControl\Security\Crypto;
use RegRuControl\Support\Json;
use Throwable;

/**
 * Hosting/server inventory and the panel credentials returned by
 * `service/get_details` (login, passwd, server_ip, nss).
 */
final readonly class HostingService
{
    public function __construct(
        private HostingRepository $hostings,
        private ServiceRepository $services,
        private ServicesApiInterface $servicesApi,
        private CredentialService $credentials,
        private Crypto $crypto,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /** @return list<Hosting> */
    public function all(): array
    {
        return $this->hostings->all();
    }

    public function get(string $serviceId): Hosting
    {
        $hosting = $this->hostings->find($serviceId);

        if ($hosting === null) {
            throw HttpException::notFound('Хостинг или сервер не найден. Запустите синхронизацию.');
        }

        $row = $this->hostings->findRow($serviceId);
        $details = null;

        if ($row !== null && is_string($row['details_enc'] ?? null) && $row['details_enc'] !== '') {
            $details = Json::tryDecode($this->crypto->open((string) $row['details_enc'], 'hosting:details:' . $serviceId));
        }

        return Hosting::fromRow($row ?? [], $details);
    }

    /**
     * Reveals the hosting panel password (audited).
     *
     * @return array{login: string, password: string, url: ?string, ip: ?string}
     */
    public function panelCredentials(string $serviceId): array
    {
        $row = $this->hostings->findRow($serviceId);

        if ($row === null) {
            throw HttpException::notFound('Хостинг не найден.');
        }

        if (!is_string($row['panel_password_enc'] ?? null) || $row['panel_password_enc'] === '') {
            throw HttpException::conflict('Для этой услуги REG.RU не вернул пароль панели. Запустите синхронизацию данных хостингов.');
        }

        $password = $this->crypto->open((string) $row['panel_password_enc'], 'hosting:panel:' . $serviceId);
        $this->audit->record('hosting.reveal', $serviceId, [
            'dname' => (string) ($row['dname'] ?? ''),
            'host' => (string) ($row['server_ip'] ?? ''),
        ]);

        return [
            'login' => (string) ($row['panel_login'] ?? ''),
            'password' => $password,
            'url' => isset($row['panel_url']) ? (string) $row['panel_url'] : null,
            'ip' => isset($row['server_ip']) ? (string) $row['server_ip'] : null,
        ];
    }

    /**
     * Fetches `service/get_details` for hostings and stores encrypted panel
     * credentials plus a vault entry.
     *
     * @return array{synced: int, failed: int, errors: list<string>}
     */
    public function syncDetails(bool $force = false): array
    {
        $serviceIds = $force
            ? array_map(static fn (Hosting $hosting): string => $hosting->serviceId, $this->hostings->all())
            : $this->hostings->serviceIdsWithoutPanel();

        $synced = 0;
        $failed = 0;
        $errors = [];

        $answered = [];

        foreach (array_chunk($serviceIds, 10) as $chunk) {
            $identifiers = array_map(static fn (string $id): array => ['service_id' => $id], $chunk);

            try {
                $entries = $this->servicesApi->details($identifiers);
            } catch (Throwable $exception) {
                $failed += count($chunk);
                $errors[] = $exception->getMessage();

                continue;
            }

            foreach ($entries as $entry) {
                $serviceId = (string) ($entry['service_id'] ?? '');

                if ($serviceId === '') {
                    continue;
                }

                $answered[$serviceId] = true;

                try {
                    $this->storeDetails($serviceId, $entry);
                    $synced++;
                } catch (Throwable $exception) {
                    $failed++;
                    $errors[] = sprintf('#%s: %s', $serviceId, $exception->getMessage());
                }
            }
        }

        // Empty answer is a valid answer: REG.RU has no details for every
        // service type, so remember the attempt and do not retry it each sync.
        foreach ($serviceIds as $serviceId) {
            if (!isset($answered[$serviceId])) {
                $this->hostings->markDetailsSynced($serviceId);
            }
        }

        $this->audit->record('hosting.sync_details', '', ['synced' => $synced, 'failed' => $failed]);

        return ['synced' => $synced, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function storeDetails(string $serviceId, array $entry): void
    {
        $details = is_array($entry['details'] ?? null) ? $entry['details'] : $entry;
        $login = isset($details['login']) && is_string($details['login']) ? $details['login'] : null;
        $password = isset($details['passwd']) && is_string($details['passwd']) ? $details['passwd'] : null;
        $serverIp = isset($details['server_ip']) && is_string($details['server_ip']) ? $details['server_ip'] : null;
        $nss = [];

        if (is_array($details['nss'] ?? null)) {
            foreach ($details['nss'] as $ns) {
                if (is_array($ns) && isset($ns['ns']) && is_string($ns['ns'])) {
                    $nss[] = rtrim($ns['ns'], '.');
                } elseif (is_string($ns)) {
                    $nss[] = rtrim($ns, '.');
                }
            }
        }

        $service = $this->services->find($serviceId);
        $type = $service?->type;
        $dname = (string) ($entry['dname'] ?? $service?->dname ?? '');
        $plan = isset($details['plan']) && is_string($details['plan']) ? $details['plan'] : $service?->subtype;
        $panelUrl = $this->panelUrl($type, $serverIp);
        $safeDetails = $details;
        unset($safeDetails['passwd']);

        $encryptedPassword = $password !== null && $password !== ''
            ? $this->crypto->seal($password, 'hosting:panel:' . $serviceId)
            : null;

        $this->hostings->saveDetails(
            $serviceId,
            $serverIp,
            $panelUrl,
            $login,
            $encryptedPassword,
            $nss,
            $plan,
            $this->crypto->seal(Json::encode($safeDetails), 'hosting:details:' . $serviceId),
        );

        if ($login !== null && $password !== null && $password !== '' && $serverIp !== null) {
            $title = ($type?->category() === ServiceCategory::Server ? 'Сервер ' : 'Хостинг ')
                . ($dname !== '' ? $dname : '#' . $serviceId);

            $this->credentials->syncHostingCredential(
                title: $title,
                host: $serverIp,
                username: $login,
                password: $password,
                kind: $panelUrl !== null ? CredentialKind::Panel : CredentialKind::Ssh,
                url: $panelUrl ?? '',
                notes: 'Синхронизировано из REG.RU' . ($plan !== null ? ' · тариф ' . $plan : ''),
                serviceId: $serviceId,
                dname: $dname !== '' ? $dname : null,
            );
        }
    }

    private function panelUrl(?ServiceType $type, ?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return match ($type) {
            ServiceType::HostingIsp => 'https://' . $ip . ':1500/',
            ServiceType::HostingCpanel => 'https://' . $ip . ':2083/',
            ServiceType::HostingPlesk => 'https://' . $ip . ':8443/',
            default => null,
        };
    }

    /**
     * @return list<ServiceRecord> raw services for the "services" page
     */
    public function relatedServices(): array
    {
        return $this->services->all();
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
