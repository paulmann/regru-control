<?php

/**
 * Dependency-free test runner.
 *
 *   php backend/tests/run.php
 *
 * Exit code 0 = all tests passed, 1 = failures.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

require APP_ROOT . '/backend/autoload.php';

use RegRuControl\Kernel\App;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\Container;
use RegRuControl\Kernel\Env;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\ValidationException;
use RegRuControl\Model\DnsRecord;
use RegRuControl\Model\RecordType;
use RegRuControl\RegRu\Endpoint\ZoneApi;
use RegRuControl\RegRu\RegRuCommandCatalog;
use RegRuControl\RegRu\RegRuException;
use RegRuControl\Repository\AuditRepository;
use RegRuControl\Repository\UserRepository;
use RegRuControl\Security\ApiTokenGenerator;
use RegRuControl\Security\Crypto;
use RegRuControl\Security\Csrf;
use RegRuControl\Security\PasswordPolicy;
use RegRuControl\Service\CredentialService;
use RegRuControl\Service\DnsService;
use RegRuControl\Service\DomainService;
use RegRuControl\Service\RegRuAccountService;
use RegRuControl\Service\SettingsService;
use RegRuControl\Service\SyncService;
use RegRuControl\Support\RateLimiter;
use RegRuControl\Support\Validator;
use RegRuControl\Tests\Fakes\FakeAccountApi;
use RegRuControl\Tests\Fixtures\TestController;
use RegRuControl\Tests\TestKernel;

final class Runner
{
    /** @var list<array{0: string, 1: callable}> */
    private array $tests = [];

    public function add(string $name, callable $test): void
    {
        $this->tests[] = [$name, $test];
    }

    public function run(): int
    {
        $passed = 0;
        $failed = [];
        $started = microtime(true);

        foreach ($this->tests as [$name, $test]) {
            try {
                $test();
                $passed++;
                fwrite(STDOUT, "  ✓ {$name}\n");
            } catch (Throwable $exception) {
                $failed[] = [$name, $exception];
                fwrite(STDOUT, "  ✗ {$name}\n");
            }
        }

        $duration = round(microtime(true) - $started, 2);

        foreach ($failed as [$name, $exception]) {
            fwrite(STDERR, "\n--- {$name}\n" . $exception->getMessage() . "\n");
        }

        fwrite(STDOUT, sprintf("\n%d passed, %d failed in %ss\n", $passed, count($failed), $duration));

        return $failed === [] ? 0 : 1;
    }
}

/** @var Runner $runner */
$runner = new Runner();

function test(string $name, callable $test): void
{
    global $runner;
    $runner->add($name, $test);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            "Expected %s, got %s",
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrue(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertFalse(bool $condition, string $message = 'Expected false'): void
{
    assertTrue(!$condition, $message);
}

function assertNull(mixed $value, string $message = 'Expected null'): void
{
    assertSame(null, $value, $message);
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message !== '' ? $message : sprintf('"%s" not found in "%s"', $needle, $haystack));
    }
}

/**
 * @param class-string<Throwable> $class
 */
function assertThrows(string $class, callable $callback, ?string $messagePart = null): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof $class) {
            throw new RuntimeException(sprintf('Expected %s, got %s: %s', $class, $exception::class, $exception->getMessage()));
        }

        if ($messagePart !== null && !str_contains($exception->getMessage(), $messagePart)) {
            throw new RuntimeException(sprintf('Message "%s" does not contain "%s"', $exception->getMessage(), $messagePart));
        }

        return $exception;
    }

    throw new RuntimeException(sprintf('Expected %s, nothing thrown', $class));
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 * @param array<string, mixed> $query
 * @return array{status: int, json: array<string, mixed>, headers: array<string, string>, raw: string}
 */
function http(Container $container, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    /** @var App $app */
    $app = $container->get(App::class);
    $request = Request::create($method, $path, $query, $body, $headers);
    $response = $app->handle($request);

    return [
        'status' => $response->status,
        'json' => $response->body === '' ? [] : (json_decode($response->body, true) ?? []),
        'headers' => array_change_key_case($response->headers, CASE_LOWER),
        'raw' => $response->body,
    ];
}

