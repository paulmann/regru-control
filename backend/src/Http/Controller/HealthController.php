<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Database\Database;
use RegRuControl\Http\ApiPresenter;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;
use RegRuControl\Service\RegRuAccountService;
use Throwable;

/**
 * Public liveness/readiness probe. Never exposes secrets or account details.
 */
final readonly class HealthController
{
    public function __construct(
        private Database $database,
        private Config $config,
        private Clock $clock,
        private RegRuAccountService $account,
    ) {
    }

    #[Route('GET', '/api/v1/health')]
    public function show(Request $request): Response
    {
        $dbOk = true;

        try {
            $this->database->fetchValue('SELECT 1');
        } catch (Throwable) {
            $dbOk = false;
        }

        return ApiPresenter::data([
            'status' => $dbOk ? 'ok' : 'degraded',
            'version' => $this->config->string('app.version'),
            'php' => PHP_VERSION,
            'time' => $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
            'db' => ['ok' => $dbOk],
            'regru_configured' => $this->account->status()['configured'],
        ]);
    }
}
