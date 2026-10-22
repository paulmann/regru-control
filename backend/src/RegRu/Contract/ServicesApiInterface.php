<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Contract;

/**
 * REG.RU service operations (inventory, details, renewals).
 */
interface ServicesApiInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $servtype = null): array;

    /**
     * @param list<array<string, mixed>> $identifiers
     * @return list<array<string, mixed>>
     */
    public function info(array $identifiers): array;

    /**
     * @param list<array<string, mixed>> $identifiers
     * @return list<array<string, mixed>>
     */
    public function details(array $identifiers, bool $separateGroups = false, bool $contactsOnly = false): array;

    /**
     * @return array<string, mixed>
     */
    public function renew(string $serviceId, int $period): array;

    /**
     * @return array<string, mixed>
     */
    public function setAutorenew(string $dname, bool $flag): array;

    /**
     * @return array<string, mixed>
     */
    public function suspend(string $serviceId): array;

    /**
     * @return array<string, mixed>
     */
    public function resume(string $serviceId): array;

    /**
     * @param list<string> $serviceIds
     * @return list<array<string, mixed>>
     */
    public function checkReadonly(array $serviceIds): array;

    /**
     * @return list<string>
     */
    public function bills(string $dname): array;
}
