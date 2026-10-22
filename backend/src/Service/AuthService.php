<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\I18n;
use RegRuControl\Model\User;
use RegRuControl\Repository\UserRepository;
use RegRuControl\Security\PasswordHasher;
use RegRuControl\Security\PasswordPolicy;
use RegRuControl\Security\Session;
use RegRuControl\Support\RateLimiter;

/**
 * Login, logout and session lifecycle.
 *
 * Two independent brakes protect against password guessing: a per-IP rate
 * limit and a per-account lockout window with a failed-attempt counter.
 */
final readonly class AuthService
{
    public function __construct(
        private UserRepository $users,
        private Session $session,
        private PasswordHasher $hasher,
        private PasswordPolicy $policy,
        private RateLimiter $limiter,
        private AuditService $audit,
        private Config $config,
        private Clock $clock,
    ) {
    }

    public function attempt(string $login, string $password, string $ip): User
    {
        $login = trim($login);
        $attemptLimit = max(3, $this->config->int('security.login_attempts', 10));
        $window = max(60, $this->config->int('security.login_window', 900));

        if (!$this->limiter->hit('login_ip', $ip, $attemptLimit * 3, $window)) {
            $this->audit->record('auth.login', $login, ['reason' => 'rate_limited', 'ip' => $ip], 'failed');

            throw HttpException::tooManyRequests(
                I18n::t('auth.too_many'),
                [],
                $this->limiter->retryAfter($window),
            );
        }

        $row = $this->users->findAuthRow($login);

        if ($row === null) {
            usleep(150_000);
            $this->audit->record('auth.login', $login, ['reason' => 'unknown_login', 'ip' => $ip], 'failed');

            throw HttpException::unauthorized(I18n::t('auth.invalid_credentials'));
        }

        if ((int) ($row['is_active'] ?? 1) !== 1) {
            $this->audit->record('auth.login', $login, ['reason' => 'disabled'], 'failed');

            throw HttpException::forbidden(I18n::t('auth.account_disabled'));
        }

        $lockedUntil = $row['locked_until'] ?? null;

        if (is_string($lockedUntil) && strtotime($lockedUntil) > $this->clock->now()->getTimestamp()) {
            $seconds = max(1, strtotime($lockedUntil) - $this->clock->now()->getTimestamp());

            throw HttpException::tooManyRequests(
                I18n::t('auth.locked'),
                ['locked_until' => $lockedUntil],
                $seconds,
            );
        }

        if (!$this->hasher->verify($password, (string) $row['password_hash'])) {
            $attempts = (int) ($row['failed_attempts'] ?? 0) + 1;
            $lock = null;

            if ($attempts >= $attemptLimit) {
                $lock = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()->getTimestamp() + $window);
                $attempts = 0;
            }

            $this->users->recordFailedLogin((int) $row['id'], $attempts, $lock);
            $this->audit->record('auth.login', $login, ['reason' => 'bad_password', 'attempts' => $attempts], 'failed');

            throw HttpException::unauthorized(I18n::t('auth.invalid_credentials'));
        }

        if ($this->hasher->needsRehash((string) $row['password_hash'])) {
            $this->users->updatePassword((int) $row['id'], $this->hasher->hash($password));
        }

        $this->users->recordSuccessfulLogin((int) $row['id'], $ip);
        $this->session->login((int) $row['id']);
        $this->limiter->reset('login_ip', $ip);
        $this->audit->record('auth.login', $login, ['ip' => $ip], 'ok');

        $user = $this->users->findById((int) $row['id']);

        if ($user === null) {
            throw HttpException::unauthorized(I18n::t('error.internal'));
        }

        return $user;
    }

    public function currentUser(): ?User
    {
        $id = $this->session->userId();

        if ($id === null) {
            return null;
        }

        if ($this->session->isIdle()) {
            $this->session->destroy();

            return null;
        }

        $user = $this->users->findById($id);

        if ($user === null || !$user->isActive) {
            $this->session->destroy();

            return null;
        }

        $this->session->touch();

        return $user;
    }

    public function logout(): void
    {
        $user = $this->currentUser();

        if ($user !== null) {
            $this->audit->record('auth.logout', $user->login);
        }

        $this->session->destroy();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        $row = $this->users->findAuthRow($user->login);

        if ($row === null || !$this->hasher->verify($currentPassword, (string) $row['password_hash'])) {
            $this->audit->record('auth.password_change', $user->login, [], 'failed');

            throw HttpException::badRequest(I18n::t('auth.current_password_wrong'), ['fields' => ['current_password' => 'Неверный пароль.']]);
        }

        $errors = $this->policy->validate($newPassword);

        if ($errors !== []) {
            throw new HttpException(422, 'VALIDATION_FAILED', 'Проверьте новый пароль.', [
                'fields' => ['new_password' => implode(' ', $errors)],
            ]);
        }

        if ($currentPassword === $newPassword) {
            throw HttpException::badRequest(I18n::t('auth.password_same'));
        }

        $this->users->updatePassword($user->id, $this->hasher->hash($newPassword));
        $this->audit->record('auth.password_change', $user->login);
    }
}
