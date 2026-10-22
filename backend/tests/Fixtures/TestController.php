<?php

declare(strict_types=1);

namespace RegRuControl\Tests\Fixtures;

use RegRuControl\Kernel\Request;
use RegRuControl\Kernel\Response;
use RegRuControl\Kernel\Route;

final readonly class TestController
{
    #[Route('GET', '/test/hello')]
    public function hello(Request $request): Response
    {
        return Response::json(['ok' => true, 'path' => $request->path]);
    }

    #[Route('POST', '/test/hello')]
    public function create(Request $request): Response
    {
        return Response::json(['created' => true], 201);
    }

    #[Route('GET', '/test/user/{id}')]
    public function user(Request $request, int $id): Response
    {
        return Response::json(['id' => $id]);
    }
}
