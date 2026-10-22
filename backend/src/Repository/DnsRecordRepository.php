<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\DnsRecord;

final readonly class DnsRecordRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * @param list<DnsRecord> $records
     */
    public function replaceForDomain(string $dname, array $records): int
    {
        return $this->database->transaction(function () use ($dname, $records): int {
            $this->database->execute('DELETE FROM dns_records WHERE dname = :dname', ['dname' => $dname]);
            $now = $this->now();
            $count = 0;

            foreach ($records as $record) {
                $this->database->execute(
                    'INSERT OR IGNORE INTO dns_records (dname, subname, rectype, content, prio, synced_at)
                     VALUES (:dname, :subname, :rectype, :content, :prio, :now)',
                    [
                        'dname' => $dname,
                        'subname' => $record->subname,
                        'rectype' => $record->rectype,
                        'content' => $record->content,
                        'prio' => $record->prio,
                        'now' => $now,
                    ],
                );
                $count++;
            }

            return $count;
        });
    }

    /** @return list<DnsRecord> */
    public function forDomain(string $dname): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM dns_records WHERE dname = :dname ORDER BY rectype, subname, prio',
            ['dname' => $dname],
        );

        return array_map(static fn (array $row): DnsRecord => DnsRecord::fromRow($row), $rows);
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM dns_records');
    }

    public function countForDomain(string $dname): int
    {
        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM dns_records WHERE dname = :dname',
            ['dname' => $dname],
        );
    }

    public function syncedAt(string $dname): ?string
    {
        $value = $this->database->fetchValue(
            'SELECT MAX(synced_at) FROM dns_records WHERE dname = :dname',
            ['dname' => $dname],
        );

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<array{dname: string, cnt: int}>
     */
    public function domainsWithRecords(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT dname, COUNT(*) AS cnt FROM dns_records GROUP BY dname ORDER BY dname',
        );

        return array_map(
            static fn (array $row): array => ['dname' => (string) $row['dname'], 'cnt' => (int) $row['cnt']],
            $rows,
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
