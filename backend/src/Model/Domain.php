<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use DateTimeImmutable;
use RegRuControl\Support\Date;

final readonly class Domain
{
    /**
     * @param list<string> $nss
     */
    public function __construct(
        public string $dname,
        public string $serviceId,
        public ServiceState $state,
        public ?string $creationDate,
        public ?string $expirationDate,
        public array $nss,
        public bool $dnsManaged,
        public ?bool $autorenew,
        public string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $nss = json_decode((string) ($row['nss'] ?? '[]'), true);
        $autorenew = $row['autorenew'] ?? null;

        return new self(
            dname: (string) $row['dname'],
            serviceId: (string) $row['service_id'],
            state: ServiceState::fromApi((string) ($row['state'] ?? '')),
            creationDate: isset($row['creation_date']) ? (string) $row['creation_date'] : null,
            expirationDate: isset($row['expiration_date']) ? (string) $row['expiration_date'] : null,
            nss: is_array($nss) ? array_values(array_map('strval', $nss)) : [],
            dnsManaged: (int) ($row['dns_managed'] ?? 0) === 1,
            autorenew: $autorenew === null ? null : (bool) (int) $autorenew,
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }

    public function tld(): string
    {
        $position = strrpos($this->dname, '.');

        return $position === false ? '' : strtolower(substr($this->dname, $position + 1));
    }

    public function daysLeft(DateTimeImmutable $today): ?int
    {
        return Date::daysUntil($this->expirationDate, $today);
    }

    public function isExpiringSoon(DateTimeImmutable $today, int $days = 30): bool
    {
        $left = $this->daysLeft($today);

        return $left !== null && $left >= 0 && $left <= $days;
    }

    /** @return array<string, mixed> */
    public function toArray(DateTimeImmutable $today): array
    {
        return [
            'dname' => $this->dname,
            'tld' => $this->tld(),
            'service_id' => $this->serviceId,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'state_tone' => $this->state->tone(),
            'creation_date' => $this->creationDate,
            'expiration_date' => $this->expirationDate,
            'days_left' => $this->daysLeft($today),
            'nss' => $this->nss,
            'dns_managed' => $this->dnsManaged,
            'autorenew' => $this->autorenew,
            'updated_at' => $this->updatedAt,
        ];
    }
}
