<?php

namespace Utopia\Agents;

abstract class Role
{
    /**
     * Role constants
     */
    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_USER = 'user';

    protected string $id;

    protected string $name;

    /**
     * Create a new role
     */
    public function __construct(string $id, string $name = '')
    {
        $this->id = $id;
        $this->name = $name;
    }

    /**
     * Get the role's ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the role's name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the role's name
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the role identifier
     */
    abstract public function getIdentifier(): string;
}
