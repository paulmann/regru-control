<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use DateTimeImmutable;
use RegRuControl\Support\Date;

final readonly class ServiceRecord
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $serviceId,
        public string $servtype,
        public ?ServiceType $type,
        public string $dname,
        public string $subtype,
        public ServiceState $state,
        public ?string $creationDate,
        public ?string $expirationDate,
        public string $uplinkServiceId,
        public ?string $folder,
        public array $raw,
        public ?string $detailsSyncedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $raw = json_decode((string) ($row['raw'] ?? '{}'), true);

        return new self(
            serviceId: (string) $row['service_id'],
            servtype: (string) $row['servtype'],
            type: ServiceType::fromApi((string) $row['servtype']),
            dname: (string) ($row['dname'] ?? ''),
            subtype: (string) ($row['subtype'] ?? ''),
            state: ServiceState::fromApi((string) ($row['state'] ?? '')),
            creationDate: isset($row['creation_date']) ? (string) $row['creation_date'] : null,
            expirationDate: isset($row['expiration_date']) ? (string) $row['expiration_date'] : null,
            uplinkServiceId: (string) ($row['uplink_service_id'] ?? '0'),
            folder: isset($row['folder']) ? (string) $row['folder'] : null,
            raw: is_array($raw) ? $raw : [],
            detailsSyncedAt: isset($row['details_synced_at']) ? (string) $row['details_synced_at'] : null,
        );
    }

    /** @param array<string, mixed> $apiService */
    public static function fromApi(array $apiService, string $syncedAt): self
    {
        return new self(
            serviceId: (string) ($apiService['service_id'] ?? ''),
            servtype: (string) ($apiService['servtype'] ?? ''),
            type: ServiceType::fromApi(isset($apiService['servtype']) ? (string) $apiService['servtype'] : null),
            dname: (string) ($apiService['dname'] ?? ''),
            subtype: (string) ($apiService['subtype'] ?? ''),
            state: ServiceState::fromApi(isset($apiService['state']) ? (string) $apiService['state'] : null),
            creationDate: Date::fromApi(isset($apiService['creation_date']) ? (string) $apiService['creation_date'] : null),
            expirationDate: Date::fromApi(isset($apiService['expiration_date']) ? (string) $apiService['expiration_date'] : null),
            uplinkServiceId: (string) ($apiService['uplink_service_id'] ?? '0'),
            folder: isset($apiService['folder']) && is_string($apiService['folder']) ? $apiService['folder'] : null,
            raw: $apiService,
            detailsSyncedAt: null,
        );
    }

    public function category(): ServiceCategory
    {
        return $this->type?->category() ?? ServiceCategory::Addon;
    }

    public function label(): string
    {
        return $this->type?->label() ?? $this->servtype;
    }

    public function daysLeft(DateTimeImmutable $today): ?int
    {
        return Date::daysUntil($this->expirationDate, $today);
    }

    /** @return array<string, mixed> */
    public function toArray(DateTimeImmutable $today): array
    {
        return [
            'service_id' => $this->serviceId,
            'servtype' => $this->servtype,
            'type' => $this->type?->value,
            'type_label' => $this->label(),
            'category' => $this->category()->value,
            'icon' => $this->type?->icon() ?? 'puzzle',
            'dname' => $this->dname,
            'subtype' => $this->subtype,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'state_tone' => $this->state->tone(),
            'creation_date' => $this->creationDate,
            'expiration_date' => $this->expirationDate,
            'days_left' => $this->daysLeft($today),
            'uplink_service_id' => $this->uplinkServiceId,
            'folder' => $this->folder,
            'details_synced_at' => $this->detailsSyncedAt,
        ];
    }
}