function login(Container $container, string $login = 'admin', string $password = 'Test-Admin-Passw0rd!'): void
{
    $csrf = $container->get(Csrf::class)->token();
    $response = http($container, 'POST', '/api/v1/auth/login', [
        'login' => $login,
        'password' => $password,
    ], ['X-CSRF-Token' => $csrf]);

    assertSame(200, $response['status'], 'Login failed: ' . $response['raw']);
}

function createAdmin(Container $container, string $login = 'admin', string $password = 'Test-Admin-Passw0rd!'): void
{
    /** @var UserRepository $users */
    $users = $container->get(UserRepository::class);
    $users->create($login, password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]));
}

function csrfHeaders(Container $container): array
{
    return ['X-CSRF-Token' => $container->get(Csrf::class)->token()];
}

// ---------------------------------------------------------------------------
// Unit tests
// ---------------------------------------------------------------------------

test('Config: dot access, casts and with()', static function (): void {
    $config = (new Config(['app' => ['debug' => false, 'name' => 'X'], 'db' => ['path' => 'var/x.sqlite']]))
        ->with(['app' => ['debug' => true]]);

    assertSame('X', $config->string('app.name'));
    assertTrue($config->bool('app.debug'));
    assertSame('var/x.sqlite', $config->string('db.path'));
    assertSame('fallback', $config->string('app.missing', 'fallback'));
    assertTrue(str_ends_with($config->path('db.path'), 'var/x.sqlite'));
});

test('Env: parses quoted values and does not override real environment', static function (): void {
    $file = APP_ROOT . '/var/test-' . bin2hex(random_bytes(4)) . '.env';
    file_put_contents($file, "FOO=bar\nQUOTED=\"hello world\"\nEXPORTED=export ignored\nHASH=value # comment\n");
    Env::load($file);
    assertSame('bar', Env::get('FOO'));
    assertSame('hello world', Env::get('QUOTED'));
    assertSame('value', Env::get('HASH'));
    @unlink($file);
});

test('Crypto: roundtrip, context binding, tamper detection', static function (): void {
    $config = (new Config(['app' => ['key' => base64_encode(random_bytes(32))]]));
    $crypto = new Crypto($config);

    $sealed = $crypto->seal('super-secret', 'credential:1');
    assertTrue($crypto->isSealed($sealed));
    assertSame('super-secret', $crypto->open($sealed, 'credential:1'), 'Roundtrip failed');
    assertThrows(RuntimeException::class, static fn () => $crypto->open($sealed, 'credential:2'));
    assertThrows(RuntimeException::class, static fn () => $crypto->open(substr($sealed, 0, -4) . 'AAAA', 'credential:1'));
    assertFalse($crypto->isSealed('plain'));
});

test('Crypto: rejects missing or malformed APP_KEY', static function (): void {
    assertThrows(RuntimeException::class, static fn () => new Crypto(new Config(['app' => ['key' => '']])));
    assertThrows(RuntimeException::class, static fn () => new Crypto(new Config(['app' => ['key' => 'short']])));
});

test('PasswordPolicy: accepts strong, rejects weak', static function (): void {
    $policy = new PasswordPolicy();
    assertSame([], $policy->validate('Correct-Horse-2026'));
    assertTrue($policy->validate('short') !== []);
    assertTrue($policy->validate('password') !== []);
    assertTrue($policy->validate('alllowercaseonly') !== []);
});

test('Validator: collects field errors and throws 422', static function (): void {
    $validator = new Validator(['login' => '', 'age' => 'x', 'email' => 'bad']);
    $validator->requiredString('login', 'Логин');
    $validator->requiredInt('age', 'Возраст');
    $validator->requiredEmail('email', 'Почта');

    try {
        $validator->throwIfInvalid();
        throw new RuntimeException('Expected ValidationException');
    } catch (ValidationException $exception) {
        assertSame(422, $exception->status);
        assertTrue(isset($exception->errors()['login']));
        assertTrue(isset($exception->errors()['age']));
        assertTrue(isset($exception->errors()['email']));
    }
});

