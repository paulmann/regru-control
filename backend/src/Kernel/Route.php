<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use Attribute;

/**
 * Declares an HTTP route on a controller method.
 *
 * ```php
 * #[Route('GET', '/api/v1/domains/{name}', [AuthMiddleware::class])]
 * ```
 *
 * A method can carry several attributes (`IS_REPEATABLE`) to expose, for
 * example, both `GET` and `POST` on the same path handler.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /**
     * @param list<class-string<Middleware>> $middleware
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $middleware = [],
    ) {
    }
}
