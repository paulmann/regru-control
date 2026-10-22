<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use RegRuControl\Support\Json;

/**
 * Immutable HTTP response.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self($status, Json::encode($data), $headers + [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($status, $html, $headers + [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    public static function text(string $text, int $status = 200, array $headers = []): self
    {
        return new self($status, $text, $headers + [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /** @param array<string, string> $headers */
    public static function redirect(string $location, int $status = 302, array $headers = []): self
    {
        return new self($status, '', $headers + ['Location' => $location]);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, $this->headers + [$name => $value]);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->body, $this->headers + $headers);
    }

    public function withStatus(int $status): self
    {
        return new self($status, $this->body, $this->headers);
    }

    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;

            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        if ($this->method !== 'HEAD') {
            echo $this->body;
        }
    }

    private string $method = 'GET';

    public function forMethod(string $method): self
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);

        return $clone;
    }
}
