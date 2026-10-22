<?php

declare(strict_types=1);

namespace RegRuControl\Repository;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Model\Credential;
use RegRuControl\Model\CredentialKind;

final readonly class CredentialRepository
{
    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<Credential>
     */
    public function all(?CredentialKind $kind = null, string $search = ''): array
    {
        $where = [];
        $params = [];

        if ($kind !== null) {
            $where[] = 'kind = :kind';
            $params['kind'] = $kind->value;
        }

        if (trim($search) !== '') {
            $where[] = '(title LIKE :q OR host LIKE :q OR username LIKE :q OR notes LIKE :q OR dname LIKE :q)';
            $params['q'] = '%' . trim($search) . '%';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $rows = $this->database->fetchAll(
            'SELECT * FROM credentials' . $whereSql . ' ORDER BY title COLLATE NOCASE, id',
            $params,
        );

        return array_map(static fn (array $row): Credential => Credential::fromRow($row), $rows);
    }

    public function find(int $id): ?Credential
    {
        $row = $this->database->fetchOne('SELECT * FROM credentials WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Credential::fromRow($row);
    }

    /** @return array<string, mixed>|null row with the encrypted password */
    public function findRow(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM credentials WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array{title: string, kind: string, host: string, port: ?int, username: string, password_enc: string, url: string, notes: string, service_id: ?string, dname: ?string, source?: string} $data
     */
    public function create(array $data): int
    {
        $now = $this->now();

        return $this->database->insert('credentials', $data + [
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $allowed = ['title', 'kind', 'host', 'port', 'username', 'password_enc', 'url', 'notes', 'service_id', 'dname'];
        $data = array_intersect_key($data, array_flip($allowed));

        if ($data === []) {
            return;
        }

        $assignments = [];

        foreach (array_keys($data) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }

        $assignments[] = 'updated_at = :updated_at';
        $data['updated_at'] = $this->now();
        $data['id'] = $id;

        $this->database->execute(
            'UPDATE credentials SET ' . implode(', ', $assignments) . ' WHERE id = :id',
            $data,
        );
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM credentials WHERE id = :id', ['id' => $id]) > 0;
    }

    public function markRevealed(int $id): void
    {
        $now = $this->now();

        $this->database->execute(
            'UPDATE credentials SET last_revealed_at = :now WHERE id = :id',
            ['now' => $now, 'id' => $id],
        );
    }

    /**
     * Finds a credential created by the hosting sync (idempotency key).
     *
     * @return array<string, mixed>|null
     */
    public function findBySyncKey(string $title, string $host, string $username): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM credentials WHERE title = :title AND host = :host AND username = :username AND source = :source',
            ['title' => $title, 'host' => $host, 'username' => $username, 'source' => 'sync'],
        );
    }

    public function upsertSync(
        string $title,
        string $host,
        string $username,
        string $encryptedPassword,
        CredentialKind $kind,
        string $url,
        string $notes,
        ?string $serviceId,
        ?string $dname,
    ): int {
        $existing = $this->findBySyncKey($title, $host, $username);

        if ($existing !== null) {
            $this->update((int) $existing['id'], [
                'password_enc' => $encryptedPassword,
                'kind' => $kind->value,
                'url' => $url,
                'notes' => $notes,
                'service_id' => $serviceId,
                'dname' => $dname,
            ]);

            return (int) $existing['id'];
        }

        return $this->create([
            'title' => $title,
            'kind' => $kind->value,
            'host' => $host,
            'port' => null,
            'username' => $username,
            'password_enc' => $encryptedPassword,
            'url' => $url,
            'notes' => $notes,
            'service_id' => $serviceId,
            'dname' => $dname,
            'source' => 'sync',
        ]);
    }

    public function count(): int
    {
        return (int) $this->database->fetchValue('SELECT COUNT(*) FROM credentials');
    }

    /** @return array<string, int> kind => count */
    public function countsByKind(): array
    {
        $rows = $this->database->fetchAll('SELECT kind, COUNT(*) AS cnt FROM credentials GROUP BY kind');
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['kind']] = (int) $row['cnt'];
        }

        return $counts;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
