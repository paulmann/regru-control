<?php

declare(strict_types=1);

namespace RegRuControl\RegRu;

use RegRuControl\Kernel\HttpException;

/**
 * Base class for all REG.RU API failures. Extends HttpException so the global
 * error handler renders it as a JSON problem without extra branching.
 */
class RegRuException extends HttpException
{
    public function __construct(string $message, int $status = 502, array $details = [])
    {
        parent::__construct($status, 'REGRU_API_ERROR', $message, $details);
    }
}
