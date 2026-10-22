<?php

declare(strict_types=1);

namespace RegRuControl\Security;

/**
 * Double-submit CSRF protection bound to the server-side session.
 *
 * The SPA receives the token from `GET /api/v1/bootstrap` and sends it back in
 * the `X-CSRF-Token` header on every mutating request. Bearer-token API calls
 * are not susceptible to CSRF and skip this middleware.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);

        if (!is_string($token) || strlen($token) < 32) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $expected = $this->session->get(self::KEY);

        return is_string($expected) && hash_equals($expected, $token);
    }

    public function rotate(): void
    {
        $this->session->remove(self::KEY);
        $this->token();
    }
}
