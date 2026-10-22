<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Contract;

/**
 * REG.RU domain operations: NS, authinfo, prices, certificates.
 */
interface DomainApiInterface
{
    /**
     * @return array<string, mixed>
     */
    public function check(string $dname, bool $transfer = false): array;

    /**
     * @param list<string> $dnames
     * @return array<string, list<array<string, mixed>>>
     */
    public function getNss(array $dnames): array;

    /**
     * @param list<string> $nss
     * @param array<string, string> $ips
     * @return array<string, mixed>
     */
    public function updateNss(string $dname, array $nss, array $ips = [], bool $undelegate = false): array;

    /**
     * @return array<string, mixed>
     */
    public function setNewAuthinfo(string $dname, string $authinfo): array;

    /**
     * @return array<string, mixed>
     */
    public function getPrices(bool $renew = true): array;

    /**
     * @param list<string> $dnames
     * @return array<string, string>
     */
    public function transferStatus(array $dnames): array;

    /**
     * @return array<string, mixed>
     */
    public function tldInfo(string $tld): array;

    public function downloadCertificate(string $dname): string;
}
