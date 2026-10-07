<?php

namespace Utopia\Database\Adapter;

use Exception;
use PDOException;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Character as CharacterException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Operator as OperatorException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Operator;
use Utopia\Database\Query;

class MariaDB extends SQL
{
    /**
     * Create Database
     *
     * @param string $name
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function create(string $name): bool
    {
        $name = $this->filter($name);

        if ($this->exists($name)) {
            return true;
        }

        $sql = "CREATE DATABASE `{$name}` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;";

        $sql = $this->trigger(Database::EVENT_DATABASE_CREATE, $sql);

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * Delete Database
     *
     * @param string $name
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function delete(string $name): bool
    {
        $name = $this->filter($name);

        $sql = "DROP DATABASE `{$name}`;";

        $sql = $this->trigger(Database::EVENT_DATABASE_DELETE, $sql);

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * Create Collection
     *
     * @param string $name
     * @param array<Document> $attributes
     * @param array<Document> $indexes
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function createCollection(string $name, array $attributes = [], array $indexes = []): bool
    {
        $id = $this->filter($name);

        /** @var array<string> $attributeStrings */
        $attributeStrings = [];

        /** @var array<string> $indexStrings */
        $indexStrings = [];

        $hash = [];

        foreach ($attributes as $key => $attribute) {
            $attrId = $this->filter($attribute->getId());
            $hash[$attrId] = $attribute;

            $attrType = $this->getSQLType(
                $attribute->getAttribute('type'),
                $attribute->getAttribute('size', 0),
                $attribute->getAttribute('signed', true),
                $attribute->getAttribute('array', false),
                $attribute->getAttribute('required', false)
            );

            // Ignore relationships with virtual attributes
            if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
                $options = $attribute->getAttribute('options', []);
                $relationType = $options['relationType'] ?? null;
                $twoWay = $options['twoWay'] ?? false;
                $side = $options['side'] ?? null;

                if (
                    $relationType === Database::RELATION_MANY_TO_MANY
                    || ($relationType === Database::RELATION_ONE_TO_ONE && !$twoWay && $side === Database::RELATION_SIDE_CHILD)
                    || ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_PARENT)
                    || ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_CHILD)
                ) {
                    continue;
                }
            }

            $attributeStrings[$key] = "`{$attrId}` {$attrType}, ";
        }

        foreach ($indexes as $key => $index) {
            $indexId = $this->filter($index->getId());
            $indexType = $index->getAttribute('type');

            $indexAttributes = $index->getAttribute('attributes');
            foreach ($indexAttributes as $nested => $attribute) {
                $indexLength = $index->getAttribute('lengths')[$nested] ?? '';
                $indexLength = (empty($indexLength)) ? '' : '(' . (int)$indexLength . ')';
                $indexOrder = $index->getAttribute('orders')[$nested] ?? '';
                if ($indexType === Database::INDEX_SPATIAL && !$this->getSupportForSpatialIndexOrder() && !empty($indexOrder)) {
                    throw new DatabaseException('Spatial indexes with explicit orders are not supported. Remove the orders to create this index.');
                }
                $indexAttribute = $this->getInternalKeyForAttribute($attribute);
                $indexAttribute = $this->filter($indexAttribute);

                if ($indexType === Database::INDEX_FULLTEXT) {
                    $indexOrder = '';
                }

                $indexAttributes[$nested] = "`{$indexAttribute}`{$indexLength} {$indexOrder}";

                if (!empty($hash[$indexAttribute]['array']) && $this->getSupportForCastIndexArray()) {
                    $indexAttributes[$nested] = '(CAST(`' . $indexAttribute . '` AS char(' . Database::MAX_ARRAY_INDEX_LENGTH . ') ARRAY))';
                }
            }

            $indexAttributes = \implode(", ", $indexAttributes);

            if ($this->sharedTables && $indexType !== Database::INDEX_FULLTEXT && $indexType !== Database::INDEX_SPATIAL) {
                // Add tenant as first index column for best performance
                $indexAttributes = "_tenant, {$indexAttributes}";
            }

            $indexStrings[$key] = "{$indexType} `{$indexId}` ({$indexAttributes}),";
        }

        $collection = "
			CREATE TABLE {$this->getSQLTable($id)} (
				_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				_uid VARCHAR(255) NOT NULL,
				_createdAt DATETIME(3) DEFAULT NULL,
				_updatedAt DATETIME(3) DEFAULT NULL,
				_permissions MEDIUMTEXT DEFAULT NULL,
				PRIMARY KEY (_id),
				" . \implode(' ', $attributeStrings) . "
				" . \implode(' ', $indexStrings) . "
		";

        if ($this->sharedTables) {
            $collection .= "
            	_tenant INT(11) UNSIGNED DEFAULT NULL,
				UNIQUE KEY _uid (_uid, _tenant),
				KEY _created_at (_tenant, _createdAt),
				KEY _updated_at (_tenant, _updatedAt),
				KEY _tenant_id (_tenant, _id)
			";
        } else {
            $collection .= "
				UNIQUE KEY _uid (_uid),
				KEY _created_at (_createdAt),
				KEY _updated_at (_updatedAt)
			";
        }

        $collection .= ")";
        $collection = $this->trigger(Database::EVENT_COLLECTION_CREATE, $collection);

        $permissions = "
            CREATE TABLE {$this->getSQLTable($id . '_perms')} (
                _id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                _type VARCHAR(12) NOT NULL,
                _permission VARCHAR(255) NOT NULL,
                _document VARCHAR(255) NOT NULL,
                PRIMARY KEY (_id),
        ";

        if ($this->sharedTables) {
            $permissions .= "
                _tenant INT(11) UNSIGNED DEFAULT NULL,
                UNIQUE INDEX _index1 (_document, _tenant, _type, _permission),
                INDEX _permission (_tenant, _permission, _type)
            ";
        } else {
            $permissions .= "
                UNIQUE INDEX _index1 (_document, _type, _permission),
                INDEX _permission (_permission, _type)
            ";
        }

        $permissions .= ")";
        $permissions = $this->trigger(Database::EVENT_COLLECTION_CREATE, $permissions);

        try {
            $this->getPDO()
                ->prepare($collection)
                ->execute();

            $this->getPDO()
                ->prepare($permissions)
                ->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return true;
    }

    /**
     * Get collection size on disk
     *
     * @param string $collection
     * @return int
     * @throws DatabaseException
     */
    public function getSizeOfCollectionOnDisk(string $collection): int
    {
        $collection = $this->filter($collection);
        $collection = $this->getNamespace() . '_' . $collection;
        $database = $this->getDatabase();
        $name = $database . '/' . $collection;
        $permissions = $database . '/' . $collection . '_perms';

        $collectionSize = $this->getPDO()->prepare("
            SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  
            FROM INFORMATION_SCHEMA.INNODB_SYS_TABLESPACES
            WHERE NAME = :name
         ");

        $permissionsSize = $this->getPDO()->prepare("
            SELECT SUM(FS_BLOCK_SIZE + ALLOCATED_SIZE)  
            FROM INFORMATION_SCHEMA.INNODB_SYS_TABLESPACES
            WHERE NAME = :permissions
        ");

        $collectionSize->bindParam(':name', $name);
        $permissionsSize->bindParam(':permissions', $permissions);

        try {
            $collectionSize->execute();
            $permissionsSize->execute();
            $size = $collectionSize->fetchColumn() + $permissionsSize->fetchColumn();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get collection size: ' . $e->getMessage());
        }

        return $size;
    }

    /**
     * Get Collection Size of the raw data
     *
     * @param string $collection
     * @return int
     * @throws DatabaseException
     */
    public function getSizeOfCollection(string $collection): int
    {
        $collection = $this->filter($collection);
        $collection = $this->getNamespace() . '_' . $collection;
        $database = $this->getDatabase();
        $permissions = $collection . '_perms';

        // Both tables in one round trip. Keep the equality predicates: LIKE and IN are
        // not indexed here, they scan every table in the schema.
        $statement = $this->getPDO()->prepare("
            SELECT SUM(size) FROM (
                SELECT data_length + index_length AS size
                FROM INFORMATION_SCHEMA.TABLES
                WHERE table_name = :name AND
                table_schema = :database_name
                UNION ALL
                SELECT data_length + index_length AS size
                FROM INFORMATION_SCHEMA.TABLES
                WHERE table_name = :permissions AND
                table_schema = :database_permissions
            ) AS sizes
        ");

        $statement->bindParam(':name', $collection);
        $statement->bindParam(':permissions', $permissions);
        $statement->bindParam(':database_name', $database);
        $statement->bindParam(':database_permissions', $database);

        try {
            $statement->execute();
            $size = $statement->fetchColumn();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get collection size: ' . $e->getMessage());
        }

        return (int) $size;
    }

    /**
     * Delete collection
     *
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteCollection(string $id): bool
    {
        $id = $this->filter($id);

        $sql = "DROP TABLE {$this->getSQLTable($id)}, {$this->getSQLTable($id . '_perms')};";

        $sql = $this->trigger(Database::EVENT_COLLECTION_DELETE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Analyze a collection updating it's metadata on the database engine
     *
     * @param string $collection
     * @return bool
     * @throws DatabaseException
     */
    public function analyzeCollection(string $collection): bool
    {
        $name = $this->filter($collection);

        $sql = "ANALYZE TABLE {$this->getSQLTable($name)}";

        $stmt = $this->getPDO()->prepare($sql);
        return $stmt->execute();
    }

    /**
     * Get Schema Attributes
     *
     * @param string $collection
     * @return array<Document>
     * @throws DatabaseException
     */
    public function getSchemaAttributes(string $collection): array
    {
        $schema = $this->getDatabase();
        $collection = $this->getNamespace().'_'.$this->filter($collection);

        try {
            $stmt = $this->getPDO()->prepare('
                SELECT
                COLUMN_NAME as _id,
                COLUMN_DEFAULT as columnDefault,
                IS_NULLABLE as isNullable,
                DATA_TYPE as dataType,
                CHARACTER_MAXIMUM_LENGTH as characterMaximumLength,
                NUMERIC_PRECISION as numericPrecision,
                NUMERIC_SCALE as numericScale,
                DATETIME_PRECISION as datetimePrecision,
                COLUMN_TYPE as columnType,
                COLUMN_KEY as columnKey,
                EXTRA as extra
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
            ');
            $stmt->bindParam(':schema', $schema);
            $stmt->bindParam(':table', $collection);
            $stmt->execute();
            $results = $stmt->fetchAll();
            $stmt->closeCursor();

            foreach ($results as $index => $document) {
                $document['$id'] = $document['_id'];
                unset($document['_id']);

                $results[$index] = new Document($document);
            }

            return $results;

        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get schema attributes', $e->getCode(), $e);
        }
    }

    /**
     * Update Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @param string|null $newKey
     * @param bool $required
     * @return bool
     * @throws DatabaseException
     */
    public function updateAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, ?string $newKey = null, bool $required = false): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);
        $newKey = empty($newKey) ? null : $this->filter($newKey);
        $type = $this->getSQLType($type, $size, $signed, $array, $required);
        if (!empty($newKey)) {
            $sql = "ALTER TABLE {$this->getSQLTable($name)} CHANGE COLUMN `{$id}` `{$newKey}` {$type};";
        } else {
            $sql = "ALTER TABLE {$this->getSQLTable($name)} MODIFY `{$id}` {$type};";
        }

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_UPDATE, $sql);

        try {
            return $this->getPDO()
            ->prepare($sql)
            ->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param string $relatedCollection
     * @param bool $twoWay
     * @param string $twoWayKey
     * @return bool
     * @throws DatabaseException
     */
    public function createRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay = false,
        string $id = '',
        string $twoWayKey = ''
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $id = $this->filter($id);
        $twoWayKey = $this->filter($twoWayKey);
        $sqlType = $this->getSQLType(Database::VAR_RELATIONSHIP, 0, false, false, false);

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                $sql = "ALTER TABLE {$table} ADD COLUMN `{$id}` {$sqlType} DEFAULT NULL;";

                if ($twoWay) {
                    $sql .= "ALTER TABLE {$relatedTable} ADD COLUMN `{$twoWayKey}` {$sqlType} DEFAULT NULL;";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                $sql = "ALTER TABLE {$relatedTable} ADD COLUMN `{$twoWayKey}` {$sqlType} DEFAULT NULL;";
                break;
            case Database::RELATION_MANY_TO_ONE:
                $sql = "ALTER TABLE {$table} ADD COLUMN `{$id}` {$sqlType} DEFAULT NULL;";
                break;
            case Database::RELATION_MANY_TO_MANY:
                return true;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_CREATE, $sql);

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $key
     * @param string $twoWayKey
     * @param string $side
     * @param string|null $newKey
     * @param string|null $newTwoWayKey
     * @return bool
     * @throws DatabaseException
     */
    public function updateRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side,
        ?string $newKey = null,
        ?string $newTwoWayKey = null,
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);

        if (!\is_null($newKey)) {
            $newKey = $this->filter($newKey);
        }
        if (!\is_null($newTwoWayKey)) {
            $newTwoWayKey = $this->filter($newTwoWayKey);
        }

        $sql = '';

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if ($key !== $newKey) {
                    $sql = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`;";
                }
                if ($twoWay && $twoWayKey !== $newTwoWayKey) {
                    $sql .= "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`;";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    if ($twoWayKey !== $newTwoWayKey) {
                        $sql = "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`;";
                    }
                } else {
                    if ($key !== $newKey) {
                        $sql = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`;";
                    }
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    if ($twoWayKey !== $newTwoWayKey) {
                        $sql = "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`;";
                    }
                } else {
                    if ($key !== $newKey) {
                        $sql = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`;";
                    }
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $metadataCollection = new Document(['$id' => Database::METADATA]);
                $collection = $this->getDocument($metadataCollection, $collection);
                $relatedCollection = $this->getDocument($metadataCollection, $relatedCollection);

                $junction = $this->getSQLTable('_' . $collection->getSequence() . '_' . $relatedCollection->getSequence());

                if (!\is_null($newKey)) {
                    $sql = "ALTER TABLE {$junction} RENAME COLUMN `{$key}` TO `{$newKey}`;";
                }
                if ($twoWay && !\is_null($newTwoWayKey)) {
                    $sql .= "ALTER TABLE {$junction} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`;";
                }
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        if (empty($sql)) {
            return true;
        }

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_UPDATE, $sql);

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $key
     * @param string $twoWayKey
     * @param string $side
     * @return bool
     * @throws DatabaseException
     */
    public function deleteRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $sql = "ALTER TABLE {$table} DROP COLUMN `{$key}`;";
                    if ($twoWay) {
                        $sql .= "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`;";
                    }
                } elseif ($side === Database::RELATION_SIDE_CHILD) {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`;";
                    if ($twoWay) {
                        $sql .= "ALTER TABLE {$table} DROP COLUMN `{$key}`;";
                    }
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`;";
                } else {
                    $sql = "ALTER TABLE {$table} DROP COLUMN `{$key}`;";
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $sql = "ALTER TABLE {$table} DROP COLUMN `{$key}`;";
                } else {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`;";
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $metadataCollection = new Document(['$id' => Database::METADATA]);
                $collection = $this->getDocument($metadataCollection, $collection);
                $relatedCollection = $this->getDocument($metadataCollection, $relatedCollection);

                $junction = $side === Database::RELATION_SIDE_PARENT
                    ? $this->getSQLTable('_' . $collection->getSequence() . '_' . $relatedCollection->getSequence())
                    : $this->getSQLTable('_' . $relatedCollection->getSequence() . '_' . $collection->getSequence());

                $perms = $side === Database::RELATION_SIDE_PARENT
                    ? $this->getSQLTable('_' . $collection->getSequence() . '_' . $relatedCollection->getSequence() . '_perms')
                    : $this->getSQLTable('_' . $relatedCollection->getSequence() . '_' . $collection->getSequence() . '_perms');

                $sql = "DROP TABLE {$junction}; DROP TABLE {$perms}";
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        if (empty($sql)) {
            return true;
        }

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_DELETE, $sql);

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * Rename Index
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     * @throws Exception
     */
    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $old = $this->filter($old);
        $new = $this->filter($new);

        $sql = "ALTER TABLE {$this->getSQLTable($collection)} RENAME INDEX `{$old}` TO `{$new}`;";

        $sql = $this->trigger(Database::EVENT_INDEX_RENAME, $sql);

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * Create Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array<string> $attributes
     * @param array<int> $lengths
     * @param array<string> $orders
     * @param array<string,string> $indexAttributeTypes
     * @return bool
     * @throws DatabaseException
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders, array $indexAttributeTypes = [], array $collation = [], int $ttl = 1): bool
    {
        $metadataCollection = new Document(['$id' => Database::METADATA]);
        $collection = $this->getDocument($metadataCollection, $collection);

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        /**
         * We do not have sequence's added to list, since we check only for array field
         */
        $collectionAttributes = \json_decode($collection->getAttribute('attributes', []), true);

        $id = $this->filter($id);

        foreach ($attributes as $i => $attr) {
            $attribute = null;
            foreach ($collectionAttributes as $collectionAttribute) {
                if (\strtolower($collectionAttribute['$id']) === \strtolower($attr)) {
                    $attribute = $collectionAttribute;
                    break;
                }
            }

            $order = empty($orders[$i]) || Database::INDEX_FULLTEXT === $type ? '' : $orders[$i];
            $length = empty($lengths[$i]) ? '' : '(' . (int)$lengths[$i] . ')';

            $attr = $this->getInternalKeyForAttribute($attr);
            $attr = $this->filter($attr);

            $attributes[$i] = "`{$attr}`{$length} {$order}";

            if ($this->getSupportForCastIndexArray() && !empty($attribute['array'])) {
                $attributes[$i] = '(CAST(`' . $attr . '` AS char(' . Database::MAX_ARRAY_INDEX_LENGTH . ') ARRAY))';
            }
        }

        $sqlType = match ($type) {
            Database::INDEX_KEY => 'INDEX',
            Database::INDEX_UNIQUE => 'UNIQUE INDEX',
            Database::INDEX_FULLTEXT => 'FULLTEXT INDEX',
            Database::INDEX_SPATIAL => 'SPATIAL INDEX',
            default => throw new DatabaseException('Unknown index type: ' . $type . '. Must be one of ' . Database::INDEX_KEY . ', ' . Database::INDEX_UNIQUE . ', ' . Database::INDEX_FULLTEXT . ', ' . Database::INDEX_SPATIAL),
        };

        $attributes = \implode(', ', $attributes);

        if ($this->sharedTables && $type !== Database::INDEX_FULLTEXT && $type !== Database::INDEX_SPATIAL) {
            // Add tenant as first index column for best performance
            $attributes = "_tenant, {$attributes}";
        }

        $sql =  "CREATE {$sqlType} `{$id}` ON {$this->getSQLTable($collection->getId())} ({$attributes})";
        $sql = $this->trigger(Database::EVENT_INDEX_CREATE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            // Existing rows violate the new unique index. Classified here because
            // processException() can't parse the key from a localized message.
            if ($e->getCode() === '23000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) {
                throw new UniqueException('Unique index violation', $e->getCode(), $e);
            }

            throw $this->processException($e);
        }
    }

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        $sql = "ALTER TABLE {$this->getSQLTable($name)} DROP INDEX `{$id}`;";

        $sql = $this->trigger(Database::EVENT_INDEX_DELETE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            if ($e->getCode() === "42000" && $e->errorInfo[1] === 1091) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Create Document
     *
     * @param Document $collection
     * @param Document $document
     * @return Document
     * @throws Exception
     * @throws PDOException
     * @throws DuplicateException
     * @throws \Throwable
     */
    public function createDocument(Document $collection, Document $document): Document
    {
        try {
            $spatialAttributes = $this->getSpatialAttributes($collection);
            $collection = $collection->getId();
            $attributes = $document->getAttributes();
            $attributes['_createdAt'] = $document->getCreatedAt();
            $attributes['_updatedAt'] = $document->getUpdatedAt();
            $attributes['_permissions'] = \json_encode($document->getPermissions());

            if ($this->sharedTables) {
                $attributes['_tenant'] = $document->getTenant();
            }

            $name = $this->filter($collection);
            $columns = '';
            $columnNames = '';

            /**
             * Insert Attributes
             */
            $bindIndex = 0;
            foreach ($attributes as $attribute => $value) {
                $column = $this->filter($attribute);
                $bindKey = 'key_' . $bindIndex;
                $columns .= "`{$column}`, ";
                if (in_array($attribute, $spatialAttributes)) {
                    $columnNames .= $this->getSpatialGeomFromText(':' . $bindKey) . ", ";
                } else {
                    $columnNames .= ':' . $bindKey . ', ';
                }
                $bindIndex++;
            }

            // Insert internal ID if set
            if (!empty($document->getSequence())) {
                $bindKey = '_id';
                $columns .= "_id, ";
                $columnNames .= ':' . $bindKey . ', ';
            }

            $sql = "
			    INSERT INTO {$this->getSQLTable($name)} ({$columns} _uid)
			    VALUES ({$columnNames} :_uid)
			";

            $sql = $this->trigger(Database::EVENT_DOCUMENT_CREATE, $sql);

            $stmt = $this->getPDO()->prepare($sql);

            $stmt->bindValue(':_uid', $document->getId());

            if (!empty($document->getSequence())) {
                $stmt->bindValue(':_id', $document->getSequence());
            }

            $attributeIndex = 0;
            foreach ($attributes as $value) {
                if (\is_array($value)) {
                    $value = \json_encode($value);
                }

                $bindKey = 'key_' . $attributeIndex;
                $attribute = $this->filter($attribute);
                $value = (\is_bool($value)) ? (int)$value : $value;
                $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
                $attributeIndex++;
            }

            $permissions = [];
            foreach (Database::PERMISSIONS as $type) {
                foreach ($document->getPermissionsByType($type) as $permission) {
                    $tenantBind = $this->sharedTables ? ", :_tenant" : '';
                    $permission = \str_replace('"', '', $permission);
                    $permission = "('{$type}', '{$permission}', :_uid {$tenantBind})";
                    $permissions[] = $permission;
                }
            }

            if (!empty($permissions)) {
                $tenantColumn = $this->sharedTables ? ', _tenant' : '';
                $permissions = \implode(', ', $permissions);

                $sqlPermissions = "
                    INSERT INTO {$this->getSQLTable($name . '_perms')} (_type, _permission, _document {$tenantColumn})
                    VALUES {$permissions};
                ";

                $stmtPermissions = $this->getPDO()->prepare($sqlPermissions);
                $stmtPermissions->bindValue(':_uid', $document->getId());
                if ($this->sharedTables) {
                    $stmtPermissions->bindValue(':_tenant', $document->getTenant());
                }
            }

            $stmt->execute();

            $document['$sequence'] = $this->pdo->lastInsertId();

            if (empty($document['$sequence'])) {
                throw new DatabaseException('Error creating document empty "$sequence"');
            }

            if (isset($stmtPermissions)) {
                try {
                    $stmtPermissions->execute();
                } catch (PDOException $e) {
                    $isOrphanedPermission = $e->getCode() === '23000'
                        && isset($e->errorInfo[1])
                        && $e->errorInfo[1] === 1062
                        && \str_contains($e->getMessage(), '_index1');

                    if (!$isOrphanedPermission) {
                        throw $e;
                    }

                    // Clean up orphaned permissions from a previous failed delete, then retry
                    $sql = "DELETE FROM {$this->getSQLTable($name . '_perms')} WHERE _document = :_uid {$this->getTenantQuery($collection)}";
                    $cleanup = $this->getPDO()->prepare($sql);
                    $cleanup->bindValue(':_uid', $document->getId());
                    if ($this->sharedTables) {
                        $cleanup->bindValue(':_tenant', $document->getTenant());
                    }
                    $cleanup->execute();

                    $stmtPermissions->execute();
                }
            }
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return $document;
    }

    /**
     * Update Document
     *
     * @param Document $collection
     * @param string $id
     * @param Document $document
     * @param bool $skipPermissions
     * @return Document
     * @throws Exception
     * @throws PDOException
     * @throws DuplicateException
     * @throws \Throwable
     */
    public function updateDocument(Document $collection, string $id, Document $document, bool $skipPermissions): Document
    {
        try {
            $spatialAttributes = $this->getSpatialAttributes($collection);
            $collection = $collection->getId();
            $attributes = $document->getAttributes();
            $attributes['_createdAt'] = $document->getCreatedAt();
            $attributes['_updatedAt'] = $document->getUpdatedAt();
            $attributes['_permissions'] = json_encode($document->getPermissions());
            $attributes['_uid'] = $document->getId();

            $name = $this->filter($collection);
            $columns = '';

            if (!$skipPermissions) {
                $newUid = $document->offsetExists('$id') ? $document->getId() : $id;

                $sql = "
			    DELETE FROM {$this->getSQLTable($name . '_perms')}
			    WHERE _document = :_uid
			    {$this->getTenantQuery($collection)}
			    ";

                $sql = $this->trigger(Database::EVENT_PERMISSIONS_DELETE, $sql);

                $stmtRemovePermissions = $this->getPDO()->prepare($sql);
                $stmtRemovePermissions->bindValue(':_uid', $id);
                if ($this->sharedTables) {
                    $stmtRemovePermissions->bindValue(':_tenant', $document->getTenant());
                }

                $values = [];
                $binds = [];
                foreach (Database::PERMISSIONS as $type) {
                    foreach ($document->getPermissionsByType($type) as $i => $permission) {
                        $tenantPlaceholder = $this->sharedTables ? ', :_tenant' : '';
                        $values[] = "( :_uid, '{$type}', :_add_{$type}_{$i} {$tenantPlaceholder})";
                        $binds[":_add_{$type}_{$i}"] = $permission;
                    }
                }

                if (!empty($values)) {
                    $tenantColumn = $this->sharedTables ? ', _tenant' : '';

                    $sql = "
				    INSERT INTO {$this->getSQLTable($name . '_perms')} (_document, _type, _permission {$tenantColumn})
				    VALUES " . \implode(', ', $values);

                    $sql = $this->trigger(Database::EVENT_PERMISSIONS_CREATE, $sql);

                    $stmtAddPermissions = $this->getPDO()->prepare($sql);
                    $stmtAddPermissions->bindValue(":_uid", $newUid);
                    if ($this->sharedTables) {
                        $stmtAddPermissions->bindValue(":_tenant", $document->getTenant());
                    }

                    foreach ($binds as $key => $permission) {
                        $stmtAddPermissions->bindValue($key, $permission);
                    }
                }
            }

            /**
             * Update Attributes
             */
            $keyIndex = 0;
            $operatorBinds = [];

            foreach ($attributes as $attribute => $value) {
                $column = $this->filter($attribute);

                // Check if this is an operator or regular attribute
                if (Operator::isOperator($value)) {
                    $operatorSQL = $this->getOperatorSQL($column, $value, $operatorBinds);
                    $columns .= $operatorSQL . ',';
                } else {
                    $bindKey = 'key_' . $keyIndex;

                    if (in_array($attribute, $spatialAttributes)) {
                        $columns .= "`{$column}`" . '=' . $this->getSpatialGeomFromText(':' . $bindKey) . ',';
                    } else {
                        $columns .= "`{$column}`" . '=:' . $bindKey . ',';
                    }
                    $keyIndex++;
                }
            }

            $sql = "
                UPDATE {$this->getSQLTable($name)}
                SET " . \rtrim($columns, ',') . "
                WHERE _id=:_sequence
                {$this->getTenantQuery($collection)}
			";

            $sql = $this->trigger(Database::EVENT_DOCUMENT_UPDATE, $sql);

            $stmt = $this->getPDO()->prepare($sql);

            $stmt->bindValue(':_sequence', $document->getSequence());

            if ($this->sharedTables) {
                $stmt->bindValue(':_tenant', $this->tenant);
            }

            $keyIndex = 0;
            foreach ($attributes as $attribute => $value) {
                // Handle operators separately
                if (Operator::isOperator($value)) {
                    continue;
                }

                // Convert spatial arrays to WKT, json_encode non-spatial arrays
                if (\in_array($attribute, $spatialAttributes, true)) {
                    if (\is_array($value)) {
                        $value = $this->convertArrayToWKT($value);
                    }
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                }

                $bindKey = 'key_' . $keyIndex;
                $value = (is_bool($value)) ? (int)$value : $value;
                $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
                $keyIndex++;
            }

            foreach ($operatorBinds as $bindKey => $bindValue) {
                $stmt->bindValue($bindKey, $bindValue, $this->getPDOType($bindValue));
            }

            $stmt->execute();

            if (isset($stmtRemovePermissions)) {
                $stmtRemovePermissions->execute();
            }
            if (isset($stmtAddPermissions)) {
                $stmtAddPermissions->execute();
            }

        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return $document;
    }

    /**
     * @param string $tableName
     * @param string $columns
     * @param array<string> $batchKeys
     * @param array<string> $attributes
     * @param array<mixed> $bindValues
     * @param string $attribute
     * @param array<Operator> $operators
     * @return mixed
     * @throws DatabaseException
     */
    public function getUpsertStatement(
        string $tableName,
        string $columns,
        array $batchKeys,
        array $attributes,
        array $bindValues,
        string $attribute = '',
        array $operators = []
    ): mixed {
        $getUpdateClause = function (string $attribute, bool $increment = false): string {
            $attribute = $this->quote($this->filter($attribute));

            if ($increment) {
                $new = "{$attribute} + VALUES({$attribute})";
            } else {
                $new = "VALUES({$attribute})";
            }

            if ($this->sharedTables) {
                return "{$attribute} = IF(_tenant = VALUES(_tenant), {$new}, {$attribute})";
            }

            return "{$attribute} = {$new}";
        };

        $updateColumns = [];
        $operatorBinds = [];

        if (!empty($attribute)) {
            // Increment specific column by its new value in place
            $updateColumns = [
                $getUpdateClause($attribute, increment: true),
                $getUpdateClause('_updatedAt'),
            ];
        } else {
            foreach (\array_keys($attributes) as $attr) {
                /**
                 * @var string $attr
                 */
                $filteredAttr = $this->filter($attr);

                if (isset($operators[$attr])) {
                    $operatorSQL = $this->getOperatorSQL($filteredAttr, $operators[$attr], $operatorBinds);
                    if ($operatorSQL !== null) {
                        $updateColumns[] = $operatorSQL;
                    }
                } else {
                    if (!in_array($attr, ['_uid', '_id', '_createdAt', '_tenant'])) {
                        $updateColumns[] = $getUpdateClause($filteredAttr);
                    }
                }
            }
        }

        $stmt = $this->getPDO()->prepare(
            "
            INSERT INTO {$this->getSQLTable($tableName)} {$columns}
            VALUES " . \implode(', ', $batchKeys) . "
            ON DUPLICATE KEY UPDATE
                " . \implode(', ', $updateColumns)
        );

        foreach ($bindValues as $key => $binding) {
            $stmt->bindValue($key, $binding, $this->getPDOType($binding));
        }

        foreach ($operatorBinds as $bindKey => $bindValue) {
            $stmt->bindValue($bindKey, $bindValue, $this->getPDOType($bindValue));
        }

        return $stmt;
    }

    /**
     * Increase or decrease an attribute value
     *
     * @param string $collection
     * @param string $id
     * @param string $attribute
     * @param int|float $value
     * @param string $updatedAt
     * @param int|float|null $min
     * @param int|float|null $max
     * @return bool
     * @throws DatabaseException
     */
    public function increaseDocumentAttribute(
        string $collection,
        string $id,
        string $attribute,
        int|float $value,
        string $updatedAt,
        int|float|null $min = null,
        int|float|null $max = null
    ): bool {
        $name = $this->filter($collection);
        $attribute = $this->filter($attribute);

        $sqlMax = $max !== null ? " AND `{$attribute}` <= :max" : '';
        $sqlMin = $min !== null ? " AND `{$attribute}` >= :min" : '';

        $sql = "
			UPDATE {$this->getSQLTable($name)}
			SET
			    `{$attribute}` = `{$attribute}` + :val,
			    `_updatedAt` = :updatedAt
			WHERE _uid = :_uid
			{$this->getTenantQuery($collection)}
		";

        $sql .= $sqlMax . $sqlMin;

        $sql = $this->trigger(Database::EVENT_DOCUMENT_UPDATE, $sql);

        $stmt = $this->getPDO()->prepare($sql);
        $stmt->bindValue(':_uid', $id);
        $stmt->bindValue(':val', $value);
        $stmt->bindValue(':updatedAt', $updatedAt);

        if ($max !== null) {
            $stmt->bindValue(':max', $max);
        }
        if ($min !== null) {
            $stmt->bindValue(':min', $min);
        }
        if ($this->sharedTables) {
            $stmt->bindValue(':_tenant', $this->tenant);
        }

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return true;
    }

    /**
     * Delete Document
     *
     * @param string $collection
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteDocument(string $collection, string $id): bool
    {
        try {
            $name = $this->filter($collection);

            $sql = "
                DELETE FROM {$this->getSQLTable($name)} 
                WHERE _uid = :_uid
                {$this->getTenantQuery($collection)}
		    ";

            $sql = $this->trigger(Database::EVENT_DOCUMENT_DELETE, $sql);

            $stmt = $this->getPDO()->prepare($sql);

            $stmt->bindValue(':_uid', $id);

            if ($this->sharedTables) {
                $stmt->bindValue(':_tenant', $this->tenant);
            }

            $sql = "
			    DELETE FROM {$this->getSQLTable($name . '_perms')} 
		        WHERE _document = :_uid
		        {$this->getTenantQuery($collection)}
		    ";

            $sql = $this->trigger(Database::EVENT_PERMISSIONS_DELETE, $sql);

            $stmtPermissions = $this->getPDO()->prepare($sql);
            $stmtPermissions->bindValue(':_uid', $id);

            if ($this->sharedTables) {
                $stmtPermissions->bindValue(':_tenant', $this->tenant);
            }

            if (!$stmt->execute()) {
                throw new DatabaseException('Failed to delete document');
            }

            $deleted = $stmt->rowCount();

            if (!$stmtPermissions->execute()) {
                throw new DatabaseException('Failed to delete permissions');
            }
        } catch (\Throwable $e) {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        return $deleted;
    }

    /**
     * Handle distance spatial queries
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param string $attribute
     * @param string $type
     * @param string $alias
     * @param string $placeholder
     * @return string
    */
    protected function handleDistanceSpatialQueries(Query $query, array &$binds, string $attribute, string $type, string $alias, string $placeholder): string
    {
        $distanceParams = $query->getValues()[0];
        $wkt = $this->convertArrayToWKT($distanceParams[0]);
        $binds[":{$placeholder}_0"] = $wkt;
        $binds[":{$placeholder}_1"] = $distanceParams[1];

        $useMeters = isset($distanceParams[2]) && $distanceParams[2] === true;

        switch ($query->getMethod()) {
            case Query::TYPE_DISTANCE_EQUAL:
                $operator = '=';
                break;
            case Query::TYPE_DISTANCE_NOT_EQUAL:
                $operator = '!=';
                break;
            case Query::TYPE_DISTANCE_GREATER_THAN:
                $operator = '>';
                break;
            case Query::TYPE_DISTANCE_LESS_THAN:
                $operator = '<';
                break;
            default:
                throw new DatabaseException('Unknown spatial query method: ' . $query->getMethod());
        }

        if ($useMeters) {
            $wktType = $this->getSpatialTypeFromWKT($wkt);
            $attrType = strtolower($type);
            if ($wktType != Database::VAR_POINT || $attrType != Database::VAR_POINT) {
                throw new QueryException('Distance in meters is not supported between '.$attrType . ' and '. $wktType);
            }
            return "ST_DISTANCE_SPHERE({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ", " . Database::EARTH_RADIUS . ") {$operator} :{$placeholder}_1";
        }
        return "ST_Distance({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ") {$operator} :{$placeholder}_1";
    }

    /**
     * Handle spatial queries
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param string $attribute
     * @param string $type
     * @param string $alias
     * @param string $placeholder
     * @return string
     */
    protected function handleSpatialQueries(Query $query, array &$binds, string $attribute, string $type, string $alias, string $placeholder): string
    {
        switch ($query->getMethod()) {
            case Query::TYPE_CROSSES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Crosses({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_NOT_CROSSES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Crosses({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_DISTANCE_EQUAL:
            case Query::TYPE_DISTANCE_NOT_EQUAL:
            case Query::TYPE_DISTANCE_GREATER_THAN:
            case Query::TYPE_DISTANCE_LESS_THAN:
                return $this->handleDistanceSpatialQueries($query, $binds, $attribute, $type, $alias, $placeholder);

            case Query::TYPE_INTERSECTS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Intersects({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_NOT_INTERSECTS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Intersects({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_OVERLAPS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Overlaps({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_NOT_OVERLAPS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Overlaps({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_TOUCHES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Touches({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_NOT_TOUCHES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Touches({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_EQUAL:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Equals({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_NOT_EQUAL:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Equals({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_CONTAINS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Contains({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_NOT_CONTAINS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Contains({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ")";

            case Query::TYPE_IS_NULL:
            case Query::TYPE_IS_NOT_NULL:
                return "{$alias}.{$attribute} {$this->getSQLOperator($query->getMethod())}";

            default:
                throw new DatabaseException('Unknown spatial query method: ' . $query->getMethod());
        }
    }

    /**
     * Get SQL Condition
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @return string
     * @throws Exception
     */
    protected function getSQLCondition(Query $query, array &$binds, ?string $forCollection = null): string
    {
        $query->setAttribute($this->getInternalKeyForAttribute($query->getAttribute()));

        $attribute = $query->getAttribute();
        $attribute = $this->filter($attribute);
        $attribute = $this->quote($attribute);
        $alias = $this->quote(Query::DEFAULT_ALIAS);
        $placeholder = ID::unique();

        if ($query->isSpatialAttribute()) {
            return $this->handleSpatialQueries($query, $binds, $attribute, $query->getAttributeType(), $alias, $placeholder);
        }

        switch ($query->getMethod()) {
            case Query::TYPE_OR:
            case Query::TYPE_AND:
                $conditions = [];
                /* @var $q Query */
                foreach ($query->getValue() as $q) {
                    $conditions[] = $this->getSQLCondition($q, $binds, $forCollection);
                }

                $method = strtoupper($query->getMethod());

                return empty($conditions) ? '' : ' '. $method .' (' . implode(' AND ', $conditions) . ')';

            case Query::TYPE_SEARCH:
                $fulltextValue = $this->getFulltextValue($query->getValue());
                if ($fulltextValue === '') {
                    return '0 = 1';
                }
                $binds[":{$placeholder}_0"] = $fulltextValue;

                return "MATCH({$alias}.{$attribute}) AGAINST (:{$placeholder}_0 IN BOOLEAN MODE)";

            case Query::TYPE_NOT_SEARCH:
                $fulltextValue = $this->getFulltextValue($query->getValue());
                if ($fulltextValue === '') {
                    return '1 = 1';
                }
                $binds[":{$placeholder}_0"] = $fulltextValue;

                return "NOT (MATCH({$alias}.{$attribute}) AGAINST (:{$placeholder}_0 IN BOOLEAN MODE))";

            case Query::TYPE_BETWEEN:
                $binds[":{$placeholder}_0"] = $query->getValues()[0];
                $binds[":{$placeholder}_1"] = $query->getValues()[1];

                return "{$alias}.{$attribute} BETWEEN :{$placeholder}_0 AND :{$placeholder}_1";

            case Query::TYPE_NOT_BETWEEN:
                $binds[":{$placeholder}_0"] = $query->getValues()[0];
                $binds[":{$placeholder}_1"] = $query->getValues()[1];

                return "{$alias}.{$attribute} NOT BETWEEN :{$placeholder}_0 AND :{$placeholder}_1";

            case Query::TYPE_IS_NULL:
            case Query::TYPE_IS_NOT_NULL:

                return "{$alias}.{$attribute} {$this->getSQLOperator($query->getMethod())}";
            case Query::TYPE_CONTAINS_ALL:
                if ($query->onArray()) {
                    $binds[":{$placeholder}_0"] = json_encode($query->getValues());
                    return "JSON_CONTAINS({$alias}.{$attribute}, :{$placeholder}_0)";
                }
                // no break
            case Query::TYPE_CONTAINS:
            case Query::TYPE_CONTAINS_ANY:
            case Query::TYPE_NOT_CONTAINS:
                if ($query->onArray()) {
                    $isNot = $query->getMethod() === Query::TYPE_NOT_CONTAINS;

                    if ($this->getSupportForJSONOverlaps()) {
                        $binds[":{$placeholder}_0"] = json_encode($query->getValues());
                        return $isNot
                            ? "NOT (JSON_OVERLAPS({$alias}.{$attribute}, :{$placeholder}_0))"
                            : "JSON_OVERLAPS({$alias}.{$attribute}, :{$placeholder}_0)";
                    }

                    // JSON_CONTAINS per element OR'd together — exact
                    // element match without LIKE's substring false positives
                    // (`%2%` matching `[12, 200]`, `%"apple"%` matching
                    // `["pineapple"]`).
                    $conditions = [];
                    foreach ($query->getValues() as $key => $value) {
                        $binds[":{$placeholder}_{$key}"] = json_encode($value);
                        $conditions[] = "JSON_CONTAINS({$alias}.{$attribute}, :{$placeholder}_{$key})";
                    }
                    if (empty($conditions)) {
                        return '';
                    }
                    $expression = '(' . implode(' OR ', $conditions) . ')';
                    return $isNot ? "NOT {$expression}" : $expression;
                }
                // no break
            default:
                $conditions = [];
                $isNotQuery = in_array($query->getMethod(), [
                    Query::TYPE_NOT_STARTS_WITH,
                    Query::TYPE_NOT_ENDS_WITH,
                    Query::TYPE_NOT_CONTAINS
                ]);

                foreach ($query->getValues() as $key => $value) {
                    $value = match ($query->getMethod()) {
                        Query::TYPE_STARTS_WITH => $this->escapeWildcards($value) . '%',
                        Query::TYPE_NOT_STARTS_WITH => $this->escapeWildcards($value) . '%',
                        Query::TYPE_ENDS_WITH => '%' . $this->escapeWildcards($value),
                        Query::TYPE_NOT_ENDS_WITH => '%' . $this->escapeWildcards($value),
                        Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY, Query::TYPE_NOT_CONTAINS => '%' . $this->escapeWildcards($value) . '%',
                        default => $value
                    };

                    $binds[":{$placeholder}_{$key}"] = $value;
                    if ($isNotQuery) {
                        $conditions[] = "{$alias}.{$attribute} NOT {$this->getSQLOperator($query->getMethod())} :{$placeholder}_{$key}";
                    } else {
                        $conditions[] = "{$alias}.{$attribute} {$this->getSQLOperator($query->getMethod())} :{$placeholder}_{$key}";
                    }
                }

                $separator = $isNotQuery ? ' AND ' : ' OR ';
                return empty($conditions) ? '' : '(' . implode($separator, $conditions) . ')';
        }
    }

    /**
     * Get SQL Type
     *
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @param bool $required
     * @return string
     * @throws DatabaseException
     */
    protected function getSQLType(string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): string
    {
        if (in_array($type, Database::SPATIAL_TYPES)) {
            return $this->getSpatialSQLType($type, $required);
        }
        if ($array === true) {
            return 'JSON';
        }

        switch ($type) {
            case Database::VAR_ID:
                return 'BIGINT UNSIGNED';

            case Database::VAR_STRING:
                // $size = $size * 4; // Convert utf8mb4 size to bytes
                if ($size > Database::MAX_MEDIUMTEXT_BYTES) {
                    return 'LONGTEXT';
                }

                if ($size > Database::MAX_TEXT_BYTES) {
                    return 'MEDIUMTEXT';
                }

                if ($size > $this->getMaxVarcharLength()) {
                    return 'TEXT';
                }

                return "VARCHAR({$size})";

            case Database::VAR_VARCHAR:
                if ($size <= 0) {
                    throw new DatabaseException('VARCHAR size ' . $size . ' is invalid; must be > 0. Use TEXT, MEDIUMTEXT, or LONGTEXT instead.');
                }
                if ($size > $this->getMaxVarcharLength()) {
                    throw new DatabaseException('VARCHAR size ' . $size . ' exceeds maximum varchar length ' . $this->getMaxVarcharLength() . '. Use TEXT, MEDIUMTEXT, or LONGTEXT instead.');
                }
                return "VARCHAR({$size})";

            case Database::VAR_TEXT:
                return 'TEXT';

            case Database::VAR_MEDIUMTEXT:
                return 'MEDIUMTEXT';

            case Database::VAR_LONGTEXT:
                return 'LONGTEXT';

            case Database::VAR_INTEGER:  // We don't support zerofill: https://stackoverflow.com/a/5634147/2299554
                $signed = ($signed) ? '' : ' UNSIGNED';

                if ($size >= 8) { // INT = 4 bytes, BIGINT = 8 bytes
                    return 'BIGINT' . $signed;
                }

                return 'INT' . $signed;

            case Database::VAR_BIGINT:
                $signed = ($signed) ? '' : ' UNSIGNED';
                return 'BIGINT' . $signed;

            case Database::VAR_FLOAT:
                $signed = ($signed) ? '' : ' UNSIGNED';
                return 'DOUBLE' . $signed;

            case Database::VAR_BOOLEAN:
                return 'TINYINT(1)';

            case Database::VAR_RELATIONSHIP:
                return 'VARCHAR(255)';

            case Database::VAR_DATETIME:
                return 'DATETIME(3)';

            default:
                throw new DatabaseException('Unknown type: ' . $type . '. Must be one of ' . Database::VAR_STRING . ', ' . Database::VAR_VARCHAR . ', ' . Database::VAR_TEXT . ', ' . Database::VAR_MEDIUMTEXT . ', ' . Database::VAR_LONGTEXT . ', ' . Database::VAR_INTEGER . ', ' . Database::VAR_BIGINT . ', ' . Database::VAR_FLOAT . ', ' . Database::VAR_BOOLEAN . ', ' . Database::VAR_DATETIME . ', ' . Database::VAR_RELATIONSHIP . ', ' . Database::VAR_POINT . ', ' . Database::VAR_LINESTRING . ', ' . Database::VAR_POLYGON);
        }
    }

    /**
     * Get PDO Type
     *
     * @param mixed $value
     * @return int
     * @throws Exception
     */
    protected function getPDOType(mixed $value): int
    {
        return match (gettype($value)) {
            'string','double' => \PDO::PARAM_STR,
            'integer', 'boolean' => \PDO::PARAM_INT,
            'NULL' => \PDO::PARAM_NULL,
            default => throw new DatabaseException('Unknown PDO Type for ' . \gettype($value)),
        };
    }

    /**
     * Get the SQL function for random ordering
     *
     * @return string
     */
    protected function getRandomOrder(): string
    {
        return 'RAND()';
    }

    /**
     * Size of POINT spatial type
     *
     * @return int
    */
    protected function getMaxPointSize(): int
    {
        // https://dev.mysql.com/doc/refman/8.4/en/gis-data-formats.html#gis-internal-format
        return 25;
    }

    public function getMinDateTime(): \DateTime
    {
        return new \DateTime('1000-01-01 00:00:00');
    }

    public function getMaxDateTime(): \DateTime
    {
        return new \DateTime('9999-12-31 23:59:59');
    }

    /**
     * Is fulltext Wildcard index supported?
     *
     * @return bool
     */
    public function getSupportForFulltextWildcardIndex(): bool
    {
        return true;
    }

    /**
     * Does the adapter handle Query Array Overlaps?
     *
     * @return bool
     */
    public function getSupportForJSONOverlaps(): bool
    {
        return true;
    }

    public function getSupportForIntegerBooleans(): bool
    {
        return true;
    }

    /**
     * Are timeouts supported?
     *
     * @return bool
     */
    public function getSupportForTimeouts(): bool
    {
        return true;
    }

    public function getSupportForUpserts(): bool
    {
        return true;
    }

    public function getSupportForUpsertOnUniqueIndex(): bool
    {
        return true;
    }

    public function getSupportForSchemaAttributes(): bool
    {
        return true;
    }

    public function getSupportForSchemaIndexes(): bool
    {
        return true;
    }

    public function getSchemaIndexes(string $collection): array
    {
        $schema = $this->getDatabase();
        $collection = $this->getNamespace() . '_' . $this->filter($collection);

        try {
            $stmt = $this->getPDO()->prepare('
                SELECT
                    INDEX_NAME as indexName,
                    COLUMN_NAME as columnName,
                    NON_UNIQUE as nonUnique,
                    SEQ_IN_INDEX as seqInIndex,
                    INDEX_TYPE as indexType,
                    SUB_PART as subPart
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
                ORDER BY INDEX_NAME, SEQ_IN_INDEX
            ');
            $stmt->bindParam(':schema', $schema);
            $stmt->bindParam(':table', $collection);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $stmt->closeCursor();

            $grouped = [];
            foreach ($rows as $row) {
                $name = $row['indexName'];
                if (!isset($grouped[$name])) {
                    $grouped[$name] = [
                        '$id' => $name,
                        'indexName' => $name,
                        'indexType' => $row['indexType'],
                        'nonUnique' => (int)$row['nonUnique'],
                        'columns' => [],
                        'lengths' => [],
                    ];
                }
                $grouped[$name]['columns'][] = $row['columnName'];
                $grouped[$name]['lengths'][] = $row['subPart'] !== null ? (int)$row['subPart'] : null;
            }

            return \array_map(fn ($idx) => new Document($idx), \array_values($grouped));
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get schema indexes', $e->getCode(), $e);
        }
    }

    /**
     * Set max execution time
     * @param int $milliseconds
     * @param string $event
     * @return void
     * @throws DatabaseException
     */
    public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): void
    {
        if (!$this->getSupportForTimeouts()) {
            return;
        }
        if ($milliseconds <= 0) {
            throw new DatabaseException('Timeout must be greater than 0');
        }

        $this->timeout = $milliseconds;

        $seconds = $milliseconds / 1000;

        $this->before($event, 'timeout', function ($sql) use ($seconds) {
            return "SET STATEMENT max_statement_time = {$seconds} FOR " . $sql;
        });
    }

    /**
     * @return string
     */
    public function getConnectionId(): string
    {
        $stmt = $this->getPDO()->query("SELECT CONNECTION_ID();");
        return $stmt->fetchColumn();
    }

    public function getInternalIndexesKeys(): array
    {
        return ['primary', '_created_at', '_updated_at', '_tenant_id'];
    }

    protected function processException(PDOException $e): \Exception
    {
        if ($e->getCode() === '22007' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1366) {
            return new CharacterException('Invalid character', $e->getCode(), $e);
        }

        // Timeout
        if ($e->getCode() === '70100' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1969) {
            return new TimeoutException('Query timed out', $e->getCode(), $e);
        }

        // Duplicate table
        if ($e->getCode() === '42S01' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1050) {
            return new DuplicateException('Collection already exists', $e->getCode(), $e);
        }

        // Duplicate column
        if ($e->getCode() === '42S21' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1060) {
            return new DuplicateException('Attribute already exists', $e->getCode(), $e);
        }

        // Duplicate index
        if ($e->getCode() === '42000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1061) {
            return new DuplicateException('Index already exists', $e->getCode(), $e);
        }

        // Index key too long
        if ($e->getCode() === '42000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1071) {
            return new IndexException('Index key length exceeds the maximum', $e->getCode(), $e);
        }

        // Duplicate row
        if ($e->getCode() === '23000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) {
            $key = $this->getViolatedKey($e->getMessage());
            if ($key === '_index1') {
                return new DuplicateException('Duplicate permissions for document', $e->getCode(), $e);
            }
            if ($key !== null && $key !== '_uid' && $key !== 'PRIMARY') {
                return new UniqueException('Unique index violation', $e->getCode(), $e);
            }
            return new DuplicateException('Document already exists', $e->getCode(), $e);
        }

        // Data is too big for column resize
        if (($e->getCode() === '22001' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1406) ||
            ($e->getCode() === '01000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1265)) {
            return new TruncateException('Resize would result in data truncation', $e->getCode(), $e);
        }

        // Numeric value out of range
        if ($e->getCode() === '22003' && isset($e->errorInfo[1]) && ($e->errorInfo[1] === 1264 || $e->errorInfo[1] === 1690)) {
            return new LimitException('Value out of range', $e->getCode(), $e);
        }

        // Numeric value out of range
        if ($e->getCode() === 'HY000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1690) {
            return new LimitException('Value is out of range', $e->getCode(), $e);
        }

        // Unknown database
        if ($e->getCode() === '42000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1049) {
            return new NotFoundException('Database not found', $e->getCode(), $e);
        }

        // Unknown collection
        if ($e->getCode() === '42S02' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1049) {
            return new NotFoundException('Collection not found', $e->getCode(), $e);
        }

        // Unknown collection
        // We have two of same, because docs point to 1051.
        // Keeping previous 1049 (above) just in case it's for older versions
        if ($e->getCode() === '42S02' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1051) {
            return new NotFoundException('Collection not found', $e->getCode(), $e);
        }

        // Unknown column
        if ($e->getCode() === '42000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1091) {
            return new NotFoundException('Attribute not found', $e->getCode(), $e);
        }

        return $e;
    }

    /**
     * Extract the index name from a duplicate entry error, e.g.
     * "Duplicate entry 'x' for key 'movies._uid'" resolves to "_uid".
     * Returns null when the message cannot be parsed.
     */
    protected function getViolatedKey(string $message): ?string
    {
        if (\preg_match("/for key '(?:[^'.]*\.)?([^']+)'/", $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function quote(string $string): string
    {
        return "`{$string}`";
    }

    /**
     * Get operator SQL
     * Override to handle MariaDB/MySQL-specific operators
     *
     * @param string $column
     * @param Operator $operator
     * @param array<string, mixed> $binds
     * @return ?string
     */
    protected function getOperatorSQL(string $column, Operator $operator, array &$binds): ?string
    {
        $quotedColumn = $this->quote($column);
        $method = $operator->getMethod();
        $values = $operator->getValues();

        switch ($method) {
            // Numeric operators
            case Operator::TYPE_INCREMENT:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    // Compare with the operand moved across (`col > max - val`) instead of
                    // `col + val > max`, so the guard never overflows BIGINT when col is near the
                    // integer range limit. Inclusive: a result landing exactly on max still applies.
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$quotedColumn}, 0) > :$maxKey - :$bindKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) + :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) + :$bindKey";

            case Operator::TYPE_DECREMENT:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $minKey = $this->registerOperatorBind($binds, $values[1]);
                    // `col < min + val` rather than `col - val < min`: overflow-safe near the
                    // integer range limit. Inclusive: a result landing exactly on min still applies.
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$quotedColumn}, 0) < :$minKey + :$bindKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) - :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) - :$bindKey";

            case Operator::TYPE_MULTIPLY:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    // Compare via division (`col > max/val`, sign-aware) instead of computing
                    // `col * val`, which would overflow BIGINT for large operands. The factor's
                    // sign flips the inequality. Inclusive: a result exactly on max still applies.
                    return "{$quotedColumn} = CASE
                        WHEN :$bindKey > 0 AND COALESCE({$quotedColumn}, 0) > :$maxKey / :$bindKey THEN COALESCE({$quotedColumn}, 0)
                        WHEN :$bindKey < 0 AND COALESCE({$quotedColumn}, 0) < :$maxKey / :$bindKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) * :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) * :$bindKey";

            case Operator::TYPE_DIVIDE:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $minKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN :$bindKey != 0 AND COALESCE({$quotedColumn}, 0) / :$bindKey < :$minKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) / :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) / :$bindKey";

            case Operator::TYPE_MODULO:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                return "{$quotedColumn} = MOD(COALESCE({$quotedColumn}, 0), :$bindKey)";

            case Operator::TYPE_POWER:
                $exponent = $values[0] ?? 1;
                $bindKey = $this->registerOperatorBind($binds, $exponent);
                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    $col = "COALESCE({$quotedColumn}, 0)";

                    // Leave the value unchanged only for undefined inputs, then apply the power if
                    // the result stays within the max. The exponent is constant, so only the
                    // undefined guard its value can actually trigger is emitted.
                    $oddInteger = \floor($exponent) == $exponent && ((int) $exponent) % 2 !== 0;

                    $whens = [];
                    if ($exponent < 0) {
                        // 0 to a negative power is undefined (POWER would error / return NULL).
                        $whens[] = "WHEN {$col} = 0 THEN {$col}";
                    }
                    if (\floor($exponent) != $exponent) {
                        // A negative base to a fractional exponent is not a real number.
                        $whens[] = "WHEN {$col} < 0 THEN {$col}";
                    }
                    // Cap by magnitude via logarithms so POWER() never runs on a value that would
                    // overflow (base^exp > max  <=>  exp * LOG(base) > LOG(max)).
                    if ($exponent == 0) {
                        // Every base to the zeroth power is 1 (including 0^0), which the magnitude
                        // check below can't see for a base of 0. The result 1 exceeds the max when
                        // max < 1, i.e. LOG(max) < 0 (LOG also coerces the bound value numerically).
                        $whens[] = "WHEN LOG(:$maxKey) < 0 THEN {$col}";
                    } elseif ($oddInteger) {
                        // An odd exponent keeps a negative base negative, and a negative result is
                        // always within a positive max, so only cap positive bases; negative bases
                        // fall through to POWER() and their (negative) result is applied.
                        $whens[] = "WHEN {$col} > 0 AND :$bindKey * LOG({$col}) > LOG(:$maxKey) THEN {$col}";
                    } else {
                        // Otherwise the result is non-negative, so its magnitude equals its value —
                        // cap either sign. ABS() keeps LOG() defined for a negative even-power base.
                        $whens[] = "WHEN {$col} <> 0 AND :$bindKey * LOG(ABS({$col})) > LOG(:$maxKey) THEN {$col}";
                    }

                    $whenSql = \implode(' ', $whens);
                    return "{$quotedColumn} = CASE {$whenSql} ELSE POWER({$col}, :$bindKey) END";
                }
                return "{$quotedColumn} = POWER(COALESCE({$quotedColumn}, 0), :$bindKey)";

                // String operators
            case Operator::TYPE_STRING_CONCAT:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? '');
                return "{$quotedColumn} = CONCAT(COALESCE({$quotedColumn}, ''), :$bindKey)";

            case Operator::TYPE_STRING_REPLACE:
                $searchKey = $this->registerOperatorBind($binds, $values[0] ?? '');
                $replaceKey = $this->registerOperatorBind($binds, $values[1] ?? '');
                return "{$quotedColumn} = REPLACE({$quotedColumn}, :$searchKey, :$replaceKey)";

                // Boolean operators
            case Operator::TYPE_TOGGLE:
                return "{$quotedColumn} = NOT COALESCE({$quotedColumn}, FALSE)";

                // Array operators
            case Operator::TYPE_ARRAY_APPEND:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = JSON_MERGE_PRESERVE(IFNULL({$quotedColumn}, JSON_ARRAY()), :$bindKey)";

            case Operator::TYPE_ARRAY_PREPEND:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = JSON_MERGE_PRESERVE(:$bindKey, IFNULL({$quotedColumn}, JSON_ARRAY()))";

            case Operator::TYPE_ARRAY_INSERT:
                $indexKey = $this->registerOperatorBind($binds, $values[0] ?? 0);
                $valueKey = $this->registerOperatorBind($binds, json_encode($values[1] ?? null));
                return "{$quotedColumn} = JSON_ARRAY_INSERT(
                    {$quotedColumn},
                    CONCAT('$[', :$indexKey, ']'),
                    JSON_EXTRACT(:$valueKey, '$')
                )";

            case Operator::TYPE_ARRAY_REMOVE:
                $removeValue = $values[0] ?? null;
                // Cast scalars to string so the value binds as PDO::PARAM_STR, preserving the
                // pre-refactor behavior (it was bound with an explicit PARAM_STR). JSON_TABLE
                // extracts `value` as TEXT, so the search term must compare as text — without
                // the cast, getPDOType() would bind a number as PARAM_INT. Do not drop it.
                $removeValue = is_array($removeValue) ? json_encode($removeValue) : (string)$removeValue;
                $bindKey = $this->registerOperatorBind($binds, $removeValue);
                return "{$quotedColumn} = IFNULL((
                    SELECT JSON_ARRAYAGG(value)
                    FROM JSON_TABLE({$quotedColumn}, '\$[*]' COLUMNS(value TEXT PATH '\$')) AS jt
                    WHERE value != :$bindKey
                ), JSON_ARRAY())";

            case Operator::TYPE_ARRAY_UNIQUE:
                return "{$quotedColumn} = IFNULL((
                    SELECT JSON_ARRAYAGG(DISTINCT jt.value)
                    FROM JSON_TABLE({$quotedColumn}, '\$[*]' COLUMNS(value TEXT PATH '\$')) AS jt
                ), JSON_ARRAY())";

            case Operator::TYPE_ARRAY_INTERSECT:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = IFNULL((
                    SELECT JSON_ARRAYAGG(jt1.value)
                    FROM JSON_TABLE({$quotedColumn}, '\$[*]' COLUMNS(value TEXT PATH '\$')) AS jt1
                    WHERE jt1.value IN (
                        SELECT value
                        FROM JSON_TABLE(:$bindKey, '\$[*]' COLUMNS(value TEXT PATH '\$')) AS jt2
                    )
                ), JSON_ARRAY())";

            case Operator::TYPE_ARRAY_DIFF:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = IFNULL((
                    SELECT JSON_ARRAYAGG(jt1.value)
                    FROM JSON_TABLE({$quotedColumn}, '\$[*]' COLUMNS(value TEXT PATH '\$')) AS jt1
                    WHERE jt1.value NOT IN (
                        SELECT value
                        FROM JSON_TABLE(:$bindKey, '\$[*]' COLUMNS(value TEXT PATH '\$')) AS jt2
                    )
                ), JSON_ARRAY())";

            case Operator::TYPE_ARRAY_FILTER:
                $condition = $values[0] ?? 'equal';
                $filterValue = $values[1] ?? null;
                $conditionKey = $this->registerOperatorBind($binds, $condition);
                $valueKey = $this->registerOperatorBind($binds, $filterValue === null ? null : json_encode($filterValue));
                return "{$quotedColumn} = IFNULL((
                    SELECT JSON_ARRAYAGG(value)
                    FROM JSON_TABLE({$quotedColumn}, '\$[*]' COLUMNS(value TEXT PATH '\$')) AS jt
                    WHERE CASE :$conditionKey
                        WHEN 'equal' THEN value = JSON_UNQUOTE(:$valueKey)
                        WHEN 'notEqual' THEN value != JSON_UNQUOTE(:$valueKey)
                        WHEN 'greaterThan' THEN CAST(value AS DECIMAL(65,30)) > CAST(JSON_UNQUOTE(:$valueKey) AS DECIMAL(65,30))
                        WHEN 'greaterThanEqual' THEN CAST(value AS DECIMAL(65,30)) >= CAST(JSON_UNQUOTE(:$valueKey) AS DECIMAL(65,30))
                        WHEN 'lessThan' THEN CAST(value AS DECIMAL(65,30)) < CAST(JSON_UNQUOTE(:$valueKey) AS DECIMAL(65,30))
                        WHEN 'lessThanEqual' THEN CAST(value AS DECIMAL(65,30)) <= CAST(JSON_UNQUOTE(:$valueKey) AS DECIMAL(65,30))
                        WHEN 'isNull' THEN value IS NULL
                        WHEN 'isNotNull' THEN value IS NOT NULL
                        ELSE TRUE
                    END
                ), JSON_ARRAY())";

                // Date operators
            case Operator::TYPE_DATE_ADD_DAYS:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 0);
                return "{$quotedColumn} = DATE_ADD({$quotedColumn}, INTERVAL :$bindKey DAY)";

            case Operator::TYPE_DATE_SUB_DAYS:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 0);
                return "{$quotedColumn} = DATE_SUB({$quotedColumn}, INTERVAL :$bindKey DAY)";

            case Operator::TYPE_DATE_SET_NOW:
                return "{$quotedColumn} = NOW()";

            default:
                throw new OperatorException("Invalid operator: {$method}");
        }
    }

    public function getSupportForNumericCasting(): bool
    {
        return true;
    }

    public function getSupportForIndexArray(): bool
    {
        return true;
    }

    public function getSupportForSpatialAttributes(): bool
    {
        return true;
    }

    public function getSupportForObject(): bool
    {
        return false;
    }

    public function getSupportForUnsignedBigInt(): bool
    {
        return true;
    }

    /**
     * Are object (JSON) indexes supported?
     *
     * @return bool
     */
    public function getSupportForObjectIndexes(): bool
    {
        return false;
    }

    /**
     * Get Support for Null Values in Spatial Indexes
     *
     * @return bool
     */
    public function getSupportForSpatialIndexNull(): bool
    {
        return false;
    }
    /**
     * Does the adapter includes boundary during spatial contains?
     *
     * @return bool
     */

    public function getSupportForBoundaryInclusiveContains(): bool
    {
        return true;
    }
    /**
     * Does the adapter support order attribute in spatial indexes?
     *
     * @return bool
     */
    public function getSupportForSpatialIndexOrder(): bool
    {
        return true;
    }

    /**
     * Does the adapter support calculating distance(in meters) between multidimension geometry(line, polygon,etc)?
     *
     * @return bool
    */
    public function getSupportForDistanceBetweenMultiDimensionGeometryInMeters(): bool
    {
        return false;
    }

    public function getSpatialSQLType(string $type, bool $required): string
    {
        $srid = Database::DEFAULT_SRID;
        $nullability = '';

        if (!$this->getSupportForSpatialIndexNull()) {
            if ($required) {
                $nullability = ' NOT NULL';
            } else {
                $nullability = ' NULL';
            }
        }

        switch ($type) {
            case Database::VAR_POINT:
                return "POINT($srid)$nullability";

            case Database::VAR_LINESTRING:
                return "LINESTRING($srid)$nullability";

            case Database::VAR_POLYGON:
                return "POLYGON($srid)$nullability";
        }

        return '';
    }

    /**
     * Does the adapter support spatial axis order specification?
     *
     * @return bool
     */
    public function getSupportForSpatialAxisOrder(): bool
    {
        return false;
    }

    /**
     * Adapter supports optional spatial attributes with existing rows.
     *
     * @return bool
     */
    public function getSupportForOptionalSpatialAttributeWithExistingRows(): bool
    {
        return true;
    }

    public function getSupportForAlterLocks(): bool
    {
        return true;
    }

    public function getSupportNonUtfCharacters(): bool
    {
        return true;
    }

    public function getSupportForTrigramIndex(): bool
    {
        return false;
    }

    public function getSupportForPCRERegex(): bool
    {
        return true;
    }

    public function getSupportForPOSIXRegex(): bool
    {
        return false;
    }

    public function getSupportForTTLIndexes(): bool
    {
        return false;
    }
}
