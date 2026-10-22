<?php

declare(strict_types=1);

namespace RegRuControl\Security;

/**
 * API token generation and verification.
 *
 * Tokens look like `rg_<43 base64url chars>` and are stored only as SHA-256
 * hashes. The first characters (`rg_xxxxxxx`) are kept in clear as a display
 * prefix, which lets a user identify a token without revealing it.
 */
final class ApiTokenGenerator
{
    public const PREFIX = 'rg_';
    public const DISPLAY_LENGTH = 12;

    /**
     * @return array{plain: string, prefix: string, hash: string}
     */
    public function generate(): array
    {
        $plain = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [
            'plain' => $plain,
            'prefix' => substr($plain, 0, self::DISPLAY_LENGTH) . '…',
            'hash' => $this->hash($plain),
        ];
    }

    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        return hash_equals($hash, $this->hash($plain));
    }

    public function looksLikeToken(?string $value): bool
    {
        return is_string($value) && preg_match('/^rg_[A-Za-z0-9_-]{20,}$/', $value) === 1;
    }
}
