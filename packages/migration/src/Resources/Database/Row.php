<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Row extends Resource
{
    /**
     * @param string $id
     * @param Table $table
     * @param array<string, mixed> $data
     * @param array<string> $permissions
     */
    public function __construct(
        string $id,
        private readonly Table $table,
        private readonly array $data = [],
        array $permissions = []
    ) {
        $this->id = $id;
        $this->permissions = $permissions;
    }

    /**
     * @param array{
     *     id: string,
     *     collection?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         documentSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     table?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         rowSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     data: array<string, mixed>,
     *     permissions: ?array<string>
     * } $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            Table::fromArray($array['table'] ?? $array['collection']),
            $array['data'],
            $array['permissions'] ?? []
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'table' => $this->table,
            'data' => $this->data,
            'permissions' => $this->permissions,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_ROW;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_DATABASES;
    }

    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}
