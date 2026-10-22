<?php

declare(strict_types=1);

namespace RegRuControl\Model;

final readonly class DnsRecord
{
    public function __construct(
        public ?int $id,
        public string $dname,
        public string $subname,
        public string $rectype,
        public string $content,
        public int $prio,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            dname: (string) $row['dname'],
            subname: (string) $row['subname'],
            rectype: strtoupper((string) $row['rectype']),
            content: (string) $row['content'],
            prio: (int) ($row['prio'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function fromApi(array $record, string $dname): self
    {
        return new self(
            id: isset($record['id']) && is_numeric($record['id']) ? (int) $record['id'] : null,
            dname: $dname,
            subname: (string) ($record['subname'] ?? '@'),
            rectype: strtoupper((string) ($record['rectype'] ?? '')),
            content: (string) ($record['content'] ?? ''),
            prio: (int) ($record['prio'] ?? $record['priority'] ?? 0),
        );
    }

    public function type(): ?RecordType
    {
        return RecordType::fromApi($this->rectype);
    }

    public function key(): string
    {
        return $this->subname . '|' . $this->rectype . '|' . $this->prio . '|' . $this->content;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subname' => $this->subname,
            'rectype' => $this->rectype,
            'content' => $this->content,
            'prio' => $this->prio,
        ];
    }

    /** Payload for zone/remove_record. @return array<string, mixed> */
    public function toRemovalPayload(): array
    {
        $payload = [
            'subdomain' => $this->subname,
            'record_type' => $this->rectype,
        ];

        if ($this->prio > 0) {
            $payload['priority'] = $this->prio;
        }

        if ($this->content !== '') {
            $payload['content'] = $this->content;
        }

        return $payload;
    }
}
