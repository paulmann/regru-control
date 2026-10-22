<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Database\Database;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\ValidationException;
use RegRuControl\Repository\SettingsRepository;

/**
 * Application settings and system information for the settings page.
 */
final readonly class SettingsService
{
    public function __construct(
        private SettingsRepository $settings,
        private RegRuAccountService $account,
        private Database $database,
        private Config $config,
        private Clock $clock,
        private AuditService $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $now = $this->clock->now();

        return [
            'app' => [
                'name' => $this->config->string('app.name'),
                'version' => $this->config->string('app.version'),
                'env' => $this->config->string('app.env'),
                'debug' => $this->config->isDebug(),
                'url' => $this->config->string('app.url'),
                'timezone' => $this->config->string('app.timezone'),
                'local_time' => $now->format('Y-m-d H:i:s'),
            ],
            'account' => $this->account->status(),
            'sync' => [
                'ttl' => $this->syncTtl(),
            ],
            'limits' => [
                'regru_per_hour' => $this->config->int('regru.rate_limit_per_hour', 1100),
                'api_per_minute' => $this->config->int('security.api_rate_limit', 600),
                'session_idle_seconds' => $this->config->int('session.idle', 1800),
            ],
            'system' => [
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
                'sqlite' => (string) $this->database->fetchValue('SELECT sqlite_version()'),
                'db_path' => $this->database->path(),
                'db_size' => $this->formatBytes($this->database->size()),
                'extensions' => $this->extensions(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $errors = [];

        if (array_key_exists('sync_ttl', $data)) {
            $ttl = (int) $data['sync_ttl'];

            if ($ttl < 300 || $ttl > 86400) {
                $errors['sync_ttl'] = 'Интервал синхронизации — от 300 до 86400 секунд.';
            } else {
                $this->settings->set('sync.ttl', (string) $ttl);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->audit->record('settings.update', '', ['keys' => array_keys($data)]);

        return $this->view();
    }

    public function syncTtl(): int
    {
        $stored = $this->settings->get('sync.ttl');

        return $stored !== null && is_numeric($stored) ? (int) $stored : $this->config->int('sync.ttl', 3600);
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        $required = ['curl', 'json', 'mbstring', 'openssl', 'pdo_sqlite', 'sodium'];
        $extensions = [];

        foreach ($required as $extension) {
            $extensions[] = $extension . (extension_loaded($extension) ? '' : ' (отсутствует!)');
        }

        return $extensions;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' Б';
        }

        $units = ['КБ', 'МБ', 'ГБ'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024) {
                return round($value, 1) . ' ' . $unit;
            }

            $value /= 1024;
        }

        return round($value, 1) . ' ТБ';
    }
}
