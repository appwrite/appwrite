<?php

namespace Utopia\Database\Adapter;

use Exception;
use PDOException;
use Utopia\Database\Adapter;
use Utopia\Database\Change;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Operator;
use Utopia\Database\Query;

abstract class SQL extends Adapter
{
    protected const VECTOR_DISTANCE_COLUMN = '_distance';

    protected mixed $pdo;

    /**
     * Controls how many fractional digits are used when binding float parameters.
     */
    protected int $floatPrecision = 17;

    /**
     * Configure float precision for parameter binding/logging.
     */
    public function setFloatPrecision(int $precision): void
    {
        $this->floatPrecision = $precision;
    }

    /**
     * Helper to format a float value according to configured precision for binding/logging.
     */
    protected function getFloatPrecision(float $value): string
    {
        return sprintf('%.'. $this->floatPrecision . 'F', $value);
    }

    /**
     * Build conditions threading `$name` to per-query builders so adapter
     * overrides (SQLite FTS5 routing) can resolve auxiliary tables.
     *
     * @param array<Query> $queries
     * @param array<string,mixed> $binds
     */
    protected function getSQLConditionsForCollection(string $name, array $queries, array &$binds, string $separator = 'AND'): string
    {
        return $this->getSQLConditions($queries, $binds, $separator, $name);
    }

