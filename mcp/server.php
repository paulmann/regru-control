<?php

/**
 * REG.RU Control — MCP server (stdio, JSON-RPC 2.0).
 *
 * Exposes the panel's HTTP API as MCP tools so AI agents can inspect domains,
 * DNS, hostings, credentials and billing, and (optionally) change them.
 *
 *   REGRU_PANEL_URL=https://example.com/regru \
 *   REGRU_API_TOKEN=rg_... \
 *   php mcp/server.php
 *
 * Safety: write tools are disabled unless REGRU_MCP_ALLOW_WRITE=1. The raw
 * REG.RU call tool only permits read-only commands without that flag.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

const SERVER_NAME = 'regru-control';
const SERVER_VERSION = '1.0.0';
const PROTOCOL_VERSION = '2024-11-05';

$options = getopt('', ['self-test', 'help', 'token-file:']);

if (isset($options['help'])) {
    fwrite(STDOUT, "REG.RU Control MCP server (stdio).\n\nEnvironment:\n");
    fwrite(STDOUT, "  REGRU_PANEL_URL      panel base URL, e.g. https://example.com/regru (required)\n");
    fwrite(STDOUT, "  REGRU_API_TOKEN      API token with scopes read[, write, dns:write, secrets:read, regru:raw] (required)\n");
    fwrite(STDOUT, "  REGRU_MCP_ALLOW_WRITE  1 to enable write/destructive tools (default 0)\n");
    fwrite(STDOUT, "  REGRU_MCP_LOCALE    response language: en, ru, zh, hi, de, es (default en)\n");
    fwrite(STDOUT, "  REGRU_MCP_TIMEOUT   HTTP timeout in seconds (default 30)\n");
    fwrite(STDOUT, "  --token-file PATH   read the API token from a file (useful when the MCP client filters TOKEN env names)\n");
    fwrite(STDOUT, "  REGRU_MCP_CAINFO    path to a CA bundle (auto-detected on common systems)\n");
    fwrite(STDOUT, "  REGRU_MCP_INSECURE  1 to skip TLS verification (development only)\n");
    exit(0);
}

$tokenFile = (string) ($options['token-file'] ?? '');
$token = (string) (getenv('REGRU_API_TOKEN') ?: '');

if ($tokenFile !== '') {
    $token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
}

$config = [
    'panel_url' => rtrim((string) (getenv('REGRU_PANEL_URL') ?: ''), '/'),
    'token' => $token,
    'allow_write' => in_array(getenv('REGRU_MCP_ALLOW_WRITE'), ['1', 'true', 'yes'], true),
    'locale' => (string) (getenv('REGRU_MCP_LOCALE') ?: 'en'),
    'timeout' => max(5, (int) (getenv('REGRU_MCP_TIMEOUT') ?: 30)),
    'insecure' => getenv('REGRU_MCP_INSECURE') === '1',
];

/**
 * Tool catalogue. `readOnly` tools never change anything; `write` tools are
 * refused unless REGRU_MCP_ALLOW_WRITE=1.
 *
 * @return array<string, array<string, mixed>>
 */
