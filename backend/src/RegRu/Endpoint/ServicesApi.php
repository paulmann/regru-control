<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Endpoint;

use RegRuControl\RegRu\Contract\ServicesApiInterface;
use RegRuControl\RegRu\RegRuClient;

/**
 * Service commands (`service/*`): inventory, details, renewals.
 */
final readonly class ServicesApi implements ServicesApiInterface
{
    public function __construct(private RegRuClient $client)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $servtype = null): array
    {
        $input = $servtype === null || $servtype === '' ? [] : ['servtype' => $servtype];

        return $this->client->call('service/get_list', $input)->services();
    }

    /**
     * @param list<array{service_id: string}|array{domain_name: string}> $identifiers
     * @return list<array<string, mixed>>
     */
    public function info(array $identifiers): array
    {
        return $this->client->call('service/get_info', ['services' => $identifiers])->services();
    }

    /**
     * Additional data. For hostings this response contains `login`, `passwd`,
     * `server_ip` and `nss` — the panel credentials shown in the vault.
     *
     * @param list<array{service_id: string}|array{domain_name: string}> $identifiers
     * @return list<array<string, mixed>>
     */
    public function details(array $identifiers, bool $separateGroups = false, bool $contactsOnly = false): array
    {
        return $this->client->call('service/get_details', [
            'services' => $identifiers,
            'separate_groups' => $separateGroups ? 1 : 0,
            'show_contacts_only' => $contactsOnly ? 1 : 0,
            'splitted_contacts' => 1,
        ])->services();
    }

    /**
     * @return array<string, mixed>
     */
    public function renew(string $serviceId, int $period): array
    {
        return $this->client->answer('service/renew', [
            'service_id' => $serviceId,
            'period' => $period,
            'pay_type' => 'prepay',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setAutorenew(string $dname, bool $flag): array
    {
        return $this->client->answer('service/set_autorenew_flag', [
            'dname' => $dname,
            'flag_value' => $flag ? 1 : 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function suspend(string $serviceId): array
    {
        return $this->client->answer('service/suspend', ['service_id' => $serviceId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function resume(string $serviceId): array
    {
        return $this->client->answer('service/resume', ['service_id' => $serviceId]);
    }

    /**
     * Checks whether services are editable (documents verified, no locks).
     *
     * @param list<string> $serviceIds
     * @return list<array<string, mixed>>
     */
    public function checkReadonly(array $serviceIds): array
    {
        $answer = $this->client->answer('service/check_readonly', [
            'services' => array_map(static fn (string $id): array => ['service_id' => $id], $serviceIds),
        ]);

        $services = $answer['services'] ?? [];

        return is_array($services) ? array_values(array_filter($services, 'is_array')) : [];
    }

    /**
     * @return list<string>
     */
    public function bills(string $dname): array
    {
        $answer = $this->client->answer('service/get_bills', ['dname' => $dname]);
        $services = $answer['services'] ?? [];
        $bills = [];

        if (is_array($services)) {
            foreach ($services as $service) {
                if (is_array($service) && is_array($service['bills'] ?? null)) {
                    foreach ($service['bills'] as $bill) {
                        $bills[] = (string) $bill;
                    }
                }
            }
        }

        return array_values(array_unique($bills));
    }
}
