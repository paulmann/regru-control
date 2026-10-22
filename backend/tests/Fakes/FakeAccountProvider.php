<?php

declare(strict_types=1);

namespace RegRuControl\Tests\Fakes;

use RegRuControl\RegRu\RegRuAccountProvider;

final class FakeAccountProvider implements RegRuAccountProvider
{
    public function __construct(
        private bool $configured = true,
        private string $username = 'test',
        private string $password = 'test',
    ) {
    }

    public function credentials(): array
    {
        return ['username' => $this->username, 'password' => $this->password];
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
