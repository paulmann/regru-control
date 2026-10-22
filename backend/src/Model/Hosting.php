<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use DateTimeImmutable;
use RegRuControl\Support\Date;

final readonly class Hosting
{
    /**
     * @param list<string> $nss
     * @param array<string, mixed>|null $details decrypted service details (never leaves the backend unmasked)
     */
    public function __construct(
        public string $serviceId,
        public string $servtype,
        public ?ServiceType $type,
        public string $dname,
        public ?string $serverIp,
        public ?string $panelUrl,
        public ?string $panelLogin,
        public bool $hasPanelPassword,
        public array $nss,
        public ?string $plan,
        public ServiceState $state,
        public ?string $expirationDate,
        public ?array $details,
        public string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $details
     */
    public static function fromRow(array $row, ?array $details = null): self
    {
        $nss = json_decode((string) ($row['nss'] ?? '[]'), true);

        return new self(
            serviceId: (string) $row['service_id'],
            servtype: (string) $row['servtype'],
            type: ServiceType::fromApi((string) $row['servtype']),
            dname: (string) ($row['dname'] ?? ''),
            serverIp: isset($row['server_ip']) && $row['server_ip'] !== '' ? (string) $row['server_ip'] : null,
            panelUrl: isset($row['panel_url']) && $row['panel_url'] !== '' ? (string) $row['panel_url'] : null,
            panelLogin: isset($row['panel_login']) && $row['panel_login'] !== '' ? (string) $row['panel_login'] : null,
            hasPanelPassword: isset($row['panel_password_enc']) && is_string($row['panel_password_enc']) && $row['panel_password_enc'] !== '',
            nss: is_array($nss) ? array_values(array_map('strval', $nss)) : [],
            plan: isset($row['plan']) && $row['plan'] !== '' ? (string) $row['plan'] : null,
            state: ServiceState::fromApi((string) ($row['state'] ?? '')),
            expirationDate: isset($row['expiration_date']) ? (string) $row['expiration_date'] : null,
            details: $details,
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }

    public function title(): string
    {
        if ($this->dname !== '') {
            return $this->dname;
        }

        if ($this->plan !== null && $this->plan !== '') {
            return 'Услуга ' . $this->plan;
        }

        return 'Услуга #' . $this->serviceId;
    }

    public function daysLeft(DateTimeImmutable $today): ?int
    {
        return Date::daysUntil($this->expirationDate, $today);
    }

    /** @return array<string, mixed> */
    public function toArray(DateTimeImmutable $today, bool $withDetails = false): array
    {
        $data = [
            'service_id' => $this->serviceId,
            'servtype' => $this->servtype,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label() ?? $this->servtype,
            'category' => $this->type?->category()->value,
            'dname' => $this->dname,
            'title' => $this->title(),
            'server_ip' => $this->serverIp,
            'panel_url' => $this->panelUrl,
            'panel_login' => $this->panelLogin,
            'has_panel_password' => $this->hasPanelPassword,
            'nss' => $this->nss,
            'plan' => $this->plan,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'state_tone' => $this->state->tone(),
            'expiration_date' => $this->expirationDate,
            'days_left' => $this->daysLeft($today),
            'updated_at' => $this->updatedAt,
        ];

        if ($withDetails && $this->details !== null) {
            $data['details'] = $this->details;
        }

        return $data;
    }
}
