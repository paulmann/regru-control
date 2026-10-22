<?php

declare(strict_types=1);

namespace RegRuControl\Security;

/**
 * Session storage abstraction: native PHP sessions in production, an in-memory
 * implementation in tests (no headers sent, no global state).
 */
interface SessionStorage
{
    /** @param array<string, mixed> $options */
    public function start(string $name, array $options): void;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function destroy(): void;

    public function id(): string;
}
