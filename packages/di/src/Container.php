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
