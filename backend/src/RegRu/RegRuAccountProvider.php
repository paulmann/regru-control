<?php

declare(strict_types=1);

namespace RegRuControl\RegRu;

/**
 * Supplies the REG.RU API credentials for every outbound call.
 *
 * Implemented by the service layer on top of the encrypted settings table;
 * keeping it an interface means the API client stays unit-testable without a
 * database.
 */
interface RegRuAccountProvider
{
    /**
     * @return array{username: string, password: string}
     */
    public function credentials(): array;

    public function isConfigured(): bool;
}
