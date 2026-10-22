<?php

declare(strict_types=1);

namespace RegRuControl\Support;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;

/**
 * Fixed-window rate limiter backed by SQLite.
 *
 * Used for login attempts, API tokens and the outbound REG.RU quota. The
 * counter row is updated in a transaction, so concurrent FPM workers cannot
 * exceed the limit by racing.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $database,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Registers a hit and returns false when the limit is already exhausted.
     */
    public function hit(string $bucket, string $key, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $now = $this->clock->now()->getTimestamp();
        $windowStart = $now - ($now % max(1, $windowSeconds));

        return $this->database->transaction(function () use ($bucket, $key, $limit, $windowStart): bool {
            $current = $this->database->fetchOne(
                'SELECT window_start, counter FROM rate_limits WHERE bucket = :bucket AND key = :key',
                ['bucket' => $bucket, 'key' => $key],
            );

            if ($current === null) {
                $this->database->execute(
                    'INSERT INTO rate_limits (bucket, key, window_start, counter) VALUES (:bucket, :key, :start, 1)',
                    ['bucket' => $bucket, 'key' => $key, 'start' => $windowStart],
                );

                return $limit >= 1;
            }

            if ((int) $current['window_start'] !== $windowStart) {
                $this->database->execute(
                    'UPDATE rate_limits SET window_start = :start, counter = 1 WHERE bucket = :bucket AND key = :key',
                    ['bucket' => $bucket, 'key' => $key, 'start' => $windowStart],
                );

                return true;
            }

            $counter = (int) $current['counter'] + 1;

            if ($counter > $limit) {
                return false;
            }

            $this->database->execute(
                'UPDATE rate_limits SET counter = :counter WHERE bucket = :bucket AND key = :key',
                ['bucket' => $bucket, 'key' => $key, 'counter' => $counter],
            );

            return true;
        });
    }

    /**
     * Seconds until the current window ends (for `Retry-After`).
     */
    public function retryAfter(int $windowSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();
        $seconds = max(1, $windowSeconds);

        return max(1, $seconds - ($now % $seconds));
    }

    public function reset(string $bucket, string $key): void
    {
        $this->database->execute('DELETE FROM rate_limits WHERE bucket = :bucket AND key = :key', [
            'bucket' => $bucket,
            'key' => $key,
        ]);
    }
}
