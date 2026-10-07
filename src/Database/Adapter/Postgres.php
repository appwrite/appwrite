<?php

namespace Utopia\Database\Adapter;

use Exception;
use PDO;
use PDOException;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Operator as OperatorException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Operator;
use Utopia\Database\Query;

/**
 * Differences between MariaDB and Postgres
 *
 * 1. Need to use CASCADE to DROP schema
 * 2. Quotes are different ` vs "
 * 3. DATETIME is TIMESTAMP
 * 4. Full-text search is different - to_tsvector() and to_tsquery()
 */
class Postgres extends SQL
{
    public const MAX_IDENTIFIER_NAME = 63;

    /**
     * @inheritDoc
     */
    public function rollbackTransaction(): bool
    {
        if ($this->inTransaction === 0) {
            return false;
        }

        try {
            if ($this->inTransaction > 1) {
                $this->getPDO()->exec('ROLLBACK TO transaction' . ($this->inTransaction - 1));
                $this->inTransaction--;
                return true;
            }

            $result = $this->getPDO()->rollBack();
            $this->inTransaction = 0;
        } catch (PDOException $e) {
            $this->inTransaction = 0;
            throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }

        if (!$result) {
            throw new TransactionException('Failed to rollback transaction');
        }

        return $result;
    }

    protected function execute(mixed $stmt): bool
    {
        $pdo = $this->getPDO();

        // Choose the right SET command based on transaction state
        $sql = $this->inTransaction === 0
            ? "SET statement_timeout = '{$this->timeout}ms'"
            : "SET LOCAL statement_timeout = '{$this->timeout}ms'";

        // Apply timeout
        $pdo->exec($sql);

        try {
            return $stmt->execute();
        } finally {
            // Only reset the global timeout when not in a transaction
            if ($this->inTransaction === 0) {
                $pdo->exec("RESET statement_timeout");
            }
        }
    }



    /**
     * Returns Max Execution Time
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
    }

    /**
     * Create Database
     *
     * @param string $name
     *
     * @return bool
     * @throws DatabaseException
     */
    public function create(string $name): bool
    {
        $name = $this->filter($name);

        if ($this->exists($name)) {
            return true;
        }

        $sql = "CREATE SCHEMA \"{$name}\"";
        $sql = $this->trigger(Database::EVENT_DATABASE_CREATE, $sql);

        $dbCreation = $this->getPDO()
            ->prepare($sql)
            ->execute();

        // Enable extensions
        $this->getPDO()->prepare('CREATE EXTENSION IF NOT EXISTS postgis')->execute();
        $this->getPDO()->prepare('CREATE EXTENSION IF NOT EXISTS vector')->execute();
        $this->getPDO()->prepare('CREATE EXTENSION IF NOT EXISTS pg_trgm')->execute();

        $collation = "
            CREATE COLLATION IF NOT EXISTS utf8_ci_ai (
            provider = icu,
            locale = 'und-u-ks-level1',
            deterministic = false
            )
        ";
        $this->getPDO()->prepare($collation)->execute();
        return $dbCreation;
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

        $sql = "DROP SCHEMA IF EXISTS \"{$name}\" CASCADE";
        $sql = $this->trigger(Database::EVENT_DATABASE_DELETE, $sql);

        return $this->getPDO()->prepare($sql)->execute();
    }

    /**
     * Create Collection
     *
     * @param string $name
     * @param array<Document> $attributes
     * @param array<Document> $indexes
     * @return bool
     * @throws DuplicateException
     */
    public function createCollection(string $name, array $attributes = [], array $indexes = []): bool
    {
        $namespace = $this->getNamespace();
        $id = $this->filter($name);

        /** @var array<string> $attributeStrings */
        $attributeStrings = [];
        foreach ($attributes as $attribute) {
            $attrId = $this->filter($attribute->getId());

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

            $attributeStrings[] = "\"{$attrId}\" {$attrType}, ";
        }

        $sqlTenant = $this->sharedTables ? '_tenant INTEGER DEFAULT NULL,' : '';
        $collection = "
            CREATE TABLE {$this->getSQLTable($id)} (
                _id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                _uid VARCHAR(255) NOT NULL,
                " . $sqlTenant . "
                \"_createdAt\" TIMESTAMP(3) DEFAULT NULL,
                \"_updatedAt\" TIMESTAMP(3) DEFAULT NULL,
                " . \implode(' ', $attributeStrings) . "
                _permissions JSONB DEFAULT NULL
            );
        ";

        if ($this->sharedTables) {
            $uidIndex = $this->getShortKey("{$namespace}_{$this->tenant}_{$id}_uid");
            $createdIndex = $this->getShortKey("{$namespace}_{$this->tenant}_{$id}_created");
            $updatedIndex = $this->getShortKey("{$namespace}_{$this->tenant}_{$id}_updated");
            $tenantIdIndex = $this->getShortKey("{$namespace}_{$this->tenant}_{$id}_tenant_id");
            $permissionsIndex = $this->getShortKey("{$namespace}_{$this->tenant}_{$id}_permissions");
            $collection .= "
				CREATE UNIQUE INDEX \"{$uidIndex}\" ON {$this->getSQLTable($id)} (\"_uid\" COLLATE utf8_ci_ai, \"_tenant\");
            	CREATE INDEX \"{$createdIndex}\" ON {$this->getSQLTable($id)} (_tenant, \"_createdAt\");
            	CREATE INDEX \"{$updatedIndex}\" ON {$this->getSQLTable($id)} (_tenant, \"_updatedAt\");
            	CREATE INDEX \"{$tenantIdIndex}\" ON {$this->getSQLTable($id)} (_tenant, _id);
            	CREATE INDEX \"{$permissionsIndex}\" ON {$this->getSQLTable($id)} USING gin (_permissions);
			";
        } else {
            $uidIndex = $this->getShortKey("{$namespace}_{$id}_uid");
            $createdIndex = $this->getShortKey("{$namespace}_{$id}_created");
            $updatedIndex = $this->getShortKey("{$namespace}_{$id}_updated");
            $permissionsIndex = $this->getShortKey("{$namespace}_{$id}_permissions");
            $collection .= "
				CREATE UNIQUE INDEX \"{$uidIndex}\" ON {$this->getSQLTable($id)} (\"_uid\" COLLATE utf8_ci_ai);
            	CREATE INDEX \"{$createdIndex}\" ON {$this->getSQLTable($id)} (\"_createdAt\");
            	CREATE INDEX \"{$updatedIndex}\" ON {$this->getSQLTable($id)} (\"_updatedAt\");
            	CREATE INDEX \"{$permissionsIndex}\" ON {$this->getSQLTable($id)} USING gin (_permissions);
			";
        }

        $collection = $this->trigger(Database::EVENT_COLLECTION_CREATE, $collection);

        $permissions = "
            CREATE TABLE {$this->getSQLTable($id . '_perms')} (
                _id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                _tenant INTEGER DEFAULT NULL,
                _type VARCHAR(12) NOT NULL,
                _permission VARCHAR(255) NOT NULL,
                _document VARCHAR(255) NOT NULL
            );
        ";

        if ($this->sharedTables) {
            $uniquePermissionIndex = $this->getShortKey("{$namespace}_{$this->tenant}_{$id}_ukey");
            $permissionIndex = $this->getShortKey("{$namespace}_{$this->tenant}_{$id}_permission");
            $permissions .= "
                CREATE UNIQUE INDEX \"{$uniquePermissionIndex}\" 
                    ON {$this->getSQLTable($id . '_perms')} USING btree (_tenant,_document,_type,_permission);
                CREATE INDEX \"{$permissionIndex}\" 
                    ON {$this->getSQLTable($id . '_perms')} USING btree (_tenant,_permission,_type); 
            ";
        } else {
            $uniquePermissionIndex = $this->getShortKey("{$namespace}_{$id}_ukey");
            $permissionIndex = $this->getShortKey("{$namespace}_{$id}_permission");
            $permissions .= "
                CREATE UNIQUE INDEX \"{$uniquePermissionIndex}\" 
                    ON {$this->getSQLTable($id . '_perms')} USING btree (_document COLLATE utf8_ci_ai,_type,_permission);
                CREATE INDEX \"{$permissionIndex}\" 
                    ON {$this->getSQLTable($id . '_perms')} USING btree (_permission,_type); 
            ";
        }

        $permissions = $this->trigger(Database::EVENT_COLLECTION_CREATE, $permissions);

        try {
            $this->getPDO()->prepare($collection)->execute();

            $this->getPDO()->prepare($permissions)->execute();

            foreach ($indexes as $index) {
                $indexId = $this->filter($index->getId());
                $indexType = $index->getAttribute('type');
                $indexAttributes = $index->getAttribute('attributes', []);
                $indexAttributesWithType = [];
                foreach ($indexAttributes as $indexAttribute) {
                    foreach ($attributes as $attribute) {
                        if ($attribute->getId() === $indexAttribute) {
                            $indexAttributesWithType[$indexAttribute] = $attribute->getAttribute('type');
                        }
                    }
                }
                $indexOrders = $index->getAttribute('orders', []);
                $indexTtl = $index->getAttribute('ttl', 0);
                if ($indexType === Database::INDEX_SPATIAL && count($indexOrders)) {
                    throw new DatabaseException('Spatial indexes with explicit orders are not supported. Remove the orders to create this index.');
                }
                $this->createIndex(
                    $id,
                    $indexId,
                    $indexType,
                    $indexAttributes,
                    [],
                    $indexOrders,
                    $indexAttributesWithType,
                    [],
                    $indexTtl
                );
            }
        } catch (PDOException $e) {
            $e = $this->processException($e);

            if (!($e instanceof DuplicateException)) {
                $this->execute($this->getPDO()
                    ->prepare("DROP TABLE IF EXISTS {$this->getSQLTable($id)}, {$this->getSQLTable($id . '_perms')};"));
            }

            throw $e;
        }

        return true;
    }

