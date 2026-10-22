<?php

declare(strict_types=1);

namespace RegRuControl\RegRu;

/**
 * Transport-level failure: DNS, TLS, timeout, HTTP 5xx. Retried by the client.
 */
final class RegRuConnectionException extends RegRuException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus === 0 ? 504 : 502, ['http_status' => $httpStatus]);
    }
}
