<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use InvalidArgumentException;

/**
 * Immutable, dot-addressable configuration.
 */
final readonly class Config
{
    /**
     * Environment variable => configuration key.
     *
     * @var array<string, string>
     */
    private const ENV_MAP = [
        'APP_NAME' => 'app.name',
        'APP_ENV' => 'app.env',
        'APP_DEBUG' => 'app.debug',
        'APP_URL' => 'app.url',
        'APP_TIMEZONE' => 'app.timezone',
        'APP_KEY' => 'app.key',
        'APP_TRUSTED_PROXIES' => 'app.trusted_proxies',
        'DB_PATH' => 'db.path',
        'LOG_PATH' => 'log.path',
        'LOG_LEVEL' => 'log.level',
        'SESSION_NAME' => 'session.name',
        'SESSION_IDLE' => 'session.idle',
        'SESSION_SECURE' => 'session.secure',
        'REGRU_BASE_URL' => 'regru.base_url',
        'REGRU_TIMEOUT' => 'regru.timeout',
        'REGRU_RETRIES' => 'regru.retries',
        'REGRU_RATE_LIMIT_PER_HOUR' => 'regru.rate_limit_per_hour',
        'SYNC_TTL' => 'sync.ttl',
    ];

    /**
     * Keys cast to a native type after reading from the environment.
     *
     * @var array<string, 'bool'|'int'>
     */
    private const CASTS = [
        'app.debug' => 'bool',
        'session.secure' => 'bool',
        'session.idle' => 'int',
        'regru.timeout' => 'int',
        'regru.retries' => 'int',
        'regru.rate_limit_per_hour' => 'int',
        'sync.ttl' => 'int',
        'db.busy_timeout' => 'int',
        'log.rotate_bytes' => 'int',
        'log.keep_files' => 'int',
        'security.login_attempts' => 'int',
        'security.login_window' => 'int',
        'security.api_rate_limit' => 'int',
        'security.api_rate_window' => 'int',
        'security.reveal_ttl' => 'int',
        'sync.details_batch' => 'int',
    ];

    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    /**
     * @param array<string, mixed> $defaults
     */
    public static function fromEnvironment(array $defaults): self
    {
        $values = $defaults;

        foreach (self::ENV_MAP as $envName => $key) {
            $raw = Env::get($envName);

            if ($raw === null || $raw === '') {
                continue;
            }

            $values = self::setDot($values, $key, self::cast($key, $raw));
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = self::getDot($this->values, $key);

        return $value ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return (bool) filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Absolute path for a path-like configuration key.
     */
    public function path(string $key): string
    {
        $value = $this->string($key);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Configuration key "%s" is empty.', $key));
        }

        if (str_starts_with($value, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $value) === 1) {
            return $value;
        }

        return APP_ROOT . '/' . ltrim($value, '/');
    }

    public function isProduction(): bool
    {
        return $this->string('app.env', 'production') === 'production';
    }

    public function isDebug(): bool
    {
        return $this->bool('app.debug', false);
    }

    /**
     * Returns a copy with deep-merged overrides — used by tests.
     *
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        return new self(array_replace_recursive($this->values, $overrides));
    }

    private static function cast(string $key, string $raw): mixed
    {
        return match (self::CASTS[$key] ?? null) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $raw,
            default => $raw,
        };
    }

    /** @param array<string, mixed> $values */
    private static function setDot(array $values, string $key, mixed $value): array
    {
        $cursor = &$values;

        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor)) {
                $cursor = [];
            }

            $cursor = &$cursor[$part];
        }

        $cursor = $value;

        return $values;
    }

    /** @param array<string, mixed> $values */
    private static function getDot(array $values, string $key): mixed
    {
        $cursor = $values;

        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }

            $cursor = $cursor[$part];
        }

        return $cursor;
    }
}
