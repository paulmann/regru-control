<?php

declare(strict_types=1);

namespace RegRuControl\Http;

use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\Request;
use RegRuControl\Model\ApiToken;
use RegRuControl\Model\TokenScope;
use RegRuControl\Model\User;

/**
 * The authenticated actor of the current request: either a panel user
 * (session cookie) or an API token (Bearer). Controllers ask `can()`/`require()`
 * instead of branching on the authentication mechanism.
 */
final readonly class AuthContext
{
    /**
     * @param list<TokenScope> $scopes
     */
    public function __construct(
        public string $actorType,
        public string $actorName,
        public ?User $user = null,
        public ?ApiToken $token = null,
        public array $scopes = [],
    ) {
    }

    public static function guest(): self
    {
        return new self('guest', 'guest', null, null, []);
    }

    public static function forUser(User $user): self
    {
        return new self('ui', $user->login, $user, null, TokenScope::all());
    }

    /**
     * @param list<TokenScope> $scopes
     */
    public static function forToken(ApiToken $token, array $scopes): self
    {
        return new self('api', $token->name, null, $token, $scopes);
    }

    public static function fromRequest(Request $request): self
    {
        $auth = $request->attribute('auth');

        if (!$auth instanceof self) {
            throw HttpException::unauthorized();
        }

        return $auth;
    }

    public function isAuthenticated(): bool
    {
        return $this->actorType !== 'guest';
    }

    public function isAdmin(): bool
    {
        if ($this->user !== null) {
            return $this->user->isAdmin();
        }

        return $this->can(TokenScope::Admin);
    }

    public function can(TokenScope $required): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope->implies($required)) {
                return true;
            }
        }

        return false;
    }

    public function require(TokenScope $required): void
    {
        if (!$this->can($required)) {
            throw HttpException::forbidden(
                sprintf('Для этого действия нужен доступ «%s».', $required->label()),
                ['required_scope' => $required->value],
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_type' => $this->actorType,
            'actor' => $this->actorName,
            'user' => $this->user?->toArray(),
            'token' => $this->token === null ? null : [
                'id' => $this->token->id,
                'name' => $this->token->name,
                'scopes' => $this->token->scopeValues(),
            ],
            'scopes' => array_map(static fn (TokenScope $scope): string => $scope->value, $this->scopes),
            'is_admin' => $this->isAdmin(),
        ];
    }
}
