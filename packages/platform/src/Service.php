<?php

namespace Utopia\Platform;

abstract class Service
{
    public const TYPE_HTTP = 'http';

    public const TYPE_GRAPHQL = 'GraphQL';

    public const TYPE_TASK = 'Task';

    public const TYPE_WORKER = 'Worker';

    protected array $actions;

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
     * Add
     */
    public function addAction(string $key, Action $action): Service
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
        return $this->actions[$key] ?? null;
    }

    /**
     * Get Actions
     */
    public function getActions(): array
    {
        return $this->actions;
    }
}
