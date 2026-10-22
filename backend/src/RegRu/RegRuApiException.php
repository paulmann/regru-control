<?php

declare(strict_types=1);

namespace RegRuControl\RegRu;

use RegRuControl\Kernel\I18n;

/**
 * Application-level failure returned by REG.RU (`{"result":"error", ...}`),
 * including per-domain errors inside an otherwise successful response.
 */
final class RegRuApiException extends RegRuException
{
    /**
     * @param array<string, mixed> $apiParams
     * @param list<array<string, mixed>> $domainErrors
     */
    public function __construct(
        public readonly string $command,
        public readonly string $apiCode,
        public readonly string $apiText,
        public readonly array $apiParams = [],
        public readonly array $domainErrors = [],
    ) {
        $message = self::humanMessage($this->apiCode, $this->apiText);
        $details = [
            'command' => $command,
            'api_code' => $apiCode,
            'api_text' => $apiText,
            'api_params' => $apiParams,
        ];

        if ($domainErrors !== []) {
            $details['domains'] = $domainErrors;
        }

        parent::__construct($message, self::statusFor($apiCode), $details);
    }

    private static function humanMessage(string $code, string $text): string
    {
        return match ($code) {
            'ACCESS_DENIED_FROM_IP' => I18n::t('regru.access_denied_from_ip'),
            'NO_SUCH_COMMAND' => I18n::t('regru.no_such_command'),
            'INSUFFICIENT_FUNDS', 'NOT_ENOUGH_MONEY' => I18n::t('regru.insufficient_funds'),
            'AUTH_FAILED', 'INVALID_AUTH', 'PASSWORD_AUTH_FAILED' => I18n::t('regru.auth_failed'),
            'IP_EXCEEDED_ALLOWED_CONNECTION_RATE', 'ACCOUNT_EXCEEDED_ALLOWED_CONNECTION_RATE' => I18n::t('regru.rate_limited'),
            'DOMAIN_IS_NOT_USE_REGRU_NSS' => I18n::t('regru.domain_not_regru_ns'),
            default => I18n::t('regru.http_error', [
                'message' => $text !== '' ? $text : 'error',
                'code' => $code,
            ]),
        };
    }

    private static function statusFor(string $code): int
    {
        return match ($code) {
            'ACCESS_DENIED_FROM_IP' => 403,
            'IP_EXCEEDED_ALLOWED_CONNECTION_RATE', 'ACCOUNT_EXCEEDED_ALLOWED_CONNECTION_RATE' => 429,
            'NO_SUCH_COMMAND' => 501,
            'INSUFFICIENT_FUNDS', 'NOT_ENOUGH_MONEY' => 402,
            'DOMAIN_IS_NOT_USE_REGRU_NSS' => 409,
            default => 502,
        };
    }
}
