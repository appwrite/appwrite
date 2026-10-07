<?php

namespace Utopia\Database\Validator\Authorization;

class Input
{
    /**
     * @var array<string> $permissions
     */
    protected array $permissions;
    protected string $action;

    /**
     * @param string[] $permissions
     */
    public function __construct(string $action, array $permissions)
    {
        $this->permissions = $permissions;
        $this->action = $action;
    }

    /**
     * @param string[] $permissions
     */
    public function setPermissions(array $permissions): self
    {
        $this->permissions = $permissions;
        return $this;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function getAction(): string
    {
        return $this->action;
    }
}
