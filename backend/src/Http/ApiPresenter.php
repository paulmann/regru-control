<?php

declare(strict_types=1);

namespace RegRuControl\Http;

use RegRuControl\Kernel\Response;

/**
 * Uniform JSON envelope:
 *   success: {"data": ..., "meta": {...}}
 *   error:   {"error": {"code", "message", "details"}, "request_id": "..."}
 */
final class ApiPresenter
{
    /**
     * @param array<string, mixed> $meta
     */
    public static function data(mixed $data, array $meta = [], int $status = 200): Response
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return Response::json($payload, $status);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function created(mixed $data, array $meta = []): Response
    {
        return self::data($data, $meta, 201);
    }

    /**
     * @param list<mixed> $items
     * @param array<string, mixed> $meta
     */
    public static function page(array $items, int $total, int $page, int $perPage, array $meta = []): Response
    {
        return self::data($items, $meta + [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    public static function noContent(): Response
    {
        return Response::noContent();
    }
}
