<?php

namespace Utopia\Platform;

abstract class Service
{
    public const TYPE_HTTP = 'http';

    public const TYPE_GRAPHQL = 'GraphQL';

    public const TYPE_TASK = 'Task';

    public const TYPE_WORKER = 'Worker';

    /**
     * @var array<string, Action|class-string<Action>>
     */
    protected array $actions = [];

    protected string $type;

    /**
     * Set Type
     */
    public function setType(string $type): Service
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get Type
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Add an action, or its class name to construct it on first use.
     *
     * @param Action|class-string<Action> $action
     */
    public function addAction(string $key, Action|string $action): Service
    {
        $this->actions[$key] = $action;

        return $this;
    }

    /**
     * Remove Action
     */
    public function removeAction(string $key): Service
    {
        unset($this->actions[$key]);

        return $this;
    }

    /**
     * Get Action
     */
    public function getAction(string $key): ?Action
    {
        return isset($this->actions[$key]) ? $this->resolveAction($key) : null;
    }

    /**
     * Get Actions
     *
     * @return array<string, Action>
     */
    public function getActions(): array
    {
        $actions = [];

        foreach (\array_keys($this->actions) as $key) {
            $actions[$key] = $this->resolveAction($key);
        }

        return $actions;
    }

    private function resolveAction(string $key): Action
    {
        $action = $this->actions[$key];

        if (\is_string($action)) {
            $action = new $action();
            $this->actions[$key] = $action;
        }

        return $action;
    }
}
