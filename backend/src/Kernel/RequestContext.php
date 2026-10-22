<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * Per-request correlation context.
 *
 * The request id ends up in every log line and in the `X-Request-Id` response
 * header; the actor/ip/user-agent fields feed the audit log, so controllers do
 * not have to thread request metadata through every call.
 */
final class RequestContext
{
    private string $requestId;

    private ?string $actor = null;

    private string $actorType = 'system';

    private string $ip = '';

    private string $userAgent = '';

    public function __construct()
    {
        $this->requestId = self::generateId();
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function reset(): void
    {
        $this->requestId = self::generateId();
        $this->actor = null;
        $this->actorType = 'system';
        $this->ip = '';
        $this->userAgent = '';
    }

    public function id(): string
    {
        return $this->requestId;
    }

    public function actor(): ?string
    {
        return $this->actor;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function setActor(string $actor, string $type = 'ui', string $ip = '', string $userAgent = ''): void
    {
        $this->actor = $actor;
        $this->actorType = $type;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }
}
