<?php

namespace Utopia\Agents\Roles;

use Utopia\Agents\Role;

class Assistant extends Role
{
    /**
     * Create a new assistant
     */
    public function __construct(string $id, string $name = '')
    {
        parent::__construct($id, $name);
    }

    /**
     * Get the role identifier
     */
    public function getIdentifier(): string
    {
        return Role::ROLE_ASSISTANT;
    }
}
