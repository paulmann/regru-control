<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\Hosting;
use RegRuControl\Support\Json;

final readonly class HostingRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * Keeps the hosting inventory in sync. Panel fields are preserved because
     * they are filled by the slower details sync.
     *
     * @param list<Hosting> $hostings
     */
    public function replaceAll(array $hostings): int
    {
        return $this->database->transaction(function () use ($hostings): int {
            $now = $this->now();
            $keep = [];

            foreach ($hostings as $hosting) {
                $keep[] = $hosting->serviceId;

                $this->database->execute(
                    'INSERT INTO hostings (service_id, servtype, dname, plan, state, expiration_date, nss, synced_at, updated_at)
                     VALUES (:service_id, :servtype, :dname, :plan, :state, :expiration_date, :nss, :now, :now)
                     ON CONFLICT (service_id) DO UPDATE SET
                        servtype = excluded.servtype,
                        dname = excluded.dname,
                        plan = excluded.plan,
                        state = excluded.state,
                        expiration_date = excluded.expiration_date,
                        synced_at = excluded.synced_at,
                        updated_at = excluded.updated_at',
                    [
                        'service_id' => $hosting->serviceId,
                        'servtype' => $hosting->servtype,
                        'dname' => $hosting->dname,
                        'plan' => $hosting->plan,
                        'state' => $hosting->state->value,
                        'expiration_date' => $hosting->expirationDate,
                        'nss' => Json::encode($hosting->nss),
                        'now' => $now,
                    ],
                );
            }

            if ($keep === []) {
                $this->database->execute('DELETE FROM hostings');
            } else {
                $placeholders = implode(', ', array_fill(0, count($keep), '?'));
                $this->database->execute(
                    'DELETE FROM hostings WHERE service_id NOT IN (' . $placeholders . ')',
                    $keep,
                );
            }

            return count($keep);
        });
    }

    /**
     * Stores the sensitive part returned by `service/get_details`.
     *
     * @param array<string, mixed> $details
     * @param list<string> $nss
     */
    public function saveDetails(
        string $serviceId,
        ?string $serverIp,
        ?string $panelUrl,
        ?string $panelLogin,
        ?string $encryptedPassword,
        array $nss,
        ?string $plan,
        ?string $encryptedDetails,
    ): void {
        $this->database->execute(
            'UPDATE hostings SET
                server_ip = :ip,
                panel_url = :url,
                panel_login = :login,
                panel_password_enc = :password,
                nss = :nss,
                plan = :plan,
                details_enc = :details,
                details_synced_at = :now,
                updated_at = :now
             WHERE service_id = :id',
            [
                'ip' => $serverIp,
                'url' => $panelUrl,
                'login' => $panelLogin,
                'password' => $encryptedPassword,
                'nss' => Json::encode($nss),
                'plan' => $plan,
                'details' => $encryptedDetails,
                'now' => $this->now(),
                'id' => $serviceId,
            ],
        );
    }

    /** @return list<Hosting> */
    public function all(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM hostings ORDER BY dname, service_id',
        );

        return array_map(static fn (array $row): Hosting => Hosting::fromRow($row), $rows);
    }

    public function find(string $serviceId): ?Hosting
    {
        $row = $this->database->fetchOne('SELECT * FROM hostings WHERE service_id = :id', ['id' => $serviceId]);

        return $row === null ? null : Hosting::fromRow($row);
    }

    /**
     * @return array<string, mixed>|null raw row including encrypted fields
     */
    public function findRow(string $serviceId): ?array
    {
        return $this->database->fetchOne('SELECT * FROM hostings WHERE service_id = :id', ['id' => $serviceId]);
    }

    /**
     * @return list<string>
     */
    public function serviceIdsWithoutPanel(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT service_id FROM hostings WHERE details_synced_at IS NULL ORDER BY service_id',
        );

        return array_map(static fn (array $row): string => (string) $row['service_id'], $rows);
    }

    public function markDetailsSynced(string $serviceId): void
    {
        $this->database->execute(
            'UPDATE hostings SET details_synced_at = :now, updated_at = :now WHERE service_id = :id',
            ['now' => $this->now(), 'id' => $serviceId],
        );
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM hostings');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
