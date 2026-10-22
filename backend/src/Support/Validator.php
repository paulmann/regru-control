<?php

declare(strict_types=1);

namespace RegRuControl\Support;

use RegRuControl\Kernel\I18n;
use RegRuControl\Kernel\ValidationException;

/**
 * Small explicit validator.
 *
 * Messages come from the translation catalog (`validation.*`, `field.*`), so the
 * same 422 response speaks the language negotiated for the request. Field labels
 * are passed as Russian UI labels by controllers; `FIELD_KEYS` maps them to
 * catalog keys, unknown labels fall back to the label as-is.
 */
final class Validator
{
    /** @var array<string, string> Russian label => field key */
    private const FIELD_KEYS = [
        'Логин' => 'login',
        'Логин REG.RU' => 'login',
        'Пароль' => 'password',
        'Пароль API' => 'password',
        'Текущий пароль' => 'current_password',
        'Новый пароль' => 'new_password',
        'Повторите новый пароль' => 'new_password',
        'Название' => 'title',
        'Тип' => 'kind',
        'Хост' => 'host',
        'Хост / адрес' => 'host',
        'Порт' => 'port',
        'Ссылка' => 'url',
        'Ссылка на панель' => 'url',
        'Заметки' => 'notes',
        'Домен' => 'dname',
        'Связанный домен' => 'dname',
        'ID услуги' => 'service_id',
        'ID услуги REG.RU' => 'service_id',
        'Период' => 'period',
        'Период продления' => 'period',
        'Период продления, лет' => 'period',
        'Автопродление' => 'enabled',
        'DNS-серверы' => 'nss',
        'Поддомен' => 'subname',
        'Тип записи' => 'rectype',
        'Содержимое' => 'content',
        'Приоритет' => 'prio',
        'TTL' => 'ttl',
        'TTL зоны' => 'ttl',
        'Minimum TTL' => 'minimum_ttl',
        'Подтверждение' => 'confirm',
        'Объём' => 'scope',
        'Источник' => 'trigger',
        'Валюта' => 'currency',
        'Интервал синхронизации' => 'sync_ttl',
        'Права' => 'scope',
        'Срок действия' => 'period',
        'Запись' => 'record',
        'Язык' => 'locale',
    ];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $clean = [];

    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function requiredString(string $key, string $label, int $min = 1, int $max = 5000): string
    {
        $value = $this->raw($key);

        if (!is_scalar($value) || trim((string) $value) === '') {
            $this->requiredError($key, $label);

            return '';
        }

        $value = trim((string) $value);
        $length = mb_strlen($value);

        if ($length < $min) {
            $this->fail($key, I18n::t('validation.min_length', ['field' => $this->field($label), 'min' => $min]));
        }

        if ($length > $max) {
            $this->fail($key, I18n::t('validation.max_length', ['field' => $this->field($label), 'max' => $max]));
        }

        return $this->clean[$key] = $value;
    }

    public function optionalString(string $key, string $label, int $min = 0, int $max = 5000): ?string
    {
        $value = $this->raw($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value)) {
            $this->fail($key, I18n::t('validation.string', ['field' => $this->field($label)]));

            return null;
        }

        $value = trim((string) $value);
        $length = mb_strlen($value);

        if ($length < $min) {
            $this->fail($key, I18n::t('validation.min_length', ['field' => $this->field($label), 'min' => $min]));
        }

        if ($length > $max) {
            $this->fail($key, I18n::t('validation.max_length', ['field' => $this->field($label), 'max' => $max]));
        }

        return $this->clean[$key] = $value;
    }

    public function requiredInt(string $key, string $label, ?int $min = null, ?int $max = null): int
    {
        $value = $this->raw($key);

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            $this->fail($key, I18n::t('validation.integer', ['field' => $this->field($label)]));

            return 0;
        }

        if ($min !== null && $value < $min) {
            $this->fail($key, I18n::t('validation.min', ['field' => $this->field($label), 'min' => $min]));
        }

        if ($max !== null && $value > $max) {
            $this->fail($key, I18n::t('validation.max', ['field' => $this->field($label), 'max' => $max]));
        }

        return $this->clean[$key] = $value;
    }

    public function optionalInt(string $key, string $label, ?int $min = null, ?int $max = null): ?int
    {
        $value = $this->raw($key);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->requiredInt($key, $label, $min, $max);
    }

    public function requiredBool(string $key, string $label): bool
    {
        $value = $this->raw($key);

        if (!is_bool($value) && !is_int($value) && !is_string($value)) {
            $this->fail($key, I18n::t('validation.boolean', ['field' => $this->field($label)]));

            return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            $this->fail($key, I18n::t('validation.boolean', ['field' => $this->field($label)]));

            return false;
        }

        return $this->clean[$key] = $parsed;
    }

    /**
     * @param list<string> $allowed
     */
    public function requiredEnum(string $key, string $label, array $allowed): string
    {
        $value = $this->requiredString($key, $label);

        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->fail($key, I18n::t('validation.enum', [
                'field' => $this->field($label),
                'allowed' => implode(', ', $allowed),
            ]));
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public function requiredList(string $key, string $label, int $max = 100): array
    {
        $value = $this->raw($key);

        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            $this->fail($key, I18n::t('validation.list', ['field' => $this->field($label)]));

            return [];
        }

        if (count($value) > $max) {
            $this->fail($key, I18n::t('validation.too_many_items', ['field' => $this->field($label), 'max' => $max]));
        }

        return $this->clean[$key] = $value;
    }

    public function requiredEmail(string $key, string $label): string
    {
        $value = $this->requiredString($key, $label, 5, 254);

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($key, I18n::t('validation.email', ['field' => $this->field($label)]));
        }

        return $value;
    }

    public function requiredIp(string $key, string $label, int $version = 0): string
    {
        $value = $this->requiredString($key, $label, 2, 45);

        $flags = $version === 4 ? FILTER_FLAG_IPV4 : ($version === 6 ? FILTER_FLAG_IPV6 : 0);

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP, $flags) === false) {
            $this->fail($key, I18n::t('validation.ip', ['field' => $this->field($label)]));
        }

        return $value;
    }

    public function fail(string $key, string $message): void
    {
        $this->errors[$key] ??= $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->clean;
    }

    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }

    private function requiredError(string $key, string $label): void
    {
        $this->fail($key, I18n::t('validation.required', ['field' => $this->field($label)]));
    }

    private function field(string $label): string
    {
        $fieldKey = self::FIELD_KEYS[$label] ?? null;

        if ($fieldKey === null) {
            return $label;
        }

        return I18n::t('field.' . $fieldKey, [], $label);
    }
}
