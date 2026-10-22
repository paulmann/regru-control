<?php

declare(strict_types=1);

namespace RegRuControl\RegRu;

/**
 * Decoded REG.RU API2 response.
 */
final readonly class ApiResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $command,
        public array $raw,
    ) {
    }

    public function isSuccess(): bool
    {
        return ($this->raw['result'] ?? null) === 'success';
    }

    /** @return array<string, mixed> */
    public function answer(): array
    {
        $answer = $this->raw['answer'] ?? [];

        return is_array($answer) ? $answer : [];
    }

    /** @return list<array<string, mixed>> */
    public function services(): array
    {
        return $this->listAnswer('services');
    }

    /** @return list<array<string, mixed>> */
    public function domains(): array
    {
        return $this->listAnswer('domains');
    }

    /** @return list<array<string, mixed>> */
    public function bills(): array
    {
        return $this->listAnswer('bills');
    }

    /**
     * Per-domain result inside a multi-domain response.
     *
     * @return array<string, mixed>|null
     */
    public function domainResult(string $dname): ?array
    {
        foreach ($this->domains() as $domain) {
            if (($domain['dname'] ?? null) === $dname) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>> list of failing domain entries
     */
    public function failedDomains(): array
    {
        $failed = [];

        foreach ($this->domains() as $domain) {
            if (($domain['result'] ?? null) !== 'success') {
                $failed[] = $domain;
            }
        }

        return $failed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAnswer(string $key): array
    {
        $value = $this->answer()[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