test('ApiTokenGenerator: format, hash and verification', static function (): void {
    $generator = new ApiTokenGenerator();
    $token = $generator->generate();

    assertTrue(str_starts_with($token['plain'], 'rg_'));
    assertTrue($generator->looksLikeToken($token['plain']));
    assertTrue($generator->verify($token['plain'], $token['hash']));
    assertFalse($generator->verify($token['plain'] . 'x', $token['hash']));
    assertFalse($generator->looksLikeToken('nope'));
});

test('ZoneApi::addPayload maps every supported record type', static function (): void {
    $cases = [
        ['A', '203.0.113.10', 0, 'add_alias'],
        ['AAAA', '2001:db8::1', 0, 'add_aaaa'],
        ['CNAME', 'example.com.', 0, 'add_cname'],
        ['MX', 'mail.example.com.', 10, 'add_mx'],
        ['TXT', 'v=spf1 -all', 0, 'add_txt'],
        ['NS', 'ns1.example.com.', 0, 'add_ns'],
        ['SRV', '10 5060 sip.example.com.', 5, 'add_srv'],
        ['CAA', '0 issue letsencrypt.org', 0, 'add_caa'],
    ];

    foreach ($cases as [$type, $content, $prio, $action]) {
        $payload = ZoneApi::addPayload(new DnsRecord(null, 'example.com', 'www', $type, $content, $prio));
        assertSame($action, $payload['action'], 'Wrong action for ' . $type);
        assertSame('www', $payload['subdomain']);
    }

    assertThrows(InvalidArgumentException::class, static fn () => ZoneApi::addPayload(
        new DnsRecord(null, 'example.com', 'sip.udp', 'SRV', 'bad', 0),
    ));
});

test('DnsRecord: normalises API types and builds removal payload', static function (): void {
    $record = DnsRecord::fromApi(['subname' => '@', 'rectype' => 'mx', 'content' => 'mail.example.com', 'prio' => '10'], 'example.com');
    assertSame('MX', $record->rectype);
    assertSame(10, $record->prio);
    assertSame('@|MX|10|mail.example.com', $record->key());
    assertSame(10, $record->toRemovalPayload()['priority']);
});

test('RateLimiter: allows up to the limit and blocks after', static function (): void {
    $container = TestKernel::boot();
    $limiter = new RateLimiter($container->get(\RegRuControl\Database\Database::class), $container->get(\RegRuControl\Kernel\Clock::class));

    assertTrue($limiter->hit('test', 'key', 2, 60));
    assertTrue($limiter->hit('test', 'key', 2, 60));
    assertFalse($limiter->hit('test', 'key', 2, 60));
    assertTrue($limiter->retryAfter(60) > 0);
});

test('Router: dispatches params, 404 and 405', static function (): void {
    $container = TestKernel::boot();
    $router = new \RegRuControl\Kernel\Router($container);
    $router->registerController(TestController::class);

    $response = $router->dispatch(Request::create('GET', '/test/hello'));
    assertSame(200, $response->status);

    $response = $router->dispatch(Request::create('GET', '/test/user/42'));
    assertSame(200, $response->status);
    assertSame(42, json_decode($response->body, true)['id']);

    assertThrows(HttpException::class, static fn () => $router->dispatch(Request::create('GET', '/nope')));
    $exception = assertThrows(HttpException::class, static fn () => $router->dispatch(Request::create('PUT', '/test/hello')));
    assertSame(405, $exception->status);
    assertSame('GET, POST', $exception->headers['Allow'] ?? '');
});

test('Date: normalises REG.RU dates and treats 0000-00-00 as empty', static function (): void {
    assertSame('2027-10-06', \RegRuControl\Support\Date::fromApi('2027-10-06'));
    assertNull(\RegRuControl\Support\Date::fromApi('0000-00-00'));
    assertNull(\RegRuControl\Support\Date::fromApi(''));
    $today = new \DateTimeImmutable('2026-10-22');
    assertSame(10, \RegRuControl\Support\Date::daysUntil('2026-11-01', $today));
    assertSame(-2, \RegRuControl\Support\Date::daysUntil('2026-10-20', $today));
});
test('Redactor: hides secrets recursively', static function (): void {
    $clean = \RegRuControl\Kernel\Redactor::redact([
        'login' => 'admin',
        'password' => 'secret',
        'nested' => ['api_key' => 'k', 'token' => 't'],
    ]);

    assertSame('admin', $clean['login']);
    assertSame('***', $clean['password']);
    assertSame('***', $clean['nested']['api_key']);
    assertSame('***', $clean['nested']['token']);
});

