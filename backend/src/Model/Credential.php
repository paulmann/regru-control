<?php

declare(strict_types=1);

namespace RegRuControl\Model;

final readonly class Credential
{
    public function __construct(
        public ?int $id,
        public string $title,
        public CredentialKind $kind,
        public string $host,
        public ?int $port,
        public string $username,
        public bool $hasPassword,
        public string $url,
        public string $notes,
        public ?string $serviceId,
        public ?string $dname,
        public string $source,
        public ?string $lastRevealedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            title: (string) $row['title'],
            kind: CredentialKind::fromString(isset($row['kind']) ? (string) $row['kind'] : null),
            host: (string) ($row['host'] ?? ''),
            port: isset($row['port']) ? (int) $row['port'] : null,
            username: (string) ($row['username'] ?? ''),
            hasPassword: isset($row['password_enc']) && is_string($row['password_enc']) && $row['password_enc'] !== '',
            url: (string) ($row['url'] ?? ''),
            notes: (string) ($row['notes'] ?? ''),
            serviceId: isset($row['service_id']) && $row['service_id'] !== '' ? (string) $row['service_id'] : null,
            dname: isset($row['dname']) && $row['dname'] !== '' ? (string) $row['dname'] : null,
            source: (string) ($row['source'] ?? 'manual'),
            lastRevealedAt: isset($row['last_revealed_at']) ? (string) $row['last_revealed_at'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'icon' => $this->kind->icon(),
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'has_password' => $this->hasPassword,
            'url' => $this->url,
            'notes' => $this->notes,
            'service_id' => $this->serviceId,
            'dname' => $this->dname,
            'source' => $this->source,
            'last_revealed_at' => $this->lastRevealedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
