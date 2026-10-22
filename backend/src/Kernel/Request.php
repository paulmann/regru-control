<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use RegRuControl\Support\Json;
use RuntimeException;

/**
 * Immutable HTTP request representation.
 *
 * Built from globals in production and from plain arrays in tests, so the same
 * code path is exercised everywhere.
 */
final class Request
{
    /** @var array<string, string> */
    private array $attributes = [];

    /** @var array<string, mixed>|null */
    private ?array $jsonBody = null;

    /**
     * @param array<string, string|array<string>> $query
     * @param array<string, string> $headers lower-case names
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        private readonly string $rawBody = '',
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? self::normalizePath($path) : '/';

        // Support deployment in a subdirectory (e.g. https://example.com/regru/):
        // strip the mount point derived from SCRIPT_NAME so routes stay absolute.
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($basePath !== '' && $basePath !== '.') {
            if ($path === $basePath) {
                $path = '/';
            } elseif (str_starts_with($path, $basePath . '/')) {
                $path = self::normalizePath(substr($path, strlen($basePath)));
            }
        }

        $headers = [];

        foreach (self::readAllHeaders() as $name => $value) {
            $headers[strtolower($name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        $cookies = [];

        foreach ($_COOKIE as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }

        $body = file_get_contents('php://input');

        return new self(
            $method,
            $path,
            $_GET,
            $body === false ? '' : $body,
            $headers,
            $cookies,
            $_SERVER,
        );
    }

    /**
     * Test/diagnostic factory.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public static function create(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        array $cookies = [],
        array $server = [],
    ): self {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $queryFromPath);
            $query = $query + $queryFromPath;
        }

        $rawBody = '';

        if ($body !== []) {
            $rawBody = Json::encode($body);
            $normalized['content-type'] ??= 'application/json';
        }

        $server += [
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $path,
        ];

        return new self(
            strtoupper($method),
            self::normalizePath($path),
            $query,
            $rawBody,
            $normalized,
            $cookies,
            $server,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim(preg_replace('~/{2,}~', '/', $path) ?? '/', '/');

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type', '')), 'application/json');
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        if ($this->rawBody === '') {
            return $this->jsonBody = [];
        }

        $decoded = json_decode($this->rawBody, true, 64);

        if (!is_array($decoded)) {
            throw new HttpException(400, 'INVALID_JSON', 'Тело запроса не является корректным JSON.');
        }

        return $this->jsonBody = $decoded;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        if ($this->isJson()) {
            return $this->json();
        }

        if ($this->rawBody === '') {
            return [];
        }

        parse_str($this->rawBody, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->body();

        if (array_key_exists($key, $body)) {
            return $body[$key];
        }

        return $this->query[$key] ?? $default;
    }

    public function queryString(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        if (is_array($value)) {
            return $default;
        }

        return $value === null ? $default : (string) $value;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->queryString($key);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public function boolInput(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = trim((string) (getenv('APP_TRUSTED_PROXIES') ?: ''));

        if ($trusted === '') {
            return $remote;
        }

        $trustedIps = array_filter(array_map('trim', explode(',', $trusted)));

        if (!in_array($remote, $trustedIps, true)) {
            return $remote;
        }

        $forwarded = $this->header('x-forwarded-for');

        if ($forwarded === null) {
            return $remote;
        }

        $chain = array_map('trim', explode(',', $forwarded));

        foreach (array_reverse($chain) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false && !in_array($candidate, $trustedIps, true)) {
                return $candidate;
            }
        }

        return $remote;
    }

    public function userAgent(): string
    {
        return (string) ($this->header('user-agent') ?? '');
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    /** @return array<string, string|array<string>> */
    private static function readAllHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }
}
