<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Endpoint;

use RegRuControl\RegRu\ApiResponse;
use RegRuControl\RegRu\Contract\DomainApiInterface;
use RegRuControl\RegRu\RegRuClient;

/**
 * Domain commands (`domain/*`): NS, authinfo, prices, transfer status.
 */
final readonly class DomainApi implements DomainApiInterface
{
    public function __construct(private RegRuClient $client)
    {
    }

    /**
     * @return array<string, mixed> availability entry for the domain
     */
    public function check(string $dname, bool $transfer = false): array
    {
        $response = $this->client->call('domain/check', [
            'domain_name' => $dname,
            'is_transfer' => $transfer ? 1 : 0,
        ]);

        return $response->domains()[0] ?? [];
    }

    /**
     * @param list<string> $dnames
     * @return array<string, list<array<string, mixed>>> dname => nss entries
     */
    public function getNss(array $dnames): array
    {
        $response = $this->client->call('domain/get_nss', [
            'domains' => array_map(static fn (string $dname): array => ['dname' => $dname], $dnames),
        ]);

        $result = [];

        foreach ($response->domains() as $domain) {
            $dname = (string) ($domain['dname'] ?? '');

            if ($dname === '') {
                continue;
            }

            $nss = $domain['nss'] ?? [];
            $result[$dname] = is_array($nss) ? array_values(array_filter($nss, 'is_array')) : [];
        }

        return $result;
    }

    /**
     * @param list<string> $nss hostnames in priority order (ns0..ns3)
     * @param array<string, string> $ips hostname => comma separated IPs
     * @return array<string, mixed>
     */
    public function updateNss(string $dname, array $nss, array $ips = [], bool $undelegate = false): array
    {
        $input = [
            'dname' => $dname,
            'nss' => [],
        ];

        foreach (array_values($nss) as $index => $host) {
            $key = 'ns' . $index;
            $input['nss'][$key] = $host;

            if (isset($ips[$host]) && $ips[$host] !== '') {
                $input['nss'][$key . 'ip'] = $ips[$host];
            }
        }

        if ($undelegate) {
            $input['undelegate'] = 1;
        }

        return $this->client->call('domain/update_nss', $input)->answer();
    }

    /**
     * @return array<string, mixed>
     */
    public function setNewAuthinfo(string $dname, string $authinfo): array
    {
        return $this->client->call('domain/set_new_authinfo', [
            'dname' => $dname,
            'authinfo' => $authinfo,
        ])->answer();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrices(bool $renew = true): array
    {
        return $this->client->answer('domain/get_prices', [
            'show_renew_data' => $renew ? 1 : 0,
        ]);
    }

    /**
     * @param list<string> $dnames
     * @return array<string, string> dname => status
     */
    public function transferStatus(array $dnames): array
    {
        $response = $this->client->call('domain/get_transfer_status', [
            'domains' => array_map(static fn (string $dname): array => ['dname' => $dname], $dnames),
        ]);

        $statuses = [];

        foreach ($response->domains() as $domain) {
            if (isset($domain['dname'])) {
                $statuses[(string) $domain['dname']] = (string) ($domain['status'] ?? ($domain['error_code'] ?? 'unknown'));
            }
        }

        return $statuses;
    }

    /**
     * Downloads the domain ownership certificate (`srv_voucher`, partners only).
     * Returns the raw PDF body.
     */
    public function downloadCertificate(string $dname): string
    {
        return $this->client->download('domain/download_certificate', ['dname' => $dname]);
    }

    /**
     * @return array<string, mixed>
     */
    public function tldInfo(string $tld): array
    {
        return $this->client->call('domain/get_tld_info', ['tld' => $tld])->answer();
    }

    public function raw(string $command, array $input): ApiResponse
    {
        return $this->client->call($command, $input);
    }
}
