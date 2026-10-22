<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Endpoint;

use RegRuControl\Model\DnsRecord;
use RegRuControl\Model\RecordType;
use RegRuControl\RegRu\Contract\ZoneApiInterface;
use RegRuControl\RegRu\RegRuClient;

/**
 * DNS zone commands (`zone/*`).
 *
 * REG.RU exposes records without ids, therefore "editing" a record is a remove
 * + add pair submitted in one `zone/update_records` call (atomic per action
 * list, ordered as given).
 */
final readonly class ZoneApi implements ZoneApiInterface
{
    public function __construct(private RegRuClient $client)
    {
    }

    /**
     * @param list<string> $dnames
     * @return array<string, array{rrs: list<array<string, mixed>>, soa: array<string, mixed>, error: ?string}>
     */
    public function records(array $dnames): array
    {
        if ($dnames === []) {
            return [];
        }

        $response = $this->client->call('zone/get_resource_records', [
            'domains' => array_map(static fn (string $dname): array => ['dname' => $dname], $dnames),
        ]);

        $result = [];

        foreach ($response->domains() as $domain) {
            $dname = (string) ($domain['dname'] ?? '');

            if ($dname === '') {
                continue;
            }

            $result[$dname] = [
                'rrs' => is_array($domain['rrs'] ?? null) ? array_values(array_filter($domain['rrs'], 'is_array')) : [],
                'soa' => is_array($domain['soa'] ?? null) ? $domain['soa'] : [],
                'error' => ($domain['result'] ?? '') === 'success' ? null : (string) ($domain['error_code'] ?? 'UNKNOWN'),
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @return array<string, mixed>
     */
    public function update(string $dname, array $actions): array
    {
        return $this->client->call('zone/update_records', [
            'domains' => [[
                'dname' => $dname,
                'action_list' => $actions,
            ]],
        ])->answer();
    }

    /**
     * @return array<string, mixed>
     */
    public function addRecord(string $dname, DnsRecord $record): array
    {
        return $this->update($dname, [self::addPayload($record)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeRecord(string $dname, DnsRecord $record): array
    {
        return $this->update($dname, [[
            'action' => 'remove_record',
            'subdomain' => $record->subname,
            'record_type' => $record->rectype,
            'priority' => $record->prio,
            'content' => $record->content,
        ]]);
    }

    /**
     * Atomic replace: remove the old record, add the new one.
     *
     * @return array<string, mixed>
     */
    public function replaceRecord(string $dname, DnsRecord $old, DnsRecord $new): array
    {
        return $this->update($dname, [
            [
                'action' => 'remove_record',
                'subdomain' => $old->subname,
                'record_type' => $old->rectype,
                'priority' => $old->prio,
                'content' => $old->content,
            ],
            self::addPayload($new),
        ]);
    }

    /**
     * HTTPS/SVCB record (`zone/add_https`, added after the 2022 docs).
     *
     * `$value` is a space separated key=value list, e.g.
     * `alpn=h3 ipv4hint=203.0.113.10`.
     *
     * @return array<string, mixed>
     */
    public function addHttpsRecord(string $dname, string $subname, int $priority, string $target, string $value): array
    {
        return $this->update($dname, [[
            'action' => 'add_https',
            'subdomain' => $subname,
            'priority' => $priority,
            'target' => $target,
            'value' => $value,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateSoa(string $dname, string $ttl, string $minimumTtl): array
    {
        return $this->client->call('zone/update_soa', [
            'domains' => [['dname' => $dname]],
            'ttl' => $ttl,
            'minimum_ttl' => $minimumTtl,
        ])->answer();
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(string $dname): array
    {
        return $this->client->call('zone/clear', ['domains' => [['dname' => $dname]]])->answer();
    }

    /**
     * @return array<string, mixed>
     */
    public static function addPayload(DnsRecord $record): array
    {
        $type = RecordType::fromApi($record->rectype);

        if ($type === null) {
            throw new \InvalidArgumentException(sprintf('Unsupported record type "%s".', $record->rectype));
        }

        $base = [
            'action' => $type->addAction(),
            'subdomain' => $record->subname,
        ];

        return match ($type) {
            RecordType::A, RecordType::AAAA => $base + ['ipaddr' => $record->content],
            RecordType::CNAME => $base + ['canonical_name' => $record->content],
            RecordType::MX => $base + [
                'priority' => $record->prio,
                'mail_server' => $record->content,
            ],
            RecordType::TXT => $base + ['text' => $record->content],
            RecordType::NS => $base + ['dns_server' => $record->content],
            RecordType::SRV => $base + self::srvFields($record),
            RecordType::CAA => $base + self::caaFields($record),
        };
    }

    /**
     * SRV content format used by the panel: "<weight> <port> <target>".
     *
     * @return array{priority: int, weight: int, port: int, target: string}
     */
    private static function srvFields(DnsRecord $record): array
    {
        $parts = preg_split('/\s+/', trim($record->content)) ?: [];

        if (count($parts) < 3) {
            throw new \InvalidArgumentException('SRV-запись: укажите «вес порт цель», например «10 5060 sip.example.com».');
        }

        [$weight, $port, $target] = $parts;

        if (!ctype_digit((string) $weight) || !ctype_digit((string) $port)) {
            throw new \InvalidArgumentException('SRV-запись: вес и порт должны быть числами.');
        }

        return [
            'priority' => $record->prio,
            'weight' => (int) $weight,
            'port' => (int) $port,
            'target' => (string) $target,
        ];
    }

    /**
     * CAA content: "[flags] <tag> <value>", e.g. "0 issue letsencrypt.org".
     *
     * @return array{flags: int, tag: string, value: string}
     */
    private static function caaFields(DnsRecord $record): array
    {
        $parts = preg_split('/\s+/', trim($record->content)) ?: [];

        $flags = 0;
        $tag = 'issue';
        $value = '';

        if (count($parts) >= 3 && ctype_digit($parts[0])) {
            $flags = (int) $parts[0];
            $tag = strtolower($parts[1]);
            $value = implode(' ', array_slice($parts, 2));
        } elseif (count($parts) >= 2 && in_array(strtolower($parts[0]), ['issue', 'issuewild', 'iodef'], true)) {
            $tag = strtolower($parts[0]);
            $value = implode(' ', array_slice($parts, 1));
        } elseif (count($parts) === 1) {
            $value = $parts[0];
        }

        if (!in_array($tag, ['issue', 'issuewild', 'iodef'], true) || $value === '') {
            throw new \InvalidArgumentException('CAA-запись: ожидается «0 issue letsencrypt.org» (flags, tag, value).');
        }

        return [
            'flags' => $flags,
            'tag' => $tag,
            'value' => $value,
        ];
    }
}