    /**
     * Get Collection Size on disk
     * @param string $collection
     * @return int
     * @throws DatabaseException
     */
    public function getSizeOfCollectionOnDisk(string $collection): int
    {
        $collection = $this->filter($collection);
        $name = $this->getSQLTable($collection);
        $permissions = $this->getSQLTable($collection . '_perms');

        $collectionSize = $this->getPDO()->prepare("
             SELECT pg_total_relation_size(:name);
        ");

        $permissionsSize = $this->getPDO()->prepare("
             SELECT pg_total_relation_size(:permissions);
        ");

        $collectionSize->bindParam(':name', $name);
        $permissionsSize->bindParam(':permissions', $permissions);

        try {
            $this->execute($collectionSize);
            $this->execute($permissionsSize);
            $size = $collectionSize->fetchColumn() + $permissionsSize->fetchColumn();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get collection size: ' . $e->getMessage());
        }

        return  $size;
    }

    /**
     * Get Collection Size of raw data
     * @param string $collection
     * @return int
     * @throws DatabaseException
     *
     */
    public function getSizeOfCollection(string $collection): int
    {
        $collection = $this->filter($collection);
        $name = $this->getSQLTable($collection);
        $permissions = $this->getSQLTable($collection . '_perms');

        $collectionSize = $this->getPDO()->prepare("
             SELECT pg_relation_size(:name);
        ");

        $permissionsSize = $this->getPDO()->prepare("
             SELECT pg_relation_size(:permissions);
        ");

        $collectionSize->bindParam(':name', $name);
        $permissionsSize->bindParam(':permissions', $permissions);

        try {
            $this->execute($collectionSize);
            $this->execute($permissionsSize);
            $size = $collectionSize->fetchColumn() + $permissionsSize->fetchColumn();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get collection size: ' . $e->getMessage());
        }

        return  $size;
    }

    /**
     * Delete Collection
     *
     * @param string $id
     * @return bool
     */
    public function deleteCollection(string $id): bool
    {
        $id = $this->filter($id);

        $sql = "DROP TABLE {$this->getSQLTable($id)}, {$this->getSQLTable($id . '_perms')}";
        $sql = $this->trigger(Database::EVENT_COLLECTION_DELETE, $sql);

        try {
            return $this->getPDO()->prepare($sql)->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Analyze a collection updating it's metadata on the database engine
     *
     * @param string $collection
     * @return bool
     */
    public function analyzeCollection(string $collection): bool
    {
        return false;
    }

    /**
     * Create Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     *
     * @return bool
     * @throws DatabaseException
     */
    public function createAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): bool
    {
        // Ensure pgvector extension is installed for vector types
        if ($type === Database::VAR_VECTOR) {
            if ($size <= 0) {
                throw new DatabaseException('Vector dimensions must be a positive integer');
            }
            if ($size > Database::MAX_VECTOR_DIMENSIONS) {
                throw new DatabaseException('Vector dimensions cannot exceed ' . Database::MAX_VECTOR_DIMENSIONS);
            }
        }

        $name = $this->filter($collection);
        $id = $this->filter($id);
        $type = $this->getSQLType($type, $size, $signed, $array, $required);

        $sql = "
			ALTER TABLE {$this->getSQLTable($name)}
			ADD COLUMN \"{$id}\" {$type}
		";

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_CREATE, $sql);

        try {
            return $this->execute($this->getPDO()
                ->prepare($sql));
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     * @param bool $array
     *
     * @return bool
     * @throws DatabaseException
     */
    public function deleteAttribute(string $collection, string $id, bool $array = false): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        $sql = "
			ALTER TABLE {$this->getSQLTable($name)}
			DROP COLUMN \"{$id}\";
		";

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_DELETE, $sql);

        try {
            return $this->execute($this->getPDO()
                ->prepare($sql));
        } catch (PDOException $e) {
            if ($e->getCode() === "42703" && $e->errorInfo[1] === 7) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Rename Attribute
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function renameAttribute(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $old = $this->filter($old);
        $new = $this->filter($new);

        $sql = "
			ALTER TABLE {$this->getSQLTable($collection)} 
			RENAME COLUMN \"{$old}\" TO \"{$new}\"
		";

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_UPDATE, $sql);

        return $this->execute($this->getPDO()
            ->prepare($sql));
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
     * @throws Exception
     * @throws PDOException
     */
    public function updateAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, ?string $newKey = null, bool $required = false): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);
        $newKey = empty($newKey) ? null : $this->filter($newKey);

        if ($type === Database::VAR_VECTOR) {
            if ($size <= 0) {
                throw new DatabaseException('Vector dimensions must be a positive integer');
            }
            if ($size > Database::MAX_VECTOR_DIMENSIONS) {
                throw new DatabaseException('Vector dimensions cannot exceed ' . Database::MAX_VECTOR_DIMENSIONS);
            }
        }

        $type = $this->getSQLType(
            $type,
            $size,
            $signed,
            $array,
            $required,
        );

        if ($type == 'TIMESTAMP(3)') {
            $type = "TIMESTAMP(3) without time zone USING TO_TIMESTAMP(\"$id\", 'YYYY-MM-DD HH24:MI:SS.MS')";
        }

        if (!empty($newKey) && $id !== $newKey) {
            $newKey = $this->filter($newKey);

            $sql = "
                    ALTER TABLE {$this->getSQLTable($name)}
                    RENAME COLUMN \"{$id}\" TO \"{$newKey}\"
                ";

            $sql = $this->trigger(Database::EVENT_ATTRIBUTE_UPDATE, $sql);

            $result = $this->execute($this->getPDO()
                ->prepare($sql));

            if (!$result) {
                return false;
            }

            $id = $newKey;
        }

        $sql = "
                ALTER TABLE {$this->getSQLTable($name)}
                ALTER COLUMN \"{$id}\" TYPE {$type}
            ";

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_UPDATE, $sql);

        try {
            $result = $this->execute($this->getPDO()
                ->prepare($sql));

            return $result;
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
     * @throws Exception
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
                $sql = "ALTER TABLE {$table} ADD COLUMN \"{$id}\" {$sqlType} DEFAULT NULL;";

                if ($twoWay) {
                    $sql .= "ALTER TABLE {$relatedTable} ADD COLUMN \"{$twoWayKey}\" {$sqlType} DEFAULT NULL;";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                $sql = "ALTER TABLE {$relatedTable} ADD COLUMN \"{$twoWayKey}\" {$sqlType} DEFAULT NULL;";
                break;
            case Database::RELATION_MANY_TO_ONE:
                $sql = "ALTER TABLE {$table} ADD COLUMN \"{$id}\" {$sqlType} DEFAULT NULL;";
                break;
            case Database::RELATION_MANY_TO_MANY:
                return true;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_CREATE, $sql);

        return $this->execute($this->getPDO()
            ->prepare($sql));
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
                    $sql = "ALTER TABLE {$table} RENAME COLUMN \"{$key}\" TO \"{$newKey}\";";
                }
                if ($twoWay && $twoWayKey !== $newTwoWayKey) {
                    $sql .= "ALTER TABLE {$relatedTable} RENAME COLUMN \"{$twoWayKey}\" TO \"{$newTwoWayKey}\";";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    if ($twoWayKey !== $newTwoWayKey) {
                        $sql = "ALTER TABLE {$relatedTable} RENAME COLUMN \"{$twoWayKey}\" TO \"{$newTwoWayKey}\";";
                    }
                } else {
                    if ($key !== $newKey) {
                        $sql = "ALTER TABLE {$table} RENAME COLUMN \"{$key}\" TO \"{$newKey}\";";
                    }
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    if ($twoWayKey !== $newTwoWayKey) {
                        $sql = "ALTER TABLE {$relatedTable} RENAME COLUMN \"{$twoWayKey}\" TO \"{$newTwoWayKey}\";";
                    }
                } else {
                    if ($key !== $newKey) {
                        $sql = "ALTER TABLE {$table} RENAME COLUMN \"{$key}\" TO \"{$newKey}\";";
                    }
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $metadataCollection = new Document(['$id' => Database::METADATA]);
                $collection = $this->getDocument($metadataCollection, $collection);
                $relatedCollection = $this->getDocument($metadataCollection, $relatedCollection);

                $junction = $this->getSQLTable('_' . $collection->getSequence() . '_' . $relatedCollection->getSequence());

                if (!\is_null($newKey)) {
                    $sql = "ALTER TABLE {$junction} RENAME COLUMN \"{$key}\" TO \"{$newKey}\";";
                }
                if ($twoWay && !\is_null($newTwoWayKey)) {
                    $sql .= "ALTER TABLE {$junction} RENAME COLUMN \"{$twoWayKey}\" TO \"{$newTwoWayKey}\";";
                }
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        if (empty($sql)) {
            return true;
        }

        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_UPDATE, $sql);

        return $this->execute($this->getPDO()
            ->prepare($sql));
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

        $sql = '';

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $sql = "ALTER TABLE {$table} DROP COLUMN \"{$key}\";";
                    if ($twoWay) {
                        $sql .= "ALTER TABLE {$relatedTable} DROP COLUMN \"{$twoWayKey}\";";
                    }
                } elseif ($side === Database::RELATION_SIDE_CHILD) {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN \"{$twoWayKey}\";";
                    if ($twoWay) {
                        $sql .= "ALTER TABLE {$table} DROP COLUMN \"{$key}\";";
                    }
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN \"{$twoWayKey}\";";
                } else {
                    $sql = "ALTER TABLE {$table} DROP COLUMN \"{$key}\";";
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN \"{$twoWayKey}\";";
                } else {
                    $sql = "ALTER TABLE {$table} DROP COLUMN \"{$key}\";";
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

        return $this->execute($this->getPDO()
            ->prepare($sql));
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
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders, array $indexAttributeTypes = [], array $collation = [], int $ttl = 1): bool
    {
        $collection = $this->filter($collection);
        $id = $this->filter($id);

        foreach ($attributes as $i => $attr) {
            $order = empty($orders[$i]) || Database::INDEX_FULLTEXT === $type ? '' : $orders[$i];
            $isNestedPath = isset($indexAttributeTypes[$attr]) && \str_contains($attr, '.') && $indexAttributeTypes[$attr] === Database::VAR_OBJECT;
            if ($isNestedPath) {
                $attributes[$i] = $this->buildJsonbPath($attr, true) . ($order ? " {$order}" : '');
            } else {
                $attr = $this->getInternalKeyForAttribute($attr);
                $attr = $this->filter($attr);

                $attributes[$i] = "\"{$attr}\" {$order}";
            }
        }

        $sqlType = match ($type) {
            Database::INDEX_KEY,
            Database::INDEX_FULLTEXT,
            Database::INDEX_SPATIAL,
            Database::INDEX_HNSW_EUCLIDEAN,
            Database::INDEX_HNSW_COSINE,
            Database::INDEX_HNSW_DOT,
            Database::INDEX_OBJECT,
            Database::INDEX_TRIGRAM => 'INDEX',
            Database::INDEX_UNIQUE => 'UNIQUE INDEX',
            default => throw new DatabaseException('Unknown index type: ' . $type . '. Must be one of ' . Database::INDEX_KEY . ', ' . Database::INDEX_UNIQUE . ', ' . Database::INDEX_FULLTEXT . ', ' . Database::INDEX_SPATIAL . ', ' . Database::INDEX_OBJECT . ', ' . Database::INDEX_HNSW_EUCLIDEAN . ', ' . Database::INDEX_HNSW_COSINE . ', ' . Database::INDEX_HNSW_DOT),
        };

        $keyName = $this->getShortKey("{$this->getNamespace()}_{$this->tenant}_{$collection}_{$id}");
        $attributes = \implode(', ', $attributes);

        if ($this->sharedTables && \in_array($type, [Database::INDEX_KEY, Database::INDEX_UNIQUE])) {
            // Add tenant as first index column for best performance
            $attributes = "_tenant, {$attributes}";
        }

        $sql = "CREATE {$sqlType} \"{$keyName}\" ON {$this->getSQLTable($collection)}";

        // Add USING clause for special index types
        $sql .= match ($type) {
            Database::INDEX_SPATIAL => " USING GIST ({$attributes})",
            Database::INDEX_HNSW_EUCLIDEAN => " USING HNSW ({$attributes} vector_l2_ops)",
            Database::INDEX_HNSW_COSINE => " USING HNSW ({$attributes} vector_cosine_ops)",
            Database::INDEX_HNSW_DOT => " USING HNSW ({$attributes} vector_ip_ops)",
            Database::INDEX_OBJECT => " USING GIN ({$attributes})",
            Database::INDEX_TRIGRAM =>
                " USING GIN (" . implode(', ', array_map(
                    fn ($attr) => "$attr gin_trgm_ops",
                    array_map(fn ($attr) => trim($attr), explode(',', $attributes))
                )) . ")",
            default => " ({$attributes})",
        };

        $sql = $this->trigger(Database::EVENT_INDEX_CREATE, $sql);

        try {
            return $this->getPDO()->prepare($sql)->execute();
        } catch (PDOException $e) {
            // Existing rows violate the new unique index. Classified here because an
            // expression key (nested object path) has no columns for processException() to parse.
            if ($e->getCode() === '23505' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
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
     *
     * @return bool
     * @throws Exception
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $collection = $this->filter($collection);
        $id = $this->filter($id);
        $schemaName = $this->getDatabase();

        $keyName = $this->getShortKey("{$this->getNamespace()}_{$this->tenant}_{$collection}_{$id}");

        $sql = "DROP INDEX IF EXISTS \"{$schemaName}\".\"{$keyName}\"";
        $sql = $this->trigger(Database::EVENT_INDEX_DELETE, $sql);

        return $this->execute($this->getPDO()
            ->prepare($sql));
    }

    /**
     * Rename Index
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $namespace = $this->getNamespace();
        $old = $this->filter($old);
        $new = $this->filter($new);
        $schema = $this->getDatabase();
        $oldIndexName = $this->getShortKey("{$namespace}_{$this->tenant}_{$collection}_{$old}");
        $newIndexName = $this->getShortKey("{$namespace}_{$this->tenant}_{$collection}_{$new}");

        $sql = "ALTER INDEX \"{$schema}\".\"{$oldIndexName}\" RENAME TO \"{$newIndexName}\"";
        $sql = $this->trigger(Database::EVENT_INDEX_RENAME, $sql);

        return $this->execute($this->getPDO()
            ->prepare($sql));
    }

    /**
     * Create Document
     *
     * @param Document $collection
     * @param Document $document
     *
     * @return Document
     */
    public function createDocument(Document $collection, Document $document): Document
    {
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

        // Insert internal id if set
        if (!empty($document->getSequence())) {
            $bindKey = '_id';
            $columns .= "\"_id\", ";
            $columnNames .= ':' . $bindKey . ', ';
        }

        $bindIndex = 0;
        foreach ($attributes as $attribute => $value) {
            $column = $this->filter($attribute);
            $bindKey = 'key_' . $bindIndex;
            $columns .= "\"{$column}\", ";
            $columnNames .= ':' . $bindKey . ', ';
            $bindIndex++;
        }

        $sql = "
			INSERT INTO {$this->getSQLTable($name)} ({$columns} \"_uid\")
			VALUES ({$columnNames} :_uid)
		";

        $sql = $this->trigger(Database::EVENT_DOCUMENT_CREATE, $sql);

        $stmt = $this->getPDO()->prepare($sql);

        $stmt->bindValue(':_uid', $document->getId(), PDO::PARAM_STR);

        if (!empty($document->getSequence())) {
            $stmt->bindValue(':_id', $document->getSequence(), PDO::PARAM_STR);
        }

        $attributeIndex = 0;
        foreach ($attributes as $value) {
            if (\is_array($value)) {
                $value = \json_encode($value);
            }

            $bindKey = 'key_' . $attributeIndex;
            $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
            $attributeIndex++;
        }

        $permissions = [];
        foreach (Database::PERMISSIONS as $type) {
            foreach ($document->getPermissionsByType($type) as $permission) {
                $permission = \str_replace('"', '', $permission);
                $sqlTenant = $this->sharedTables ? ', :_tenant' : '';
                $permissions[] = "('{$type}', '{$permission}', :_uid {$sqlTenant})";
            }
        }


        if (!empty($permissions)) {
            $permissions = \implode(', ', $permissions);
            $sqlTenant = $this->sharedTables ? ', _tenant' : '';

            $queryPermissions = "
				INSERT INTO {$this->getSQLTable($name . '_perms')} (_type, _permission, _document {$sqlTenant})
				VALUES {$permissions}
			";

            $queryPermissions = $this->trigger(Database::EVENT_PERMISSIONS_CREATE, $queryPermissions);
            $stmtPermissions = $this->getPDO()->prepare($queryPermissions);
            $stmtPermissions->bindValue(':_uid', $document->getId());
            if ($sqlTenant) {
                $stmtPermissions->bindValue(':_tenant', $document->getTenant());
            }
        }

        try {
            $this->execute($stmt);
            $lastInsertedId = $this->getPDO()->lastInsertId();
            // Sequence can be manually set as well
            $document['$sequence'] ??= $lastInsertedId;

            if (isset($stmtPermissions)) {
                $this->execute($stmtPermissions);
            }
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return $document;
    }

    /**
     * Update Document
     *
     *
     * @param Document $collection
     * @param string $id
     * @param Document $document
     * @param bool $skipPermissions
     * @return Document
     * @throws DatabaseException
     * @throws DuplicateException
     */
    public function updateDocument(Document $collection, string $id, Document $document, bool $skipPermissions): Document
    {
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
                    $sqlTenant = $this->sharedTables ? ', :_tenant' : '';
                    $values[] = "( :_uid, '{$type}', :_add_{$type}_{$i} {$sqlTenant})";
                    $binds[":_add_{$type}_{$i}"] = $permission;
                }
            }

            if (!empty($values)) {
                $sqlTenant = $this->sharedTables ? ', _tenant' : '';

                $sql = "
				INSERT INTO {$this->getSQLTable($name . '_perms')} (_document, _type, _permission {$sqlTenant})
				VALUES " . \implode(', ', $values);

                $sql = $this->trigger(Database::EVENT_PERMISSIONS_CREATE, $sql);

                $stmtAddPermissions = $this->getPDO()->prepare($sql);
                $stmtAddPermissions->bindValue(":_uid", $newUid);
                if ($this->sharedTables) {
                    $stmtAddPermissions->bindValue(':_tenant', $document->getTenant());
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

            // Check if this is an operator, spatial attribute, or regular attribute
            if (Operator::isOperator($value)) {
                $operatorSQL = $this->getOperatorSQL($column, $value, $operatorBinds);
                $columns .= $operatorSQL . ',';
            } elseif (\in_array($attribute, $spatialAttributes, true)) {
                $bindKey = 'key_' . $keyIndex;
                $columns .= "\"{$column}\" = " . $this->getSpatialGeomFromText(':' . $bindKey) . ',';
                $keyIndex++;
            } else {
                $bindKey = 'key_' . $keyIndex;
                $columns .= "\"{$column}\"" . '=:' . $bindKey . ',';
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
            $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
            $keyIndex++;
        }

        foreach ($operatorBinds as $bindKey => $bindValue) {
            $stmt->bindValue($bindKey, $bindValue, $this->getPDOType($bindValue));
        }

        try {
            $this->execute($stmt);
            if (isset($stmtRemovePermissions)) {
                $this->execute($stmtRemovePermissions);
            }
            if (isset($stmtAddPermissions)) {
                $this->execute($stmtAddPermissions);
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
     */
    protected function getUpsertStatement(
        string $tableName,
        string $columns,
        array $batchKeys,
        array $attributes,
        array $bindValues,
        string $attribute = '',
        array $operators = [],
    ): mixed {
        $getUpdateClause = function (string $attribute, bool $increment = false): string {
            $attribute = $this->quote($this->filter($attribute));
            if ($increment) {
                $new = "target.{$attribute} + EXCLUDED.{$attribute}";
            } else {
                $new = "EXCLUDED.{$attribute}";
            }

            if ($this->sharedTables) {
                return "{$attribute} = CASE WHEN target._tenant = EXCLUDED._tenant THEN {$new} ELSE target.{$attribute} END";
            }

            return "{$attribute} = {$new}";
        };

        $operatorBinds = [];

        if (!empty($attribute)) {
            // Increment specific column by its new value in place
            $updateColumns = [
                $getUpdateClause($attribute, increment: true),
                $getUpdateClause('_updatedAt'),
            ];
        } else {
            // Update all columns and apply operators
            $updateColumns = [];
            foreach (array_keys($attributes) as $attr) {
                /**
                 * @var string $attr
                 */
                $filteredAttr = $this->filter($attr);

                // Check if this attribute has an operator
                if (isset($operators[$attr])) {
                    $operatorSQL = $this->getOperatorSQL($filteredAttr, $operators[$attr], $operatorBinds, useTargetPrefix: true);
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

        $conflictKeys = $this->sharedTables ? '("_uid", _tenant)' : '("_uid")';

        $stmt = $this->getPDO()->prepare(
            "
            INSERT INTO {$this->getSQLTable($tableName)} AS target {$columns}
            VALUES " . implode(', ', $batchKeys) . "
            ON CONFLICT {$conflictKeys} DO UPDATE
                SET " . implode(', ', $updateColumns)
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
    public function increaseDocumentAttribute(string $collection, string $id, string $attribute, int|float $value, string $updatedAt, int|float|null $min = null, int|float|null $max = null): bool
    {
        $name = $this->filter($collection);
        $attribute = $this->filter($attribute);

        $sqlMax = $max !== null ? " AND \"{$attribute}\" <= :max" : "";
        $sqlMin = $min !== null ? " AND \"{$attribute}\" >= :min" : "";

        $sql = "
			UPDATE {$this->getSQLTable($name)}
			SET
			    \"{$attribute}\" = \"{$attribute}\" + :val,
                \"_updatedAt\" = :updatedAt
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

        $this->execute($stmt) || throw new DatabaseException('Failed to update attribute');
        return true;
    }

    /**
     * Delete Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteDocument(string $collection, string $id): bool
    {
        $name = $this->filter($collection);

        $sql = "
			DELETE FROM {$this->getSQLTable($name)} 
			WHERE _uid = :_uid
			{$this->getTenantQuery($collection)}
		";

        $sql = $this->trigger(Database::EVENT_DOCUMENT_DELETE, $sql);
        $stmt = $this->getPDO()->prepare($sql);
        $stmt->bindValue(':_uid', $id, PDO::PARAM_STR);

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

        $deleted = false;

        try {
            if (!$this->execute($stmt)) {
                throw new DatabaseException('Failed to delete document');
            }

            $deleted = $stmt->rowCount();

            if (!$this->execute($stmtPermissions)) {
                throw new DatabaseException('Failed to delete permissions');
            }
        } catch (\Throwable $th) {
            throw new DatabaseException($th->getMessage());
        }

        return $deleted;
    }

    /**
     * @return string
     */
    public function getConnectionId(): string
    {
        $stmt = $this->getPDO()->query("SELECT pg_backend_pid();");
        return $stmt->fetchColumn();
    }

    /**
     * Handle distance spatial queries
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param string $attribute
     * @param string $alias
     * @param string $placeholder
     * @return string
    */
    protected function handleDistanceSpatialQueries(Query $query, array &$binds, string $attribute, string $alias, string $placeholder): string
    {
        $distanceParams = $query->getValues()[0];
        $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($distanceParams[0]);
        $binds[":{$placeholder}_1"] = $distanceParams[1];

        $meters = isset($distanceParams[2]) && $distanceParams[2] === true;

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

        if ($meters) {
            $attr = "({$alias}.{$attribute}::geography)";
            $geom = "ST_SetSRID(" . $this->getSpatialGeomFromText(":{$placeholder}_0", null) . ", " . Database::DEFAULT_SRID . ")::geography";
            return "ST_Distance({$attr}, {$geom}) {$operator} :{$placeholder}_1";
        }

        // Without meters, use the original SRID (e.g., 4326)
        return "ST_Distance({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ") {$operator} :{$placeholder}_1";
    }


    /**
     * Handle spatial queries
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param string $attribute
     * @param string $alias
     * @param string $placeholder
     * @return string
     */
    protected function handleSpatialQueries(Query $query, array &$binds, string $attribute, string $alias, string $placeholder): string
    {
        switch ($query->getMethod()) {
            case Query::TYPE_CROSSES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Crosses({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_NOT_CROSSES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Crosses({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_DISTANCE_EQUAL:
            case Query::TYPE_DISTANCE_NOT_EQUAL:
            case Query::TYPE_DISTANCE_GREATER_THAN:
            case Query::TYPE_DISTANCE_LESS_THAN:
                return $this->handleDistanceSpatialQueries($query, $binds, $attribute, $alias, $placeholder);
            case Query::TYPE_EQUAL:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Equals({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_NOT_EQUAL:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Equals({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_INTERSECTS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Intersects({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_NOT_INTERSECTS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Intersects({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_OVERLAPS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Overlaps({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_NOT_OVERLAPS:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Overlaps({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_TOUCHES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "ST_Touches({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_NOT_TOUCHES:
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return "NOT ST_Touches({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_CONTAINS:
            case Query::TYPE_NOT_CONTAINS:
                // using st_cover instead of contains to match the boundary matching behaviour of the mariadb st_contains
                // postgis st_contains excludes matching the boundary
                $isNot = $query->getMethod() === Query::TYPE_NOT_CONTAINS;
                $binds[":{$placeholder}_0"] = $this->convertArrayToWKT($query->getValues()[0]);
                return $isNot
                    ? "NOT ST_Covers({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")"
                    : "ST_Covers({$alias}.{$attribute}, " . $this->getSpatialGeomFromText(":{$placeholder}_0") . ")";

            case Query::TYPE_IS_NULL:
            case Query::TYPE_IS_NOT_NULL:
                return "{$alias}.{$attribute} {$this->getSQLOperator($query->getMethod())}";

            default:
                throw new DatabaseException('Unknown spatial query method: ' . $query->getMethod());
        }
    }

    /**
     * Handle JSONB queries
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param string $attribute
     * @param string $alias
     * @param string $placeholder
     * @return string
     */
    protected function handleObjectQueries(Query $query, array &$binds, string $attribute, string $alias, string $placeholder): string
    {
        switch ($query->getMethod()) {
            case Query::TYPE_EQUAL:
            case Query::TYPE_NOT_EQUAL: {
                $isNot = $query->getMethod() === Query::TYPE_NOT_EQUAL;
                $conditions = [];
                foreach ($query->getValues() as $key => $value) {
                    $binds[":{$placeholder}_{$key}"] = json_encode($value);
                    $fragment = "{$alias}.{$attribute} @> :{$placeholder}_{$key}::jsonb";
                    $conditions[] = $isNot ? "NOT (" . $fragment . ")" : $fragment;
                }
                $separator = $isNot ? ' AND ' : ' OR ';
                return empty($conditions) ? '' : '(' . implode($separator, $conditions) . ')';
            }

            case Query::TYPE_CONTAINS:
            case Query::TYPE_CONTAINS_ANY:
            case Query::TYPE_CONTAINS_ALL:
            case Query::TYPE_NOT_CONTAINS: {
                $isNot = $query->getMethod() === Query::TYPE_NOT_CONTAINS;
                $conditions = [];
                foreach ($query->getValues() as $key => $value) {
                    if (count($value) === 1) {
                        $jsonKey = array_key_first($value);
                        $jsonValue = $value[$jsonKey];

                        // If scalar (e.g. "skills" => "typescript"),
                        // wrap it to express array containment: {"skills": ["typescript"]}
                        // If it's already an object/associative array (e.g. "config" => ["lang" => "en"]),
                        // keep as-is to express object containment.
                        if (!\is_array($jsonValue)) {
                            $value[$jsonKey] = [$jsonValue];
                        }
                    }
                    $binds[":{$placeholder}_{$key}"] = json_encode($value);
                    $fragment = "{$alias}.{$attribute} @> :{$placeholder}_{$key}::jsonb";
                    $conditions[] = $isNot ? "NOT (" . $fragment . ")" : $fragment;
                }
                $separator = $isNot ? ' AND ' : ' OR ';
                return empty($conditions) ? '' : '(' . implode($separator, $conditions) . ')';
            }

            default:
                throw new DatabaseException('Query method ' . $query->getMethod() . ' not supported for object attributes');
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
        $isNestedObjectAttribute = $query->isObjectAttribute() && \str_contains($query->getAttribute(), '.');
        if ($isNestedObjectAttribute) {
            $attribute = $this->buildJsonbPath($query->getAttribute());
        } else {
            $attribute = $this->filter($query->getAttribute());
            $attribute = $this->quote($attribute);
        }

        $alias = $this->quote(Query::DEFAULT_ALIAS);
        $placeholder = ID::unique();

        $operator = null;

        if ($query->isSpatialAttribute()) {
            return $this->handleSpatialQueries($query, $binds, $attribute, $alias, $placeholder);
        }

        if ($query->isObjectAttribute() && !$isNestedObjectAttribute) {
            return $this->handleObjectQueries($query, $binds, $attribute, $alias, $placeholder);
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
                return empty($conditions) ? '' : ' ' . $method . ' (' . implode(' AND ', $conditions) . ')';

            case Query::TYPE_SEARCH:
                $fulltextValue = $this->getFulltextValue($query->getValue());
                if ($fulltextValue === '') {
                    return '0 = 1';
                }
                $binds[":{$placeholder}_0"] = $fulltextValue;
                return "to_tsvector(regexp_replace({$attribute}, '[^\w]+',' ','g')) @@ websearch_to_tsquery(:{$placeholder}_0)";

            case Query::TYPE_NOT_SEARCH:
                $fulltextValue = $this->getFulltextValue($query->getValue());
                if ($fulltextValue === '') {
                    return '1 = 1';
                }
                $binds[":{$placeholder}_0"] = $fulltextValue;
                return "NOT (to_tsvector(regexp_replace({$attribute}, '[^\w]+',' ','g')) @@ websearch_to_tsquery(:{$placeholder}_0))";

            case Query::TYPE_VECTOR_DOT:
            case Query::TYPE_VECTOR_COSINE:
            case Query::TYPE_VECTOR_EUCLIDEAN:
                return ''; // Handled in ORDER BY clause

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
                    // @> checks the array contains ALL specified values
                    $binds[":{$placeholder}_0"] = \json_encode($query->getValues());
                    return "{$alias}.{$attribute} @> :{$placeholder}_0::jsonb";
                }
                // no break
            case Query::TYPE_CONTAINS:
            case Query::TYPE_CONTAINS_ANY:
            case Query::TYPE_NOT_CONTAINS:
                if ($query->onArray()) {
                    $operator = '@>';
                }

                // no break
            default:
                $conditions = [];
                $operator = $operator ?? $this->getSQLOperator($query->getMethod());
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
                        Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY => ($query->onArray()) ? \json_encode($value) : '%' . $this->escapeWildcards($value) . '%',
                        Query::TYPE_NOT_CONTAINS => ($query->onArray()) ? \json_encode($value) : '%' . $this->escapeWildcards($value) . '%',
                        default => $value
                    };

                    $binds[":{$placeholder}_{$key}"] = $value;

                    if ($isNotQuery && $query->onArray()) {
                        // For array NOT queries, wrap the entire condition in NOT()
                        $conditions[] = "NOT ({$alias}.{$attribute} {$operator} :{$placeholder}_{$key})";
                    } elseif ($isNotQuery && !$query->onArray()) {
                        $conditions[] = "{$alias}.{$attribute} NOT {$operator} :{$placeholder}_{$key}";
                    } else {
                        $conditions[] = "{$alias}.{$attribute} {$operator} :{$placeholder}_{$key}";
                    }
                }

                $separator = $isNotQuery ? ' AND ' : ' OR ';
                return empty($conditions) ? '' : '(' . implode($separator, $conditions) . ')';
        }
    }

    /**
     * Get the SQL expression measuring distance between a vector attribute and the query vector
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param string $alias
     * @return string|null
     * @throws DatabaseException
     */
    protected function getSQLVectorDistance(Query $query, array &$binds, string $alias): ?string
    {
        $query->setAttribute($this->getInternalKeyForAttribute($query->getAttribute()));

        $attribute = $this->filter($query->getAttribute());
        $attribute = $this->quote($attribute);
        $alias = $this->quote($alias);
        $placeholder = ID::unique();

        $values = $query->getValues();
        $vectorArray = $values[0] ?? [];
        $vector = \json_encode(\array_map(\floatval(...), $vectorArray));
        $binds[":vector_{$placeholder}"] = $vector;

        return match ($query->getMethod()) {
            Query::TYPE_VECTOR_DOT => "({$alias}.{$attribute} <#> :vector_{$placeholder}::vector)",
            Query::TYPE_VECTOR_COSINE => "({$alias}.{$attribute} <=> :vector_{$placeholder}::vector)",
            Query::TYPE_VECTOR_EUCLIDEAN => "({$alias}.{$attribute} <-> :vector_{$placeholder}::vector)",
            default => null,
        };
    }

    /**
     * @param string $distance
     * @return string
     */
    protected function getSQLReadableDistance(string $distance): string
    {
        return "{$distance}::text";
    }

    /**
     * Match the permission against the copy carried on the row rather than joining the
     * permissions table.
     *
     * Both hold the same fact, written together, but a semi join has to be resolved before
     * anything can be ordered, which forces the whole collection to be read whenever the
     * ordering could otherwise have come from an index. Matching on the row leaves the
     * planner free to cost the permission against the ordering, so a selective permission
     * drives from the GIN index and a permissive one is a cheap filter over whichever index
     * the ordering wanted.
     *
     * @param string $collection
     * @param array<string> $roles
     * @param string $alias
     * @param string $type
     * @return string
     * @throws DatabaseException
     */
    protected function getSQLPermissionsCondition(
        string $collection,
        array $roles,
        string $alias,
        string $type = Database::PERMISSION_READ
    ): string {
        if (!\in_array($type, Database::PERMISSIONS)) {
            throw new DatabaseException('Unknown permission type: ' . $type);
        }

        $column = "{$this->quote($alias)}.{$this->quote('_permissions')}";

        // Containment rather than jsonb's ?| key operator: PDO reads a lone ? as a positional
        // placeholder, and doubling it to escape breaks once a named placeholder is repeated,
        // which the cursor conditions do. Each role is its own @> so the index can answer them
        // as a BitmapOr; jsonb_exists_any would express it in one call but is not indexable.
        $permissions = \array_map(
            fn ($role) => "{$column} @> {$this->getPDO()->quote(\json_encode(["{$type}(\"{$role}\")"]))}::jsonb",
            $roles
        );

        if ($permissions === []) {
            return 'FALSE';
        }

        return '(' . \implode(' OR ', $permissions) . ')';
    }

    /**
     * @param string $value
     * @return string
     */
    protected function getFulltextValue(string $value): string
    {
        $exact = str_ends_with($value, '"') && str_starts_with($value, '"');

        /** Keep only unicode letters, numbers, underscores, and whitespace. */
        $value = preg_replace('/[^\p{L}\p{N}_\s]/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = trim($value);

        if (empty($value)) {
            return '';
        }

        if (!$exact) {
            $value = str_replace(' ', ' or ', $value);
        }

        return "'" . $value . "'";
    }

    /**
     * Get SQL Type
     *
     * @param string $type
     * @param int $size in chars
     * @param bool $signed
     * @param bool $array
     * @param bool $required
     * @return string
     * @throws DatabaseException
     */
    protected function getSQLType(string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): string
    {
        if ($array === true) {
            return 'JSONB';
        }

        switch ($type) {
            case Database::VAR_ID:
                return 'BIGINT';

            case Database::VAR_STRING:
                // $size = $size * 4; // Convert utf8mb4 size to bytes
                if ($size > $this->getMaxVarcharLength()) {
                    return 'TEXT';
                }

                return "VARCHAR({$size})";

            case Database::VAR_VARCHAR:
                return "VARCHAR({$size})";

            case Database::VAR_TEXT:
            case Database::VAR_MEDIUMTEXT:
            case Database::VAR_LONGTEXT:
                return 'TEXT';  // PostgreSQL doesn't have MEDIUMTEXT/LONGTEXT, use TEXT

            case Database::VAR_INTEGER:  // We don't support zerofill: https://stackoverflow.com/a/5634147/2299554

                if ($size >= 8) { // INT = 4 bytes, BIGINT = 8 bytes
                    return 'BIGINT';
                }

                return 'INTEGER';

            case Database::VAR_BIGINT:
                return 'BIGINT';

            case Database::VAR_FLOAT:
                return 'DOUBLE PRECISION';

            case Database::VAR_BOOLEAN:
                return 'BOOLEAN';

            case Database::VAR_RELATIONSHIP:
                return 'VARCHAR(255)';

            case Database::VAR_DATETIME:
                return 'TIMESTAMP(3)';

            case Database::VAR_OBJECT:
                return 'JSONB';

            case Database::VAR_POINT:
                return 'GEOMETRY(POINT,' . Database::DEFAULT_SRID . ')';

            case Database::VAR_LINESTRING:
                return 'GEOMETRY(LINESTRING,' . Database::DEFAULT_SRID . ')';

            case Database::VAR_POLYGON:
                return 'GEOMETRY(POLYGON,' . Database::DEFAULT_SRID . ')';

            case Database::VAR_VECTOR:
                return "VECTOR({$size})";

            default:
                throw new DatabaseException('Unknown Type: ' . $type . '. Must be one of ' . Database::VAR_STRING . ', ' . Database::VAR_VARCHAR . ', ' . Database::VAR_TEXT . ', ' . Database::VAR_MEDIUMTEXT . ', ' . Database::VAR_LONGTEXT . ', ' . Database::VAR_INTEGER . ', ' . Database::VAR_BIGINT . ', ' . Database::VAR_FLOAT . ', ' . Database::VAR_BOOLEAN . ', ' . Database::VAR_DATETIME . ', ' . Database::VAR_RELATIONSHIP . ', ' . Database::VAR_OBJECT . ', ' . Database::VAR_POINT . ', ' . Database::VAR_LINESTRING . ', ' . Database::VAR_POLYGON);
        }
    }

    /**
     * Get SQL schema
     *
     * @return string
     */
    protected function getSQLSchema(): string
    {
        if (!$this->getSupportForSchemas()) {
            return '';
        }

        return "\"{$this->getDatabase()}\".";
    }

    /**
     * Get PDO Type
     *
     * @param mixed $value
     *
     * @return int
     * @throws DatabaseException
     */
    protected function getPDOType(mixed $value): int
    {
        return match (\gettype($value)) {
            'string', 'double' => PDO::PARAM_STR,
            'boolean' => PDO::PARAM_BOOL,
            'integer' => PDO::PARAM_INT,
            'NULL' => PDO::PARAM_NULL,
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
        return 'RANDOM()';
    }

    /**
     * Size of POINT spatial type
     *
     * @return int
    */
    protected function getMaxPointSize(): int
    {
        // https://stackoverflow.com/questions/30455025/size-of-data-type-geographypoint-4326-in-postgis
        return 32;
    }


    /**
     * Encode array
     *
     * @param string $value
     *
     * @return array<string>
     */
    protected function encodeArray(string $value): array
    {
        $string = substr($value, 1, -1);
        if (empty($string)) {
            return [];
        } else {
            return explode(',', $string);
        }
    }

    /**
     * Decode array
     *
     * @param array<string> $value
     *
     * @return string
     */
    protected function decodeArray(array $value): string
    {
        if (empty($value)) {
            return '{}';
        }

        foreach ($value as $index => $item) {
            $value[$index] = '"' . str_replace(['"', '(', ')'], ['\"', '\(', '\)'], $item) . '"';
        }

        return '{' . implode(",", $value) . '}';
    }

    public function getMinDateTime(): \DateTime
    {
        return new \DateTime('-4713-01-01 00:00:00');
    }

    /**
     * Is fulltext Wildcard index supported?
     *
     * @return bool
     */
    public function getSupportForFulltextWildcardIndex(): bool
    {
        return false;
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

    /**
     * Does the adapter handle Query Array Overlaps?
     *
     * @return bool
     */
    public function getSupportForJSONOverlaps(): bool
    {
        return false;
    }

    public function getSupportForIntegerBooleans(): bool
    {
        return false; // Postgres has native boolean type
    }

    /**
     * Is get schema attributes supported?
     *
     * @return bool
     */
    public function getSupportForSchemaAttributes(): bool
    {
        return false;
    }

    public function getSupportForSchemaIndexes(): bool
    {
        return false;
    }

    public function getSupportForUpserts(): bool
    {
        return true;
    }

    public function getSupportForUpsertOnUniqueIndex(): bool
    {
        return false;
    }

    /**
     * Is vector type supported?
     *
     * @return bool
     */
    public function getSupportForVectors(): bool
    {
        return true;
    }

    public function getSupportForPCRERegex(): bool
    {
        return false;
    }

    public function getSupportForPOSIXRegex(): bool
    {
        return true;
    }

    public function getSupportForTrigramIndex(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getLikeOperator(): string
    {
        return 'ILIKE';
    }

    /**
     * @return string
     */
    public function getRegexOperator(): string
    {
        return '~';
    }

    protected function processException(PDOException $e): \Exception
    {
        // Timeout
        if ($e->getCode() === '57014' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new TimeoutException('Query timed out', $e->getCode(), $e);
        }

        // Duplicate table
        if ($e->getCode() === '42P07' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new DuplicateException('Collection already exists', $e->getCode(), $e);
        }

        // Duplicate column
        if ($e->getCode() === '42701' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new DuplicateException('Attribute already exists', $e->getCode(), $e);
        }

        // Duplicate row
        if ($e->getCode() === '23505' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            $columns = $this->getViolatedColumns($e->getMessage());
            if ($columns !== null && $columns !== ['_uid'] && $columns !== ['_tenant', '_uid']) {
                return new UniqueException('Unique index violation', $e->getCode(), $e);
            }
            return new DuplicateException('Document already exists', $e->getCode(), $e);
        }

        // Data is too big for column resize
        if ($e->getCode() === '22001' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new TruncateException('Resize would result in data truncation', $e->getCode(), $e);
        }

        // Numeric value out of range (overflow/underflow from operators)
        if ($e->getCode() === '22003' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new LimitException('Numeric value out of range', $e->getCode(), $e);
        }

        // Invalid argument for power function (e.g. 0 to a negative power, or a negative base to a
        // fractional exponent) — matches MariaDB, which reports the same as a numeric range error.
        if ($e->getCode() === '2201F' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new LimitException('Value out of range', $e->getCode(), $e);
        }

        // Datetime field overflow
        if ($e->getCode() === '22008' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new LimitException('Datetime field overflow', $e->getCode(), $e);
        }

        // Index row too large
        if ($e->getCode() === '54000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7 && \str_contains($e->getMessage(), 'index row')) {
            return new LimitException('Index row size exceeds the maximum', $e->getCode(), $e);
        }

        // Unknown table
        if ($e->getCode() === '42P01' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new NotFoundException('Collection not found', $e->getCode(), $e);
        }

        // Unknown column
        if ($e->getCode() === "42703" && isset($e->errorInfo[1]) && $e->errorInfo[1] === 7) {
            return new NotFoundException('Attribute not found', $e->getCode(), $e);
        }

        return $e;
    }

    /**
     * Extract the violated columns from a unique violation error, e.g.
     * "DETAIL:  Key (_uid, _tenant)=(movie, 1) already exists." resolves to
     * ['_tenant', '_uid']. Returns null when the message cannot be parsed.
     *
     * @return array<string>|null
     */
    protected function getViolatedColumns(string $message): ?array
    {
        if (\preg_match('/Key \(([^)]+)\)=/', $message, $matches) !== 1) {
            return null;
        }

        $columns = \array_map(
            fn (string $column) => \trim($column, " \t\"'"),
            \explode(',', $matches[1])
        );

        \sort($columns);

        return $columns;
    }

    /**
     * @param string $string
     * @return string
     */
    protected function quote(string $string): string
    {
        return "\"{$string}\"";
    }

    /**
     * Is spatial attributes supported?
     *
     * @return bool
    */
    public function getSupportForSpatialAttributes(): bool
    {
        return true;
    }

    /**
     * Are object (JSONB) attributes supported?
     *
     * @return bool
    */
    public function getSupportForObject(): bool
    {
        return true;
    }

    /**
     * Are object (JSONB) indexes supported?
     *
     * @return bool
     */
    public function getSupportForObjectIndexes(): bool
    {
        return true;
    }

    /**
     * Does the adapter support null values in spatial indexes?
     *
     * @return bool
    */
    public function getSupportForSpatialIndexNull(): bool
    {
        return true;
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
        return false;
    }

    /**
     * Does the adapter support calculating distance(in meters) between multidimension geometry(line, polygon,etc)?
     *
     * @return bool
     */
    public function getSupportForDistanceBetweenMultiDimensionGeometryInMeters(): bool
    {
        return true;
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
        return false;
    }

    protected function getInsertKeyword(): string
    {
        return 'INSERT INTO';
    }

    protected function getInsertSuffix(string $table): string
    {
        if (!$this->skipDuplicates) {
            return '';
        }

        $conflictTarget = $this->sharedTables ? '("_uid", "_tenant")' : '("_uid")';

        return "ON CONFLICT {$conflictTarget} DO NOTHING";
    }

    protected function getInsertPermissionsSuffix(): string
    {
        if (!$this->skipDuplicates) {
            return '';
        }

        $conflictTarget = $this->sharedTables
            ? '("_type", "_permission", "_document", "_tenant")'
            : '("_type", "_permission", "_document")';

        return "ON CONFLICT {$conflictTarget} DO NOTHING";
    }

    public function decodePoint(string $wkb): array
    {
        if (str_starts_with(strtoupper($wkb), 'POINT(')) {
            $start = strpos($wkb, '(') + 1;
            $end = strrpos($wkb, ')');
            $inside = substr($wkb, $start, $end - $start);

            $coords = explode(' ', trim($inside));
            return [(float)$coords[0], (float)$coords[1]];
        }

        $bin = hex2bin($wkb);
        if ($bin === false) {
            throw new DatabaseException('Invalid hex WKB string');
        }

        if (strlen($bin) < 13) { // 1 byte endian + 4 bytes type + 8 bytes for X
            throw new DatabaseException('WKB too short');
        }

        $isLE = ord($bin[0]) === 1;

        // Type (4 bytes)
        $typeBytes = substr($bin, 1, 4);
        if (strlen($typeBytes) !== 4) {
            throw new DatabaseException('Failed to extract type bytes from WKB');
        }

        $typeArr = unpack($isLE ? 'V' : 'N', $typeBytes);
        if ($typeArr === false || !isset($typeArr[1])) {
            throw new DatabaseException('Failed to unpack type from WKB');
        }
        $type = $typeArr[1];

        // Offset to coordinates (skip SRID if present)
        $offset = 5 + (($type & 0x20000000) ? 4 : 0);

        if (strlen($bin) < $offset + 16) { // 16 bytes for X,Y
            throw new DatabaseException('WKB too short for coordinates');
        }

        $fmt = $isLE ? 'e' : 'E'; // little vs big endian double

        // X coordinate
        $xArr = unpack($fmt, substr($bin, $offset, 8));
        if ($xArr === false || !isset($xArr[1])) {
            throw new DatabaseException('Failed to unpack X coordinate');
        }
        $x = (float)$xArr[1];

        // Y coordinate
        $yArr = unpack($fmt, substr($bin, $offset + 8, 8));
        if ($yArr === false || !isset($yArr[1])) {
            throw new DatabaseException('Failed to unpack Y coordinate');
        }
        $y = (float)$yArr[1];

        return [$x, $y];
    }

    public function decodeLinestring(mixed $wkb): array
    {
        if (str_starts_with(strtoupper($wkb), 'LINESTRING(')) {
            $start = strpos($wkb, '(') + 1;
            $end = strrpos($wkb, ')');
            $inside = substr($wkb, $start, $end - $start);

            $points = explode(',', $inside);
            return array_map(function ($point) {
                $coords = explode(' ', trim($point));
                return [(float)$coords[0], (float)$coords[1]];
            }, $points);
        }

        if (ctype_xdigit($wkb)) {
            $wkb = hex2bin($wkb);
            if ($wkb === false) {
                throw new DatabaseException("Failed to convert hex WKB to binary.");
            }
        }

        if (strlen($wkb) < 9) {
            throw new DatabaseException("WKB too short to be a valid geometry");
        }

        $byteOrder = ord($wkb[0]);
        if ($byteOrder === 0) {
            throw new DatabaseException("Big-endian WKB not supported");
        } elseif ($byteOrder !== 1) {
            throw new DatabaseException("Invalid byte order in WKB");
        }

        // Type + SRID flag
        $typeField = unpack('V', substr($wkb, 1, 4));
        if ($typeField === false) {
            throw new DatabaseException('Failed to unpack the type field from WKB.');
        }

        $typeField = $typeField[1];
        $geomType = $typeField & 0xFF;
        $hasSRID = ($typeField & 0x20000000) !== 0;

        if ($geomType !== 2) { // 2 = LINESTRING
            throw new DatabaseException("Not a LINESTRING geometry type, got {$geomType}");
        }

        $offset = 5;
        if ($hasSRID) {
            $offset += 4;
        }

        $numPoints = unpack('V', substr($wkb, $offset, 4));
        if ($numPoints === false) {
            throw new DatabaseException("Failed to unpack number of points at offset {$offset}.");
        }

        $numPoints = $numPoints[1];
        $offset += 4;

        $points = [];
        for ($i = 0; $i < $numPoints; $i++) {
            $x = unpack('e', substr($wkb, $offset, 8));
            if ($x === false) {
                throw new DatabaseException("Failed to unpack X coordinate at offset {$offset}.");
            }

            $x = (float) $x[1];

            $offset += 8;

            $y = unpack('e', substr($wkb, $offset, 8));
            if ($y === false) {
                throw new DatabaseException("Failed to unpack Y coordinate at offset {$offset}.");
            }

            $y = (float) $y[1];

            $offset += 8;
            $points[] = [$x, $y];
        }

        return $points;
    }

    public function decodePolygon(string $wkb): array
    {
        // POLYGON((x1,y1),(x2,y2))
        if (str_starts_with($wkb, 'POLYGON((')) {
            $start = strpos($wkb, '((') + 2;
            $end = strrpos($wkb, '))');
            $inside = substr($wkb, $start, $end - $start);

            $rings = explode('),(', $inside);
            return array_map(function ($ring) {
                $points = explode(',', $ring);
                return array_map(function ($point) {
                    $coords = explode(' ', trim($point));
                    return [(float)$coords[0], (float)$coords[1]];
                }, $points);
            }, $rings);
        }

        // Convert hex string to binary if needed
        if (preg_match('/^[0-9a-fA-F]+$/', $wkb)) {
            $wkb = hex2bin($wkb);
            if ($wkb === false) {
                throw new DatabaseException("Invalid hex WKB");
            }
        }

        if (strlen($wkb) < 9) {
            throw new DatabaseException("WKB too short");
        }

        $uInt32 = 'V'; // little-endian 32-bit unsigned
        $uDouble = 'd'; // little-endian double

        $typeInt = unpack($uInt32, substr($wkb, 1, 4));
        if ($typeInt === false) {
            throw new DatabaseException('Failed to unpack type field from WKB.');
        }

        $typeInt = (int) $typeInt[1];
        $hasSrid = ($typeInt & 0x20000000) !== 0;
        $geomType = $typeInt & 0xFF;

        if ($geomType !== 3) { // 3 = POLYGON
            throw new DatabaseException("Not a POLYGON geometry type, got {$geomType}");
        }

        $offset = 5;
        if ($hasSrid) {
            $offset += 4;
        }

        // Number of rings
        $numRings = unpack($uInt32, substr($wkb, $offset, 4));
        if ($numRings === false) {
            throw new DatabaseException('Failed to unpack number of rings from WKB.');
        }

        $numRings = (int) $numRings[1];
        $offset += 4;

        $rings = [];
        for ($r = 0; $r < $numRings; $r++) {
            $numPoints = unpack($uInt32, substr($wkb, $offset, 4));
            if ($numPoints === false) {
                throw new DatabaseException('Failed to unpack number of points from WKB.');
            }

            $numPoints = (int) $numPoints[1];
            $offset += 4;
            $points = [];
            for ($i = 0; $i < $numPoints; $i++) {
                $x = unpack($uDouble, substr($wkb, $offset, 8));
                if ($x === false) {
                    throw new DatabaseException('Failed to unpack X coordinate from WKB.');
                }

                $x = (float) $x[1];

                $y = unpack($uDouble, substr($wkb, $offset + 8, 8));
                if ($y === false) {
                    throw new DatabaseException('Failed to unpack Y coordinate from WKB.');
                }

                $y = (float) $y[1];

                $points[] = [$x, $y];
                $offset += 16;
            }
            $rings[] = $points;
        }

        return $rings; // array of rings, each ring is array of [x,y]
    }

    /**
     * Get SQL expression for operator
     *
     * @param string $column
     * @param Operator $operator
     * @param array<string, mixed> $binds
     * @param bool $useTargetPrefix
     * @return ?string
     */
    protected function getOperatorSQL(string $column, Operator $operator, array &$binds, bool $useTargetPrefix = false): ?string
    {
        $quotedColumn = $this->quote($column);
        $columnRef = $useTargetPrefix ? "target.{$quotedColumn}" : $quotedColumn;
        $method = $operator->getMethod();
        $values = $operator->getValues();

        switch ($method) {
            // Numeric operators
            case Operator::TYPE_INCREMENT:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$columnRef}, 0) + CAST(:$bindKey AS NUMERIC) > CAST(:$maxKey AS NUMERIC) THEN COALESCE({$columnRef}, 0)
                        ELSE COALESCE({$columnRef}, 0) + CAST(:$bindKey AS NUMERIC)
                    END";
                }
                return "{$quotedColumn} = COALESCE({$columnRef}, 0) + :$bindKey";

            case Operator::TYPE_DECREMENT:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $minKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$columnRef}, 0) - CAST(:$bindKey AS NUMERIC) < CAST(:$minKey AS NUMERIC) THEN COALESCE({$columnRef}, 0)
                        ELSE COALESCE({$columnRef}, 0) - CAST(:$bindKey AS NUMERIC)
                    END";
                }
                return "{$quotedColumn} = COALESCE({$columnRef}, 0) - :$bindKey";

            case Operator::TYPE_MULTIPLY:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$columnRef}, 0) * CAST(:$bindKey AS NUMERIC) > CAST(:$maxKey AS NUMERIC) THEN COALESCE({$columnRef}, 0)
                        ELSE COALESCE({$columnRef}, 0) * CAST(:$bindKey AS NUMERIC)
                    END";
                }
                return "{$quotedColumn} = COALESCE({$columnRef}, 0) * :$bindKey";

            case Operator::TYPE_DIVIDE:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                if (isset($values[1])) {
                    $minKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN CAST(:$bindKey AS NUMERIC) != 0 AND COALESCE({$columnRef}, 0) / CAST(:$bindKey AS NUMERIC) < CAST(:$minKey AS NUMERIC) THEN COALESCE({$columnRef}, 0)
                        ELSE COALESCE({$columnRef}, 0) / CAST(:$bindKey AS NUMERIC)
                    END";
                }
                return "{$quotedColumn} = COALESCE({$columnRef}, 0) / :$bindKey";

            case Operator::TYPE_MODULO:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                return "{$quotedColumn} = MOD(COALESCE({$columnRef}::numeric, 0), :$bindKey::numeric)";

            case Operator::TYPE_POWER:
                $exponent = $values[0] ?? 1;
                $bindKey = $this->registerOperatorBind($binds, $exponent);
                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    $col = "COALESCE({$columnRef}, 0)";

                    // Leave the value unchanged only for undefined inputs, then apply the power if
                    // the result stays within the max. The exponent is constant, so only the
                    // undefined guard its value can actually trigger is emitted. PostgreSQL throws
                    // a hard error for 0 to a negative power and a negative base to a fractional
                    // exponent, so those must never reach POWER().
                    $oddInteger = \floor($exponent) == $exponent && ((int) $exponent) % 2 !== 0;

                    $whens = [];
                    if ($exponent < 0) {
                        $whens[] = "WHEN {$col} = 0 THEN {$col}";
                    }
                    if (\floor($exponent) != $exponent) {
                        $whens[] = "WHEN {$col} < 0 THEN {$col}";
                    }
                    // Cap by magnitude via logarithms so POWER() never runs on a value that would
                    // overflow (base^exp > max  <=>  exp * LN(base) > LN(max)).
                    if ($exponent == 0) {
                        // Every base to the zeroth power is 1 (including 0^0), which the magnitude
                        // check below can't see for a base of 0. The result 1 exceeds the max when
                        // max < 1, i.e. LN(max) < 0 (LN also coerces the bound value numerically).
                        $whens[] = "WHEN LN(:$maxKey) < 0 THEN {$col}";
                    } elseif ($oddInteger) {
                        // An odd exponent keeps a negative base negative, and a negative result is
                        // always within a positive max, so only cap positive bases; negative bases
                        // fall through to POWER() and their (negative) result is applied.
                        $whens[] = "WHEN {$col} > 0 AND :$bindKey * LN({$col}) > LN(:$maxKey) THEN {$col}";
                    } else {
                        // Otherwise the result is non-negative, so its magnitude equals its value —
                        // cap either sign. ABS() keeps LN() defined for a negative even-power base.
                        $whens[] = "WHEN {$col} <> 0 AND :$bindKey * LN(ABS({$col})) > LN(:$maxKey) THEN {$col}";
                    }

                    $whenSql = \implode(' ', $whens);
                    return "{$quotedColumn} = CASE {$whenSql} ELSE POWER({$col}, :$bindKey) END";
                }
                return "{$quotedColumn} = POWER(COALESCE({$columnRef}, 0), :$bindKey)";

                // String operators
            case Operator::TYPE_STRING_CONCAT:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? '');
                return "{$quotedColumn} = CONCAT(COALESCE({$columnRef}, ''), :$bindKey)";

            case Operator::TYPE_STRING_REPLACE:
                $searchKey = $this->registerOperatorBind($binds, $values[0] ?? '');
                $replaceKey = $this->registerOperatorBind($binds, $values[1] ?? '');
                return "{$quotedColumn} = REPLACE(COALESCE({$columnRef}, ''), :$searchKey, :$replaceKey)";

                // Boolean operators
            case Operator::TYPE_TOGGLE:
                return "{$quotedColumn} = NOT COALESCE({$columnRef}, FALSE)";

                // Array operators
            case Operator::TYPE_ARRAY_APPEND:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = COALESCE({$columnRef}, '[]'::jsonb) || :$bindKey::jsonb";

            case Operator::TYPE_ARRAY_PREPEND:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = :$bindKey::jsonb || COALESCE({$columnRef}, '[]'::jsonb)";

            case Operator::TYPE_ARRAY_UNIQUE:
                return "{$quotedColumn} = COALESCE((
                    SELECT jsonb_agg(DISTINCT value)
                    FROM jsonb_array_elements({$columnRef}) AS value
                ), '[]'::jsonb)";

            case Operator::TYPE_ARRAY_REMOVE:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values[0] ?? null));
                return "{$quotedColumn} = COALESCE((
                    SELECT jsonb_agg(value)
                    FROM jsonb_array_elements({$columnRef}) AS value
                    WHERE value != :$bindKey::jsonb
                ), '[]'::jsonb)";

            case Operator::TYPE_ARRAY_INSERT:
                $indexKey = $this->registerOperatorBind($binds, $values[0] ?? 0);
                $valueKey = $this->registerOperatorBind($binds, json_encode($values[1] ?? null));
                return "{$quotedColumn} = (
                    SELECT jsonb_agg(value ORDER BY idx)
                    FROM (
                        SELECT value, idx
                        FROM jsonb_array_elements({$columnRef}) WITH ORDINALITY AS t(value, idx)
                        WHERE idx - 1 < :$indexKey
                        UNION ALL
                        SELECT :$valueKey::jsonb AS value, :$indexKey + 1 AS idx
                        UNION ALL
                        SELECT value, idx + 1
                        FROM jsonb_array_elements({$columnRef}) WITH ORDINALITY AS t(value, idx)
                        WHERE idx - 1 >= :$indexKey
                    ) AS combined
                )";

            case Operator::TYPE_ARRAY_INTERSECT:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = COALESCE((
                    SELECT jsonb_agg(value)
                    FROM jsonb_array_elements({$columnRef}) AS value
                    WHERE value IN (SELECT jsonb_array_elements(:$bindKey::jsonb))
                ), '[]'::jsonb)";

            case Operator::TYPE_ARRAY_DIFF:
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                return "{$quotedColumn} = COALESCE((
                    SELECT jsonb_agg(value)
                    FROM jsonb_array_elements({$columnRef}) AS value
                    WHERE value NOT IN (SELECT jsonb_array_elements(:$bindKey::jsonb))
                ), '[]'::jsonb)";

            case Operator::TYPE_ARRAY_FILTER:
                $condition = $values[0] ?? 'equal';
                $filterValue = $values[1] ?? null;
                $conditionKey = $this->registerOperatorBind($binds, $condition);
                $valueKey = $this->registerOperatorBind($binds, $filterValue === null ? null : json_encode($filterValue));
                return "{$quotedColumn} = COALESCE((
                    SELECT jsonb_agg(value)
                    FROM jsonb_array_elements({$columnRef}) AS value
                    WHERE CASE :$conditionKey
                        WHEN 'equal' THEN value = :$valueKey::jsonb
                        WHEN 'notEqual' THEN value != :$valueKey::jsonb
                        WHEN 'greaterThan' THEN (value::text)::numeric > trim(both '\"' from :$valueKey::text)::numeric
                        WHEN 'greaterThanEqual' THEN (value::text)::numeric >= trim(both '\"' from :$valueKey::text)::numeric
                        WHEN 'lessThan' THEN (value::text)::numeric < trim(both '\"' from :$valueKey::text)::numeric
                        WHEN 'lessThanEqual' THEN (value::text)::numeric <= trim(both '\"' from :$valueKey::text)::numeric
                        WHEN 'isNull' THEN value = 'null'::jsonb
                        WHEN 'isNotNull' THEN value != 'null'::jsonb
                        ELSE TRUE
                    END
                ), '[]'::jsonb)";

                // Date operators
            case Operator::TYPE_DATE_ADD_DAYS:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 0);
                return "{$quotedColumn} = {$columnRef} + (:$bindKey || ' days')::INTERVAL";

            case Operator::TYPE_DATE_SUB_DAYS:
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 0);
                return "{$quotedColumn} = {$columnRef} - (:$bindKey || ' days')::INTERVAL";

            case Operator::TYPE_DATE_SET_NOW:
                return "{$quotedColumn} = NOW()";

            default:
                throw new OperatorException("Invalid operator: {$method}");
        }
    }

    public function getSupportNonUtfCharacters(): bool
    {
        return false;
    }

    /**
     * Ensure index key length stays within PostgreSQL's 63 character limit.
     *
     * @param string $key
     * @return string
     */
    protected function getShortKey(string $key): string
    {
        if (\strlen($key) <= self::MAX_IDENTIFIER_NAME) {
            return $key;
        }

        $suffix = '';
        $separatorPosition = strrpos($key, '_');
        if ($separatorPosition !== false) {
            $suffix = substr($key, $separatorPosition + 1);
        }

        $hash = md5($key);

        if ($suffix !== '') {
            $hashedKey = "{$hash}_{$suffix}";
            if (\strlen($hashedKey) <= self::MAX_IDENTIFIER_NAME) {
                return $hashedKey;
            }
        }

        return substr($hash, 0, self::MAX_IDENTIFIER_NAME);
    }

    protected function getSQLTable(string $name): string
    {
        $table = "{$this->getNamespace()}_{$this->filter($name)}";
        $table = $this->getShortKey($table);

        return "{$this->quote($this->getDatabase())}.{$this->quote($table)}";
    }

    public function getSupportForTTLIndexes(): bool
    {
        return false;
    }
    protected function buildJsonbPath(string $path, bool $asText = false): string
    {
        $parts = \explode('.', $path);

        foreach ($parts as $part) {
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $part)) {
                throw new DatabaseException('Invalid JSON key ' . $part);
            }
        }
        if (\count($parts) === 1) {
            $column = $this->filter($parts[0]);
            return $this->quote($column);
        }

        $baseColumn = $this->quote($this->filter(\array_shift($parts)));
        $lastKey = \array_pop($parts);

        $chain = $baseColumn;
        foreach ($parts as $key) {
            $chain .= "->'{$key}'";
        }

        $result = "{$chain}->>'{$lastKey}'";

        return $asText ? "(({$result})::text)" : $result;
    }
}
