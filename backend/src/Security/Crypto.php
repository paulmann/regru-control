<?php

declare(strict_types=1);

namespace RegRuControl\Security;

use RegRuControl\Kernel\Config;
use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated encryption for secrets at rest (libsodium secretbox).
 *
 * Context binding: the encryption key is derived per context, so a ciphertext
 * stolen from one column cannot be replayed into another (e.g. a hosting
 * password into an API token row). Payload format: `v1.<base64 nonce|cipher>`.
 */
final class Crypto
{
    private const PREFIX = 'v1.';
    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    private readonly string $key;

    public function __construct(Config $config)
    {
        $encoded = $config->string('app.key');
        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'APP_KEY is missing or invalid. Generate one with: php backend/bin/regru key:generate',
            );
        }

        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function seal(#[SensitiveParameter] string $plaintext, string $context = ''): string
    {
        $key = $this->deriveKey($context);
        $nonce = random_bytes(self::NONCE_BYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function open(string $payload, string $context = ''): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('Unsupported encrypted payload format.');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= self::NONCE_BYTES) {
            throw new RuntimeException('Corrupted encrypted payload.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $cipher = substr($raw, self::NONCE_BYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->deriveKey($context));

        if ($plaintext === false) {
            throw new RuntimeException('Cannot decrypt payload: wrong key or tampered data.');
        }

        return $plaintext;
    }

    public function isSealed(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    private function deriveKey(string $context): string
    {
        return sodium_crypto_generichash($this->key . "\0" . $context, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
