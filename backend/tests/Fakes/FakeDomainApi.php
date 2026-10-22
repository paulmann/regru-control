<?php

declare(strict_types=1);

namespace RegRuControl\Tests\Fakes;

use RegRuControl\RegRu\Contract\DomainApiInterface;

final class FakeDomainApi implements DomainApiInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $nss = [
        'example.com' => [['ns' => 'ns1.reg.ru'], ['ns' => 'ns2.reg.ru']],
        'example.ru' => [['ns' => 'ns1.other.tld'], ['ns' => 'ns2.other.tld']],
    ];

    /** @var list<array{nss: list<string>, undelegate: bool}> */
    public array $updates = [];

    public function check(string $dname, bool $transfer = false): array
    {
        return ['dname' => $dname, 'result' => 'Available'];
    }

    public function getNss(array $dnames): array
    {
        $result = [];

        foreach ($dnames as $dname) {
            $result[$dname] = $this->nss[$dname] ?? [];
        }

        return $result;
    }

    public function updateNss(string $dname, array $nss, array $ips = [], bool $undelegate = false): array
    {
        $this->updates[] = ['nss' => $nss, 'undelegate' => $undelegate];

        return ['domains' => [['dname' => $dname, 'result' => 'success', 'service_id' => '1']]];
    }

    public function setNewAuthinfo(string $dname, string $authinfo): array
    {
        return ['result' => 'success'];
    }

    public function getPrices(bool $renew = true): array
    {
        return ['currency' => 'RUR', 'prices' => ['ru' => ['reg_price' => '200', 'renew_price' => '200']]];
    }

    public function transferStatus(array $dnames): array
    {
        return array_fill_keys($dnames, 'notAvailable');
    }

    public function tldInfo(string $tld): array
    {
        return ['tld' => $tld];
    }

    public function downloadCertificate(string $dname): string
    {
        return '%PDF-1.4 test';
    }
}
