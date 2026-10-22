<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Contract;

use RegRuControl\Model\DnsRecord;

/**
 * DNS zone operations. Implemented by the real API client and by test doubles.
 */
interface ZoneApiInterface
{
    /**
     * @param list<string> $dnames
     * @return array<string, array{rrs: list<array<string, mixed>>, soa: array<string, mixed>, error: ?string}>
     */
    public function records(array $dnames): array;

    /**
     * @param list<array<string, mixed>> $actions
     * @return array<string, mixed>
     */
    public function update(string $dname, array $actions): array;

    /**
     * @return array<string, mixed>
     */
    public function addRecord(string $dname, DnsRecord $record): array;

    /**
     * @return array<string, mixed>
     */
    public function removeRecord(string $dname, DnsRecord $record): array;

    /**
     * @return array<string, mixed>
     */
    public function replaceRecord(string $dname, DnsRecord $old, DnsRecord $new): array;

    /**
     * @return array<string, mixed>
     */
    public function addHttpsRecord(string $dname, string $subname, int $priority, string $target, string $value): array;

    /**
     * @return array<string, mixed>
     */
    public function updateSoa(string $dname, string $ttl, string $minimumTtl): array;

    /**
     * @return array<string, mixed>
     */
    public function clear(string $dname): array;
}
