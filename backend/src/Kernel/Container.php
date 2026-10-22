<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Tiny autowiring service container.
 *
 * Constructor dependencies are resolved by type. Scalars are intentionally not
 * auto-resolved: pass them through Config so the dependency graph stays
 * explicit and testable. Bindings let a test replace a collaborator with a
 * double in one place.
 */
final class Container
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, class-string> */
    private array $bindings = [];

    /** @var array<string, Closure(self): object> */
    private array $factories = [];

    /**
     * @param array<string, object|Closure(self): object> $overrides
     */
    public function __construct(
        private readonly Config $config,
        array $overrides = [],
    ) {
        $this->instances[Config::class] = $config;
        $this->instances[self::class] = $this;

        foreach ($overrides as $id => $override) {
            if ($override instanceof Closure) {
                $this->factories[$id] = $override;

                continue;
            }

            if (is_object($override)) {
                $this->instances[$id] = $override;

                continue;
            }

            throw new RuntimeException(sprintf('Container override for "%s" must be an object or closure.', $id));
        }
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * @param class-string $concrete
     */
    public function bind(string $id, string $concrete): void
    {
        $this->bindings[$id] = $concrete;
    }

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            /** @var T */
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $instance = ($this->factories[$id])($this);
            $this->instances[$id] = $instance;

            /** @var T */
            return $instance;
        }

        $concrete = $this->bindings[$id] ?? $id;

        if (!class_exists($concrete)) {
            throw new RuntimeException(sprintf('Service "%s" is not registered and class "%s" does not exist.', $id, $concrete));
        }

        $instance = $this->build($concrete);
        $this->instances[$id] = $instance;

        /** @var T */
        return $instance;
    }

    /**
     * @param class-string $class
     */
    private function build(string $class): object
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException(sprintf('Class "%s" is not instantiable.', $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new RuntimeException(sprintf(
                'Cannot autowire parameter $%s of %s::__construct(). Register it explicitly in the container.',
                $parameter->getName(),
                $class,
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
