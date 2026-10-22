<?php

declare(strict_types=1);

namespace RegRuControl\Security;

/**
 * In-memory session storage for tests and CLI simulations.
 */
final class ArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    private array $data = [];

    private string $id = 'test-session';

    public function __construct(array $initial = [])
    {
        $this->data = $initial;
    }

    public function start(string $name, array $options): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->id = 'test-session-' . bin2hex(random_bytes(4));
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
