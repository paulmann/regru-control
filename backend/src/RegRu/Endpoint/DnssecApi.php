<?php

declare(strict_types=1);

namespace RegRuControl\RegRu\Endpoint;

use RegRuControl\RegRu\RegRuClient;

/**
 * DNSSEC commands (`dnssec/*`).
 */
final readonly class DnssecApi
{
    public function __construct(private RegRuClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $dname): array
    {
        return $this->client->call('dnssec/get_status', ['dname' => $dname])->answer();
    }

    /**
     * @return array<string, mixed>
     */
    public function enable(string $dname): array
    {
        return $this->client->call('dnssec/enable', ['dname' => $dname])->answer();
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(string $dname): array
    {
        return $this->client->call('dnssec/disable', ['dname' => $dname])->answer();
    }
}
