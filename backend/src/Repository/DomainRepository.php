<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\Domain;
use RegRuControl\Support\Json;

final readonly class DomainRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * Replaces the domain snapshot. `autorenew` is intentionally preserved:
     * `service/get_list` does not return it and the value is user-managed.
     *
     * @param list<Domain> $domains
     */
    public function replaceAll(array $domains): int
    {
        return $this->database->transaction(function () use ($domains): int {
            $now = $this->now();
            $keep = [];

            foreach ($domains as $domain) {
                $keep[] = $domain->dname;

                $this->database->execute(
                    'INSERT INTO domains (
                        dname, service_id, state, creation_date, expiration_date, nss, dns_managed, updated_at
                     ) VALUES (
                        :dname, :service_id, :state, :creation_date, :expiration_date, :nss, :dns_managed, :now
                     )
                     ON CONFLICT (dname) DO UPDATE SET
                        service_id = excluded.service_id,
                        state = excluded.state,
                        creation_date = excluded.creation_date,
                        expiration_date = excluded.expiration_date,
                        nss = excluded.nss,
                        dns_managed = excluded.dns_managed,
                        updated_at = excluded.updated_at',
                    [
                        'dname' => $domain->dname,
                        'service_id' => $domain->serviceId,
                        'state' => $domain->state->value,
                        'creation_date' => $domain->creationDate,
                        'expiration_date' => $domain->expirationDate,
                        'nss' => Json::encode($domain->nss),
                        'dns_managed' => $domain->dnsManaged ? 1 : 0,
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
        if ($keep === []) {
            $this->database->execute('DELETE FROM domains');

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($keep), '?'));
        $this->database->execute('DELETE FROM domains WHERE dname NOT IN (' . $placeholders . ')', $keep);
    }

    /**
     * @param array{q?: string, tld?: string, state?: string, expiring_in?: int, sort?: string, dir?: string, page?: int, per_page?: int} $filters
     * @return array{items: list<Domain>, total: int, page: int, per_page: int}
     */
    public function search(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (($filters['q'] ?? '') !== '') {
            $where[] = 'dname LIKE :q';
            $params['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
        }

        if (($filters['tld'] ?? '') !== '') {
            $where[] = 'dname LIKE :tld';
            $params['tld'] = '%.' . strtolower((string) $filters['tld']);
        }

        if (($filters['state'] ?? '') !== '') {
            $where[] = 'state = :state';
            $params['state'] = strtoupper((string) $filters['state']);
        }

        if (($filters['expiring_in'] ?? 0) > 0) {
            $where[] = "expiration_date IS NOT NULL AND julianday(expiration_date) - julianday('now') <= :expiring";
            $where[] = 'julianday(expiration_date) >= julianday(\'now\')';
            $params['expiring'] = (int) $filters['expiring_in'];
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $sortMap = [
            'dname' => 'dname',
            'expiration' => 'expiration_date',
            'days_left' => 'julianday(expiration_date)',
            'state' => 'state',
            'updated' => 'updated_at',
        ];
        $sort = $sortMap[(string) ($filters['sort'] ?? 'expiration')] ?? 'expiration_date';
        $dir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 50)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $total = (int) $this->database->fetchValue('SELECT COUNT(*) FROM domains' . $whereSql, $params);
        $rows = $this->database->fetchAll(
            'SELECT * FROM domains' . $whereSql . ' ORDER BY ' . $sort . ' ' . $dir . ', dname ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => $offset],
        );

        return [
            'items' => array_map(static fn (array $row): Domain => Domain::fromRow($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function find(string $dname): ?Domain
    {
        $row = $this->database->fetchOne('SELECT * FROM domains WHERE dname = :dname', ['dname' => $dname]);

        return $row === null ? null : Domain::fromRow($row);
    }

    /** @return list<Domain> */
    public function all(): array
    {
        return array_map(
            static fn (array $row): Domain => Domain::fromRow($row),
            $this->database->fetchAll('SELECT * FROM domains ORDER BY dname'),
        );
    }

    /** @param list<string> $nss */
    public function updateNss(string $dname, array $nss): void
    {
        $this->database->execute(
            'UPDATE domains SET nss = :nss, updated_at = :now WHERE dname = :dname',
            ['nss' => Json::encode(array_values($nss)), 'now' => $this->now(), 'dname' => $dname],
        );
    }

    public function setAutorenew(string $dname, bool $flag): void
    {
        $this->database->execute(
            'UPDATE domains SET autorenew = :flag, updated_at = :now WHERE dname = :dname',
            ['flag' => $flag ? 1 : 0, 'now' => $this->now(), 'dname' => $dname],
        );
    }

    public function setDnsManaged(string $dname, bool $managed): void
    {
        $this->database->execute(
            'UPDATE domains SET dns_managed = :managed, updated_at = :now WHERE dname = :dname',
            ['managed' => $managed ? 1 : 0, 'now' => $this->now(), 'dname' => $dname],
        );
    }

    /** @return list<Domain> */
    public function expiring(int $days = 60, int $limit = 50): array
    {
        $rows = $this->database->fetchAll(
            "SELECT * FROM domains
             WHERE expiration_date IS NOT NULL
               AND julianday(expiration_date) - julianday('now') BETWEEN 0 AND :days
             ORDER BY expiration_date ASC
             LIMIT :limit",
            ['days' => $days, 'limit' => $limit],
        );

        return array_map(static fn (array $row): Domain => Domain::fromRow($row), $rows);
    }

    /** @return list<Domain> */
    public function dnsManaged(): array
    {
        return array_map(
            static fn (array $row): Domain => Domain::fromRow($row),
            $this->database->fetchAll('SELECT * FROM domains WHERE dns_managed = 1 ORDER BY dname'),
        );
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM domains');
    }

    public function countExpiring(int $days = 30): int
    {
        return (int) $this->database->fetchValue(
            "SELECT COUNT(*) FROM domains
             WHERE expiration_date IS NOT NULL
               AND julianday(expiration_date) - julianday('now') BETWEEN 0 AND :days",
            ['days' => $days],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