test('Security headers: HTML gets a strict CSP', static function (): void {
    $container = TestKernel::boot();
    $response = http($container, 'GET', '/');
    $headers = $response['headers'];
    assertTrue(isset($headers['content-security-policy']));
    assertContains("default-src 'self'", $headers['content-security-policy']);
    assertSame('nosniff', $headers['x-content-type-options'] ?? '');
});

// ---------------------------------------------------------------------------
// Integration tests: HTTP API
// ---------------------------------------------------------------------------

test('Health endpoint is public and reports status', static function (): void {
    $response = http(TestKernel::boot(), 'GET', '/api/v1/health');
    assertSame(200, $response['status']);
    assertSame('ok', $response['json']['data']['status']);
});

test('Bootstrap: anonymous gets CSRF token and no user', static function (): void {
    $response = http(TestKernel::boot(), 'GET', '/api/v1/bootstrap');
    assertSame(200, $response['status']);
    assertFalse($response['json']['data']['authenticated']);
    assertTrue(strlen($response['json']['data']['csrf_token']) >= 32);
    assertTrue($response['json']['data']['regru_commands'] > 100);
});

test('CSRF: unsafe request without token is rejected', static function (): void {
    $response = http(TestKernel::boot(), 'POST', '/api/v1/auth/login', ['login' => 'x', 'password' => 'y']);
    assertSame(403, $response['status']);
    assertSame('CSRF_FAILED', $response['json']['error']['code']);
});

test('Login: wrong password 401, correct password 200, me works', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);

    $response = http($container, 'POST', '/api/v1/auth/login', ['login' => 'admin', 'password' => 'wrong'], csrfHeaders($container));
    assertSame(401, $response['status']);

    login($container);

    $me = http($container, 'GET', '/api/v1/auth/me');
    assertSame(200, $me['status']);
    assertSame('admin', $me['json']['data']['user']['login']);
});

test('Authorization: protected endpoint without credentials is 401', static function (): void {
    $response = http(TestKernel::boot(), 'GET', '/api/v1/domains');
    assertSame(401, $response['status']);
});

test('Sync: inventory, details, DNS snapshot from REG.RU', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);

    $response = http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));
    assertSame(200, $response['status'], $response['raw']);
    assertSame('ok', $response['json']['data']['status']);
    assertSame(4, $response['json']['data']['stats']['inventory']['services']);
    assertSame(2, $response['json']['data']['stats']['inventory']['domains']);
    assertSame(1, $response['json']['data']['stats']['inventory']['hostings']);

    $domains = http($container, 'GET', '/api/v1/domains');
    assertSame(2, $domains['json']['meta']['total']);

    $managed = array_values(array_filter(
        $domains['json']['data'],
        static fn (array $domain): bool => $domain['dname'] === 'example.com',
    ));
    assertTrue($managed[0]['dns_managed'], 'example.com must be REG.RU-managed');

    $dns = http($container, 'GET', '/api/v1/domains/example.com/dns');
    assertSame(200, $dns['status']);
    assertSame(3, count($dns['json']['data']['records']));

    $hostings = http($container, 'GET', '/api/v1/hostings');
    assertSame(1, count($hostings['json']['data']));
    assertSame('203.0.113.10', $hostings['json']['data'][0]['server_ip']);
    assertTrue($hostings['json']['data'][0]['has_panel_password']);

    $credentials = http($container, 'GET', '/api/v1/credentials');
    assertSame(1, count($credentials['json']['data']));
    assertSame('Хостинг example.com', $credentials['json']['data'][0]['title']);
});

