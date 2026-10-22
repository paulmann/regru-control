<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * Security headers applied to every response.
 *
 * The CSP is deliberately strict: the frontend is a module-based SPA with
 * external CSS/JS only, no inline scripts, no CDN and no remote fonts.
 */
final class SecurityHeaders
{
    public static function apply(Response $response, Config $config, Request $request): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        if (!str_contains($response->body, 'text/html') && !str_starts_with($response->headers['Content-Type'] ?? '', 'text/html')) {
            // Public assets may be cached by the browser; API responses may not.
            $headers['Cache-Control'] = $request->isApi() ? 'no-store, max-age=0' : 'no-cache';
            $headers['Pragma'] = 'no-cache';
        }

        if (self::isHttps($request)) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        if (str_starts_with($response->headers['Content-Type'] ?? '', 'text/html')) {
            $headers['Content-Security-Policy'] = implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]);
        }

        return $response->withHeaders($headers);
    }

    private static function isHttps(Request $request): bool
    {
        $https = strtolower((string) ($request->server['HTTPS'] ?? ''));

        return $https === 'on' || $https === '1' || $request->header('x-forwarded-proto') === 'https';
    }
}
