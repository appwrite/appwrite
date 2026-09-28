<?php

namespace Utopia\Audit\Adapter;

use Utopia\Audit\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * Base SQL Adapter for Audit
 *
 * This is an abstract base class for SQL-based adapters (Database, ClickHouse, etc.)
 * It provides common functionality and schema definitions for all SQL adapters.
 */
abstract class SQL extends Adapter
{
    public const COLLECTION = 'audit';

    /**
     * Get the collection/table name for audit logs.
     */
    public function getCollectionName(): string
    {
        return self::COLLECTION;
    }

    /**
     * Get attribute definitions for audit logs.
     *
     * Each attribute is an array with the following string keys:
     * - $id: string (attribute identifier)
     * - type: string
     * - size: int
     * - required: bool
     * - signed: bool
     * - array: bool
     * - filters: array<string>
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttributes(): array
    {
        return [
            [
                '$id' => 'userId',
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'event',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'resource',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'userAgent',
                'type' => Database::VAR_STRING,
                'size' => 65534,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'ip',
                'type' => Database::VAR_STRING,
                'size' => 45,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'time',
                'type' => Database::VAR_DATETIME,
                'format' => '',
                'size' => 0,
                'signed' => true,
                'required' => false,
                'array' => false,
                'filters' => ['datetime'],
            ],
            [
                '$id' => 'data',
                'type' => Database::VAR_STRING,
                'size' => 16777216,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => ['json'],
            ],
        ];
    }

    /**
     * Get attribute documents for audit logs.
     *
     * @return array<Document>
     */
    public function getAttributeDocuments(): array
    {
        return array_map(static fn(array $attribute): \Utopia\Database\Document => new Document($attribute), $this->getAttributes());
    }

    /**
     * Get index definitions for audit logs.
     *
     * Each index is an array with the following string keys:
     * - $id: string (index identifier)
     * - type: string
     * - attributes: array<string>
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIndexes(): array
    {
        return [
            [
                '$id' => 'idx_event',
                'type' => 'key',
                'attributes' => ['event'],
            ],
            [
                '$id' => 'idx_userId_event',
                'type' => 'key',
                'attributes' => ['userId', 'event'],
            ],
            [
                '$id' => 'idx_resource_event',
                'type' => 'key',
                'attributes' => ['resource', 'event'],
            ],
            [
                '$id' => 'idx_time_desc',
                'type' => 'key',
                'attributes' => ['time'],
            ],
        ];
    }

    /**
     * Get index documents for audit logs.
     *
     * @return array<Document>
     */
    public function getIndexDocuments(): array
    {
        return array_map(static fn(array $index): \Utopia\Database\Document => new Document($index), $this->getIndexes());
    }

    /**
     * Get a single attribute by ID.
     *
     * @return array<string, mixed>|null
     */
    protected function getAttribute(string $id)
    {
        foreach ($this->getAttributes() as $attribute) {
            if ($attribute['$id'] === $id) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Get SQL column definition for a given attribute ID.
     * This method is database-specific and must be implemented by each concrete adapter.
     *
     * @param string $id Attribute identifier
     * @return string Database-specific column definition
     */
    abstract protected function getColumnDefinition(string $id): string;

    /**
     * Get all SQL column definitions.
     * Uses the concrete adapter's implementation of getColumnDefinition.
     *
     * @return array<string>
     */
    protected function getAllColumnDefinitions(): array
    {
        $definitions = [];
        foreach ($this->getAttributes() as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];
            $definitions[] = $this->getColumnDefinition($id);
        }

        return $definitions;
    }

    /**
     * Parses the resource string from the payload and extracts its ID, type, and parent.
     *
     * Supports any even number of segments shaped as alternating `<type>/<id>`,
     * e.g. `database/<id>`, `database/<id>/collection/<id>`,
     * `database/<id>/collection/<id>/document/<id>`. The last segment is the
     * resource id, the second-to-last is the resource type, and any preceding
     * segments form the resource parent path.
     *
     * @return array{ resourceId: string, resourceType: string, resourceParent: string }
     */
    protected function parseResource(string $resource): array
    {
        $parts = explode('/', $resource);
        $count = \count($parts);

        $resourceId = $resource;
        $resourceType = '';
        $resourceParent = '';

        if ($count >= 2 && $count % 2 === 0) {
            $resourceId = $parts[$count - 1];
            $resourceType = $parts[$count - 2];

            if ($count > 2) {
                $resourceParent = implode('/', \array_slice($parts, 0, $count - 2));
            }
        }

        return [
            'resourceId' => $resourceId,
            'resourceType' => $resourceType,
            'resourceParent' => $resourceParent,
        ];
    }
}