test('Sync: second run is idempotent', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);

    http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));
    http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));

    assertSame(2, http($container, 'GET', '/api/v1/domains')['json']['meta']['total']);
    assertSame(1, count(http($container, 'GET', '/api/v1/credentials')['json']['data']));
});

test('DNS: add validates input, adds record and deletes it', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);
    http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));

    $bad = http($container, 'POST', '/api/v1/domains/example.com/dns/records', [
        'subname' => 'api',
        'rectype' => 'A',
        'content' => 'not-an-ip',
    ], csrfHeaders($container));
    assertSame(422, $bad['status']);
    assertSame('VALIDATION_FAILED', $bad['json']['error']['code']);

    $add = http($container, 'POST', '/api/v1/domains/example.com/dns/records', [
        'subname' => 'api',
        'rectype' => 'A',
        'content' => '203.0.113.99',
    ], csrfHeaders($container));
    assertSame(201, $add['status'], $add['raw']);
    assertTrue(count($add['json']['data']['records']) === 4);

    $delete = http($container, 'DELETE', '/api/v1/domains/example.com/dns/records', [
        'record' => ['subname' => 'api', 'rectype' => 'A', 'content' => '203.0.113.99', 'prio' => 0],
    ], csrfHeaders($container));
    assertSame(200, $delete['status'], $delete['raw']);
    assertSame(3, count($delete['json']['data']['records']));
});

test('DNS: non-REG.RU delegated domain is rejected with 409', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);
    http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));

    $response = http($container, 'GET', '/api/v1/domains/example.ru/dns');
    assertSame(409, $response['status']);
    assertSame('DNS_NOT_MANAGED', $response['json']['error']['code']);
});

test('Domains: renew and autorenew call REG.RU and audit', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);
    http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));

    $renew = http($container, 'POST', '/api/v1/domains/example.com/renew', ['period' => 1], csrfHeaders($container));
    assertSame(200, $renew['status'], $renew['raw']);
    assertSame('renew_success', $renew['json']['data']['status']);

    $autorenew = http($container, 'POST', '/api/v1/domains/example.com/autorenew', ['enabled' => true], csrfHeaders($container));
    assertSame(200, $autorenew['status']);

    $domain = http($container, 'GET', '/api/v1/domains/example.com');
    assertTrue($domain['json']['data']['autorenew']);

    $audit = $container->get(AuditRepository::class);
    $events = $audit->search(['action' => 'domain.renew']);
    assertSame(1, $events['total']);
});

test('Credentials: create, reveal (audited), update, delete', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);
    /** @var CredentialService $credentials */
    $credentials = $container->get(CredentialService::class);

    $csrf = csrfHeaders($container);
    $created = http($container, 'POST', '/api/v1/credentials', [
        'title' => 'FTP example',
        'kind' => 'ftp',
        'host' => 'ftp.example.com',
        'port' => 21,
        'username' => 'user',
        'password' => 's3cret-password',
    ], $csrf);

    assertSame(201, $created['status'], $created['raw']);
    $id = (int) $created['json']['data']['id'];

    $reveal = http($container, 'POST', '/api/v1/credentials/' . $id . '/reveal', [], $csrf);
    assertSame(200, $reveal['status']);
    assertSame('s3cret-password', $reveal['json']['data']['password']);

    $updated = http($container, 'PUT', '/api/v1/credentials/' . $id, ['title' => 'FTP renamed'], $csrf);
    assertSame(200, $updated['status']);
    assertSame('FTP renamed', $updated['json']['data']['title']);

    $events = $container->get(AuditRepository::class)->search(['action' => 'credential.reveal']);
    assertSame(1, $events['total']);

    $deleted = http($container, 'DELETE', '/api/v1/credentials/' . $id, [], $csrf);
    assertSame(204, $deleted['status']);
    assertSame(0, $credentials->count());
});