function tools(): array
{
    $domain = ['type' => 'string', 'description' => 'Domain name, e.g. example.com'];
    $record = [
        'type' => 'object',
        'description' => 'Existing DNS record',
        'required' => ['subname', 'rectype', 'content'],
        'properties' => [
            'subname' => ['type' => 'string', 'description' => '@ for the domain itself, or a subdomain'],
            'rectype' => ['type' => 'string', 'enum' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA']],
            'content' => ['type' => 'string', 'description' => 'Record content, e.g. 203.0.113.10'],
            'prio' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
        ],
    ];

    return [
        'regru_inventory' => [
            'description' => 'Full account inventory without secrets: counts, domains, hostings, credentials metadata, balance.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'readOnly' => true,
            'call' => static fn (array $args): array => httpGet('inventory'),
        ],
        'regru_domains' => [
            'description' => 'List domains with state, expiry, days left, nameservers and autorenew.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'Search substring'],
                    'state' => ['type' => 'string', 'description' => 'active, suspended, ...'],
                    'expiring_in' => ['type' => 'integer', 'description' => 'Only domains expiring within N days'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                ],
            ],
            'readOnly' => true,
            'call' => static fn (array $args): array => httpGet('domains', $args),
        ],
        'regru_domain_get' => [
            'description' => 'One domain with cached DNS record count.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['domain'],
                'properties' => ['domain' => $domain],
            ],
            'readOnly' => true,
            'call' => static fn (array $args): array => httpGet('domains/' . rawurlencode(requireString($args, 'domain'))),
        ],
        'regru_dns_records' => [
            'description' => 'DNS records and SOA for a domain managed by REG.RU nameservers.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['domain'],
                'properties' => [
                    'domain' => $domain,
                    'refresh' => ['type' => 'boolean', 'description' => 'Fetch fresh data from REG.RU instead of cache'],
                ],
            ],
            'readOnly' => true,
            'call' => static fn (array $args): array => httpGet(
                'domains/' . rawurlencode(requireString($args, 'domain')) . '/dns',
                ['refresh' => !empty($args['refresh']) ? 1 : null],
            ),
        ],
        'regru_dns_add_record' => [
            'description' => 'Create a DNS record. Requires token scope dns:write and REGRU_MCP_ALLOW_WRITE=1.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['domain', 'subname', 'rectype', 'content'],
                'properties' => [
                    'domain' => $domain,
                    'subname' => ['type' => 'string'],
                    'rectype' => ['type' => 'string', 'enum' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA']],
                    'content' => ['type' => 'string'],
                    'prio' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
                ],
            ],
            'readOnly' => false,
            'call' => static function (array $args): array {
                $domain = rawurlencode(requireString($args, 'domain'));
                $body = [
                    'subname' => requireString($args, 'subname'),
                    'rectype' => requireString($args, 'rectype'),
                    'content' => requireString($args, 'content'),
                ];
                if (isset($args['prio'])) {
                    $body['prio'] = (int) $args['prio'];
                }

                return httpSend('POST', 'domains/' . $domain . '/dns/records', $body);
            },
        ],
        'regru_dns_delete_record' => [
            'description' => 'Delete a DNS record. Requires dns:write and REGRU_MCP_ALLOW_WRITE=1.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['domain', 'record'],
                'properties' => ['domain' => $domain, 'record' => $record],
            ],
            'readOnly' => false,
            'call' => static function (array $args): array {
                $domain = rawurlencode(requireString($args, 'domain'));
                $record = $args['record'] ?? null;

                if (!is_array($record)) {
                    throw new InvalidArgumentException('record must be an object');
                }

                return httpSend('DELETE', 'domains/' . $domain . '/dns/records', ['record' => $record]);
            },
        ],
        'regru_hostings' => [
            'description' => 'Hosting, VPS and dedicated servers: plan, IP, panel URL/login, expiry. Passwords are not included.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'readOnly' => true,
            'call' => static fn (): array => httpGet('hostings'),
        ],
        'regru_credentials' => [
            'description' => 'List credential metadata from the encrypted vault (no passwords).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'description' => 'panel, ftp, ssh, database, email, dns, registrar, other'],
                    'q' => ['type' => 'string'],
                ],
            ],
            'readOnly' => true,
            'call' => static fn (array $args): array => httpGet('credentials', $args),
        ],
        'regru_credential_reveal' => [
            'description' => 'Decrypt one stored credential. Requires scope secrets:read; every reveal is written to the audit log.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'readOnly' => true,
            'call' => static fn (array $args): array => httpSend(
                'POST',
                'credentials/' . requireInt($args, 'id') . '/reveal',
                [],
            ),
        ],
        'regru_billing' => [
            'description' => 'REG.RU balance and unpaid invoices.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'readOnly' => true,
            'call' => static fn (): array => httpGet('billing', ['refresh' => 1]),
        ],
        'regru_sync' => [
            'description' => 'Run inventory synchronization. Requires scope write and REGRU_MCP_ALLOW_WRITE=1.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'scope' => ['type' => 'string', 'enum' => ['all', 'services', 'details', 'dns'], 'default' => 'all'],
                ],
            ],
            'readOnly' => false,
            'call' => static fn (array $args): array => httpSend('POST', 'sync', ['scope' => $args['scope'] ?? 'all']),
        ],
        'regru_domain_renew' => [
            'description' => 'Renew a domain for N years (spends money). Requires write and REGRU_MCP_ALLOW_WRITE=1.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['domain', 'period'],
                'properties' => [
                    'domain' => $domain,
                    'period' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                ],
            ],
            'readOnly' => false,
            'call' => static fn (array $args): array => httpSend(
                'POST',
                'domains/' . rawurlencode(requireString($args, 'domain')) . '/renew',
                ['period' => requireInt($args, 'period')],
            ),
        ],
        'regru_raw_call' => [
            'description' => 'Call any REG.RU API2 command. Without REGRU_MCP_ALLOW_WRITE=1 only read-only commands (get_*) are allowed. Requires scope regru:raw.',
            'inputSchema' => [
                'type' => 'object',
                'required' => ['command'],
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'e.g. user/get_balance or service/get_list'],
                    'input' => ['type' => 'object', 'description' => 'Command input_data'],
                ],
            ],
            'readOnly' => true,
            'call' => static function (array $args): array {
                $command = requireString($args, 'command');

                if (!config('allow_write') && preg_match('~^(user|service|domain|zone|bill|account)/get_~', $command) !== 1) {
                    throw new RuntimeException('Raw write commands are disabled: set REGRU_MCP_ALLOW_WRITE=1 to allow them.');
                }

                return httpSend('POST', 'regru/call', [
                    'command' => $command,
                    'input' => is_array($args['input'] ?? null) ? $args['input'] : new stdClass(),
                ]);
            },
        ],
    ];
}

