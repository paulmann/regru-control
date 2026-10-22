<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemClock implements Clock
{
    private DateTimeZone $timezone;

    public function __construct(Config $config)
    {
        $this->timezone = new DateTimeZone($config->string('app.timezone', 'UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }
}
