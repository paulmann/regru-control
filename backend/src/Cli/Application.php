<?php

declare(strict_types=1);

namespace RegRuControl\Cli;

use RegRuControl\Database\Database;
use RegRuControl\Database\Migrator;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\AppLocale;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\I18n;
use RegRuControl\Kernel\Container;
use RegRuControl\Kernel\Logger;
use RegRuControl\Kernel\RequestContext;
use RegRuControl\Kernel\Translator;
use RegRuControl\Model\TokenScope;
use RegRuControl\RegRu\Endpoint\AccountApi;
use RegRuControl\RegRu\RegRuClient;
use RegRuControl\Security\Crypto;
use RegRuControl\Security\PasswordHasher;
use RegRuControl\Security\PasswordPolicy;
use RegRuControl\Service\DnsService;
use RegRuControl\Service\RegRuAccountService;
use RegRuControl\Service\SyncService;
use RegRuControl\Service\TokenService;
use RegRuControl\Support\Json;
use RegRuControl\Kernel\App;
use Throwable;

/**
 * Command line interface.
 *
 * Exit codes: 0 = success, 1 = failure, 2 = usage error.
 */
final class Application
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);
        $options = $this->parseOptions($args);
        $this->container->get(RequestContext::class)->setActor('cli', 'cli', '127.0.0.1', 'regru-cli');

        $locale = AppLocale::fromTag(isset($options['lang']) ? (string) $options['lang'] : null) ?? AppLocale::En;
        I18n::init(new Translator(APP_ROOT . '/backend/resources/locales', $locale));

        try {
            return match ($command) {
                'help', '--help', '-h' => $this->help(),
                'version', '--version' => $this->version(),
                'key:generate' => $this->keyGenerate(),
                'migrate' => $this->migrate(),
                'user:create' => $this->userCreate($options),
                'user:passwd' => $this->userPassword($options),
                'account:set' => $this->accountSet($options),
                'account:test' => $this->accountTest(),
                'sync' => $this->sync($options),
                'doctor' => $this->doctor($options),
                'token:create' => $this->tokenCreate($options),
                'token:list' => $this->tokenList($options),
                'token:revoke' => $this->tokenRevoke($options),
                'dns:list' => $this->dnsList($options),
                'dns:add' => $this->dnsAdd($options),
                'dns:rm' => $this->dnsRemove($options),
                'api:call' => $this->apiCall($options),
                'backup' => $this->backup($options),
                'routes' => $this->routes($options),
                'serve' => $this->serve($options),
                'test' => $this->tests(),
                default => $this->unknown($command),
            };
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            if (($options['verbose'] ?? false) === true) {
                $this->line($exception->getTraceAsString());
            }

            return 1;
        }
    }

    private function help(): int
    {
        $this->line(<<<TXT
        REG.RU Control — CLI

        Использование: regru <команда> [опции]

        Установка и доступ
          key:generate                 Сгенерировать APP_KEY в .env
          migrate                      Применить миграции SQLite
          user:create                  Создать пользователя панели (--login, [--password], [--role=admin])
          user:passwd                  Сменить пароль (--login, [--password])
          account:set                  Сохранить доступы API REG.RU (--login, --password, [--currency=RUR], [--no-test])
          account:test                 Проверить доступ к API REG.RU
          doctor                       Диагностика окружения ([--online], [--json])

        Данные
          sync                         Синхронизация ([--scope=all|services|details|dns], [--json])
          dns:list                     Записи зоны (--domain)
          dns:add                      Добавить запись (--domain, --sub, --type, --content, [--prio])
          dns:rm                       Удалить запись (--domain, --sub, --type, --content, [--prio])
          api:call                     Произвольный вызов API (--command, [--data='{"json":true}'])

        Интеграции
          token:create                 Создать API-токен (--name, [--scopes=read,secrets:read], [--ttl-days=365])
          token:list                   Список токенов
          token:revoke                 Отозвать токен (--id)

        Обслуживание
          backup                       Резервная копия SQLite (VACUUM INTO)
          routes                       Показать маршруты HTTP API
          serve                        Локальный сервер ([--host=127.0.0.1], [--port=8099])
          test                         Запустить тесты
          version                      Версия приложения

        Глобальные опции: --json (машинный вывод), --verbose (трассировка ошибок)
        TXT);

        return 0;
    }

    private function version(): int
    {
        $this->line(sprintf('REG.RU Control %s (PHP %s)', $this->config->string('app.version'), PHP_VERSION));

        return 0;
    }

    private function keyGenerate(): int
    {
        $envPath = APP_ROOT . '/.env';
        $example = APP_ROOT . '/.env.example';

        if (!is_file($envPath)) {
            if (!is_file($example)) {
                $this->error('.env.example не найден.');

                return 1;
            }

            copy($example, $envPath);
        }

        $content = (string) file_get_contents($envPath);
        $key = Crypto::generateKey();

        if (preg_match('/^APP_KEY=.*$/m', $content) === 1) {
            $content = (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content);
        } else {
            $content = rtrim($content, "\n") . "\nAPP_KEY=" . $key . "\n";
        }

        file_put_contents($envPath, $content);
        @chmod($envPath, 0600);

        $this->ok('APP_KEY сгенерирован и записан в .env (права 0600).');

        return 0;
    }

    private function migrate(): int
    {
        $migrator = new Migrator(
            $this->container->get(Database::class),
            $this->container->get(Logger::class),
            APP_ROOT . '/backend/migrations',
        );

        $applied = $migrator->migrate();

        if ($applied === []) {
            $this->info('Все миграции уже применены.');
        } else {
            foreach ($applied as $version) {
                $this->ok('Применена миграция ' . $version);
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function userCreate(array $options): int
    {
        $login = (string) ($options['login'] ?? '');
        $password = (string) ($options['password'] ?? '');
        $role = (string) ($options['role'] ?? 'admin');

        if (($options['password-stdin'] ?? false) === true) {
            $password = trim((string) fgets(STDIN));
        }

        if ($login === '') {
            $this->error('Укажите --login.');

            return 2;
        }

        $users = $this->container->get(\RegRuControl\Repository\UserRepository::class);

        if ($users->findAuthRow($login) !== null) {
            $this->error('Пользователь уже существует. Для смены пароля используйте user:passwd.');

            return 1;
        }

        $policy = new PasswordPolicy();

        if ($password === '') {
            $password = (new \RegRuControl\Service\CredentialService(
                $this->container->get(\RegRuControl\Repository\CredentialRepository::class),
                $this->container->get(Crypto::class),
                $this->container->get(\RegRuControl\Service\AuditService::class),
                $this->container->get(\RegRuControl\Support\RateLimiter::class),
                $this->container->get(Clock::class),
            ))->generatePassword(20);
            $this->info('Сгенерирован пароль: ' . $password);
        }

        $errors = $policy->validate($password);

        if ($errors !== []) {
            $this->error(implode(' ', $errors));

            return 1;
        }

        $id = $users->create($login, (new PasswordHasher())->hash($password), $role, (string) ($options['name'] ?? ''));

        $this->ok(sprintf('Пользователь %s создан (id=%d, роль=%s).', $login, $id, $role));

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function userPassword(array $options): int
    {
        $login = (string) ($options['login'] ?? '');
        $password = (string) ($options['password'] ?? '');

        if (($options['password-stdin'] ?? false) === true) {
            $password = trim((string) fgets(STDIN));
        }

        if ($login === '' || $password === '') {
            $this->error('Укажите --login и --password.');

            return 2;
        }

        $errors = (new PasswordPolicy())->validate($password);

        if ($errors !== []) {
            $this->error(implode(' ', $errors));

            return 1;
        }

        $users = $this->container->get(\RegRuControl\Repository\UserRepository::class);
        $row = $users->findAuthRow($login);

        if ($row === null) {
            $this->error('Пользователь не найден.');

            return 1;
        }

        $users->updatePassword((int) $row['id'], (new PasswordHasher())->hash($password));
        $this->ok('Пароль обновлён.');

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function accountSet(array $options): int
    {
        $login = (string) ($options['login'] ?? '');
        $password = (string) ($options['password'] ?? '');
        $currency = (string) ($options['currency'] ?? 'RUR');

        if (($options['password-stdin'] ?? false) === true) {
            $password = trim((string) fgets(STDIN));
        }

        if ($login === '' || $password === '') {
            $this->error('Укажите --login и --password (пароль API REG.RU).');

            return 2;
        }

        $result = $this->container->get(RegRuAccountService::class)->save($login, $password, $currency);

        if ($result['ok']) {
            $balance = $result['balance'] ?? [];
            $this->ok(sprintf(
                'Доступ к REG.RU API подтверждён. Баланс: %s %s.',
                number_format((float) ($balance['prepay'] ?? 0), 2, '.', ' '),
                (string) ($balance['currency'] ?? $currency),
            ));

            return 0;
        }

        $this->error('Доступы сохранены, но проверка не прошла: ' . ($result['error'] ?? 'неизвестная ошибка'));

        return 1;
    }

    private function accountTest(): int
    {
        $result = $this->container->get(RegRuAccountService::class)->verify();

        if ($result['ok']) {
            $this->ok('API REG.RU доступен.');

            return 0;
        }

        $this->error($result['error'] ?? 'Проверка не прошла.');

        return 1;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sync(array $options): int
    {
        $scope = (string) ($options['scope'] ?? 'all');
        $result = $this->container->get(SyncService::class)->run($scope, 'cli');

        if (($options['json'] ?? false) === true) {
            $this->line(Json::pretty($result));

            return $result['status'] === 'failed' ? 1 : 0;
        }

        $this->ok(sprintf('Синхронизация завершена: %s', $result['status']));

        foreach ($result['stats'] as $stage => $stats) {
            $this->line(sprintf('  %s: %s', $stage, Json::encode($stats)));
        }

        foreach ($result['errors'] as $error) {
            $this->warn('  ! ' . $error);
        }

        return $result['status'] === 'failed' ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function doctor(array $options): int
    {
        $checks = [];
        $failures = 0;

        $checks[] = ['PHP >= 8.5', version_compare(PHP_VERSION, '8.5.0', '>='), PHP_VERSION];
        $missing = array_filter(
            ['curl', 'json', 'mbstring', 'openssl', 'pdo_sqlite', 'sodium'],
            static fn (string $extension): bool => !extension_loaded($extension),
        );
        $checks[] = ['Расширения PHP', $missing === [], $missing === [] ? 'все на месте' : 'нет: ' . implode(', ', $missing)];

        try {
            new Crypto($this->config);
            $checks[] = ['APP_KEY', true, 'корректный'];
        } catch (Throwable $exception) {
            $checks[] = ['APP_KEY', false, $exception->getMessage()];
        }

        foreach (['var', 'var/log', 'var/sessions'] as $directory) {
            $path = APP_ROOT . '/' . $directory;

            if (!is_dir($path)) {
                @mkdir($path, 0770, true);
            }

            $checks[] = ['Каталог ' . $directory, is_dir($path) && is_writable($path), $path];
        }

        $checks[] = ['Фронтенд', is_file(APP_ROOT . '/frontend/index.html'), APP_ROOT . '/frontend/index.html'];

        try {
            $database = $this->container->get(Database::class);
            $database->fetchValue('SELECT 1');
            $checks[] = ['База SQLite', true, $database->path() . ' (' . $database->size() . ' Б)'];

            $migrator = new Migrator($database, $this->container->get(Logger::class), APP_ROOT . '/backend/migrations');
            $status = $migrator->status();
            $pending = array_keys(array_filter($status, static fn (bool $applied): bool => !$applied));
            $checks[] = ['Миграции', $pending === [], $pending === [] ? 'все применены' : 'ожидают: ' . implode(', ', $pending)];

            $users = (int) $database->fetchValue('SELECT COUNT(*) FROM users');
            $checks[] = ['Пользователи панели', $users > 0, (string) $users];
        } catch (Throwable $exception) {
            $checks[] = ['База SQLite', false, $exception->getMessage()];
        }

        $account = $this->container->get(RegRuAccountService::class)->status();
        $checks[] = ['Аккаунт REG.RU', (bool) $account['configured'], (string) ($account['username'] ?? 'не настроен')];

        if (($options['online'] ?? false) === true && $account['configured']) {
            $result = $this->container->get(RegRuAccountService::class)->verify();
            $checks[] = ['API REG.RU (online)', (bool) $result['ok'], (string) ($result['ok'] ? 'доступен' : ($result['error'] ?? ''))];
        }

        if (($options['json'] ?? false) === true) {
            $this->line(Json::pretty(array_map(
                static fn (array $check): array => ['check' => $check[0], 'ok' => $check[1], 'detail' => $check[2]],
                $checks,
            )));
        } else {
            foreach ($checks as [$name, $ok, $detail]) {
                $this->line(sprintf('%s %-28s %s', $ok ? '  ✓' : '  ✗', $name, $detail));

                if (!$ok) {
                    $failures++;
                }
            }

            $this->line('');
        }

        return $failures === 0 ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function tokenCreate(array $options): int
    {
        $name = (string) ($options['name'] ?? '');
        $scopes = (string) ($options['scopes'] ?? 'read');
        $ttl = isset($options['ttl-days']) ? (int) $options['ttl-days'] : null;

        if ($name === '') {
            $this->error('Укажите --name.');

            return 2;
        }

        $result = $this->container->get(TokenService::class)->create(
            $name,
            array_map('trim', explode(',', $scopes)),
            $ttl,
            null,
        );

        if (($options['json'] ?? false) === true) {
            $this->line(Json::pretty(['token' => $result['token']->toArray(), 'plain' => $result['plain']]));

            return 0;
        }

        $this->ok('Токен создан (показывается один раз):');
        $this->line('');
        $this->line('  ' . $result['plain']);
        $this->line('');
        $this->info('Права: ' . implode(', ', $result['token']->scopeValues()));

        if ($ttl !== null) {
            $this->info('Действует до: ' . (string) $result['token']->expiresAt);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function tokenList(array $options): int
    {
        $tokens = $this->container->get(TokenService::class)->all();

        if (($options['json'] ?? false) === true) {
            $this->line(Json::pretty(array_map(static fn ($token): array => $token->toArray(), $tokens)));

            return 0;
        }

        if ($tokens === []) {
            $this->info('Токенов нет.');

            return 0;
        }

        foreach ($tokens as $token) {
            $this->line(sprintf(
                '#%-3d %-24s %-14s %s%s',
                $token->id,
                $token->name,
                $token->prefix,
                implode(',', $token->scopeValues()),
                $token->isRevoked() ? ' [отозван]' : '',
            ));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function tokenRevoke(array $options): int
    {
        $id = (int) ($options['id'] ?? 0);

        if ($id <= 0) {
            $this->error('Укажите --id.');

            return 2;
        }

        if (!$this->container->get(TokenService::class)->revoke($id)) {
            $this->error('Токен не найден или уже отозван.');

            return 1;
        }

        $this->ok('Токен отозван.');

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dnsList(array $options): int
    {
        $domain = (string) ($options['domain'] ?? '');

        if ($domain === '') {
            $this->error('Укажите --domain.');

            return 2;
        }

        $listing = $this->container->get(DnsService::class)->listing($domain, (bool) ($options['refresh'] ?? false));

        if (($options['json'] ?? false) === true) {
            $this->line(Json::pretty($listing));

            return 0;
        }

        foreach ($listing['records'] as $record) {
            $this->line(sprintf('%-28s %-6s %-6d %s', $record->subname, $record->rectype, $record->prio, $record->content));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dnsAdd(array $options): int
    {
        foreach (['domain', 'sub', 'type', 'content'] as $required) {
            if (!isset($options[$required]) || $options[$required] === '') {
                $this->error('Укажите --' . $required . '.');

                return 2;
            }
        }

        $result = $this->container->get(DnsService::class)->add(
            (string) $options['domain'],
            (string) $options['sub'],
            (string) $options['type'],
            (string) $options['content'],
            (int) ($options['prio'] ?? 0),
        );

        $this->ok('Запись добавлена. Записей в зоне: ' . count($result['records']));

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dnsRemove(array $options): int
    {
        foreach (['domain', 'sub', 'type'] as $required) {
            if (!isset($options[$required]) || $options[$required] === '') {
                $this->error('Укажите --' . $required . '.');

                return 2;
            }
        }

        $result = $this->container->get(DnsService::class)->delete((string) $options['domain'], [
            'subname' => (string) $options['sub'],
            'rectype' => (string) $options['type'],
            'content' => (string) ($options['content'] ?? ''),
            'prio' => (int) ($options['prio'] ?? 0),
        ]);

        $this->ok('Запись удалена. Записей в зоне: ' . count($result['records']));

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function apiCall(array $options): int
    {
        $command = (string) ($options['command'] ?? '');
        $data = (string) ($options['data'] ?? '{}');

        if ($command === '') {
            $this->error('Укажите --command.');

            return 2;
        }

        $input = Json::tryDecode($data);

        if ($input === null) {
            $this->error('--data должен быть корректным JSON.');

            return 2;
        }

        $response = $this->container->get(RegRuClient::class)->call($command, $input);
        $this->line(Json::pretty($response->raw));

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function backup(array $options): int
    {
        $database = $this->container->get(Database::class);
        $directory = APP_ROOT . '/var/backups';

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            $this->error('Не удалось создать каталог ' . $directory);

            return 1;
        }

        $file = $directory . '/regru-' . gmdate('Ymd-His') . '.sqlite';
        $database->pdo()->exec('VACUUM INTO ' . $database->pdo()->quote($file));
        @chmod($file, 0600);
        $this->ok('Резервная копия: ' . $file . ' (' . filesize($file) . ' Б)');

        $files = glob($directory . '/regru-*.sqlite') ?: [];
        sort($files);

        foreach (array_slice($files, 0, max(0, count($files) - 14)) as $old) {
            @unlink($old);
            $this->info('Удалена старая копия: ' . basename($old));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function routes(array $options): int
    {
        foreach ($this->container->get(App::class)->routes() as $route) {
            $this->line($route);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function serve(array $options): int
    {
        $host = (string) ($options['host'] ?? '127.0.0.1');
        $port = (int) ($options['port'] ?? 8099);
        $docroot = APP_ROOT . '/public';
        $router = $docroot . '/index.php';

        $this->info(sprintf('Сервер: http://%s:%d (Ctrl+C для остановки)', $host, $port));

        $command = sprintf(
            '%s -d display_errors=1 -S %s:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            $host,
            $port,
            escapeshellarg($docroot),
            escapeshellarg($router),
        );

        passthru($command, $code);

        return $code;
    }

    private function tests(): int
    {
        $runner = APP_ROOT . '/backend/tests/run.php';

        if (!is_file($runner)) {
            $this->error('Тесты не найдены.');

            return 1;
        }

        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner), $code);

        return $code;
    }

    private function unknown(string $command): int
    {
        $this->error('Неизвестная команда: ' . $command);
        $this->line('Справка: regru help');

        return 2;
    }

    /**
     * @param list<string> $args
     * @return array<string, mixed>
     */
    private function parseOptions(array $args): array
    {
        $options = [];

        for ($index = 0; $index < count($args); $index++) {
            $arg = $args[$index];

            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $arg = substr($arg, 2);

            if (str_contains($arg, '=')) {
                [$name, $value] = explode('=', $arg, 2);
                $options[$name] = $value;

                continue;
            }

            $next = $args[$index + 1] ?? null;

            if ($next !== null && !str_starts_with($next, '--')) {
                $options[$arg] = $next;
                $index++;

                continue;
            }

            $options[$arg] = true;
        }

        return $options;
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    private function info(string $message): void
    {
        $this->line('  ' . $message);
    }

    private function ok(string $message): void
    {
        $this->line('✓ ' . $message);
    }

    private function warn(string $message): void
    {
        $this->line('! ' . $message);
    }

    private function error(string $message): void
    {
        fwrite(STDERR, '✗ ' . $message . "\n");
    }
}
