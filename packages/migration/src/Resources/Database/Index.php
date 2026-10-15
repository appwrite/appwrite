<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Index extends Resource
{
    public const TYPE_UNIQUE = 'unique';

    public const TYPE_FULLTEXT = 'fulltext';

    public const TYPE_KEY = 'key';

    public const TYPE_SPATIAL = 'spatial';

    /**
     * @param string $id
     * @param string $key
     * @param Table $table
     * @param string $type
     * @param array<string> $columns
     * @param array<int> $lengths
     * @param array<string> $orders
     * @param string $createdAt
     * @param string $updatedAt
     */
    public function __construct(
        string                  $id,
        private readonly string $key,
        private readonly Table  $table,
        private readonly string $type = '',
        private readonly array  $columns = [],
        private readonly array  $lengths = [],
        private readonly array  $orders = [],
        protected string        $createdAt = '',
        protected string        $updatedAt = '',
    ) {
        $this->id = $id;
    }

    /**
     * @param array{
     *     id: string,
     *     key: string,
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
     *     type: string,
     *     columns?: array<string>,
     *     attributes?: array<string>,
     *     lengths: ?array<int>,
     *     orders: ?array<string>,
     *     createdAt: string,
     *     updatedAt: string,
     * } $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['key'],
            Table::fromArray($array['table'] ?? $array['collection']),
            $array['type'],
            $array['columns'] ?? $array['attributes'],
            $array['lengths'] ?? [],
            $array['orders'] ?? [],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'key' => $this->key,
            'table' => $this->table,
            'type' => $this->type,
            'columns' => $this->columns,
            'lengths' => $this->lengths,
            'orders' => $this->orders,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_INDEX;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_DATABASES;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTable(): Table
    {
        return $this->table;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int>
     */
    public function getLengths(): array
    {
        return $this->lengths;
    }

    /**
     * @return array<string>
     */
    public function getOrders(): array
    {
        return $this->orders;
    }
}
