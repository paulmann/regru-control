<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\ServiceCategory;
use RegRuControl\Model\ServiceRecord;
use RegRuControl\Model\ServiceType;
use RegRuControl\Support\Json;

final readonly class ServiceRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * Replaces the inventory snapshot in one transaction.
     *
     * Services that disappeared from REG.RU are deleted, so the panel never
     * shows phantom entries.
     *
     * @param list<ServiceRecord> $services
     * @return int number of stored services
     */
    public function replaceAll(array $services): int
    {
        return $this->database->transaction(function () use ($services): int {
            $now = $this->now();
            $keep = [];

            foreach ($services as $service) {
                if ($service->serviceId === '') {
                    continue;
                }

                $keep[] = $service->serviceId;

                $this->database->execute(
                    'INSERT INTO services (
                        service_id, servtype, dname, subtype, state, creation_date, expiration_date,
                        uplink_service_id, folder, raw, synced_at, updated_at
                     ) VALUES (
                        :service_id, :servtype, :dname, :subtype, :state, :creation_date, :expiration_date,
                        :uplink_service_id, :folder, :raw, :now, :now
                     )
                     ON CONFLICT (service_id) DO UPDATE SET
                        servtype = excluded.servtype,
                        dname = excluded.dname,
                        subtype = excluded.subtype,
                        state = excluded.state,
                        creation_date = excluded.creation_date,
                        expiration_date = excluded.expiration_date,
                        uplink_service_id = excluded.uplink_service_id,
                        folder = excluded.folder,
                        raw = excluded.raw,
                        synced_at = excluded.synced_at,
                        updated_at = excluded.updated_at',
                    [
                        'service_id' => $service->serviceId,
                        'servtype' => $service->servtype,
                        'dname' => $service->dname,
                        'subtype' => $service->subtype,
                        'state' => $service->state->value,
                        'creation_date' => $service->creationDate,
                        'expiration_date' => $service->expirationDate,
                        'uplink_service_id' => $service->uplinkServiceId,
                        'folder' => $service->folder,
                        'raw' => Json::encode($service->raw),
                        'now' => $now,
                    ],
                );
            }

            $this->deleteMissing($keep);

            return count($keep);
        });
    }

    /**
     * @param list<string> $keep
     */
    public function deleteMissing(array $keep): void
    {
        $keep = array_values(array_filter(array_map('strval', $keep)));

        if ($keep === []) {
            $this->database->execute('DELETE FROM services');

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($keep), '?'));
        $this->database->execute('DELETE FROM services WHERE service_id NOT IN (' . $placeholders . ')', $keep);
    }

    public function saveDetails(string $serviceId, ?string $encryptedDetails, bool $success): void
    {
        $this->database->execute(
            'UPDATE services SET details_enc = :details, details_synced_at = :now WHERE service_id = :id',
            [
                'details' => $success ? $encryptedDetails : null,
                'now' => $this->now(),
                'id' => $serviceId,
            ],
        );
    }

    /** @return list<ServiceRecord> */
    public function all(?ServiceType $type = null, ?ServiceCategory $category = null): array
    {
        $sql = 'SELECT * FROM services';
        $params = [];

        if ($type !== null) {
            $sql .= ' WHERE servtype = :type';
            $params['type'] = $type->value;
        }

        if ($category !== null) {
            $types = array_values(array_filter(
                array_map(static fn (ServiceType $case): string => $case->value, ServiceType::cases()),
                static fn (string $servtype): bool => ServiceType::fromApi($servtype)?->category() === $category,
            ));

            if ($types === []) {
                return [];
            }

            $placeholders = [];
            foreach ($types as $index => $servtype) {
                $placeholders[] = ':cat' . $index;
                $params['cat' . $index] = $servtype;
            }

            $sql .= ($type !== null ? ' AND' : ' WHERE') . ' servtype IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY expiration_date IS NULL, expiration_date, dname';

        return array_map(
            static fn (array $row): ServiceRecord => ServiceRecord::fromRow($row),
            $this->database->fetchAll($sql, $params),
        );
    }

    public function find(string $serviceId): ?ServiceRecord
    {
        $row = $this->database->fetchOne('SELECT * FROM services WHERE service_id = :id', ['id' => $serviceId]);

        return $row === null ? null : ServiceRecord::fromRow($row);
    }

    /**
     * @return array{service_id: string, details: string|null}|null
     */
    public function findDetails(string $serviceId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT service_id, details_enc AS details FROM services WHERE service_id = :id',
            ['id' => $serviceId],
        );

        return $row === null ? null : ['service_id' => (string) $row['service_id'], 'details' => $row['details']];
    }

    /** @return array<string, int> category => count */
    public function countsByCategory(): array
    {
        $rows = $this->database->fetchAll('SELECT servtype, COUNT(*) AS cnt FROM services GROUP BY servtype');
        $counts = [];

        foreach ($rows as $row) {
            $category = ServiceType::fromApi((string) $row['servtype'])?->category()->value ?? 'addon';
            $counts[$category] = ($counts[$category] ?? 0) + (int) $row['cnt'];
        }

        return $counts;
    }

    public function lastSyncedAt(): ?string
    {
        $value = $this->database->fetchValue('SELECT MAX(synced_at) FROM services');

        return is_string($value) ? $value : null;
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM services');
    }

    /** @return list<ServiceRecord> services whose details were never fetched */
    public function withoutDetails(int $limit = 20): array
    {
        return array_map(
            static fn (array $row): ServiceRecord => ServiceRecord::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM services WHERE details_synced_at IS NULL ORDER BY synced_at LIMIT :limit',
                ['limit' => $limit],
            ),
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
