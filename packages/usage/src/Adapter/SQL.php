<?php

namespace Utopia\Usage\Adapter;

use Utopia\Database\Attribute;
use Utopia\Database\Index;
use Utopia\Usage\Adapter;
use Utopia\Usage\Metric;

/**
 * Base SQL Adapter for Usage
 *
 * This is an abstract base class for SQL-based adapters (Database, ClickHouse, etc.)
 * It provides common functionality and references schema definitions from the Metric class.
 */
abstract class SQL extends Adapter
{
    public const COLLECTION = 'usage';

    /**
     * Get the collection/table name for usage metrics.
     *
     * @return string
     */
    public function getCollectionName(): string
    {
        return self::COLLECTION;
    }

    /**
     * Get attribute definitions for event metrics.
     *
     * @return list<array<string, mixed>>
     */
    public function getEventAttributes(): array
    {
        return Metric::getEventSchema();
    }

    /**
     * Get attribute definitions for gauge metrics.
     *
     * @return list<array<string, mixed>>
     */
    public function getGaugeAttributes(): array
    {
        return Metric::getGaugeSchema();
    }

    /**
     * Get attribute definitions for a specific type.
     *
     * @param string $type 'event' or 'gauge'
     * @return list<array<string, mixed>>
     */
    public function getAttributes(string $type = 'event'): array
    {
        return $type === 'gauge' ? $this->getGaugeAttributes() : $this->getEventAttributes();
    }

    /**
     * Get attribute models for a specific type.
     *
     * @param string $type 'event' or 'gauge'
     * @return list<Attribute>
     */
    public function getAttributeDocuments(string $type = 'event'): array
    {
        return array_map(Attribute::fromArray(...), $this->getAttributes($type));
    }

    /**
     * Get index definitions for event metrics.
     *
     * @return list<array<string, mixed>>
     */
    public function getEventIndexes(): array
    {
        return Metric::getEventIndexes();
    }

    /**
     * Get index definitions for gauge metrics.
     *
     * @return list<array<string, mixed>>
     */
    public function getGaugeIndexes(): array
    {
        return Metric::getGaugeIndexes();
    }

    /**
     * Get index definitions for a specific type.
     *
     * @param string $type 'event' or 'gauge'
     * @return list<array<string, mixed>>
     */
    public function getIndexes(string $type = 'event'): array
    {
        return $type === 'gauge' ? $this->getGaugeIndexes() : $this->getEventIndexes();
    }

    /**
     * Get index models for a specific type.
     *
     * @param string $type 'event' or 'gauge'
     * @return list<Index>
     */
    public function getIndexDocuments(string $type = 'event'): array
    {
        return array_map(Index::fromArray(...), $this->getIndexes($type));
    }

    /**
     * Get a single attribute by ID from a specific schema.
     *
     * @param string $id
     * @param string $type 'event' or 'gauge'
     * @return array<string, mixed>|null
     */
    protected function getAttribute(string $id, string $type = 'event')
    {
        foreach ($this->getAttributes($type) as $attribute) {
            if ($attribute['$id'] === $id) {
                return $attribute;
            }
        }

        return;
    }

    /**
     * Get SQL column definition for a given attribute ID.
     * This method is database-specific and must be implemented by each concrete adapter.
     *
     * @param string $id Attribute identifier
     * @param string $type 'event' or 'gauge'
     * @return string Database-specific column definition
     */
    abstract protected function getColumnDefinition(string $id, string $type = 'event'): string;

    /**
     * Get all SQL column definitions for a specific type.
     * Uses the concrete adapter's implementation of getColumnDefinition.
     *
     * @param string $type 'event' or 'gauge'
     * @return array<string>
     */
    protected function getAllColumnDefinitions(string $type = 'event'): array
    {
        $definitions = [];
        foreach ($this->getAttributes($type) as $attribute) {
            /** @var string $id */
            $id = $attribute['$id'];
            $definitions[] = $this->getColumnDefinition($id, $type);
        }

        return $definitions;
    }

    /**
     * Generate a UUID for row identification.
     * Since we're appending raw rows (no dedup), IDs are random.
     */
    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
