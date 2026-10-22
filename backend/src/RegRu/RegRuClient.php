<?php

declare(strict_types=1);

namespace RegRuControl\RegRu;

use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\I18n;
use RegRuControl\Kernel\Logger;
use RegRuControl\Support\Json;
use RegRuControl\Support\RateLimiter;

/**
 * REG.RU API2 HTTP client.
 *
 * - single place that knows the wire format (`input_data` JSON, POST);
 * - retries only transport failures, never business errors (a retried
 *   `service/renew` can charge twice);
 * - enforces the documented 1200 requests/hour account limit with a safety
 *   margin, so a runaway sync cannot lock the account out;
 * - logs command name, duration and outcome, never credentials.
 */
final class RegRuClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly RegRuAccountProvider $accounts,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function call(string $command, array $input = []): ApiResponse
    {
        $responseBody = $this->request($command, $input);
        $decoded = Json::tryDecode($responseBody);

        if ($decoded === null) {
            throw new RegRuException(I18n::t('regru.empty_response'));
        }

        if (($decoded['result'] ?? null) === 'error') {
            $exception = new RegRuApiException(
                $command,
                (string) ($decoded['error_code'] ?? 'UNKNOWN'),
                (string) ($decoded['error_text'] ?? ''),
                is_array($decoded['error_params'] ?? null) ? $decoded['error_params'] : [],
            );

            $this->logger->warning('REG.RU business error', [
                'command' => $command,
                'api_code' => $exception->apiCode,
            ]);

            throw $exception;
        }

        return new ApiResponse($command, $decoded);
    }

    /**
     * Issues a request and returns the raw body — for commands that answer with
     * a file (e.g. `domain/download_certificate` returns a PDF).
     *
     * @param array<string, mixed> $input
     */
    public function download(string $command, array $input = []): string
    {
        return $this->request($command, $input);
    }

    /**
     * Issues a request and returns the answer hash; convenience for endpoints.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function answer(string $command, array $input = []): array
    {
        return $this->call($command, $input)->answer();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function request(string $command, array $input = []): string
    {
        if (!$this->accounts->isConfigured()) {
            throw new HttpException(
                409,
                'REGRU_ACCOUNT_NOT_CONFIGURED',
                I18n::t('regru.account_not_configured'),
            );
        }

        $limit = max(1, $this->config->int('regru.rate_limit_per_hour', 1100));

        if (!$this->limiter->hit('regru_out', 'account', $limit, 3600)) {
            throw new RegRuApiException(
                $command,
                'IP_EXCEEDED_ALLOWED_CONNECTION_RATE',
                'Local safety limit reached',
            );
        }

        $credentials = $this->accounts->credentials();
        $payload = [
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'input_format' => 'json',
            'output_format' => 'json',
        ];

        if ($input !== []) {
            $payload['input_data'] = Json::encode($input);
        }

        $url = rtrim($this->config->string('regru.base_url', 'https://api.reg.ru/api/regru2/'), '/') . '/' . $command;
        $attempts = max(1, $this->config->int('regru.retries', 3) + 1);
        $started = microtime(true);
        $responseBody = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $responseBody = $this->send($url, $payload);
                $lastError = null;

                break;
            } catch (RegRuConnectionException $exception) {
                $lastError = $exception;

                if ($attempt === $attempts) {
                    break;
                }

                $this->logger->warning('REG.RU transport failure, retrying', [
                    'command' => $command,
                    'attempt' => $attempt,
                    'error' => $exception->getMessage(),
                ]);

                usleep((int) (300_000 * (2 ** ($attempt - 1)) + random_int(0, 120_000)));
            }
        }

        $duration = (int) round((microtime(true) - $started) * 1000);

        if ($responseBody === null) {
            $this->logger->error('REG.RU request failed', ['command' => $command, 'duration_ms' => $duration]);

            throw $lastError ?? new RegRuConnectionException(I18n::t('regru.transport', ['error' => 'request failed']));
        }

        $this->logger->info('REG.RU request', [
            'command' => $command,
            'duration_ms' => $duration,
            'bytes' => strlen($responseBody),
        ]);

        return $responseBody;
    }

    /**
     * @param array<string, string> $payload
     */
    private function send(string $url, array $payload): string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RegRuConnectionException('Не удалось инициализировать HTTP-клиент.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(5, $this->config->int('regru.timeout', 20)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: regru-control/1.0',
            ],
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ($body === false || $error !== '') {
            throw new RegRuConnectionException(I18n::t('regru.transport', ['error' => $error]));
        }

        if ($status >= 500) {
            throw new RegRuConnectionException(
                sprintf('REG.RU ответил HTTP %d. Повторим позже.', $status),
                $status,
            );
        }

        if ($status === 0) {
            throw new RegRuConnectionException(I18n::t('regru.empty_response'));
        }

        return (string) $body;
    }
}