    /**
     * Constructor.
     *
     * Set connection and settings
     *
     * @param mixed $pdo
     */
    public function __construct(mixed $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @inheritDoc
     */
    public function startTransaction(): bool
    {
        try {
            if ($this->inTransaction === 0) {
                try {
                    if ($this->getPDO()->inTransaction()) {
                        $this->getPDO()->rollBack();
                    } else {
                        // If no active transaction, this has no effect.
                        $this->getPDO()->prepare('ROLLBACK')->execute();
                    }
                } catch (PDOException) {
                    // A pooled connection can report a transaction it no longer
                    // holds after a reconnect (e.g. Swoole PDOProxy keeps its own
                    // counter), making this cleanup rollback throw. It is best
                    // effort; swallow it and begin a fresh transaction below.
                }

                $this->getPDO()->beginTransaction();

            } else {
                $this->getPDO()->exec('SAVEPOINT transaction' . $this->inTransaction);
            }
        } catch (PDOException $e) {
            throw new TransactionException('Failed to start transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $this->inTransaction++;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function commitTransaction(): bool
    {
        if ($this->inTransaction === 0) {
            return false;
        }

        if (!$this->getPDO()->inTransaction()) {
            $this->inTransaction = 0;
            return false;
        }

        if ($this->inTransaction > 1) {
            $this->inTransaction--;
            return true;
        }

        try {
            $result = $this->getPDO()->commit();
            $this->inTransaction = 0;
        } catch (PDOException $e) {
            throw new TransactionException('Failed to commit transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }

        if (!$result) {
            throw new TransactionException('Failed to commit transaction');
        }

        return $result;
    }

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
            } else {
                $this->getPDO()->rollBack();
                $this->inTransaction = 0;
            }
        } catch (PDOException $e) {
            $this->inTransaction = 0;
            throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return true;
    }

    /**
     * Ping Database
     *
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function ping(): bool
    {
        return $this->getPDO()
            ->prepare("SELECT 1;")
            ->execute();
    }

    public function reconnect(): void
    {
        $this->getPDO()->reconnect();
        $this->inTransaction = 0;
    }

    /**
     * Check if Database exists
     * Optionally check if collection exists in Database
     *
     * @param string $database
     * @param string|null $collection
     * @return bool
     * @throws DatabaseException
     */
    public function exists(string $database, ?string $collection = null): bool
    {
        $database = $this->filter($database);

        if (!\is_null($collection)) {
            $collection = $this->filter($collection);
            $stmt = $this->getPDO()->prepare("
                SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = :schema 
                  AND TABLE_NAME = :table
            ");
            $stmt->bindValue(':schema', $database, \PDO::PARAM_STR);
            $stmt->bindValue(':table', "{$this->getNamespace()}_{$collection}", \PDO::PARAM_STR);
        } else {
            $stmt = $this->getPDO()->prepare("
                SELECT SCHEMA_NAME FROM
                INFORMATION_SCHEMA.SCHEMATA
                WHERE SCHEMA_NAME = :schema
            ");
            $stmt->bindValue(':schema', $database, \PDO::PARAM_STR);
        }

        try {
            $stmt->execute();
            $document = $stmt->fetchAll();
            $stmt->closeCursor();
        } catch (PDOException $e) {
            $e = $this->processException($e);

            if ($e instanceof NotFoundException) {
                return false;
            }

            throw $e;
        }

        if (empty($document)) {
            return false;
        }

        return true;
    }

    /**
     * List Databases
     *
     * @return array<Document>
     */
    public function list(): array
    {
        return [];
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
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function createAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): bool
    {
        $id = $this->quote($this->filter($id));
        $type = $this->getSQLType($type, $size, $signed, $array, $required);
        $sql = "ALTER TABLE {$this->getSQLTable($collection)} ADD COLUMN {$id} {$type} {$this->getLockType()};";
        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_CREATE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Create Attributes
     *
     * @param string $collection
     * @param array<array<string, mixed>> $attributes
     * @return bool
     * @throws DatabaseException
     */
    public function createAttributes(string $collection, array $attributes): bool
    {
        $parts = [];
        foreach ($attributes as $attribute) {
            $id = $this->quote($this->filter($attribute['$id']));
            $type = $this->getSQLType(
                $attribute['type'],
                $attribute['size'],
                $attribute['signed'] ?? true,
                $attribute['array'] ?? false,
                $attribute['required'] ?? false,
            );
            $parts[] = "{$id} {$type}";
        }

        $columns = \implode(', ADD COLUMN ', $parts);

        $sql = "ALTER TABLE {$this->getSQLTable($collection)} ADD COLUMN {$columns} {$this->getLockType()};";
        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_CREATE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
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
        $old = $this->quote($this->filter($old));
        $new = $this->quote($this->filter($new));

        $sql = "ALTER TABLE {$this->getSQLTable($collection)} RENAME COLUMN {$old} TO {$new};";

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
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     * @param bool $array
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteAttribute(string $collection, string $id, bool $array = false): bool
    {
        $id = $this->quote($this->filter($id));
        $sql = "ALTER TABLE {$this->getSQLTable($collection)} DROP COLUMN {$id};";
        $sql = $this->trigger(Database::EVENT_ATTRIBUTE_DELETE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Get Document
     *
     * @param Document $collection
     * @param string $id
     * @param Query[] $queries
     * @param bool $forUpdate
     * @return Document
     * @throws DatabaseException
     */
    public function getDocument(Document $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        $collection = $collection->getId();

        $name = $this->filter($collection);
        $selections = $this->getAttributeSelections($queries);

        $forUpdate = $forUpdate ? 'FOR UPDATE' : '';

        $alias = Query::DEFAULT_ALIAS;

        $sql = "
		    SELECT {$this->getAttributeProjection($selections, $alias)}
            FROM {$this->getSQLTable($name)} AS {$this->quote($alias)}
            WHERE {$this->quote($alias)}.{$this->quote('_uid')} = :_uid 
            {$this->getTenantQuery($collection, $alias)}
		";

        if ($this->getSupportForUpdateLock()) {
            $sql .= " {$forUpdate}";
        }

        $sql = $this->trigger(Database::EVENT_DOCUMENT_READ, $sql);

        $stmt = null;
        $document = [];
        $exception = null;

        try {
            $stmt = $this->getPDO()->prepare($sql);

            $stmt->bindValue(':_uid', $id);

            if ($this->sharedTables) {
                $stmt->bindValue(':_tenant', $this->getTenant());
            }

            $this->execute($stmt);
            $document = $stmt->fetchAll();
        } catch (PDOException $e) {
            $exception = $e;
        } finally {
            if ($stmt !== null) {
                try {
                    $stmt->closeCursor();
                } catch (PDOException $e) {
                    $exception ??= $e;
                }
            }
        }

        if ($exception !== null) {
            throw $this->processException($exception);
        }

        if (empty($document)) {
            return new Document([]);
        }

        $document = $document[0];

        if (\array_key_exists('_id', $document)) {
            $document['$sequence'] = $document['_id'];
            unset($document['_id']);
        }
        if (\array_key_exists('_uid', $document)) {
            $document['$id'] = $document['_uid'];
            unset($document['_uid']);
        }
        if (\array_key_exists('_tenant', $document)) {
            $document['$tenant'] = $document['_tenant'];
            unset($document['_tenant']);
        }
        if (\array_key_exists('_createdAt', $document)) {
            $document['$createdAt'] = $document['_createdAt'];
            unset($document['_createdAt']);
        }
        if (\array_key_exists('_updatedAt', $document)) {
            $document['$updatedAt'] = $document['_updatedAt'];
            unset($document['_updatedAt']);
        }
        if (\array_key_exists('_deletedAt', $document)) {
            $document['$deletedAt'] = $document['_deletedAt'];
            unset($document['_deletedAt']);
        }
        if (\array_key_exists('_permissions', $document)) {
            $document['$permissions'] = json_decode($document['_permissions'] ?? '[]', true);
            unset($document['_permissions']);
        }

        return new Document($document);
    }

    /**
     * Helper method to extract spatial type attributes from collection attributes
     *
     * @param Document $collection
     * @return array<int,string>
     */
    protected function getSpatialAttributes(Document $collection): array
    {
        $collectionAttributes = $collection->getAttribute('attributes', []);
        $spatialAttributes = [];
        foreach ($collectionAttributes as $attr) {
            if ($attr instanceof Document) {
                $attributeType = $attr->getAttribute('type');
                if (in_array($attributeType, Database::SPATIAL_TYPES)) {
                    $spatialAttributes[] = $attr->getId();
                }
            }
        }
        return $spatialAttributes;
    }

    /**
     * Update documents
     *
     * Updates all documents which match the given query.
     *
     * @param Document $collection
     * @param Document $updates
     * @param array<Document> $documents
     *
     * @return int
     *
     * @throws DatabaseException
     */
    public function updateDocuments(Document $collection, Document $updates, array $documents): int
    {
        if (empty($documents)) {
            return 0;
        }
        $spatialAttributes = $this->getSpatialAttributes($collection);
        $collection = $collection->getId();

        $attributes = $updates->getAttributes();

        if (!empty($updates->getUpdatedAt())) {
            $attributes['_updatedAt'] = $updates->getUpdatedAt();
        }

        if (!empty($updates->getCreatedAt())) {
            $attributes['_createdAt'] = $updates->getCreatedAt();
        }

        if ($updates->offsetExists('$permissions')) {
            $attributes['_permissions'] = json_encode($updates->getPermissions());
        }

        if (empty($attributes)) {
            return 0;
        }

        $keyIndex = 0;
        $operatorBinds = [];
        $columns = '';

        foreach ($attributes as $attribute => $value) {
            $column = $this->filter($attribute);

            // Check if this is an operator, spatial attribute, or regular attribute
            if (Operator::isOperator($value)) {
                $columns .= $this->getOperatorSQL($column, $value, $operatorBinds);
            } elseif (\in_array($attribute, $spatialAttributes)) {
                $columns .= "{$this->quote($column)} = " . $this->getSpatialGeomFromText(":key_{$keyIndex}");
                $keyIndex++;
            } else {
                $columns .= "{$this->quote($column)} = :key_{$keyIndex}";
                $keyIndex++;
            }

            if ($attribute !== \array_key_last($attributes)) {
                $columns .= ',';
            }
        }

        // Remove trailing comma if present
        $columns = \rtrim($columns, ',');

        if (empty($columns)) {
            return 0;
        }

        $name = $this->filter($collection);
        $sequences = \array_map(fn ($document) => $document->getSequence(), $documents);

        $sql = "
            UPDATE {$this->getSQLTable($name)}
            SET {$columns}
            WHERE _id IN (" . \implode(', ', \array_map(fn ($index) => ":_id_{$index}", \array_keys($sequences))) . ")
            {$this->getTenantQuery($collection)}
        ";

        $sql = $this->trigger(Database::EVENT_DOCUMENTS_UPDATE, $sql);
        $stmt = $this->getPDO()->prepare($sql);

        if ($this->sharedTables) {
            $stmt->bindValue(':_tenant', $this->tenant);
        }

        foreach ($sequences as $id => $value) {
            $stmt->bindValue(":_id_{$id}", $value);
        }

        $keyIndex = 0;
        foreach ($attributes as $attributeName => $value) {
            // Skip operators as they don't need value binding
            if (Operator::isOperator($value)) {
                continue;
            }

            // Convert spatial arrays to WKT, json_encode non-spatial arrays
            if (\in_array($attributeName, $spatialAttributes, true)) {
                if (\is_array($value)) {
                    $value = $this->convertArrayToWKT($value);
                }
            } elseif (\is_array($value)) {
                $value = \json_encode($value);
            }

            $bindKey = 'key_' . $keyIndex;
            if ($this->getSupportForIntegerBooleans()) {
                $value = (\is_bool($value)) ? (int)$value : $value;
            }
            $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
            $keyIndex++;
        }

        foreach ($operatorBinds as $bindKey => $bindValue) {
            $stmt->bindValue($bindKey, $bindValue, $this->getPDOType($bindValue));
        }

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        $affected = $stmt->rowCount();

        // Permissions logic
        if ($updates->offsetExists('$permissions')) {
            $removeQueries = [];
            $removeBindValues = [];

            $addQuery = '';
            $addBindValues = [];

            foreach ($documents as $index => $document) {
                if ($document->getAttribute('$skipPermissionsUpdate', false)) {
                    continue;
                }

                $sql = "
                    SELECT _type, _permission
                    FROM {$this->getSQLTable($name . '_perms')}
                    WHERE _document = :_uid
                    {$this->getTenantQuery($collection)}
                ";

                $sql = $this->trigger(Database::EVENT_PERMISSIONS_READ, $sql);

                $permissionsStmt = $this->getPDO()->prepare($sql);
                $permissionsStmt->bindValue(':_uid', $document->getId());

                if ($this->sharedTables) {
                    $permissionsStmt->bindValue(':_tenant', $this->tenant);
                }

                $permissionsStmt->execute();
                $permissions = $permissionsStmt->fetchAll();
                $permissionsStmt->closeCursor();

                $initial = [];
                foreach (Database::PERMISSIONS as $type) {
                    $initial[$type] = [];
                }

                $permissions = \array_reduce($permissions, function (array $carry, array $item) {
                    $carry[$item['_type']][] = $item['_permission'];
                    return $carry;
                }, $initial);

                // Get removed Permissions
                $removals = [];
                foreach (Database::PERMISSIONS as $type) {
                    $diff = array_diff($permissions[$type], $updates->getPermissionsByType($type));
                    if (!empty($diff)) {
                        $removals[$type] = $diff;
                    }
                }

                // Build inner query to remove permissions
                if (!empty($removals)) {
                    foreach ($removals as $type => $permissionsToRemove) {
                        $bindKey = '_uid_' . $index;
                        $removeBindKeys[] = ':_uid_' . $index;
                        $removeBindValues[$bindKey] = $document->getId();

                        $removeQueries[] = "(
                            _document = :_uid_{$index}
                            {$this->getTenantQuery($collection)}
                            AND _type = '{$type}'
                            AND _permission IN (" . \implode(', ', \array_map(function (string $i) use ($permissionsToRemove, $index, $type, &$removeBindKeys, &$removeBindValues) {
                            $bindKey = 'remove_' . $type . '_' . $index . '_' . $i;
                            $removeBindKeys[] = ':' . $bindKey;
                            $removeBindValues[$bindKey] = $permissionsToRemove[$i];

                            return ':' . $bindKey;
                        }, \array_keys($permissionsToRemove))) .
                            ")
                        )";
                    }
                }

                // Get added Permissions
                $additions = [];
                foreach (Database::PERMISSIONS as $type) {
                    $diff = \array_diff($updates->getPermissionsByType($type), $permissions[$type]);
                    if (!empty($diff)) {
                        $additions[$type] = $diff;
                    }
                }

                // Build inner query to add permissions
                if (!empty($additions)) {
                    foreach ($additions as $type => $permissionsToAdd) {
                        foreach ($permissionsToAdd as $i => $permission) {
                            $bindKey = '_uid_' . $index;
                            $addBindValues[$bindKey] = $document->getId();

                            $bindKey = 'add_' . $type . '_' . $index . '_' . $i;
                            $addBindValues[$bindKey] = $permission;

                            $addQuery .= "(:_uid_{$index}, '{$type}', :{$bindKey}";

                            if ($this->sharedTables) {
                                $addQuery .= ", :_tenant)";
                            } else {
                                $addQuery .= ")";
                            }

                            if ($i !== \array_key_last($permissionsToAdd) || $type !== \array_key_last($additions)) {
                                $addQuery .= ', ';
                            }
                        }
                    }
                    if ($index !== \array_key_last($documents)) {
                        $addQuery .= ', ';
                    }
                }
            }

            if (!empty($removeQueries)) {
                $removeQuery = \implode(' OR ', $removeQueries);

                $stmtRemovePermissions = $this->getPDO()->prepare("
                    DELETE
                    FROM {$this->getSQLTable($name . '_perms')}
                    WHERE ({$removeQuery})
                ");

                foreach ($removeBindValues as $key => $value) {
                    $stmtRemovePermissions->bindValue($key, $value, $this->getPDOType($value));
                }

                if ($this->sharedTables) {
                    $stmtRemovePermissions->bindValue(':_tenant', $this->tenant);
                }
                $stmtRemovePermissions->execute();
            }

            if (!empty($addQuery)) {
                $sqlAddPermissions = "
                    INSERT INTO {$this->getSQLTable($name . '_perms')} (_document, _type, _permission
                ";

                if ($this->sharedTables) {
                    $sqlAddPermissions .= ', _tenant)';
                } else {
                    $sqlAddPermissions .= ')';
                }

                $sqlAddPermissions .=  " VALUES {$addQuery}";

                $stmtAddPermissions = $this->getPDO()->prepare($sqlAddPermissions);

                foreach ($addBindValues as $key => $value) {
                    $stmtAddPermissions->bindValue($key, $value, $this->getPDOType($value));
                }

                if ($this->sharedTables) {
                    $stmtAddPermissions->bindValue(':_tenant', $this->tenant);
                }

                $stmtAddPermissions->execute();
            }
        }

        return $affected;
    }


    /**
     * Delete Documents
     *
     * @param string $collection
     * @param array<string> $sequences
     * @param array<string> $permissionIds
     *
     * @return int
     * @throws DatabaseException
     */
    public function deleteDocuments(string $collection, array $sequences, array $permissionIds): int
    {
        if (empty($sequences)) {
            return 0;
        }

        try {
            $name = $this->filter($collection);

            $sql = "
            DELETE FROM {$this->getSQLTable($name)} 
            WHERE _id IN (" . \implode(', ', \array_map(fn ($index) => ":_id_{$index}", \array_keys($sequences))) . ")
            {$this->getTenantQuery($collection)}
            ";

            $sql = $this->trigger(Database::EVENT_DOCUMENTS_DELETE, $sql);

            $stmt = $this->getPDO()->prepare($sql);

            foreach ($sequences as $id => $value) {
                $stmt->bindValue(":_id_{$id}", $value);
            }

            if ($this->sharedTables) {
                $stmt->bindValue(':_tenant', $this->tenant);
            }

            if (!$stmt->execute()) {
                throw new DatabaseException('Failed to delete documents');
            }

            if (!empty($permissionIds)) {
                $sql = "
                DELETE FROM {$this->getSQLTable($name . '_perms')} 
                WHERE _document IN (" . \implode(', ', \array_map(fn ($index) => ":_id_{$index}", \array_keys($permissionIds))) . ")
                {$this->getTenantQuery($collection)}
                ";

                $sql = $this->trigger(Database::EVENT_PERMISSIONS_DELETE, $sql);

                $stmtPermissions = $this->getPDO()->prepare($sql);

                foreach ($permissionIds as $id => $value) {
                    $stmtPermissions->bindValue(":_id_{$id}", $value);
                }

                if ($this->sharedTables) {
                    $stmtPermissions->bindValue(':_tenant', $this->tenant);
                }

                if (!$stmtPermissions->execute()) {
                    throw new DatabaseException('Failed to delete permissions');
                }
            }
        } catch (\Throwable $e) {
            throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
        }

        return $stmt->rowCount();
    }

    /**
     * Assign internal IDs for the given documents
     *
     * @param string $collection
     * @param array<Document> $documents
     * @return array<Document>
     * @throws DatabaseException
     */
    public function getSequences(string $collection, array $documents): array
    {
        $documentIds = [];
        $keys = [];
        $binds = [];
        $tenants = [];

        foreach ($documents as $i => $document) {
            if (empty($document->getSequence())) {
                $documentIds[] = $document->getId();

                $key = ":uid_{$i}";

                $binds[$key] = $document->getId();
                $keys[] = $key;

                if ($this->sharedTables) {
                    $tenant = $document->getTenant();

                    // One placeholder per distinct tenant
                    if (!\in_array($tenant, $tenants, true)) {
                        $binds[':_tenant_'.\count($tenants)] = $tenant;
                        $tenants[] = $tenant;
                    }
                }
            }
        }

        if (empty($documentIds)) {
            return $documents;
        }

        $placeholders = implode(',', array_values($keys));

        $sql = "
            SELECT _uid, _id
            FROM {$this->getSQLTable($collection)}
            WHERE {$this->quote('_uid')} IN ({$placeholders})
            {$this->getTenantQuery($collection, tenantCount: \count($tenants))}
            ";

        $stmt = $this->getPDO()->prepare($sql);

        foreach ($binds as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        $sequences = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR); // Fetch as [documentId => sequence]
        $stmt->closeCursor();

        foreach ($documents as $document) {
            if (isset($sequences[$document->getId()])) {
                $document['$sequence'] = $sequences[$document->getId()];
            }
        }

        return $documents;
    }

    /**
     * Get max STRING limit
     *
     * @return int
     */
    public function getLimitForString(): int
    {
        return 4294967295;
    }

    /**
     * Get max INT limit
     *
     * @return int
     */
    public function getLimitForInt(): int
    {
        return 4294967295;
    }

    /**
     * Get max BIGINT limit
     *
     * @return int
     */
    public function getLimitForBigInt(): int
    {
        return Database::MAX_BIG_INT;
    }

    /**
     * Get maximum column limit.
     * https://mariadb.com/kb/en/innodb-limitations/#limitations-on-schema
     * Can be inherited by MySQL since we utilize the InnoDB engine
     *
     * @return int
     */
    public function getLimitForAttributes(): int
    {
        return 1017;
    }

    /**
     * Get maximum index limit.
     * https://mariadb.com/kb/en/innodb-limitations/#limitations-on-schema
     *
     * @return int
     */
    public function getLimitForIndexes(): int
    {
        return 64;
    }

    /**
     * Is schemas supported?
     *
     * @return bool
     */
    public function getSupportForSchemas(): bool
    {
        return true;
    }

    /**
     * Is index supported?
     *
     * @return bool
     */
    public function getSupportForIndex(): bool
    {
        return true;
    }

    /**
     * Are attributes supported?
     *
     * @return bool
     */
    public function getSupportForAttributes(): bool
    {
        return true;
    }

    /**
     * Is unique index supported?
     *
     * @return bool
     */
    public function getSupportForUniqueIndex(): bool
    {
        return true;
    }

    /**
     * Is fulltext index supported?
     *
     * @return bool
     */
    public function getSupportForFulltextIndex(): bool
    {
        return true;
    }

    /**
     * Are FOR UPDATE locks supported?
     *
     * @return bool
     */
    public function getSupportForUpdateLock(): bool
    {
        return true;
    }

    /**
     * Is Attribute Resizing Supported?
     *
     * @return bool
     */
    public function getSupportForAttributeResizing(): bool
    {
        return true;
    }

    /**
     * Are batch operations supported?
     *
     * @return bool
     */
    public function getSupportForBatchOperations(): bool
    {
        return true;
    }

    /**
     * Is get connection id supported?
     *
     * @return bool
     */
    public function getSupportForGetConnectionId(): bool
    {
        return true;
    }

    /**
     * Is cache fallback supported?
     *
     * @return bool
     */
    public function getSupportForCacheSkipOnFailure(): bool
    {
        return true;
    }

    public function getSupportForCaching(): bool
    {
        return true;
    }

    /**
     * Is hostname supported?
     *
     * @return bool
     */
    public function getSupportForHostname(): bool
    {
        return true;
    }

    /**
     * Returns the INSERT keyword, optionally with IGNORE for duplicate handling.
     * Override in adapter subclasses for DB-specific syntax.
     */
    protected function getInsertKeyword(): string
    {
        return $this->skipDuplicates ? 'INSERT IGNORE INTO' : 'INSERT INTO';
    }

    /**
     * Returns a suffix appended after VALUES clause for duplicate handling.
     * Override in adapter subclasses (e.g., Postgres uses ON CONFLICT DO NOTHING).
     */
    protected function getInsertSuffix(string $table): string
    {
        return '';
    }

    /**
     * Returns a suffix for the permissions INSERT statement when ignoring duplicates.
     * Override in adapter subclasses for DB-specific syntax.
     */
    protected function getInsertPermissionsSuffix(): string
    {
        return '';
    }

    /**
     * Get current attribute count from collection document
     *
     * @param Document $collection
     * @return int
     */
    public function getCountOfAttributes(Document $collection): int
    {
        $attributes = \count($collection->getAttribute('attributes') ?? []);

        return $attributes + $this->getCountOfDefaultAttributes();
    }

    /**
     * Get current index count from collection document
     *
     * @param Document $collection
     * @return int
     */
    public function getCountOfIndexes(Document $collection): int
    {
        $indexes = \count($collection->getAttribute('indexes') ?? []);
        return $indexes + $this->getCountOfDefaultIndexes();
    }

    /**
     * Returns number of attributes used by default.
     *
     * @return int
     */
    public function getCountOfDefaultAttributes(): int
    {
        return \count(Database::INTERNAL_ATTRIBUTES);
    }

    /**
     * Returns number of indexes used by default.
     *
     * @return int
     */
    public function getCountOfDefaultIndexes(): int
    {
        return \count(Database::INTERNAL_INDEXES);
    }

    /**
     * Get maximum width, in bytes, allowed for a SQL row
     * Return 0 when no restrictions apply
     *
     * @return int
     */
    public function getDocumentSizeLimit(): int
    {
        return 65535;
    }

    /**
     * Estimate maximum number of bytes required to store a document in $collection.
     * Byte requirement varies based on column type and size.
     * Needed to satisfy MariaDB/MySQL row width limit.
     *
     * @param Document $collection
     * @return int
     * @throws DatabaseException
     */
    public function getAttributeWidth(Document $collection): int
    {
        /**
         * @link https://dev.mysql.com/doc/refman/8.0/en/storage-requirements.html
         *
         * `_id` bigint => 8 bytes
         * `_uid` varchar(255) => 1021 (4 * 255 + 1) bytes
         * `_tenant` int => 4 bytes
         * `_createdAt` datetime(3) => 7 bytes
         * `_updatedAt` datetime(3) => 7 bytes
         * `_permissions` mediumtext => 20
         */

        $total = 1067;

        $attributes = $collection->getAttributes()['attributes'] ?? [];

        foreach ($attributes as $attribute) {
            /**
             * Json / Longtext
             * only the pointer contributes 20 bytes
             * data is stored externally
             */

            if ($attribute['array'] ?? false) {
                $total += 20;
                continue;
            }

            switch ($attribute['type']) {
                case Database::VAR_ID:
                    $total += 8; //  BIGINT 8 bytes
                    break;

                case Database::VAR_STRING:
                    /**
                     * Text / Mediumtext / Longtext
                     * only the pointer contributes 20 bytes to the row size
                     * data is stored externally
                     */

                    $total += match (true) {
                        $attribute['size'] > $this->getMaxVarcharLength() => 20,
                        $attribute['size'] > 255 => $attribute['size'] * 4 + 2, //  VARCHAR(>255) + 2 length
                        default => $attribute['size'] * 4 + 1, //  VARCHAR(<=255) + 1 length
                    };

                    break;

                case Database::VAR_VARCHAR:
                    $total += match (true) {
                        $attribute['size'] > 255 => $attribute['size'] * 4 + 2, //  VARCHAR(>255) + 2 length
                        default => $attribute['size'] * 4 + 1, //  VARCHAR(<=255) + 1 length
                    };
                    break;

                case Database::VAR_TEXT:
                case Database::VAR_MEDIUMTEXT:
                case Database::VAR_LONGTEXT:
                    $total += 20; // Pointer storage for TEXT types
                    break;

                case Database::VAR_INTEGER:
                    if ($attribute['size'] >= 8) {
                        $total += 8; //  BIGINT 8 bytes
                    } else {
                        $total += 4; // INT 4 bytes
                    }
                    break;

                case Database::VAR_BIGINT:
                    $total += 8; //  BIGINT 8 bytes
                    break;

                case Database::VAR_FLOAT:
                    $total += 8; // DOUBLE 8 bytes
                    break;

                case Database::VAR_BOOLEAN:
                    $total += 1; // TINYINT(1) 1 bytes
                    break;

                case Database::VAR_RELATIONSHIP:
                    $total += Database::LENGTH_KEY * 4 + 1; // VARCHAR(<=255)
                    break;

                case Database::VAR_DATETIME:
                    /**
                     * 1 byte year + month
                     * 1 byte for the day
                     * 3 bytes for the hour, minute, and second
                     * 2 bytes miliseconds DATETIME(3)
                     */
                    $total += 7;
                    break;

                case Database::VAR_OBJECT:
                    /**
                     * JSONB/JSON type
                     * Only the pointer contributes 20 bytes to the row size
                     * Data is stored externally
                     */
                    $total += 20;
                    break;

                case Database::VAR_POINT:
                    $total += $this->getMaxPointSize();
                    break;
                case Database::VAR_LINESTRING:
                case Database::VAR_POLYGON:
                    $total += 20;
                    break;

                case Database::VAR_VECTOR:
                    // Each dimension is typically 4 bytes (float32)
                    $total += ($attribute['size'] ?? 0) * 4;
                    break;

                default:
                    throw new DatabaseException('Unknown type: ' . $attribute['type']);
            }
        }

        return $total;
    }

    /**
     * Get list of keywords that cannot be used
     *  Refference: https://mariadb.com/kb/en/reserved-words/
     *
     * @return array<string>
     */
    public function getKeywords(): array
    {
        return [
            'ACCESSIBLE',
            'ADD',
            'ALL',
            'ALTER',
            'ANALYZE',
            'AND',
            'AS',
            'ASC',
            'ASENSITIVE',
            'BEFORE',
            'BETWEEN',
            'BIGINT',
            'BINARY',
            'BLOB',
            'BOTH',
            'BY',
            'CALL',
            'CASCADE',
            'CASE',
            'CHANGE',
            'CHAR',
            'CHARACTER',
            'CHECK',
            'COLLATE',
            'COLUMN',
            'CONDITION',
            'CONSTRAINT',
            'CONTINUE',
            'CONVERT',
            'CREATE',
            'CROSS',
            'CURRENT_DATE',
            'CURRENT_ROLE',
            'CURRENT_TIME',
            'CURRENT_TIMESTAMP',
            'CURRENT_USER',
            'CURSOR',
            'DATABASE',
            'DATABASES',
            'DAY_HOUR',
            'DAY_MICROSECOND',
            'DAY_MINUTE',
            'DAY_SECOND',
            'DEC',
            'DECIMAL',
            'DECLARE',
            'DEFAULT',
            'DELAYED',
            'DELETE',
            'DELETE_DOMAIN_ID',
            'DESC',
            'DESCRIBE',
            'DETERMINISTIC',
            'DISTINCT',
            'DISTINCTROW',
            'DIV',
            'DO_DOMAIN_IDS',
            'DOUBLE',
            'DROP',
            'DUAL',
            'EACH',
            'ELSE',
            'ELSEIF',
            'ENCLOSED',
            'ESCAPED',
            'EXCEPT',
            'EXISTS',
            'EXIT',
            'EXPLAIN',
            'FALSE',
            'FETCH',
            'FLOAT',
            'FLOAT4',
            'FLOAT8',
            'FOR',
            'FORCE',
            'FOREIGN',
            'FROM',
            'FULLTEXT',
            'GENERAL',
            'GRANT',
            'GROUP',
            'HAVING',
            'HIGH_PRIORITY',
            'HOUR_MICROSECOND',
            'HOUR_MINUTE',
            'HOUR_SECOND',
            'IF',
            'IGNORE',
            'IGNORE_DOMAIN_IDS',
            'IGNORE_SERVER_IDS',
            'IN',
            'INDEX',
            'INFILE',
            'INNER',
            'INOUT',
            'INSENSITIVE',
            'INSERT',
            'INT',
            'INT1',
            'INT2',
            'INT3',
            'INT4',
            'INT8',
            'INTEGER',
            'INTERSECT',
            'INTERVAL',
            'INTO',
            'IS',
            'ITERATE',
            'JOIN',
            'KEY',
            'KEYS',
            'KILL',
            'LEADING',
            'LEAVE',
            'LEFT',
            'LIKE',
            'LIMIT',
            'LINEAR',
            'LINES',
            'LOAD',
            'LOCALTIME',
            'LOCALTIMESTAMP',
            'LOCK',
            'LONG',
            'LONGBLOB',
            'LONGTEXT',
            'LOOP',
            'LOW_PRIORITY',
            'MASTER_HEARTBEAT_PERIOD',
            'MASTER_SSL_VERIFY_SERVER_CERT',
            'MATCH',
            'MAXVALUE',
            'MEDIUMBLOB',
            'MEDIUMINT',
            'MEDIUMTEXT',
            'MIDDLEINT',
            'MINUTE_MICROSECOND',
            'MINUTE_SECOND',
            'MOD',
            'MODIFIES',
            'NATURAL',
            'NOT',
            'NO_WRITE_TO_BINLOG',
            'NULL',
            'NUMERIC',
            'OFFSET',
            'ON',
            'OPTIMIZE',
            'OPTION',
            'OPTIONALLY',
            'OR',
            'ORDER',
            'OUT',
            'OUTER',
            'OUTFILE',
            'OVER',
            'PAGE_CHECKSUM',
            'PARSE_VCOL_EXPR',
            'PARTITION',
            'POSITION',
            'PRECISION',
            'PRIMARY',
            'PROCEDURE',
            'PURGE',
            'RANGE',
            'READ',
            'READS',
            'READ_WRITE',
            'REAL',
            'RECURSIVE',
            'REF_SYSTEM_ID',
            'REFERENCES',
            'REGEXP',
            'RELEASE',
            'RENAME',
            'REPEAT',
            'REPLACE',
            'REQUIRE',
            'RESIGNAL',
            'RESTRICT',
            'RETURN',
            'RETURNING',
            'REVOKE',
            'RIGHT',
            'RLIKE',
            'ROWS',
            'SCHEMA',
            'SCHEMAS',
            'SECOND_MICROSECOND',
            'SELECT',
            'SENSITIVE',
            'SEPARATOR',
            'SET',
            'SHOW',
            'SIGNAL',
            'SLOW',
            'SMALLINT',
            'SPATIAL',
            'SPECIFIC',
            'SQL',
            'SQLEXCEPTION',
            'SQLSTATE',
            'SQLWARNING',
            'SQL_BIG_RESULT',
            'SQL_CALC_FOUND_ROWS',
            'SQL_SMALL_RESULT',
            'SSL',
            'STARTING',
            'STATS_AUTO_RECALC',
            'STATS_PERSISTENT',
            'STATS_SAMPLE_PAGES',
            'STRAIGHT_JOIN',
            'TABLE',
            'TERMINATED',
            'THEN',
            'TINYBLOB',
            'TINYINT',
            'TINYTEXT',
            'TO',
            'TRAILING',
            'TRIGGER',
            'TRUE',
            'UNDO',
            'UNION',
            'UNIQUE',
            'UNLOCK',
            'UNSIGNED',
            'UPDATE',
            'USAGE',
            'USE',
            'USING',
            'UTC_DATE',
            'UTC_TIME',
            'UTC_TIMESTAMP',
            'VALUES',
            'VARBINARY',
            'VARCHAR',
            'VARCHARACTER',
            'VARYING',
            'WHEN',
            'WHERE',
            'WHILE',
            'WINDOW',
            'WITH',
            'WRITE',
            'XOR',
            'YEAR_MONTH',
            'ZEROFILL',
            'ACTION',
            'BIT',
            'DATE',
            'ENUM',
            'NO',
            'TEXT',
            'TIME',
            'TIMESTAMP',
            'BODY',
            'ELSIF',
            'GOTO',
            'HISTORY',
            'MINUS',
            'OTHERS',
            'PACKAGE',
            'PERIOD',
            'RAISE',
            'ROWNUM',
            'ROWTYPE',
            'SYSDATE',
            'SYSTEM',
            'SYSTEM_TIME',
            'VERSIONING',
            'WITHOUT'
        ];
    }

    /**
     * Does the adapter handle casting?
     *
     * @return bool
     */
    public function getSupportForCasting(): bool
    {
        return true;
    }

    public function getSupportForNumericCasting(): bool
    {
        return false;
    }


    /**
     * Does the adapter handle Query Array Contains?
     *
     * @return bool
     */
    public function getSupportForQueryContains(): bool
    {
        return true;
    }

    /**
     * Does the adapter handle array Overlaps?
     *
     * @return bool
     */
    abstract public function getSupportForJSONOverlaps(): bool;

    public function getSupportForIndexArray(): bool
    {
        return true;
    }

    public function getSupportForCastIndexArray(): bool
    {
        return false;
    }

    public function getSupportForRelationships(): bool
    {
        return true;
    }

    public function getSupportForReconnection(): bool
    {
        return true;
    }

    public function getSupportForBatchCreateAttributes(): bool
    {
        return true;
    }

    /**
     * Are spatial attributes supported?
     *
     * @return bool
    */
    public function getSupportForSpatialAttributes(): bool
    {
        return false;
    }

    /**
     * Does the adapter support null values in spatial indexes?
     *
     * @return bool
     */
    public function getSupportForSpatialIndexNull(): bool
    {
        return false;
    }

    /**
     * Does the adapter support operators?
     *
     * @return bool
     */
    public function getSupportForOperators(): bool
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
    * Is internal casting supported?
    *
    * @return bool
    */
    public function getSupportForInternalCasting(): bool
    {
        return false;
    }

    /**
     * Does the adapter support multiple fulltext indexes?
     *
     * @return bool
     */
    public function getSupportForMultipleFulltextIndexes(): bool
    {
        return true;
    }

    /**
     * Does the adapter support identical indexes?
     *
     * @return bool
     */
    public function getSupportForIdenticalIndexes(): bool
    {
        return true;
    }

    /**
     * Does the adapter support random order for queries?
     *
     * @return bool
     */
    public function getSupportForOrderRandom(): bool
    {
        return true;
    }

    public function getSupportForUTCCasting(): bool
    {
        return false;
    }

    public function setUTCDatetime(string $value): mixed
    {
        return $value;
    }

    public function castingBefore(Document $collection, Document $document): Document
    {
        return $document;
    }

    public function castingAfter(Document $collection, Document $document): Document
    {
        return $document;
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
     * Is vector type supported?
     *
     * @return bool
     */
    public function getSupportForVectors(): bool
    {
        return false;
    }

    /**
     * Generate ST_GeomFromText call with proper SRID and axis order support
     *
     * @param string $wktPlaceholder
     * @param int|null $srid
     * @return string
     */
    protected function getSpatialGeomFromText(string $wktPlaceholder, ?int $srid = null): string
    {
        $srid = $srid ?? Database::DEFAULT_SRID;
        $geomFromText = "ST_GeomFromText({$wktPlaceholder}, {$srid}";

        if ($this->getSupportForSpatialAxisOrder()) {
            $geomFromText .= ", " . $this->getSpatialAxisOrderSpec();
        }

        $geomFromText .= ")";

        return $geomFromText;
    }

    /**
     * Get the spatial axis order specification string
     *
     * @return string
     */
    protected function getSpatialAxisOrderSpec(): string
    {
        return "'axis-order=long-lat'";
    }

    /**
     * @param string $tableName
     * @param string $columns
     * @param array<string> $batchKeys
     * @param array<mixed> $bindValues
     * @param array<string> $attributes
     * @param string $attribute
     * @param array<Operator> $operators
     * @return mixed
     */
    abstract protected function getUpsertStatement(
        string $tableName,
        string $columns,
        array $batchKeys,
        array $attributes,
        array $bindValues,
        string $attribute = '',
        array $operators = []
    ): mixed;

    /**
     * Get the SQL expression measuring distance between a vector attribute and the query vector
     *
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param string $alias
     * @return string|null
     */
    protected function getSQLVectorDistance(Query $query, array &$binds, string $alias): ?string
    {
        return null;
    }

    /**
     * Render a vector distance expression in a form safe to read back into PHP
     *
     * A distance is undefined for a zero vector and can overflow for a large one, so the
     * expression can evaluate to NaN or infinity. Those cannot survive the trip into a PHP
     * float, so the value is carried as text and interpreted during hydration.
     *
     * @param string $distance
     * @return string
     */
    protected function getSQLReadableDistance(string $distance): string
    {
        return $distance;
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

        if ($exact) {
            $value = '"' . $value . '"';
        } else {
            /** Prepend wildcard by default on the back. */
            $value .= '*';
        }

        return $value;
    }

    /**
     * Get SQL Operator
     *
     * @param string $method
     * @return string
     * @throws Exception
     */
    protected function getSQLOperator(string $method): string
    {
        switch ($method) {
            case Query::TYPE_EQUAL:
                return '=';
            case Query::TYPE_NOT_EQUAL:
                return '!=';
            case Query::TYPE_LESSER:
                return '<';
            case Query::TYPE_LESSER_EQUAL:
                return '<=';
            case Query::TYPE_GREATER:
                return '>';
            case Query::TYPE_GREATER_EQUAL:
                return '>=';
            case Query::TYPE_IS_NULL:
                return 'IS NULL';
            case Query::TYPE_IS_NOT_NULL:
                return 'IS NOT NULL';
            case Query::TYPE_STARTS_WITH:
            case Query::TYPE_ENDS_WITH:
            case Query::TYPE_CONTAINS:
            case Query::TYPE_CONTAINS_ANY:
            case Query::TYPE_CONTAINS_ALL:
            case Query::TYPE_NOT_STARTS_WITH:
            case Query::TYPE_NOT_ENDS_WITH:
            case Query::TYPE_NOT_CONTAINS:
                return $this->getLikeOperator();
            case Query::TYPE_REGEX:
                return $this->getRegexOperator();
            case Query::TYPE_VECTOR_DOT:
            case Query::TYPE_VECTOR_COSINE:
            case Query::TYPE_VECTOR_EUCLIDEAN:
                throw new DatabaseException('Vector queries are not supported by this database');
            case Query::TYPE_EXISTS:
            case Query::TYPE_NOT_EXISTS:
                throw new DatabaseException('Exists queries are not supported by this database');
            default:
                throw new DatabaseException('Unknown method: ' . $method);
        }
    }

    abstract protected function getSQLType(
        string $type,
        int $size,
        bool $signed = true,
        bool $array = false,
        bool $required = false
    ): string;

    /**
     * @throws DatabaseException For unknown type values.
     */
    public function getColumnType(string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): string
    {
        return $this->getSQLType($type, $size, $signed, $array, $required);
    }

    /**
     * Get SQL Index Type
     *
     * @param string $type
     * @return string
     * @throws Exception
     */
    protected function getSQLIndexType(string $type): string
    {
        return match ($type) {
            Database::INDEX_KEY => 'INDEX',
            Database::INDEX_UNIQUE => 'UNIQUE INDEX',
            Database::INDEX_FULLTEXT => 'FULLTEXT INDEX',
            default => throw new DatabaseException('Unknown index type: ' . $type . '. Must be one of ' . Database::INDEX_KEY . ', ' . Database::INDEX_UNIQUE . ', ' . Database::INDEX_FULLTEXT),
        };
    }

    /**
     * Get SQL condition for permissions
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

        $roles = \array_map(fn ($role) => $this->getPDO()->quote($role), $roles);
        $roles = \implode(', ', $roles);

        return "{$this->quote($alias)}.{$this->quote('_uid')} IN (
            SELECT _document
            FROM {$this->getSQLTable($collection . '_perms')}
            WHERE _permission IN ({$roles})
              AND _type = '{$type}'
              {$this->getTenantQuery($collection)}
        )";
    }

    /**
     * Get SQL table
     *
     * @param string $name
     * @return string
     * @throws DatabaseException
     */
    protected function getSQLTable(string $name): string
    {
        return "{$this->quote($this->getDatabase())}.{$this->quote($this->getNamespace() . '_' .$this->filter($name))}";
    }

    /**
     * Generate SQL expression for operator
     * Each adapter must implement operators specific to their SQL dialect
     *
     * @param string $column
     * @param Operator $operator
     * @param array<string, mixed> $binds
     * @return string|null Returns null if operator can't be expressed in SQL
     */
    abstract protected function getOperatorSQL(string $column, Operator $operator, array &$binds): ?string;

    /**
     * Register an operator bind value and return its placeholder name (without leading colon).
     * Lets getOperatorSQL() capture a parameter's value as it emits the placeholder, so SQL and
     * binds can never drift out of sync. The placeholder is unique by construction (ID::unique()),
     * so no shared counter needs to be threaded between callers.
     *
     * @param array<string, mixed> $binds
     */
    protected function registerOperatorBind(array &$binds, mixed $value): string
    {
        $key = ID::unique();
        $binds[":{$key}"] = $value;
        return $key;
    }

    /**
     * Apply an operator to a value (used for new documents with only operators).
     * This method applies the operator logic in PHP to compute what the SQL would compute.
     *
     * @param Operator $operator
     * @param mixed $value The current value (typically the attribute default)
     * @return mixed The result after applying the operator
     */
    protected function applyOperatorToValue(Operator $operator, mixed $value): mixed
    {
        $method = $operator->getMethod();
        $values = $operator->getValues();

        switch ($method) {
            // Numeric operators
            case Operator::TYPE_INCREMENT:
                return ($value ?? 0) + ($values[0] ?? 1);

            case Operator::TYPE_DECREMENT:
                return ($value ?? 0) - ($values[0] ?? 1);

            case Operator::TYPE_MULTIPLY:
                return ($value ?? 0) * ($values[0] ?? 1);

            case Operator::TYPE_DIVIDE:
                $divisor = $values[0] ?? 1;
                return (float)$divisor !== 0.0 ? ($value ?? 0) / $divisor : ($value ?? 0);

            case Operator::TYPE_MODULO:
                $divisor = $values[0] ?? 1;
                return (float)$divisor !== 0.0 ? ($value ?? 0) % $divisor : ($value ?? 0);

            case Operator::TYPE_POWER:
                return pow($value ?? 0, $values[0] ?? 1);

                // Array operators
            case Operator::TYPE_ARRAY_APPEND:
                return array_merge($value ?? [], $values);

            case Operator::TYPE_ARRAY_PREPEND:
                return array_merge($values, $value ?? []);

            case Operator::TYPE_ARRAY_INSERT:
                $arr = $value ?? [];
                $index = $values[0] ?? 0;
                $item = $values[1] ?? null;
                array_splice($arr, $index, 0, [$item]);
                return $arr;

            case Operator::TYPE_ARRAY_REMOVE:
                $arr = $value ?? [];
                $toRemove = $values[0] ?? null;
                if (is_array($toRemove)) {
                    return array_values(array_diff($arr, $toRemove));
                }
                return array_values(array_diff($arr, [$toRemove]));

            case Operator::TYPE_ARRAY_UNIQUE:
                return array_values(array_unique($value ?? []));

            case Operator::TYPE_ARRAY_INTERSECT:
                return array_values(array_intersect($value ?? [], $values));

            case Operator::TYPE_ARRAY_DIFF:
                return array_values(array_diff($value ?? [], $values));

            case Operator::TYPE_ARRAY_FILTER:
                return $value ?? [];

                // String operators
            case Operator::TYPE_STRING_CONCAT:
                return ($value ?? '') . ($values[0] ?? '');

            case Operator::TYPE_STRING_REPLACE:
                $search = $values[0] ?? '';
                $replace = $values[1] ?? '';
                return str_replace($search, $replace, $value ?? '');

                // Boolean operators
            case Operator::TYPE_TOGGLE:
                return !($value ?? false);

                // Date operators
            case Operator::TYPE_DATE_ADD_DAYS:
            case Operator::TYPE_DATE_SUB_DAYS:
                // For NULL dates, operators return NULL
                return $value;

            case Operator::TYPE_DATE_SET_NOW:
                return DateTime::now();

            default:
                return $value;
        }
    }

    /**
     * Returns the current PDO object
     * @return mixed
     * @deprecated Use getDriver() instead
     */
    protected function getPDO(): mixed
    {
        return $this->pdo;
    }

    /**
     * Returns the current PDO object
     * @return mixed
     */
    public function getDriver(): mixed
    {
        return $this->pdo;
    }

    /**
     * Get PDO Type
     *
     * @param mixed $value
     * @return int
     * @throws Exception
     */
    abstract protected function getPDOType(mixed $value): int;

    /**
     * Get the SQL function for random ordering
     *
     * @return string
     */
    abstract protected function getRandomOrder(): string;

    /**
     * Returns default PDO configuration
     *
     * @return array<int, mixed>
     */
    public static function getPDOAttributes(): array
    {
        return [
            \PDO::ATTR_TIMEOUT => 3, // Specifies the timeout duration in seconds. Takes a value of type int.
            \PDO::ATTR_PERSISTENT => true, // Create a persistent connection
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, // Fetch a result row as an associative array.
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, // PDO will throw a PDOException on errors
            \PDO::ATTR_EMULATE_PREPARES => true, // Emulate prepared statements
            \PDO::ATTR_STRINGIFY_FETCHES => true // Returns all fetched data as Strings
        ];
    }

    public function getHostname(): string
    {
        try {
            return $this->pdo->getHostname();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return int
     */
    public function getMaxVarcharLength(): int
    {
        return 16381; // Floor value for Postgres:16383 | MySQL:16381 | MariaDB:16382
    }

    /**
     * Size of POINT spatial type
     *
     * @return int
    */
    abstract protected function getMaxPointSize(): int;
    /**
     * @return string
     */
    public function getIdAttributeType(): string
    {
        return Database::VAR_INTEGER;
    }

    /**
     * @return int
     */
    public function getMaxIndexLength(): int
    {
        /**
         * $tenant int = 1
         */
        return $this->sharedTables ? 767 : 768;
    }

    /**
     * @return int
     */
    public function getMaxUIDLength(): int
    {
        return 36;
    }

    /**
     * @param Query $query
     * @param array<string, mixed> $binds
     * @param ?string $forCollection Filtered collection id (for FTS5 routing).
     * @return string
     * @throws Exception
     */
    abstract protected function getSQLCondition(Query $query, array &$binds, ?string $forCollection = null): string;

    /**
     * @param array<Query> $queries
     * @param array<string, mixed> $binds
     * @param string $separator
     * @param ?string $forCollection See {@see getSQLCondition}.
     * @return string
     * @throws Exception
     */
    public function getSQLConditions(array $queries, array &$binds, string $separator = 'AND', ?string $forCollection = null): string
    {
        $conditions = [];
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_SELECT) {
                continue;
            }

            if ($query->isNested()) {
                $conditions[] = $this->getSQLConditions($query->getValues(), $binds, $query->getMethod(), $forCollection);
            } else {
                $conditions[] = $this->getSQLCondition($query, $binds, $forCollection);
            }
        }

        $tmp = implode(' ' . $separator . ' ', $conditions);
        return empty($tmp) ? '' : '(' . $tmp . ')';
    }

    /**
     * @return string
     */
    public function getLikeOperator(): string
    {
        return 'LIKE';
    }

    /**
     * @return string
     */
    public function getRegexOperator(): string
    {
        return 'REGEXP';
    }

    public function getInternalIndexesKeys(): array
    {
        return [];
    }

    public function getSchemaAttributes(string $collection): array
    {
        return [];
    }

    public function getSchemaIndexes(string $collection): array
    {
        return [];
    }

    public function getSupportForSchemaIndexes(): bool
    {
        return false;
    }

    public function getTenantQuery(
        string $collection,
        string $alias = '',
        int $tenantCount = 0,
        string $condition = 'AND'
    ): string {
        if (!$this->sharedTables) {
            return '';
        }

        $dot = '';
        if ($alias !== '') {
            $dot = '.';
            $alias = $this->quote($alias);
        }

        $bindings = [];
        if ($tenantCount === 0) {
            $bindings[] = ':_tenant';
        } else {
            for ($index = 0; $index < $tenantCount; $index++) {
                $bindings[] = ":_tenant_{$index}";
            }
        }
        $bindings = \implode(',', $bindings);

        $orIsNull = '';
        if ($collection === Database::METADATA) {
            $orIsNull = " OR {$alias}{$dot}_tenant IS NULL";
        }

        return "{$condition} ({$alias}{$dot}_tenant IN ({$bindings}) {$orIsNull})";
    }

    /**
     * Get the SQL projection given the selected attributes
     *
     * @param array<string> $selections
     * @param string $prefix
     * @return mixed
     * @throws Exception
     */
    protected function getAttributeProjection(array $selections, string $prefix): mixed
    {
        if (empty($selections) || \in_array('*', $selections)) {
            return "{$this->quote($prefix)}.*";
        }

        // Handle specific selections with spatial conversion where needed
        $internalKeys = [
            '$id',
            '$sequence',
            '$permissions',
            '$createdAt',
            '$updatedAt',
        ];

        $hasDeletedAt = \in_array('$deletedAt', $selections);

        $selections = \array_diff($selections, [...$internalKeys, '$deletedAt', '$collection']);

        foreach ($internalKeys as $internalKey) {
            $selections[] = $this->getInternalKeyForAttribute($internalKey);
        }

        if ($hasDeletedAt) {
            $selections[] = $this->getInternalKeyForAttribute('$deletedAt');
        }

        $projections = [];
        foreach ($selections as $selection) {
            $filteredSelection = $this->filter($selection);
            $quotedSelection = $this->quote($filteredSelection);
            $projections[] = "{$this->quote($prefix)}.{$quotedSelection}";
        }

        return \implode(',', $projections);
    }

    protected function getInternalKeyForAttribute(string $attribute): string
    {
        return match ($attribute) {
            '$id' => '_uid',
            '$sequence' => '_id',
            '$collection' => '_collection',
            '$tenant' => '_tenant',
            '$createdAt' => '_createdAt',
            '$updatedAt' => '_updatedAt',
            '$deletedAt' => '_deletedAt',
            '$permissions' => '_permissions',
            default => $attribute
        };
    }

    protected function escapeWildcards(string $value): string
    {
        $wildcards = ['%', '_', '[', ']', '^', '-', '.', '*', '+', '?', '(', ')', '{', '}', '|'];

        foreach ($wildcards as $wildcard) {
            $value = \str_replace($wildcard, "\\$wildcard", $value);
        }

        return $value;
    }

    protected function processException(PDOException $e): \Exception
    {
        return $e;
    }

    /**
     * @param mixed $stmt
     * @return bool
     */
    protected function execute(mixed $stmt): bool
    {
        return $stmt->execute();
    }

    /**
     * Create Documents in batches
     *
     * @param Document $collection
     * @param array<Document> $documents
     *
     * @return array<Document>
     *
     * @throws DuplicateException
     * @throws \Throwable
     */
    public function createDocuments(Document $collection, array $documents): array
    {
        if (empty($documents)) {
            return $documents;
        }

        $spatialAttributes = $this->getSpatialAttributes($collection);
        $collection = $collection->getId();
        try {
            $name = $this->filter($collection);

            $attributeKeys = Database::INTERNAL_ATTRIBUTE_KEYS;

            $hasSequence = null;
            foreach ($documents as $document) {
                $attributes = $document->getAttributes();
                $attributeKeys = [...$attributeKeys, ...\array_keys($attributes)];

                if ($hasSequence === null) {
                    $hasSequence = !empty($document->getSequence());
                } elseif ($hasSequence == empty($document->getSequence())) {
                    throw new DatabaseException('All documents must have an sequence if one is set');
                }
            }

            $attributeKeys = array_unique($attributeKeys);

            if ($hasSequence) {
                $attributeKeys[] = '_id';
            }

            if ($this->sharedTables) {
                $attributeKeys[] = '_tenant';
            }

            $columns = [];
            foreach ($attributeKeys as $key => $attribute) {
                $columns[$key] = $this->quote($this->filter($attribute));
            }

            $columns = '(' . \implode(', ', $columns) . ')';

            $bindIndex = 0;
            $batchKeys = [];
            $bindValues = [];
            $permissions = [];
            $bindValuesPermissions = [];

            foreach ($documents as $index => $document) {
                $attributes = $document->getAttributes();
                $attributes['_uid'] = $document->getId();
                $attributes['_createdAt'] = $document->getCreatedAt();
                $attributes['_updatedAt'] = $document->getUpdatedAt();
                $attributes['_permissions'] = \json_encode($document->getPermissions());

                if (!empty($document->getSequence())) {
                    $attributes['_id'] = $document->getSequence();
                }

                if ($this->sharedTables) {
                    $attributes['_tenant'] = $document->getTenant();
                }

                $bindKeys = [];

                foreach ($attributeKeys as $key) {
                    $value = $attributes[$key] ?? null;
                    if (\is_array($value)) {
                        $value = \json_encode($value);
                    }
                    if (in_array($key, $spatialAttributes)) {
                        $bindKey = 'key_' . $bindIndex;
                        $bindKeys[] = $this->getSpatialGeomFromText(":" . $bindKey);
                    } else {
                        if ($this->getSupportForIntegerBooleans()) {
                            $value = (\is_bool($value)) ? (int)$value : $value;
                        }
                        $bindKey = 'key_' . $bindIndex;
                        $bindKeys[] = ':' . $bindKey;
                    }
                    $bindValues[$bindKey] = $value;
                    $bindIndex++;
                }

                $batchKeys[] = '(' . \implode(', ', $bindKeys) . ')';

                foreach (Database::PERMISSIONS as $type) {
                    foreach ($document->getPermissionsByType($type) as $permission) {
                        $tenantBind = $this->sharedTables ? ", :_tenant_{$index}" : '';
                        $permission = \str_replace('"', '', $permission);
                        $permission = "('{$type}', '{$permission}', :_uid_{$index} {$tenantBind})";
                        $permissions[] = $permission;
                        $bindValuesPermissions[":_uid_{$index}"] = $document->getId();
                        if ($this->sharedTables) {
                            $bindValuesPermissions[":_tenant_{$index}"] = $document->getTenant();
                        }
                    }
                }
            }

            $batchKeys = \implode(', ', $batchKeys);

            $stmt = $this->getPDO()->prepare("
                {$this->getInsertKeyword()} {$this->getSQLTable($name)} {$columns}
                VALUES {$batchKeys}
                {$this->getInsertSuffix($name)}
            ");

            foreach ($bindValues as $key => $value) {
                $stmt->bindValue($key, $value, $this->getPDOType($value));
            }

            $this->execute($stmt);

            if (!empty($permissions)) {
                $tenantColumn = $this->sharedTables ? ', _tenant' : '';
                $permissions = \implode(', ', $permissions);

                $sqlPermissions = "
                    {$this->getInsertKeyword()} {$this->getSQLTable($name . '_perms')} (_type, _permission, _document {$tenantColumn})
                    VALUES {$permissions}
                    {$this->getInsertPermissionsSuffix()}
                ";

                $stmtPermissions = $this->getPDO()->prepare($sqlPermissions);

                foreach ($bindValuesPermissions as $key => $value) {
                    $stmtPermissions->bindValue($key, $value, $this->getPDOType($value));
                }

                $this->execute($stmtPermissions);
            }

        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return $documents;
    }

    /**
     * @param Document $collection
     * @param string $attribute
     * @param array<Change> $changes
     * @return array<Document>
     * @throws DatabaseException
     */
    public function upsertDocuments(
        Document $collection,
        string $attribute,
        array $changes
    ): array {
        if (empty($changes)) {
            return $changes;
        }
        try {
            $spatialAttributes = $this->getSpatialAttributes($collection);

            $attributeDefaults = [];
            foreach ($collection->getAttribute('attributes', []) as $attr) {
                $attributeDefaults[$attr['$id']] = $attr['default'] ?? null;
            }

            $collection = $collection->getId();
            $name = $this->filter($collection);

            $hasOperators = false;
            $firstChange = $changes[0];
            $firstDoc = $firstChange->getNew();
            $firstExtracted = Operator::extractOperators($firstDoc->getAttributes());

            if (!empty($firstExtracted['operators'])) {
                $hasOperators = true;
            } else {
                foreach ($changes as $change) {
                    $doc = $change->getNew();
                    $extracted = Operator::extractOperators($doc->getAttributes());
                    if (!empty($extracted['operators'])) {
                        $hasOperators = true;
                        break;
                    }
                }
            }

            if (!$hasOperators) {
                $bindIndex = 0;
                $batchKeys = [];
                $bindValues = [];
                $allColumnNames = [];
                $documentsData = [];

                foreach ($changes as $change) {
                    $document = $change->getNew();
                    $currentRegularAttributes = $document->getAttributes();

                    $currentRegularAttributes['_uid'] = $document->getId();
                    $currentRegularAttributes['_createdAt'] = $document->getCreatedAt() ? DateTime::setTimezone($document->getCreatedAt()) : null;
                    $currentRegularAttributes['_updatedAt'] = $document->getUpdatedAt() ? DateTime::setTimezone($document->getUpdatedAt()) : null;
                    $currentRegularAttributes['_permissions'] = \json_encode($document->getPermissions());

                    if (!empty($document->getSequence())) {
                        $currentRegularAttributes['_id'] = $document->getSequence();
                    }

                    if ($this->sharedTables) {
                        $currentRegularAttributes['_tenant'] = $document->getTenant();
                    }

                    foreach (\array_keys($currentRegularAttributes) as $colName) {
                        $allColumnNames[$colName] = true;
                    }

                    $documentsData[] = ['regularAttributes' => $currentRegularAttributes];
                }

                $allColumnNames = \array_keys($allColumnNames);
                \sort($allColumnNames);

                $columnsArray = [];
                foreach ($allColumnNames as $attr) {
                    $columnsArray[] = "{$this->quote($this->filter($attr))}";
                }
                $columns = '(' . \implode(', ', $columnsArray) . ')';

                foreach ($documentsData as $docData) {
                    $currentRegularAttributes = $docData['regularAttributes'];
                    $bindKeys = [];

                    foreach ($allColumnNames as $attributeKey) {
                        $attrValue = $currentRegularAttributes[$attributeKey] ?? null;

                        if (\is_array($attrValue)) {
                            $attrValue = \json_encode($attrValue);
                        }

                        if (in_array($attributeKey, $spatialAttributes) && $attrValue !== null) {
                            $bindKey = 'key_' . $bindIndex;
                            $bindKeys[] = $this->getSpatialGeomFromText(":" . $bindKey);
                        } else {
                            if ($this->getSupportForIntegerBooleans()) {
                                $attrValue = (\is_bool($attrValue)) ? (int)$attrValue : $attrValue;
                            }
                            $bindKey = 'key_' . $bindIndex;
                            $bindKeys[] = ':' . $bindKey;
                        }
                        $bindValues[$bindKey] = $attrValue;
                        $bindIndex++;
                    }

                    $batchKeys[] = '(' . \implode(', ', $bindKeys) . ')';
                }

                $regularAttributes = [];
                foreach ($allColumnNames as $colName) {
                    $regularAttributes[$colName] = null;
                }
                foreach ($documentsData[0]['regularAttributes'] as $key => $value) {
                    $regularAttributes[$key] = $value;
                }

                $stmt = $this->getUpsertStatement($name, $columns, $batchKeys, $regularAttributes, $bindValues, $attribute, []);
                $stmt->execute();
                $stmt->closeCursor();
            } else {
                $groups = [];

                foreach ($changes as $change) {
                    $document = $change->getNew();
                    $extracted = Operator::extractOperators($document->getAttributes());
                    $operators = $extracted['operators'];

                    if (empty($operators)) {
                        $signature = 'no_ops';
                    } else {
                        $parts = [];
                        foreach ($operators as $attr => $op) {
                            $parts[] = $attr . ':' . $op->getMethod() . ':' . json_encode($op->getValues());
                        }
                        sort($parts);
                        $signature = implode('|', $parts);
                    }

                    if (!isset($groups[$signature])) {
                        $groups[$signature] = [
                            'documents' => [],
                            'operators' => $operators
                        ];
                    }

                    $groups[$signature]['documents'][] = $change;
                }

                foreach ($groups as $group) {
                    $groupChanges = $group['documents'];
                    $operators = $group['operators'];

                    $bindIndex = 0;
                    $batchKeys = [];
                    $bindValues = [];
                    $allColumnNames = [];
                    $documentsData = [];

                    foreach ($groupChanges as $change) {
                        $document = $change->getNew();
                        $attributes = $document->getAttributes();

                        $extracted = Operator::extractOperators($attributes);
                        $currentRegularAttributes = $extracted['updates'];
                        $extractedOperators = $extracted['operators'];

                        // For new documents, apply operators to attribute defaults
                        if ($change->getOld()->isEmpty() && !empty($extractedOperators)) {
                            foreach ($extractedOperators as $operatorKey => $operator) {
                                $default = $attributeDefaults[$operatorKey] ?? null;
                                $currentRegularAttributes[$operatorKey] = $this->applyOperatorToValue($operator, $default);
                            }
                        }

                        $currentRegularAttributes['_uid'] = $document->getId();
                        $currentRegularAttributes['_createdAt'] = $document->getCreatedAt() ? $document->getCreatedAt() : null;
                        $currentRegularAttributes['_updatedAt'] = $document->getUpdatedAt() ? $document->getUpdatedAt() : null;
                        $currentRegularAttributes['_permissions'] = \json_encode($document->getPermissions());

                        if (!empty($document->getSequence())) {
                            $currentRegularAttributes['_id'] = $document->getSequence();
                        }

                        if ($this->sharedTables) {
                            $currentRegularAttributes['_tenant'] = $document->getTenant();
                        }

                        foreach (\array_keys($currentRegularAttributes) as $colName) {
                            $allColumnNames[$colName] = true;
                        }

                        $documentsData[] = ['regularAttributes' => $currentRegularAttributes];
                    }

                    foreach (\array_keys($operators) as $colName) {
                        $allColumnNames[$colName] = true;
                    }

                    $allColumnNames = \array_keys($allColumnNames);
                    \sort($allColumnNames);

                    $columnsArray = [];
                    foreach ($allColumnNames as $attr) {
                        $columnsArray[] = "{$this->quote($this->filter($attr))}";
                    }
                    $columns = '(' . \implode(', ', $columnsArray) . ')';

                    foreach ($documentsData as $docData) {
                        $currentRegularAttributes = $docData['regularAttributes'];
                        $bindKeys = [];

                        foreach ($allColumnNames as $attributeKey) {
                            $attrValue = $currentRegularAttributes[$attributeKey] ?? null;

                            if (\is_array($attrValue)) {
                                $attrValue = \json_encode($attrValue);
                            }

                            if (in_array($attributeKey, $spatialAttributes) && $attrValue !== null) {
                                $bindKey = 'key_' . $bindIndex;
                                $bindKeys[] = $this->getSpatialGeomFromText(":" . $bindKey);
                            } else {
                                if ($this->getSupportForIntegerBooleans()) {
                                    $attrValue = (\is_bool($attrValue)) ? (int)$attrValue : $attrValue;
                                }
                                $bindKey = 'key_' . $bindIndex;
                                $bindKeys[] = ':' . $bindKey;
                            }
                            $bindValues[$bindKey] = $attrValue;
                            $bindIndex++;
                        }

                        $batchKeys[] = '(' . \implode(', ', $bindKeys) . ')';
                    }

                    $regularAttributes = [];
                    foreach ($allColumnNames as $colName) {
                        $regularAttributes[$colName] = null;
                    }
                    foreach ($documentsData[0]['regularAttributes'] as $key => $value) {
                        $regularAttributes[$key] = $value;
                    }

                    $stmt = $this->getUpsertStatement(
                        $name,
                        $columns,
                        $batchKeys,
                        $regularAttributes,
                        $bindValues,
                        '',
                        $operators
                    );

                    $stmt->execute();
                    $stmt->closeCursor();
                }
            }

            $removeQueries = [];
            $removeBindValues = [];
            $addQueries = [];
            $addBindValues = [];

            foreach ($changes as $index => $change) {
                $old = $change->getOld();
                $document = $change->getNew();

                $current = [];
                foreach (Database::PERMISSIONS as $type) {
                    $current[$type] = $old->getPermissionsByType($type);
                }

                foreach (Database::PERMISSIONS as $type) {
                    $toRemove = \array_diff($current[$type], $document->getPermissionsByType($type));
                    if (!empty($toRemove)) {
                        $removeQueries[] = "(
                            _document = :_uid_{$index}
                            " . ($this->sharedTables ? " AND _tenant = :_tenant_{$index}" : '') . "
                            AND _type = '{$type}'
                            AND _permission IN (" . \implode(',', \array_map(fn ($i) => ":remove_{$type}_{$index}_{$i}", \array_keys($toRemove))) . ")
                        )";
                        $removeBindValues[":_uid_{$index}"] = $document->getId();
                        if ($this->sharedTables) {
                            $removeBindValues[":_tenant_{$index}"] = $document->getTenant();
                        }
                        foreach ($toRemove as $i => $perm) {
                            $removeBindValues[":remove_{$type}_{$index}_{$i}"] = $perm;
                        }
                    }
                }

                foreach (Database::PERMISSIONS as $type) {
                    $toAdd = \array_diff($document->getPermissionsByType($type), $current[$type]);

                    foreach ($toAdd as $i => $permission) {
                        $addQuery = "(:_uid_{$index}, '{$type}', :add_{$type}_{$index}_{$i}";

                        if ($this->sharedTables) {
                            $addQuery .= ", :_tenant_{$index}";
                        }

                        $addQuery .= ")";
                        $addQueries[] = $addQuery;
                        $addBindValues[":_uid_{$index}"] = $document->getId();
                        $addBindValues[":add_{$type}_{$index}_{$i}"] = $permission;

                        if ($this->sharedTables) {
                            $addBindValues[":_tenant_{$index}"] = $document->getTenant();
                        }
                    }
                }
            }

            if (!empty($removeQueries)) {
                $removeQuery = \implode(' OR ', $removeQueries);
                $stmtRemovePermissions = $this->getPDO()->prepare("DELETE FROM {$this->getSQLTable($name . '_perms')} WHERE {$removeQuery}");
                foreach ($removeBindValues as $key => $value) {
                    $stmtRemovePermissions->bindValue($key, $value, $this->getPDOType($value));
                }
                $stmtRemovePermissions->execute();
            }

            if (!empty($addQueries)) {
                $sqlAddPermissions = "INSERT INTO {$this->getSQLTable($name . '_perms')} (_document, _type, _permission";
                if ($this->sharedTables) {
                    $sqlAddPermissions .= ", _tenant";
                }
                $sqlAddPermissions .= ") VALUES " . \implode(', ', $addQueries);
                $stmtAddPermissions = $this->getPDO()->prepare($sqlAddPermissions);
                foreach ($addBindValues as $key => $value) {
                    $stmtAddPermissions->bindValue($key, $value, $this->getPDOType($value));
                }
                $stmtAddPermissions->execute();
            }
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return \array_map(fn ($change) => $change->getNew(), $changes);
    }

    /**
     * Build geometry WKT string from array input for spatial queries
     *
     * @param array<mixed> $geometry
     * @return string
     * @throws DatabaseException
     */
    protected function convertArrayToWKT(array $geometry): string
    {
        // point [x, y]
        if (count($geometry) === 2 && is_numeric($geometry[0]) && is_numeric($geometry[1])) {
            return "POINT({$geometry[0]} {$geometry[1]})";
        }

        // linestring [[x1, y1], [x2, y2], ...]
        if (is_array($geometry[0]) && count($geometry[0]) === 2 && is_numeric($geometry[0][0])) {
            $points = [];
            foreach ($geometry as $point) {
                if (!is_array($point) || count($point) !== 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                    throw new DatabaseException('Invalid point format in geometry array');
                }
                $points[] = "{$point[0]} {$point[1]}";
            }
            return 'LINESTRING(' . implode(', ', $points) . ')';
        }

        // polygon [[[x1, y1], [x2, y2], ...], ...]
        if (is_array($geometry[0]) && is_array($geometry[0][0]) && count($geometry[0][0]) === 2) {
            $rings = [];
            foreach ($geometry as $ring) {
                if (!is_array($ring)) {
                    throw new DatabaseException('Invalid ring format in polygon geometry');
                }
                $points = [];
                foreach ($ring as $point) {
                    if (!is_array($point) || count($point) !== 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                        throw new DatabaseException('Invalid point format in polygon ring');
                    }
                    $points[] = "{$point[0]} {$point[1]}";
                }
                $rings[] = '(' . implode(', ', $points) . ')';
            }
            return 'POLYGON(' . implode(', ', $rings) . ')';
        }

        throw new DatabaseException('Unrecognized geometry array format');
    }

    /**
     * Find Documents
     *
     * @param Document $collection
     * @param array<Query> $queries
     * @param int|null $limit
     * @param int|null $offset
     * @param array<string> $orderAttributes
     * @param array<string> $orderTypes
     * @param array<string, mixed> $cursor
     * @param string $cursorDirection
     * @param string $forPermission
     * @return array<Document>
     * @throws DatabaseException
     * @throws TimeoutException
     * @throws Exception
     */
    public function find(Document $collection, array $queries = [], ?int $limit = 25, ?int $offset = null, array $orderAttributes = [], array $orderTypes = [], array $cursor = [], string $cursorDirection = Database::CURSOR_AFTER, string $forPermission = Database::PERMISSION_READ): array
    {
        $collection = $collection->getId();
        $name = $this->filter($collection);
        $roles = $this->authorization->getRoles();
        $where = [];
        $orders = [];
        $alias = Query::DEFAULT_ALIAS;
        $binds = [];

        $queries = array_map(fn ($query) => clone $query, $queries);

        // Extract vector queries for ORDER BY
        $vectorQueries = [];
        $otherQueries = [];
        foreach ($queries as $query) {
            if (in_array($query->getMethod(), Query::VECTOR_TYPES)) {
                $vectorQueries[] = $query;
            } else {
                $otherQueries[] = $query;
            }
        }

        $queries = $otherQueries;

        $cursorWhere = [];

        foreach ($orderAttributes as $i => $originalAttribute) {
            $orderType = $orderTypes[$i] ?? Database::ORDER_ASC;

            // Handle random ordering
            if ($orderType === Database::ORDER_RANDOM) {
                $orders[] = $this->getRandomOrder();
                continue;
            }

            $attribute = $this->getInternalKeyForAttribute($originalAttribute);
            $attribute = $this->filter($attribute);

            $orderType = $this->filter($orderType);
            $direction = $orderType;

            if ($cursorDirection === Database::CURSOR_BEFORE) {
                $direction = ($direction === Database::ORDER_ASC)
                    ? Database::ORDER_DESC
                    : Database::ORDER_ASC;
            }

            $orders[] = "{$this->quote($attribute)} {$direction}";

            // Build pagination WHERE clause only if we have a cursor
            if (!empty($cursor)) {
                // Special case: No tie breaks. only 1 attribute and it's a unique primary key
                if (count($orderAttributes) === 1 && $i === 0 && $originalAttribute === '$sequence') {
                    $operator = ($direction === Database::ORDER_DESC)
                        ? Query::TYPE_LESSER
                        : Query::TYPE_GREATER;

                    $bindName = ":cursor_pk";
                    $binds[$bindName] = $cursor[$originalAttribute];

                    $cursorWhere[] = "{$this->quote($alias)}.{$this->quote($attribute)} {$this->getSQLOperator($operator)} {$bindName}";
                    break;
                }

                $conditions = [];

                // Add equality conditions for previous attributes
                for ($j = 0; $j < $i; $j++) {
                    $prevOriginal = $orderAttributes[$j];
                    $prevAttr = $this->filter($this->getInternalKeyForAttribute($prevOriginal));

                    $bindName = ":cursor_{$j}";
                    $binds[$bindName] = $cursor[$prevOriginal];

                    $conditions[] = "{$this->quote($alias)}.{$this->quote($prevAttr)} = {$bindName}";
                }

                // Add comparison for current attribute
                $operator = ($direction === Database::ORDER_DESC)
                    ? Query::TYPE_LESSER
                    : Query::TYPE_GREATER;

                $bindName = ":cursor_{$i}";
                $binds[$bindName] = $cursor[$originalAttribute];

                $conditions[] = "{$this->quote($alias)}.{$this->quote($attribute)} {$this->getSQLOperator($operator)} {$bindName}";

                $cursorWhere[] = '(' . implode(' AND ', $conditions) . ')';
            }
        }

        if (!empty($cursorWhere)) {
            $where[] = '(' . implode(' OR ', $cursorWhere) . ')';
        }

        $conditions = $this->getSQLConditionsForCollection($name, $queries, $binds);
        if (!empty($conditions)) {
            $where[] = $conditions;
        }

        if ($this->authorization->getStatus()) {
            $where[] = $this->getSQLPermissionsCondition($name, $roles, $alias, $forPermission);
        }

        if ($this->sharedTables) {
            $binds[':_tenant'] = $this->tenant;
            $where[] = "{$this->getTenantQuery($collection, $alias, condition: '')}";
        }

        $sqlWhere = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $vectorDistances = [];
        foreach ($vectorQueries as $query) {
            $vectorDistance = $this->getSQLVectorDistance($query, $binds, $alias);
            if ($vectorDistance) {
                $vectorDistances[] = $vectorDistance;
            }
        }

        if (!empty($vectorDistances)) {
            // Vector orders should come first for similarity search
            $orders = \array_merge($vectorDistances, $orders);
        }

        $sqlOrder = !empty($orders) ? 'ORDER BY ' . implode(', ', $orders) : '';

        $sqlLimit = '';
        if (! \is_null($limit)) {
            $binds[':limit'] = $limit;
            $sqlLimit = 'LIMIT :limit';
        }

        if (! \is_null($offset)) {
            $binds[':offset'] = $offset;
            $sqlLimit .= ' OFFSET :offset';
        }

        $selections = $this->getAttributeSelections($queries);

        $projection = $this->getAttributeProjection($selections, $alias);

        if (!empty($vectorDistances)) {
            $readable = $this->getSQLReadableDistance($vectorDistances[0]);
            $projection .= ", {$readable} AS {$this->quote(static::VECTOR_DISTANCE_COLUMN)}";
        }

        $sql = "
            SELECT {$projection}
            FROM {$this->getSQLTable($name)} AS {$this->quote($alias)}
            {$sqlWhere}
            {$sqlOrder}
            {$sqlLimit};
        ";

        $sql = $this->trigger(Database::EVENT_DOCUMENT_FIND, $sql);

        try {
            $stmt = $this->getPDO()->prepare($sql);

            foreach ($binds as $key => $value) {
                if (gettype($value) === 'double') {
                    $stmt->bindValue($key, $this->getFloatPrecision($value), \PDO::PARAM_STR);
                } else {
                    $stmt->bindValue($key, $value, $this->getPDOType($value));
                }
            }

            $this->execute($stmt);
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        $results = $stmt->fetchAll();
        $stmt->closeCursor();

        foreach ($results as $index => $document) {
            if (\array_key_exists('_uid', $document)) {
                $results[$index]['$id'] = $document['_uid'];
                unset($results[$index]['_uid']);
            }
            if (\array_key_exists('_id', $document)) {
                $results[$index]['$sequence'] = $document['_id'];
                unset($results[$index]['_id']);
            }
            if (\array_key_exists('_tenant', $document)) {
                $results[$index]['$tenant'] = $document['_tenant'];
                unset($results[$index]['_tenant']);
            }
            if (\array_key_exists('_createdAt', $document)) {
                $results[$index]['$createdAt'] = $document['_createdAt'];
                unset($results[$index]['_createdAt']);
            }
            if (\array_key_exists('_updatedAt', $document)) {
                $results[$index]['$updatedAt'] = $document['_updatedAt'];
                unset($results[$index]['_updatedAt']);
            }
            if (\array_key_exists('_permissions', $document)) {
                $results[$index]['$permissions'] = \json_decode($document['_permissions'] ?? '[]', true);
                unset($results[$index]['_permissions']);
            }
            if (\array_key_exists(static::VECTOR_DISTANCE_COLUMN, $document)) {
                $value = $document[static::VECTOR_DISTANCE_COLUMN];
                $results[$index][Database::VECTOR_DISTANCE] = \is_numeric($value) ? (float)$value : null;
                unset($results[$index][static::VECTOR_DISTANCE_COLUMN]);
            }

            $results[$index] = new Document($results[$index]);
        }

        if ($cursorDirection === Database::CURSOR_BEFORE) {
            $results = \array_reverse($results);
        }

        return $results;
    }

    /**
     * Count Documents
     *
     * @param Document $collection
     * @param array<Query> $queries
     * @param int|null $max
     * @return int
     * @throws Exception
     * @throws PDOException
     */
    public function count(Document $collection, array $queries = [], ?int $max = null): int
    {
        $collection = $collection->getId();
        $name = $this->filter($collection);
        $roles = $this->authorization->getRoles();
        $binds = [];
        $where = [];
        $alias = Query::DEFAULT_ALIAS;

        $limit = '';
        if (! \is_null($max)) {
            $binds[':limit'] = $max;
            $limit = 'LIMIT :limit';
        }

        $queries = array_map(fn ($query) => clone $query, $queries);

        $otherQueries = [];
        foreach ($queries as $query) {
            if (!in_array($query->getMethod(), Query::VECTOR_TYPES)) {
                $otherQueries[] = $query;
            }
        }

        $conditions = $this->getSQLConditionsForCollection($name, $otherQueries, $binds);
        if (!empty($conditions)) {
            $where[] = $conditions;
        }

        if ($this->authorization->getStatus()) {
            $where[] = $this->getSQLPermissionsCondition($name, $roles, $alias);
        }

        if ($this->sharedTables) {
            $binds[':_tenant'] = $this->tenant;
            $where[] = "{$this->getTenantQuery($collection, $alias, condition: '')}";
        }

        $sqlWhere = !empty($where)
            ? 'WHERE ' . \implode(' AND ', $where)
            : '';

        if (empty($limit)) {
            $sql = "
            SELECT COUNT(1) as sum
			FROM {$this->getSQLTable($name)} AS {$this->quote($alias)}
			{$sqlWhere}
        ";
        } else {
            $sql = "
			SELECT COUNT(1) as sum FROM (
				SELECT 1
				FROM {$this->getSQLTable($name)} AS {$this->quote($alias)}
                {$sqlWhere}
                {$limit}
			) table_count
        ";
        }

        $sql = $this->trigger(Database::EVENT_DOCUMENT_COUNT, $sql);

        $stmt = $this->getPDO()->prepare($sql);

        foreach ($binds as $key => $value) {
            $stmt->bindValue($key, $value, $this->getPDOType($value));
        }

        try {
            $this->execute($stmt);
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        $result = $stmt->fetchAll();
        $stmt->closeCursor();
        if (!empty($result)) {
            $result = $result[0];
        }

        return $result['sum'] ?? 0;
    }

    /**
     * Sum an Attribute
     *
     * @param Document $collection
     * @param string $attribute
     * @param array<Query> $queries
     * @param int|null $max
     * @return int|float
     * @throws Exception
     * @throws PDOException
     */
    public function sum(Document $collection, string $attribute, array $queries = [], ?int $max = null): int|float
    {
        $collection = $collection->getId();
        $name = $this->filter($collection);
        $attribute = $this->filter($attribute);
        $roles = $this->authorization->getRoles();
        $where = [];
        $alias = Query::DEFAULT_ALIAS;
        $binds = [];

        $limit = '';
        if (! \is_null($max)) {
            $binds[':limit'] = $max;
            $limit = 'LIMIT :limit';
        }

        $queries = array_map(fn ($query) => clone $query, $queries);

        $otherQueries = [];
        foreach ($queries as $query) {
            if (!in_array($query->getMethod(), Query::VECTOR_TYPES)) {
                $otherQueries[] = $query;
            }
        }

        $conditions = $this->getSQLConditionsForCollection($name, $otherQueries, $binds);
        if (!empty($conditions)) {
            $where[] = $conditions;
        }

        if ($this->authorization->getStatus()) {
            $where[] = $this->getSQLPermissionsCondition($name, $roles, $alias);
        }

        if ($this->sharedTables) {
            $binds[':_tenant'] = $this->tenant;
            $where[] = "{$this->getTenantQuery($collection, $alias, condition: '')}";
        }

        $sqlWhere = !empty($where)
            ? 'WHERE ' . \implode(' AND ', $where)
            : '';

        if (empty($limit)) {
            $sql = "
			SELECT SUM({$this->quote($attribute)}) as sum
			FROM {$this->getSQLTable($name)} AS {$this->quote($alias)}
			{$sqlWhere}
        ";
        } else {
            $sql = "
			SELECT SUM({$this->quote($attribute)}) as sum FROM (
				SELECT {$this->quote($attribute)}
				FROM {$this->getSQLTable($name)} AS {$this->quote($alias)}
				{$sqlWhere}
				{$limit}
			) table_count
        ";
        }


        $sql = $this->trigger(Database::EVENT_DOCUMENT_SUM, $sql);

        $stmt = $this->getPDO()->prepare($sql);

        foreach ($binds as $key => $value) {
            $stmt->bindValue($key, $value, $this->getPDOType($value));
        }

        try {
            $this->execute($stmt);
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        $result = $stmt->fetchAll();
        $stmt->closeCursor();
        if (!empty($result)) {
            $result = $result[0];
        }

        return $result['sum'] ?? 0;
    }

    public function getSpatialTypeFromWKT(string $wkt): string
    {
        $wkt = trim($wkt);
        $pos = strpos($wkt, '(');
        if ($pos === false) {
            throw new DatabaseException("Invalid spatial type");
        }
        return strtolower(trim(substr($wkt, 0, $pos)));
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

        /**
         * [0..3]   SRID (4 bytes, little-endian)
         * [4]      Byte order (1 = little-endian, 0 = big-endian)
         * [5..8]   Geometry type (with SRID flag bit)
         * [9..]    Geometry payload (coordinates, etc.)
         */

        if (strlen($wkb) < 25) {
            throw new DatabaseException('Invalid WKB: too short for POINT');
        }

        // 4 bytes SRID first → skip to byteOrder at offset 4
        $byteOrder = ord($wkb[4]);
        $littleEndian = ($byteOrder === 1);

        if (!$littleEndian) {
            throw new DatabaseException('Only little-endian WKB supported');
        }

        // After SRID (4) + byteOrder (1) + type (4) = 9 bytes
        $coordsBin = substr($wkb, 9, 16);
        if (strlen($coordsBin) !== 16) {
            throw new DatabaseException('Invalid WKB: missing coordinate bytes');
        }

        // Unpack two doubles
        $coords = unpack('d2', $coordsBin);
        if ($coords === false || !isset($coords[1], $coords[2])) {
            throw new DatabaseException('Invalid WKB: failed to unpack coordinates');
        }

        return [(float)$coords[1], (float)$coords[2]];
    }

    public function decodeLinestring(string $wkb): array
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

        // Skip 1 byte (endianness) + 4 bytes (type) + 4 bytes (SRID)
        $offset = 9;

        // Number of points (4 bytes little-endian)
        $numPointsArr = unpack('V', substr($wkb, $offset, 4));
        if ($numPointsArr === false || !isset($numPointsArr[1])) {
            throw new DatabaseException('Invalid WKB: cannot unpack number of points');
        }

        $numPoints = $numPointsArr[1];
        $offset += 4;

        $points = [];
        for ($i = 0; $i < $numPoints; $i++) {
            $xArr = unpack('d', substr($wkb, $offset, 8));
            $yArr = unpack('d', substr($wkb, $offset + 8, 8));

            if ($xArr === false || !isset($xArr[1]) || $yArr === false || !isset($yArr[1])) {
                throw new DatabaseException('Invalid WKB: cannot unpack point coordinates');
            }

            $points[] = [(float)$xArr[1], (float)$yArr[1]];
            $offset += 16;
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

        // Convert HEX string to binary if needed
        if (str_starts_with($wkb, '0x') || ctype_xdigit($wkb)) {
            $wkb = hex2bin(str_starts_with($wkb, '0x') ? substr($wkb, 2) : $wkb);
            if ($wkb === false) {
                throw new DatabaseException('Invalid hex WKB');
            }
        }

        if (strlen($wkb) < 21) {
            throw new DatabaseException('WKB too short to be a POLYGON');
        }

        // MySQL SRID-aware WKB layout: 4 bytes SRID prefix
        $offset = 4;

        $byteOrder = ord($wkb[$offset]);
        if ($byteOrder !== 1) {
            throw new DatabaseException('Only little-endian WKB supported');
        }
        $offset += 1;

        $typeArr = unpack('V', substr($wkb, $offset, 4));
        if ($typeArr === false || !isset($typeArr[1])) {
            throw new DatabaseException('Invalid WKB: cannot unpack geometry type');
        }

        $type = $typeArr[1];
        $hasSRID = ($type & 0x20000000) === 0x20000000;
        $geomType = $type & 0xFF;
        $offset += 4;

        if ($geomType !== 3) { // 3 = POLYGON
            throw new DatabaseException("Not a POLYGON geometry type, got {$geomType}");
        }

        // Skip SRID in type flag if present
        if ($hasSRID) {
            $offset += 4;
        }

        $numRingsArr = unpack('V', substr($wkb, $offset, 4));

        if ($numRingsArr === false || !isset($numRingsArr[1])) {
            throw new DatabaseException('Invalid WKB: cannot unpack number of rings');
        }

        $numRings = $numRingsArr[1];
        $offset += 4;

        $rings = [];

        for ($r = 0; $r < $numRings; $r++) {
            $numPointsArr = unpack('V', substr($wkb, $offset, 4));

            if ($numPointsArr === false || !isset($numPointsArr[1])) {
                throw new DatabaseException('Invalid WKB: cannot unpack number of points');
            }

            $numPoints = $numPointsArr[1];
            $offset += 4;
            $ring = [];

            for ($p = 0; $p < $numPoints; $p++) {
                $xArr = unpack('d', substr($wkb, $offset, 8));
                if ($xArr === false) {
                    throw new DatabaseException('Failed to unpack X coordinate from WKB.');
                }

                $x = (float) $xArr[1];

                $yArr = unpack('d', substr($wkb, $offset + 8, 8));
                if ($yArr === false) {
                    throw new DatabaseException('Failed to unpack Y coordinate from WKB.');
                }

                $y = (float) $yArr[1];

                $ring[] = [$x, $y];
                $offset += 16;
            }

            $rings[] = $ring;
        }

        return $rings;
    }

    public function setSupportForAttributes(bool $support): bool
    {
        return true;
    }

    public function getSupportForAlterLocks(): bool
    {
        return false;
    }

    public function getLockType(): string
    {
        if ($this->getSupportForAlterLocks() && $this->alterLocks) {
            return ',LOCK=SHARED';
        }

        return '';
    }

    public function getSupportForTransactionRetries(): bool
    {
        return true;
    }

    public function getSupportForNestedTransactions(): bool
    {
        return true;
    }
}
