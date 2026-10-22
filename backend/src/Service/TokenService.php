<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Model\ApiToken;
use RegRuControl\Model\TokenScope;
use RegRuControl\Repository\ApiTokenRepository;
use RegRuControl\Security\ApiTokenGenerator;

/**
 * API token lifecycle. The plaintext token exists exactly once — in the
 * create() response; only its SHA-256 hash is stored.
 */
final readonly class TokenService
{
    public function __construct(
        private ApiTokenRepository $repository,
        private ApiTokenGenerator $generator,
        private Clock $clock,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @return array{token: ApiToken, plain: string}
     */
    public function create(string $name, array $scopes, ?int $ttlDays, ?int $createdBy): array
    {
        $parsed = [];

        foreach ($scopes as $scope) {
            $case = TokenScope::tryFrom((string) $scope);

            if ($case !== null) {
                $parsed[$case->value] = $case;
            }
        }

        if ($parsed === []) {
            $parsed[TokenScope::Read->value] = TokenScope::Read;
        }

        $generated = $this->generator->generate();
        $expiresAt = $ttlDays !== null && $ttlDays > 0
            ? gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()->getTimestamp() + $ttlDays * 86400)
            : null;

        $id = $this->repository->create(
            $name,
            $generated['prefix'],
            $generated['hash'],
            array_keys($parsed),
            $createdBy,
            $expiresAt,
        );

        $token = $this->repository->find($id);

        if ($token === null) {
            throw new \RuntimeException('Token was not stored.');
        }

        return ['token' => $token, 'plain' => $generated['plain']];
    }

    public function authenticate(string $plain): ?ApiToken
    {
        if (!$this->generator->looksLikeToken($plain)) {
            return null;
        }

        $row = $this->repository->findByHash($this->generator->hash($plain));

        if ($row === null) {
            return null;
        }

        $token = ApiToken::fromRow($row);

        if (!$token->isActive($this->clock->now())) {
            return null;
        }

        $this->repository->touch($token->id);

        return $token;
    }

    /** @return list<ApiToken> */
    public function all(): array
    {
        return $this->repository->all();
    }

    public function revoke(int $id): bool
    {
        return $this->repository->revoke($id);
    }

    public function countActive(): int
    {
        return $this->repository->countActive();
    }
}
