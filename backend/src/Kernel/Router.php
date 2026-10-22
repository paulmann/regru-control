<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Attribute-based router.
 *
 * Patterns use `{name}` placeholders and match a single path segment. The
 * router distinguishes 404 (unknown path) from 405 (known path, wrong method)
 * and lists allowed methods in the `Allow` header.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: array{class-string, string}, middleware: list<class-string<Middleware>>}> */
    private array $routes = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param class-string $controller
     */
    public function registerController(string $controller): void
    {
        $reflection = new ReflectionClass($controller);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            foreach ($method->getAttributes(Route::class) as $attribute) {
                /** @var Route $route */
                $route = $attribute->newInstance();
                $this->add($route->method, $route->path, [$controller, $method->getName()], $route->middleware);
            }
        }
    }

    /**
     * @param array{class-string, string} $handler
     * @param list<class-string<Middleware>> $middleware
     */
    public function add(string $method, string $path, array $handler, array $middleware = []): void
    {
        $method = strtoupper($method);
        $path = Request::normalizePath($path);
        $regex = '#^' . preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', preg_quote($path, '#')) . '$#';

        // preg_quote escaped the braces, restore the placeholder groups.
        $regex = '#^' . preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            preg_quote($path, '#'),
        ) . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $path,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];

                continue;
            }

            $params = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            $handler = fn (Request $current): Response => $this->invoke($route['handler'], $current, $params);

            return Pipeline::handle($route['middleware'], $request, $handler, $this->container);
        }

        if ($allowed !== []) {
            throw new HttpException(
                405,
                'METHOD_NOT_ALLOWED',
                'Метод не поддерживается для этого адреса.',
                ['allowed' => array_values(array_unique($allowed))],
                ['Allow' => implode(', ', array_unique($allowed))],
            );
        }

        throw HttpException::notFound('Маршрут не найден.', ['path' => $request->path]);
    }

    /** @return list<string> */
    public function routeList(): array
    {
        return array_map(
            static fn (array $route): string => $route['method'] . ' ' . $route['pattern'],
            $this->routes,
        );
    }

    /**
     * @param array{class-string, string} $handler
     * @param array<string, string> $params
     */
    private function invoke(array $handler, Request $request, array $params): Response
    {
        [$class, $methodName] = $handler;

        $controller = $this->container->get($class);
        $reflection = new ReflectionMethod($controller, $methodName);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), Request::class, true)) {
                $arguments[] = $request;

                continue;
            }

            if (array_key_exists($name, $params)) {
                $arguments[] = $type instanceof ReflectionNamedType && $type->getName() === 'int'
                    ? (int) $params[$name]
                    : $params[$name];

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            throw new \RuntimeException(sprintf('Cannot resolve parameter $%s of %s::%s().', $name, $class, $methodName));
        }

        $result = $reflection->invokeArgs($controller, $arguments);

        if (!$result instanceof Response) {
            throw new \RuntimeException(sprintf('%s::%s() must return %s.', $class, $methodName, Response::class));
        }

        return $result;
    }
}
