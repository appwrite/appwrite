<?php

namespace Appwrite\GraphQL\Types;

use GraphQL\Type\Definition\Type;

class Registry
{
    /**
     * @var array<string, Type> Per-project type storage
     */
    private array $types = [];

    /**
     * @var array<string, Type> Shared base types (boolean, string, etc.)
     */
    private array $baseTypes = [];

    /**
     * @var string Current project context
     */
    private string $projectId = '';

    /**
     * Create a new Registry instance.
     *
     * @param string $projectId The project ID for this registry
     */
    public function __construct(string $projectId = '')
    {
        $this->projectId = $projectId;
    }

    /**
     * Get the current project ID.
     */
    public function getProjectId(): string
    {
        return $this->projectId;
    }

    /**
     * Set the current project ID.
     */
    public function setProjectId(string $projectId): void
    {
        $this->projectId = $projectId;
    }

    /**
     * Check if a type exists in the registry (checks base types first, then project types).
     */
    public function has(string $type): bool
    {
        return isset($this->baseTypes[$type]) || isset($this->types[$type]);
    }

    /**
     * Get a type from the registry.
     */
    public function get(string $type): Type
    {
        if (isset($this->baseTypes[$type])) {
            return $this->baseTypes[$type];
        }

        if (!isset($this->types[$type])) {
            throw new \RuntimeException("Type '{$type}' not found in registry for project '{$this->projectId}'");
        }

        return $this->types[$type];
    }

    /**
     * Set a type in the registry.
     *
     * @param string $type The type name
     * @param Type $typeObject The type object
     * @param bool $isBaseType If true, stores as a shared base type
     */
    public function set(string $type, Type $typeObject, bool $isBaseType = false): void
    {
        if ($isBaseType) {
            $this->baseTypes[$type] = $typeObject;
        } else {
            $this->types[$type] = $typeObject;
        }
    }

    /**
     * Clear all project types (keeps base types by default).
     *
     * @param bool $includeBaseTypes If true, also clears base types
     */
    public function clear(bool $includeBaseTypes = false): void
    {
        $this->types = [];
        if ($includeBaseTypes) {
            $this->baseTypes = [];
        }
    }

    /**
     * Initialize base types that are shared across all schemas.
     *
     * @param array<string, Type> $types
     */
    public function initBaseTypes(array $types): void
    {
        foreach ($types as $name => $type) {
            $this->baseTypes[$name] = $type;
        }
    }

    /**
     * Get all registered types (excluding base types).
     *
     * @return array<string, Type>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Get all base types.
     *
     * @return array<string, Type>
     */
    public function getBaseTypes(): array
    {
        return $this->baseTypes;
    }
}
