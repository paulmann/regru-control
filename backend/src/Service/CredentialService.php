<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Model\Credential;
use RegRuControl\Model\CredentialKind;
use RegRuControl\Repository\CredentialRepository;
use RegRuControl\Security\Crypto;
use RegRuControl\Support\RateLimiter;

/**
 * Encrypted credential vault.
 *
 * Plaintext passwords never touch the database or the logs: each row is sealed
 * with a key derived from APP_KEY and the row id, and every reveal is audited.
 */
final readonly class CredentialService
{
    private const PASSWORD_ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*()-_=+';

    public function __construct(
        private CredentialRepository $repository,
        private Crypto $crypto,
        private AuditService $audit,
        private RateLimiter $limiter,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<Credential>
     */
    public function search(?CredentialKind $kind = null, string $query = ''): array
    {
        return $this->repository->all($kind, $query);
    }

    public function get(int $id): Credential
    {
        $credential = $this->repository->find($id);

        if ($credential === null) {
            throw HttpException::notFound('Запись не найдена.');
        }

        return $credential;
    }

    /**
     * @param array{title: string, kind: string, host: string, port: ?int, username: string, password: string, url: string, notes: string, service_id: ?string, dname: ?string} $data
     */
    public function create(array $data): Credential
    {
        $kind = CredentialKind::fromString($data['kind'] ?? null);

        $id = $this->repository->create([
            'title' => $data['title'],
            'kind' => $kind->value,
            'host' => $data['host'],
            'port' => $data['port'],
            'username' => $data['username'],
            'password_enc' => '',
            'url' => $data['url'],
            'notes' => $data['notes'],
            'service_id' => $data['service_id'] ?? null,
            'dname' => $data['dname'] ?? null,
            'source' => 'manual',
        ]);

        $this->repository->update($id, [
            'password_enc' => $this->crypto->seal($data['password'], 'credential:' . $id),
        ]);

        $this->audit->record('credential.create', (string) $id, ['title' => $data['title'], 'kind' => $kind->value]);

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Credential
    {
        $this->get($id);
        $update = [];

        foreach (['title', 'host', 'url', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = (string) $data[$field];
            }
        }

        if (array_key_exists('kind', $data)) {
            $update['kind'] = CredentialKind::fromString((string) $data['kind'])->value;
        }

        if (array_key_exists('port', $data)) {
            $update['port'] = $data['port'] === null || $data['port'] === '' ? null : (int) $data['port'];
        }

        foreach (['username', 'service_id', 'dname'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field] === '' ? null : (string) $data[$field];
            }
        }

        if (isset($data['password']) && is_string($data['password']) && $data['password'] !== '') {
            $update['password_enc'] = $this->crypto->seal($data['password'], 'credential:' . $id);
        }

        $this->repository->update($id, $update);
        $this->audit->record('credential.update', (string) $id, ['fields' => array_keys($update)]);

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $credential = $this->get($id);

        if (!$this->repository->delete($id)) {
            throw HttpException::notFound('Запись не найдена.');
        }

        $this->audit->record('credential.delete', (string) $id, ['title' => $credential->title]);
    }

    /**
     * @return array{id: int, title: string, username: string, password: string, url: string}
     */
    public function reveal(int $id): array
    {
        if (!$this->limiter->hit('reveal', 'actor:' . ($this->audit->count() % 1) . $id, 60, 300)) {
            throw HttpException::tooManyRequests('Слишком частое открытие паролей. Подождите несколько минут.', [], 300);
        }

        $row = $this->repository->findRow($id);

        if ($row === null) {
            throw HttpException::notFound('Запись не найдена.');
        }

        $password = $this->crypto->open((string) $row['password_enc'], 'credential:' . $id);
        $this->repository->markRevealed($id);
        $this->audit->record('credential.reveal', (string) $id, ['title' => (string) $row['title']]);

        return [
            'id' => $id,
            'title' => (string) $row['title'],
            'username' => (string) ($row['username'] ?? ''),
            'password' => $password,
            'url' => (string) ($row['url'] ?? ''),
        ];
    }

    /**
     * Idempotent upsert used by the hosting details sync.
     */
    public function syncHostingCredential(
        string $title,
        string $host,
        string $username,
        string $password,
        CredentialKind $kind,
        string $url,
        string $notes,
        ?string $serviceId,
        ?string $dname,
    ): int {
        $existing = $this->repository->findBySyncKey($title, $host, $username);

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->repository->update($id, [
                'password_enc' => $this->crypto->seal($password, 'credential:' . $id),
                'kind' => $kind->value,
                'url' => $url,
                'notes' => $notes,
                'service_id' => $serviceId,
                'dname' => $dname,
            ]);

            return $id;
        }

        $id = $this->repository->create([
            'title' => $title,
            'kind' => $kind->value,
            'host' => $host,
            'port' => null,
            'username' => $username,
            'password_enc' => '',
            'url' => $url,
            'notes' => $notes,
            'service_id' => $serviceId,
            'dname' => $dname,
            'source' => 'sync',
        ]);

        $this->repository->update($id, [
            'password_enc' => $this->crypto->seal($password, 'credential:' . $id),
        ]);

        return $id;
    }

    public function generatePassword(int $length = 24): string
    {
        $length = max(16, min(64, $length));
        $alphabet = self::PASSWORD_ALPHABET;
        $max = strlen($alphabet) - 1;
        $password = '';

        for ($index = 0; $index < $length; $index++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    public function count(): int
    {
        return $this->repository->count();
    }

    /** @return array<string, int> */
    public function countsByKind(): array
    {
        return $this->repository->countsByKind();
    }
}