/** @return array<string, mixed> */
function config(?string $key = null): array|string|bool|int|null
{
    static $config = null;

    if ($config === null) {
        $config = $GLOBALS['config'];
    }

    return $key === null ? $config : ($config[$key] ?? null);
}

function findCaBundle(): ?string
{
    $configured = getenv('REGRU_MCP_CAINFO');

    if (is_string($configured) && $configured !== '' && is_file($configured)) {
        return $configured;
    }

    $candidates = [
        (string) ini_get('curl.cainfo'),
        (string) ini_get('openssl.cafile'),
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/ssl/cert.pem',
        '/etc/pki/tls/certs/ca-bundle.crt',
        'C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt',
        'C:\Program Files\Git\mingw64\ssl\certs\ca-bundle.crt',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}
function requireString(array $args, string $key): string
{
    $value = $args[$key] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException(sprintf('Argument "%s" is required and must be a non-empty string.', $key));
    }

    return trim($value);
}

function requireInt(array $args, string $key): int
{
    $value = $args[$key] ?? null;

    if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
        return (int) $value;
    }

    if (!is_int($value) || $value < 1) {
        throw new InvalidArgumentException(sprintf('Argument "%s" is required and must be a positive integer.', $key));
    }

    return $value;
}

/**
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function httpGet(string $path, array $query = []): array
{
    return httpRequest('GET', $path, null, $query);
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function httpSend(string $method, string $path, array $body): array
{
    return httpRequest($method, $path, $body, []);
}

/**
 * @param array<string, mixed>|null $body
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function httpRequest(string $method, string $path, ?array $body, array $query): array
{
    $base = (string) config('panel_url');
    $token = (string) config('token');

    if ($base === '' || $token === '') {
        throw new RuntimeException('REGRU_PANEL_URL and REGRU_API_TOKEN must be set.');
    }

    $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    $url = $base . '/api/v1/' . ltrim($path, '/');

    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Cannot initialize HTTP client.');
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Accept-Language: ' . (string) config('locale'),
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => (int) config('timeout'),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body === [] ? new stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    if (config('insecure')) {
        $options[CURLOPT_SSL_VERIFYPEER] = false;
        $options[CURLOPT_SSL_VERIFYHOST] = 0;
    } elseif (($cainfo = findCaBundle()) !== null) {
        $options[CURLOPT_CAINFO] = $cainfo;
    }

    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    if ($raw === false) {
        if (str_contains(strtolower($error), 'certificate') || str_contains(strtolower($error), 'ssl')) {
            $error .= ' — set REGRU_MCP_CAINFO to a CA bundle (or REGRU_MCP_INSECURE=1 for a trusted LAN panel).';
        }

        throw new RuntimeException('Network error: ' . $error);
    }

    $decoded = json_decode((string) $raw, true);
    $payload = is_array($decoded) ? $decoded : ['raw' => mb_substr((string) $raw, 0, 4000)];

    if ($status >= 400) {
        $message = $payload['error']['message'] ?? ('HTTP ' . $status);
        $code = $payload['error']['code'] ?? 'HTTP_ERROR';
        throw new RuntimeException(sprintf('%s (%s)', (string) $message, (string) $code));
    }

    return $payload;
}

/**
 * @return array<string, mixed>
 */
