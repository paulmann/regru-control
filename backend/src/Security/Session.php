<?php

declare(strict_types=1);

namespace RegRuControl\Security;

use RegRuControl\Kernel\Config;

/**
 * Session facade.
 *
 * Owns cookie parameters, the authenticated user id and the idle timeout.
 * Lazily starts the session so public endpoints never emit a cookie.
 */
final class Session
{
    private const USER_KEY = '_user_id';
    private const SEEN_KEY = '_last_seen';
    private const CREATED_KEY = '_created_at';

    private bool $started = false;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly Config $config,
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $savePath = APP_ROOT . '/var/sessions';

        if (!is_dir($savePath)) {
            @mkdir($savePath, 0770, true);
        }

        $this->storage->start($this->config->string('session.name', 'rg_session'), [
            'secure' => $this->config->bool('session.secure', true),
            'path' => '/',
            'save_path' => $savePath,
        ]);

        $this->started = true;

        if ($this->storage->get(self::CREATED_KEY) === null) {
            $this->storage->set(self::CREATED_KEY, time());
        }
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $this->storage->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $this->storage->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->start();
        $this->storage->remove($key);
    }

    public function regenerate(): void
    {
        $this->start();
        $this->storage->regenerate();
    }

    public function destroy(): void
    {
        if (!$this->started) {
            return;
        }

        $this->storage->destroy();
        $this->started = false;
    }

    public function id(): string
    {
        $this->start();

        return $this->storage->id();
    }

    public function login(int $userId): void
    {
        $this->regenerate();
        $this->set(self::USER_KEY, $userId);
        $this->set(self::SEEN_KEY, time());
    }

    public function userId(): ?int
    {
        $value = $this->get(self::USER_KEY);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    public function touch(): void
    {
        if ($this->userId() !== null) {
            $this->set(self::SEEN_KEY, time());
        }
    }

    public function lastSeen(): int
    {
        $value = $this->get(self::SEEN_KEY);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function isIdle(): bool
    {
        $lastSeen = $this->lastSeen();

        if ($lastSeen === 0) {
            return true;
        }

        return (time() - $lastSeen) > $this->config->int('session.idle', 1800);
    }
}
