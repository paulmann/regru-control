<?php

declare(strict_types=1);

namespace RegRuControl\Http\Controller;

use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;

/**
 * Serves the single-page application shell.
 *
 * The shell is static (no inline script/style) so the strict CSP holds; all
 * data is fetched from `/api/v1/*`.
 */
final readonly class PageController
{
    public function __construct(private Config $config)
    {
    }

    #[Route('GET', '/')]
    #[Route('GET', '/index.html')]
    public function index(Request $request): Response
    {
        $file = APP_ROOT . '/frontend/index.html';

        if (!is_file($file)) {
            return Response::html($this->fallback(), 500);
        }

        $html = file_get_contents($file);

        if ($html === false) {
            return Response::html($this->fallback(), 500);
        }

        return Response::html($html)->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function fallback(): string
    {
        return '<!doctype html><html lang="ru"><meta charset="utf-8"><title>REG.RU Control</title>'
            . '<body style="background:#0d1117;color:#e6edf3;font:16px system-ui;display:grid;place-items:center;height:100vh">'
            . '<p>Фронтенд не собран: ожидался файл <code>frontend/index.html</code>.</p></body></html>';
    }
}
