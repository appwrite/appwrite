<?php

namespace Utopia\Audit\Adapter;

use Utopia\Audit\Adapter;
use Utopia\Database\Attribute;
use Utopia\Database\Index;

/**
 * Base SQL Adapter for Audit
 *
 * This is an abstract base class for SQL-based adapters (Database, ClickHouse, etc.)
 * It provides common functionality and schema definitions for all SQL adapters.
 */
abstract class SQL extends Adapter
{
    public const string COLLECTION = 'audit';

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
     * @return array<int, Attribute>
     */
    public function getAttributes(): array
    {
        return [
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'event', required: true),
            Attribute::string(key: 'resource'),
            Attribute::string(key: 'userAgent', size: 65534, required: true),
            Attribute::string(key: 'ip', size: 45, required: true),
            Attribute::datetime(key: 'time', filters: ['datetime']),
            Attribute::string(key: 'data', size: 16777216, filters: ['json']),
        ];
    }

    /**
     * Get attribute value objects for createCollection.
     *
     * @return array<Attribute>
     */
    public function getAttributeDocuments(): array
    {
        return $this->getAttributes();
    }

    /**
     * Get index definitions for audit logs.
     *
     * @return array<int, Index>
     */
    public function getIndexes(): array
    {
        return [
            Index::key(key: 'idx_event', attributes: ['event']),
            Index::key(key: 'idx_userId_event', attributes: ['userId', 'event']),
            Index::key(key: 'idx_resource_event', attributes: ['resource', 'event']),
            Index::key(key: 'idx_time_desc', attributes: ['time']),
        ];
    }

    /**
     * Get index value objects for createCollection.
     *
     * @return array<Index>
     */
    public function getIndexDocuments(): array
    {
        return $this->getIndexes();
    }

    /**
     * Get a single attribute by key.
     */
    protected function getAttribute(string $id): ?Attribute
    {
        foreach ($this->getAttributes() as $attribute) {
            if ($attribute->key === $id) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Get SQL column definition for a given attribute ID.
     * This method is database-specific and must be implemented by each concrete adapter.
     *
     * @param  string  $id  Attribute identifier
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
            $definitions[] = $this->getColumnDefinition($attribute->key);
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
