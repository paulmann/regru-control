<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use Stringable;
use Throwable;

/**
 * Structured, dependency-free logger.
 *
 * One JSON object per line: machine-readable, greppable and safe for log
 * shippers. Sensitive values are redacted before they reach the disk.
 * Rotation is size based (5 MB x 5 files by default) and happens lazily, so the
 * class works without cron.
 */
final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'notice' => 25, 'warning' => 30, 'error' => 40, 'critical' => 50];

    public function __construct(
        private readonly Config $config,
        private readonly RequestContext $context,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function exception(Throwable $exception, array $context = []): void
    {
        $this->error($exception->getMessage(), $context + [
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }

        $threshold = self::LEVELS[$this->config->string('log.level', 'info')] ?? 20;

        if (self::LEVELS[$level] < $threshold) {
            return;
        }

        $record = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'request_id' => $this->context->id(),
            'message' => $message,
        ];

        if ($this->context->actor() !== null) {
            $record['actor'] = $this->context->actor();
        }

        if ($context !== []) {
            $record['context'] = Redactor::redact($context);
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($line === false) {
            return;
        }

        $this->write($line . "\n");
    }

    private function write(string $line): void
    {
        $path = $this->config->path('log.path');
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            error_log($line);

            return;
        }

        $this->rotateIfNeeded($path);
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function rotateIfNeeded(string $path): void
    {
        $limit = $this->config->int('log.rotate_bytes', 5_242_880);

        if ($limit <= 0 || !is_file($path)) {
            return;
        }

        clearstatcache(true, $path);

        if ((int) filesize($path) < $limit) {
            return;
        }

        $keep = max(1, $this->config->int('log.keep_files', 5));

        for ($index = $keep - 1; $index >= 1; $index--) {
            $from = $path . '.' . $index;
            $to = $path . '.' . ($index + 1);

            if (is_file($from)) {
                @rename($from, $to);
            }
        }

        @rename($path, $path . '.1');
    }
}
