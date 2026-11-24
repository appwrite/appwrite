<?php

namespace Utopia\DI;

use Psr\Container\ContainerInterface;

/**
 * @phpstan-consistent-constructor
 */
class Container implements ContainerInterface
{
    /**
     * Map of dependency IDs to their required dependency IDs.
     *
     * @var array<string, list<string>>
     */
    private array $dependencies = [];

    /**
     * Map of dependency IDs to their factory callables.
     *
     * @var array<string, callable>
     */
    private array $factories = [];

    /**
     * Map of dependency IDs to a cache of resolved instances.
     *
     * @var array<string, mixed>
     */
    private array $concrete = [];

    /**
     * @param ContainerInterface|null $parent Optional parent container for hierarchical resolution.
     */
    public function __construct(
        private readonly ?ContainerInterface $parent = null,
    ) {
    }

    /**
     * Register a dependency factory on the current container.
     *
     * If a dependency with the same ID already exists, it will be overridden.
     *
     * @param string $id Unique identifier for the dependency.
     * @param callable $factory Factory callable invoked to create the instance.
     * @param list<string> $dependencies List of dependency IDs required by the factory.
     */
    public function set(string $id, callable $factory, array $dependencies = []): static
    {
        $this->factories[$id] = $factory;
        $this->dependencies[$id] = $dependencies;
        unset($this->concrete[$id]);

        return $this;
    }

    /**
     * Resolve dependencies, and everything built from them, in this container's own scope.
     *
     * A child that does not define a key reuses the value its parent resolved, which was built
     * from the parent's dependencies. Once the child overrides one of those, the values derived
     * from it have to be rebuilt in the child too, or the child keeps reading the parent's.
     * Scoping an identifier registers this container's own copy of its factory, and of every
     * factory in the chain that depends on it directly or transitively. Parents keep their values.
     *
     * Only factories registered on a Container can be copied. The walk up the chain stops at the
     * first parent that is any other PSR-11 container, since that container exposes values, not
     * the factories that build them.
     *
     * @param string ...$ids Identifiers of dependencies registered on this container or a Container ancestor.
     * @throws Exceptions\NotFoundException When no Container in the chain registers an identifier.
     */
    public function scope(string ...$ids): static
    {
        $definitions = [];
        for ($container = $this; $container instanceof self; $container = $container->parent) {
            foreach ($container->factories as $id => $factory) {
                $definitions[$id] ??= [$factory, $container->dependencies[$id]];
            }
        }

        foreach ($ids as $id) {
            if (! \array_key_exists($id, $definitions)) {
                throw new Exceptions\NotFoundException("Dependency $id has no factory on this container or a Container ancestor to scope");
            }
        }

        $scoped = \array_fill_keys($ids, true);
        do {
            $added = false;
            foreach ($definitions as $id => [, $dependencies]) {
                if (isset($scoped[$id])) {
                    continue;
                }
                foreach ($dependencies as $dependency) {
                    if (isset($scoped[$dependency])) {
                        $scoped[$id] = true;
                        $added = true;
                        break;
                    }
                }
            }
        } while ($added);

        foreach (\array_keys($scoped) as $id) {
            [$factory, $dependencies] = $definitions[$id];
            $this->set($id, $factory, $dependencies);
        }

        return $this;
    }

    public function get(string $id): mixed
    {
        if (\array_key_exists($id, $this->concrete)) {
            return $this->concrete[$id];
        }

        if (\array_key_exists($id, $this->factories)) {
            $concrete = $this->build($id);
            $this->concrete[$id] = $concrete;
            return $concrete;
        }

        if ($this->parent instanceof ContainerInterface) {
            return $this->parent->get($id);
        }

        throw new Exceptions\NotFoundException("Dependency $id not found");
    }

    public function has(string $id): bool
    {
        if (\array_key_exists($id, $this->factories)) {
            return true;
        }

        return $this->parent?->has($id) ?? false;
    }

    /**
     * Build a dependency by resolving its dependencies and invoking its factory.
     *
     * @param string $id Identifier of the dependency to build.
     * @return mixed The constructed dependency instance.
     */
    private function build(string $id): mixed
    {
        $dependencies = [];
        foreach ($this->dependencies[$id] as $dependency) {
            $dependencies[] = $this->get($dependency);
        }

        return \call_user_func($this->factories[$id], ...$dependencies);
    }
}
