<?php

declare(strict_types=1);

namespace RegRuControl\Support;

use DateTimeImmutable;

final class Date
{
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function fromApi(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // REG.RU uses 0000-00-00 for services without a paid period (e.g. cloud VPS).
        if (str_starts_with($value, '0000-00-00')) {
            return null;
        }

        // REG.RU returns `YYYY-MM-DD` for services and full ISO for bills.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d', $timestamp);
    }

    public static function daysUntil(?string $date, DateTimeImmutable $today): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        $target = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        if ($target === false) {
            return null;
        }

        $from = $today->setTime(0, 0, 0);
        $diff = $from->diff($target);

        return $diff->invert === 1 ? -$diff->days : $diff->days;
    }

    public static function isPast(?string $date, DateTimeImmutable $today): bool
    {
        $days = self::daysUntil($date, $today);

        return $days !== null && $days < 0;
    }
}
