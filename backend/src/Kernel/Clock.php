<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time source abstraction. Tests freeze time instead of sleeping.
 */
interface Clock
{
    public function now(): DateTimeImmutable;

    public function timezone(): DateTimeZone;
}