test('API tokens: scope enforcement on read-only token', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);

    /** @var \RegRuControl\Service\TokenService $tokens */
    $tokens = $container->get(\RegRuControl\Service\TokenService::class);
    $read = $tokens->create('reader', ['read'], null, null);

    $domains = http($container, 'GET', '/api/v1/domains', [], ['Authorization' => 'Bearer ' . $read['plain']]);
    assertSame(200, $domains['status']);

    $sync = http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], ['Authorization' => 'Bearer ' . $read['plain']]);
    assertSame(403, $sync['status']);
    assertSame('FORBIDDEN', $sync['json']['error']['code']);

    $write = $tokens->create('writer', ['write'], null, null);
    $sync = http($container, 'POST', '/api/v1/sync', ['scope' => 'services'], ['Authorization' => 'Bearer ' . $write['plain']]);
    assertSame(200, $sync['status'], $sync['raw']);
});

test('API tokens: invalid token is 401, revoked token stops working', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    $tokens = $container->get(\RegRuControl\Service\TokenService::class);
    $token = $tokens->create('temp', ['read'], null, null);

    $ok = http($container, 'GET', '/api/v1/domains', [], ['Authorization' => 'Bearer ' . $token['plain']]);
    assertSame(200, $ok['status']);

    $tokens->revoke($token['token']->id);

    $revoked = http($container, 'GET', '/api/v1/domains', [], ['Authorization' => 'Bearer ' . $token['plain']]);
    assertSame(401, $revoked['status']);

    $invalid = http($container, 'GET', '/api/v1/domains', [], ['Authorization' => 'Bearer rg_invalid']);
    assertSame(401, $invalid['status']);
});

test('Account settings: save verifies credentials and stores encrypted password', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);

    $response = http($container, 'PUT', '/api/v1/settings/account', [
        'username' => 'test',
        'password' => 'api-password',
        'currency' => 'RUR',
    ], csrfHeaders($container));

    assertSame(200, $response['status'], $response['raw']);
    assertTrue($response['json']['data']['ok']);
    assertSame('test', $container->get(RegRuAccountService::class)->status()['username']);

    $raw = $container->get(\RegRuControl\Database\Database::class)->fetchOne('SELECT password_enc FROM regru_account WHERE id = 1');
    assertTrue(str_starts_with((string) $raw['password_enc'], 'v1.'), 'Password must be sealed');

    $export = http($container, 'POST', '/api/v1/settings/export', ['include_secrets' => true, 'confirm' => 'EXPORT'], csrfHeaders($container));
    assertSame(200, $export['status'], $export['raw']);
});

test('Inventory: contains addresses but no secrets', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);
    http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));

    $response = http($container, 'GET', '/api/v1/inventory');
    assertSame(200, $response['status']);
    $raw = $response['raw'];

    assertFalse(str_contains($raw, 'hosting-password'), 'Plaintext password leaked into inventory');
    assertTrue(str_contains($raw, '203.0.113.10'));
    assertTrue($response['json']['data']['hostings'][0]['panel_password_present']);
});

test('Dashboard summary counts and balance', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    $container->get(RegRuAccountService::class)->save('test', 'test-password');
    login($container);
    http($container, 'POST', '/api/v1/sync', ['scope' => 'all'], csrfHeaders($container));

    $response = http($container, 'GET', '/api/v1/dashboard');
    assertSame(200, $response['status']);
    assertSame(2, $response['json']['data']['counts']['domains']);
    assertSame(1, $response['json']['data']['counts']['hostings']);
    assertSame('RUR', $response['json']['data']['balance']['currency']);
});

test('Meta: command catalog covers the documented API', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);

    $response = http($container, 'GET', '/api/v1/meta/commands');
    assertSame(200, $response['status']);
    assertTrue($response['json']['data']['meta']['command_count'] >= 110);

    $commands = array_column($response['json']['data']['commands'], 'command');
    foreach (['zone/update_records', 'service/get_list', 'domain/get_nss', 'user/get_balance', 'zone/add_https'] as $command) {
        assertTrue(in_array($command, $commands, true), 'Missing command: ' . $command);
    }
});

