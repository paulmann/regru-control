<?php

declare(strict_types=1);

namespace RegRuControl\Tests\Fakes;

use RegRuControl\Model\DnsRecord;
use RegRuControl\RegRu\Contract\ZoneApiInterface;

final class FakeZoneApi implements ZoneApiInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $zones = [];

    /** @var list<array{dname: string, actions: list<array<string, mixed>>}> */
    public array $updates = [];

    public function __construct()
    {
        $this->zones['example.com'] = [
            ['subname' => '@', 'rectype' => 'A', 'content' => '203.0.113.10', 'prio' => 0],
            ['subname' => 'www', 'rectype' => 'CNAME', 'content' => 'example.com', 'prio' => 0],
            ['subname' => '@', 'rectype' => 'MX', 'content' => 'mail.example.com', 'prio' => 10],
        ];
    }

    public function records(array $dnames): array
    {
        $result = [];

        foreach ($dnames as $dname) {
            $result[$dname] = [
                'rrs' => $this->zones[$dname] ?? [],
                'soa' => ['ttl' => '1d', 'minimum_ttl' => '3h'],
                'error' => null,
            ];
        }

        return $result;
    }

    public function update(string $dname, array $actions): array
    {
        $this->updates[] = ['dname' => $dname, 'actions' => $actions];
        $results = [];

        foreach ($actions as $action) {
            $type = (string) ($action['action'] ?? '');
            $results[] = ['action' => $type, 'result' => 'success'];

            if ($type === 'add_alias') {
                $this->zones[$dname][] = [
                    'subname' => (string) $action['subdomain'],
                    'rectype' => 'A',
                    'content' => (string) $action['ipaddr'],
                    'prio' => 0,
                ];
            }

            if ($type === 'add_txt') {
                $this->zones[$dname][] = [
                    'subname' => (string) $action['subdomain'],
                    'rectype' => 'TXT',
                    'content' => (string) $action['text'],
                    'prio' => 0,
                ];
            }

            if ($type === 'remove_record') {
                $this->zones[$dname] = array_values(array_filter(
                    $this->zones[$dname],
                    static fn (array $rr): bool => !(
                        $rr['subname'] === $action['subdomain']
                        && $rr['rectype'] === $action['record_type']
                        && (!isset($action['content']) || $rr['content'] === $action['content'])
                    ),
                ));
            }
        }

        return ['domains' => [['dname' => $dname, 'result' => 'success', 'action_list' => $results]]];
    }

    public function addRecord(string $dname, DnsRecord $record): array
    {
        $action = match ($record->rectype) {
            'A' => ['action' => 'add_alias', 'subdomain' => $record->subname, 'ipaddr' => $record->content],
            'TXT' => ['action' => 'add_txt', 'subdomain' => $record->subname, 'text' => $record->content],
            default => ['action' => 'add_record', 'subdomain' => $record->subname],
        };

        return $this->update($dname, [$action]);
    }

    public function removeRecord(string $dname, DnsRecord $record): array
    {
        return $this->update($dname, [[
            'action' => 'remove_record',
            'subdomain' => $record->subname,
            'record_type' => $record->rectype,
            'content' => $record->content,
            'priority' => $record->prio,
        ]]);
    }

    public function replaceRecord(string $dname, DnsRecord $old, DnsRecord $new): array
    {
        return $this->update($dname, [
            [
                'action' => 'remove_record',
                'subdomain' => $old->subname,
                'record_type' => $old->rectype,
                'content' => $old->content,
                'priority' => $old->prio,
            ],
            ['action' => 'add_alias', 'subdomain' => $new->subname, 'ipaddr' => $new->content],
        ]);
    }

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

    public function updateSoa(string $dname, string $ttl, string $minimumTtl): array
    {
        return $this->update($dname, []);
    }

    public function clear(string $dname): array
    {
        $this->zones[$dname] = [];

        return ['domains' => [['dname' => $dname, 'result' => 'success']]];
    }
}
