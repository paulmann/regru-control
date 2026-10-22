<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use RegRuControl\Support\Json;

/**
 * Expected (client-facing) HTTP error.
 *
 * `message` is safe to show to the user and follows the negotiated locale;
 * `code` is stable and machine-readable so the frontend and API consumers can
 * branch on it.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    /** @param array<string, mixed> $details */
    public static function badRequest(?string $message = null, array $details = []): self
    {
        return new self(400, 'BAD_REQUEST', $message ?? I18n::t('error.bad_request'), $details);
    }

    /** @param array<string, mixed> $details */
    public static function unauthorized(?string $message = null, array $details = []): self
    {
        return new self(401, 'UNAUTHORIZED', $message ?? I18n::t('error.unauthorized'), $details);
    }

    /** @param array<string, mixed> $details */
    public static function forbidden(?string $message = null, array $details = []): self
    {
        return new self(403, 'FORBIDDEN', $message ?? I18n::t('error.forbidden'), $details);
    }

    /** @param array<string, mixed> $details */
    public static function notFound(?string $message = null, array $details = []): self
    {
        return new self(404, 'NOT_FOUND', $message ?? I18n::t('error.not_found'), $details);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(?string $message = null, array $details = []): self
    {
        return new self(409, 'CONFLICT', $message ?? I18n::t('error.conflict'), $details);
    }

    /** @param array<string, mixed> $details */
    public static function tooManyRequests(?string $message = null, array $details = [], ?int $retryAfter = null): self
    {
        $headers = $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter];

        return new self(429, 'RATE_LIMITED', $message ?? I18n::t('error.rate_limited'), $details, $headers);
    }

    /** @param array<string, mixed> $details */
    public static function notImplemented(?string $message = null, array $details = []): self
    {
        return new self(501, 'NOT_IMPLEMENTED', $message ?? I18n::t('error.not_implemented'), $details);
    }

    /** @param array<string, mixed> $details */
    public function toResponse(?string $requestId = null): Response
    {
        $payload = [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
            ],
        ];

        if ($requestId !== null) {
            $payload['request_id'] = $requestId;
        }

        return new Response(
            $this->status,
            Json::encode($payload),
            ['Content-Type' => 'application/json; charset=utf-8'] + $this->headers,
        );
    }
}
