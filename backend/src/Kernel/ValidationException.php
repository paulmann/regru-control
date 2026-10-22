<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * 422 with a per-field error map, produced by the validator.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string, string> $errors field => human readable message
     * @param array<string, mixed> $details
     */
    public function __construct(array $errors, array $details = [])
    {
        $details['fields'] = $errors;

        parent::__construct(422, 'VALIDATION_FAILED', I18n::t('error.validation_failed'), $details);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->details['fields'] ?? [];
    }
}
