<?php

declare(strict_types=1);

namespace RegRuControl\Security;

use SensitiveParameter;

/**
 * Password policy for panel users.
 *
 * Long passphrases are allowed and encouraged; the rules block only the
 * obviously weak cases, because forced symbol soup harms real security.
 */
final class PasswordPolicy
{
    private const MIN_LENGTH = 12;
    private const MAX_LENGTH = 200;

    /** @var list<string> */
    private const DENY_LIST = [
        'password', 'password1', 'qwerty', 'qwerty123', '123456', '12345678',
        '123456789', 'admin', 'admin123', 'letmein', 'welcome', 'regru',
        'changeme', 'пароль', 'йцукен',
    ];

    /**
     * @return list<string> human readable problems, empty when the password is acceptable
     */
    public function validate(#[SensitiveParameter] string $password): array
    {
        $errors = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $errors[] = sprintf('Пароль должен быть не короче %d символов.', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('Пароль должен быть не длиннее %d символов.', self::MAX_LENGTH);
        }

        if (preg_match('/\s/u', $password) === 1) {
            $errors[] = 'Пароль не должен содержать пробелов.';
        }

        $classes = 0;
        $classes += preg_match('/[a-zа-я]/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[A-ZА-Я]/u', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\d/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^\p{L}\d]/u', $password) === 1 ? 1 : 0;

        if ($classes < 2) {
            $errors[] = 'Пароль должен содержать минимум два класса символов: строчные, заглавные, цифры, спецсимволы.';
        }

        if (in_array(mb_strtolower($password), self::DENY_LIST, true)) {
            $errors[] = 'Этот пароль слишком распространён.';
        }

        return $errors;
    }

    public function isAcceptable(#[SensitiveParameter] string $password): bool
    {
        return $this->validate($password) === [];
    }
}