test('Raw REG.RU call: scope is enforced and input is validated', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    $tokens = $container->get(\RegRuControl\Service\TokenService::class);
    $read = $tokens->create('reader', ['read'], null, null);

    $forbidden = http($container, 'POST', '/api/v1/regru/call', ['command' => 'nop', 'input' => []], ['Authorization' => 'Bearer ' . $read['plain']]);
    assertSame(403, $forbidden['status']);
    assertSame('FORBIDDEN', $forbidden['json']['error']['code']);

    $raw = $tokens->create('raw', ['read', 'regru:raw'], null, null);
    $bad = http($container, 'POST', '/api/v1/regru/call', ['command' => 'bad command!', 'input' => []], ['Authorization' => 'Bearer ' . $raw['plain']]);
    assertSame(400, $bad['status']);
});

test('Settings: view exposes system info, update validates TTL', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);

    $view = http($container, 'GET', '/api/v1/settings');
    assertSame(200, $view['status']);
    assertSame('8.5', substr($view['json']['data']['system']['php'], 0, 3));

    $bad = http($container, 'PUT', '/api/v1/settings', ['sync_ttl' => 5], csrfHeaders($container));
    assertSame(422, $bad['status']);

    $good = http($container, 'PUT', '/api/v1/settings', ['sync_ttl' => 900], csrfHeaders($container));
    assertSame(200, $good['status']);
    assertSame(900, $good['json']['data']['sync']['ttl']);
});

test('Audit: journal records writes and is readable by admin', static function (): void {
    $container = TestKernel::boot();
    createAdmin($container);
    login($container);
    http($container, 'POST', '/api/v1/sync', ['scope' => 'services'], csrfHeaders($container));

    $response = http($container, 'GET', '/api/v1/audit?limit=50');
    assertSame(200, $response['status']);
    assertTrue($response['json']['meta']['total'] >= 2);
});

test('Migrations: repeated migrate() is a no-op', static function (): void {
    $container = TestKernel::boot();
    $migrator = new \RegRuControl\Database\Migrator(
        $container->get(\RegRuControl\Database\Database::class),
        $container->get(\RegRuControl\Kernel\Logger::class),
        APP_ROOT . '/backend/migrations',
    );

    assertSame([], $migrator->migrate());
    $status = $migrator->status();
    assertTrue($status['0001_init']);
});

test('i18n: Accept-Language selects the locale and localizes errors', static function (): void {
    $container = TestKernel::boot();

    $bootstrap = http($container, 'GET', '/api/v1/bootstrap', [], ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.5']);
    assertSame('de', $bootstrap['json']['data']['locale']);
    assertTrue(count($bootstrap['json']['data']['locales']) === 6);

    $error = http($container, 'GET', '/api/v1/domains', [], ['Accept-Language' => 'de']);
    assertSame(401, $error['status']);
    assertContains('Authentifizierung', $error['json']['error']['message']);

    $russian = http($container, 'GET', '/api/v1/domains', [], ['Accept-Language' => 'ru']);
    assertContains('авторизация', $russian['json']['error']['message']);

    $default = http($container, 'GET', '/api/v1/domains');
    assertContains('Authentication', $default['json']['error']['message']);
});

test('i18n: POST /locale validates and stores the choice in a cookie', static function (): void {
    $container = TestKernel::boot();

    $response = http($container, 'POST', '/api/v1/locale', ['locale' => 'zh'], csrfHeaders($container));
    assertSame(200, $response['status'], $response['raw']);
    assertSame('zh', $response['json']['data']['locale']);
    assertContains('rg_lang=zh', $response['headers']['set-cookie'] ?? '');

    $bad = http($container, 'POST', '/api/v1/locale', ['locale' => 'xx'], csrfHeaders($container));
    assertSame(400, $bad['status']);

    $locales = http($container, 'GET', '/api/v1/locales');
    assertSame(200, $locales['status']);
    assertSame(6, count($locales['json']['data']['locales']));
});

test('i18n: translator falls back to English for missing keys', static function (): void {
    $translator = new \RegRuControl\Kernel\Translator(APP_ROOT . '/backend/resources/locales', \RegRuControl\Kernel\AppLocale::De);
    assertContains('Authentifizierung', $translator->t('error.unauthorized'));
    assertSame('nonexistent.key', $translator->t('nonexistent.key'));
    assertSame('Wert', $translator->t('nonexistent.key', [], 'Wert'));
});
$exitCode = $runner->run();
exit($exitCode);
