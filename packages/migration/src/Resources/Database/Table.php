<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Table extends Resource
{
    /**
     * @param Database $database
     * @param string $name
     * @param string $id
     * @param bool $rowSecurity
     * @param array<string> $permissions
     * @param string $createdAt
     * @param string $updatedAt
     * @param bool $enabled
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $name,
        string $id,
        private readonly bool $rowSecurity = false,
        array $permissions = [],
        protected string $createdAt = '',
        protected string $updatedAt = '',
        protected bool $enabled = true,
    ) {
        $this->id = $id;
        $this->permissions = $permissions;
    }

    /**
     * @param array{
     *     database: array{
     *        id: string,
     *        name: string,
     *     },
     *     name: string,
     *     id: string,
     *     documentSecurity?: bool,
     *     rowSecurity?: bool,
     *     permissions: ?array<string>,
     *     createdAt: string,
     *     updatedAt: string,
     *     enabled: bool
     * } $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            Database::fromArray($array['database']),
            name: $array['name'],
            id: $array['id'],
            rowSecurity: (bool) ($array['rowSecurity'] ?? $array['documentSecurity'] ?? false),
            permissions: $array['permissions'] ?? [],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
            enabled: $array['enabled'] ?? true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge([
            'database' => $this->database,
            'id' => $this->id,
            'name' => $this->name,
            'rowSecurity' => $this->rowSecurity,
            'permissions' => $this->permissions,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'enabled' => $this->enabled,
        ]);
    }

    public static function getName(): string
    {
        return Resource::TYPE_TABLE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_DATABASES;
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getTableName(): string
    {
        return $this->name;
    }

    public function getRowSecurity(): bool
    {
        return $this->rowSecurity;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }
}