function toolResult(mixed $data): array
{
    $text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $result = ['content' => [['type' => 'text', 'text' => $text === false ? 'null' : $text]]];

    return $result;
}

/**
 * @return array<string, mixed>
 */
function toolError(string $message): array
{
    return [
        'content' => [['type' => 'text', 'text' => $message]],
        'isError' => true,
    ];
}

/**
 * @param array<string, mixed> $message
 */
function handleRequest(array $message): ?array
{
    $id = $message['id'] ?? null;
    $method = (string) ($message['method'] ?? '');
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];
    $isNotification = !array_key_exists('id', $message);

    try {
        $result = match ($method) {
            'initialize' => initializeResult(),
            'ping' => new stdClass(),
            'tools/list' => ['tools' => array_values(array_map(static fn (string $name, array $tool): array => [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
                'annotations' => [
                    'readOnlyHint' => (bool) $tool['readOnly'],
                    'destructiveHint' => !$tool['readOnly'],
                    'openWorldHint' => true,
                ],
            ], array_keys(tools()), array_values(tools())))],
            'tools/call' => callTool($params),
            default => throw new JsonRpcException('Method not found: ' . $method, -32601),
        };

        if ($isNotification) {
            return null;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    } catch (JsonRpcException $exception) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $exception->getCode(), 'message' => $exception->getMessage()]];
    }
}

/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function callTool(array $params): array
{
    $name = (string) ($params['name'] ?? '');
    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
    $catalogue = tools();

    if (!isset($catalogue[$name])) {
        return toolError('Unknown tool: ' . $name);
    }

    $tool = $catalogue[$name];

    if (!$tool['readOnly'] && !config('allow_write')) {
        return toolError('This tool changes data. Set REGRU_MCP_ALLOW_WRITE=1 to enable write tools.');
    }

    try {
        /** @var callable $call */
        $call = $tool['call'];

        return toolResult($call($arguments));
    } catch (InvalidArgumentException $exception) {
        return toolError($exception->getMessage());
    } catch (RuntimeException $exception) {
        return toolError($exception->getMessage());
    } catch (Throwable $exception) {
        return toolError('Unexpected error: ' . $exception->getMessage());
    }
}

/**
 * @return array<string, mixed>
 */
function initializeResult(): array
{
    return [
        'protocolVersion' => PROTOCOL_VERSION,
        'capabilities' => ['tools' => ['listChanged' => false]],
        'serverInfo' => ['name' => SERVER_NAME, 'version' => SERVER_VERSION],
        'instructions' => 'REG.RU Control tools. Read-only by default. Credentials are stored encrypted; regru_credential_reveal requires the secrets:read scope and is audited. Write tools need REGRU_MCP_ALLOW_WRITE=1. Prefer regru_inventory to orient, then domain/DNS/hosting tools.',
    ];
}

final class JsonRpcException extends RuntimeException
{
}

if (isset($options['self-test'])) {
    $failures = [];
    $init = initializeResult();

    if (($init['serverInfo']['name'] ?? '') !== SERVER_NAME) {
        $failures[] = 'initialize';
    }

    foreach (tools() as $name => $tool) {
        if (!is_string($tool['description'] ?? null) || !is_array($tool['inputSchema'] ?? null)) {
            $failures[] = $name;
        }

        if (!is_callable($tool['call'] ?? null)) {
            $failures[] = $name . ':call';
        }
    }

    $json = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $init], JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        $failures[] = 'json_encode';
    }

    if ($failures !== []) {
        fwrite(STDERR, 'FAILED: ' . implode(', ', $failures) . "\n");
        exit(1);
    }

    fwrite(STDOUT, sprintf("OK: %d tools, protocol %s\n", count(tools()), PROTOCOL_VERSION));
    exit(0);
}

$GLOBALS['config'] = $config;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $message = json_decode($line, true);

    if (!is_array($message)) {
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32700, 'message' => 'Parse error: expected one JSON-RPC message per line.'],
        ], JSON_UNESCAPED_UNICODE) . "\n");
        fflush(STDOUT);

        continue;
    }

    $messages = array_is_list($message) ? $message : [$message];
    $responses = [];

    foreach ($messages as $item) {
        if (!is_array($item)) {
            continue;
        }

        $response = handleRequest($item);

        if ($response !== null) {
            $responses[] = $response;
        }
    }

    if ($responses === []) {
        continue;
    }

    $payload = array_is_list($message) ? $responses : $responses[0];
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}
