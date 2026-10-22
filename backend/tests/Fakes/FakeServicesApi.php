<?php

declare(strict_types=1);

namespace RegRuControl\Tests\Fakes;

use RegRuControl\RegRu\Contract\ServicesApiInterface;

final class FakeServicesApi implements ServicesApiInterface
{
    /** @var list<array<string, mixed>> */
    public array $services = [
        [
            'service_id' => 101,
            'servtype' => 'domain',
            'dname' => 'example.com',
            'subtype' => '',
            'state' => 'A',
            'creation_date' => '2020-01-01',
            'expiration_date' => '2030-01-01',
            'uplink_service_id' => 0,
        ],
        [
            'service_id' => 102,
            'servtype' => 'domain',
            'dname' => 'example.ru',
            'subtype' => '',
            'state' => 'A',
            'creation_date' => '2021-02-02',
            'expiration_date' => '2031-02-02',
            'uplink_service_id' => 0,
        ],
        [
            'service_id' => 103,
            'servtype' => 'srv_hosting_ispmgr',
            'dname' => 'example.com',
            'subtype' => 'plan-2',
            'state' => 'A',
            'creation_date' => '2022-03-03',
            'expiration_date' => '2027-03-03',
            'uplink_service_id' => 101,
        ],
        [
            'service_id' => 104,
            'servtype' => 'srv_license_isp',
            'dname' => '',
            'subtype' => '',
            'state' => 'S',
            'creation_date' => '2023-04-04',
            'expiration_date' => '2026-11-01',
            'uplink_service_id' => 0,
        ],
    ];

    /** @var list<array<string, mixed>> */
    public array $details = [
        [
            'service_id' => 103,
            'dname' => 'example.com',
            'servtype' => 'srv_hosting_ispmgr',
            'result' => 'success',
            'details' => [
                'login' => 'hosting-login',
                'passwd' => 'hosting-password',
                'server_ip' => '203.0.113.10',
                'nss' => [['ns' => 'ns1.example.com'], ['ns' => 'ns2.example.com']],
                'plan' => 'plan-2',
            ],
        ],
    ];

    public array $renewAnswer = ['status' => 'renew_success', 'payment' => '100', 'currency' => 'RUR'];
    public array $lastRenew = [];

    public function list(?string $servtype = null): array
    {
        if ($servtype === null) {
            return $this->services;
        }

        return array_values(array_filter(
            $this->services,
            static fn (array $service): bool => $service['servtype'] === $servtype,
        ));
    }

    public function info(array $identifiers): array
    {
        return $this->services;
    }

    public function details(array $identifiers, bool $separateGroups = false, bool $contactsOnly = false): array
    {
        return $this->details;
    }

    public function renew(string $serviceId, int $period): array
    {
        $this->lastRenew = ['service_id' => $serviceId, 'period' => $period];

        return $this->renewAnswer;
    }

    public function setAutorenew(string $dname, bool $flag): array
    {
        return ['result' => 'success', 'dname' => $dname, 'flag' => $flag];
    }

    public function suspend(string $serviceId): array
    {
        return ['result' => 'success'];
    }

    public function resume(string $serviceId): array
    {
        return ['result' => 'success'];
    }

    public function checkReadonly(array $serviceIds): array
    {
        return array_map(static fn (string $id): array => [
            'service_id' => $id,
            'is_readonly' => 0,
            'error_code' => null,
        ], $serviceIds);
    }

    public function bills(string $dname): array
    {
        return ['1001'];
    }
}
