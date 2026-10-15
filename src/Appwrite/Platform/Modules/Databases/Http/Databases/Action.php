<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Feature\Relationships as FeatureRelationships;
use Utopia\Database\Adapter\Feature\Spatial as FeatureSpatial;
use Utopia\Database\Capability;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Operator;
use Utopia\Database\Query;
use Utopia\Query\Schema\ColumnType;

class Action extends AppwriteAction
{
    public const string LIST_CACHE_FIELD_DOCUMENTS = 'documents';
    public const string LIST_CACHE_FIELD_TOTAL = 'total';
    public const string LIST_CACHE_FIELD_OPERATIONS = 'operations';

    private const string GRANT_REFUSED = 'Permissions must be one of';

    private string $context = DATABASE_TYPE_LEGACY;

    public function getDatabaseType(): string
    {
        return $this->context;
    }

    protected function supportsDefinedAttributes(Adapter $adapter): bool
    {
        return $adapter->supports(Capability::DefinedAttributes);
    }

    protected function supportsSpatial(Adapter $adapter): bool
    {
        return $adapter->hasFeature(FeatureSpatial::class);
    }

    /**
     * Pool is a proxy and does not implement Feature interfaces, so instanceof
     * is always false. Ask the inner adapter via hasFeature().
     */
    protected function supportsRelationships(Adapter $adapter): bool
    {
        return $adapter->hasFeature(FeatureRelationships::class);
    }

    /**
     * Database types this path may operate on. Single source of truth shared by
     * the list filter ({@see getDatabaseTypeQueryFilters()}) and the by-id guard
     * ({@see isDatabaseTypeMismatch()}) so they cannot diverge.
     *
     * TablesDB and the legacy databases API are the same product either side of a
     * rename, so each serves both types; DocumentsDB and VectorsDB are scoped to
     * their own. Every database carries a non-null `type` (set on create and
     * backfilled to 'legacy' by migration V23), so null is not represented.
     *
     * @return string[]
     */
    protected function getAllowedDatabaseTypes(): array
    {
        return match ($this->getDatabaseType()) {
            DATABASE_TYPE_TABLESDB => [DATABASE_TYPE_TABLESDB, DATABASE_TYPE_LEGACY],
            DATABASE_TYPE_LEGACY => [DATABASE_TYPE_LEGACY, DATABASE_TYPE_TABLESDB],
            default => [$this->getDatabaseType()],
        };
    }

    /**
     * A database resolved by id must be one of the path's allowed types; otherwise
     * it is treated as not-found rather than proceeding to a type-mismatched backend
     * operation that surfaces as an opaque 500.
     *
     * The legacy path was previously exempt, which left it resolving by id what its
     * own list refused to return: `GET /v1/databases/{id}` answered for a DocumentsDB
     * or VectorsDB database that `GET /v1/databases` had never listed, and
     * `/collections` on one then 500'd against a table shaped for another product.
     */
    protected function isDatabaseTypeMismatch(Document $database): bool
    {
        return !in_array($database->getAttribute('type', ''), $this->getAllowedDatabaseTypes(), true);
    }

    /**
     * List filter for transactions on this path. Same allowed-type set as
     * {@see isTransactionTypeMismatch()} so list and by-id stay aligned.
     *
     * @return Query[]
     */
    protected function getTransactionTypeQueryFilters(): array
    {
        return [Query::equal('type', $this->getAllowedDatabaseTypes())];
    }

    /**
     * A transaction resolved by id must be one of the path's allowed types;
     * otherwise it is treated as not found, matching the list filter.
     *
     * Transactions created before `type` was stored have an empty value and
     * are treated as the legacy family. TablesDB and the legacy Databases
     * API serve that family; DocumentsDB and VectorsDB do not.
     */
    protected function isTransactionTypeMismatch(Document $transaction): bool
    {
        $type = $transaction->getAttribute('type', '');
        if ($type === '') {
            $type = DATABASE_TYPE_LEGACY;
        }

        return !\in_array($type, $this->getAllowedDatabaseTypes(), true);
    }

    public function setHttpPath(string $path): self
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = DATABASE_TYPE_TABLESDB;
        }
        if (\str_contains($path, '/documentsdb')) {
            $this->context = DATABASE_TYPE_DOCUMENTSDB;
        }
        if (\str_contains($path, '/vectorsdb')) {
            $this->context = DATABASE_TYPE_VECTORSDB;
        }
        parent::setHttpPath($path);
        return $this;
    }

    /**
     * Parse operator strings in data array and convert them to Operator objects.
     *
     * @param array $data The data array that may contain operator JSON strings or arrays
     * @param Document $collection The collection document to check for relationship attributes
     * @return array The data array with operators converted to Operator objects
     * @throws Exception If an operator string is invalid
     */
    protected function parseOperators(array $data, Document $collection): array
    {
        $relationshipKeys = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            if ($attribute->getAttribute('type') === ColumnType::Relationship->value) {
                $relationshipKeys[$attribute->getAttribute('key')] = true;
            }
        }

        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                if (\is_array($value)) {
                    $data[$key] = $this->parseOperators($value, $collection);
                }
                continue;
            }

            if (\str_starts_with($key, '$')) {
                continue;
            }

            if (isset($relationshipKeys[$key])) {
                continue;
            }

            // Handle operator as JSON string (from API requests)
            if (\is_string($value)) {
                $decoded = \json_decode($value, true);

                if (
                    \is_array($decoded) &&
                    isset($decoded['method']) &&
                    \is_string($decoded['method']) &&
                    Operator::isMethod($decoded['method'])
                ) {
                    try {
                        $data[$key] = Operator::parse($value);
                    } catch (\Exception $e) {
                        throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid operator for attribute "' . $key . '": ' . $e->getMessage());
                    }
                }
            }
            // Handle operator as array (from transaction logs after serialization)
            elseif (
                \is_array($value) &&
                isset($value['method']) &&
                \is_string($value['method']) &&
                Operator::isMethod($value['method'])
            ) {
                try {
                    $data[$key] = Operator::parseOperator($value);
                } catch (\Exception $e) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid operator for attribute "' . $key . '": ' . $e->getMessage());
                }
            } elseif (\is_array($value)) {
                $data[$key] = $this->parseOperators($value, $collection);
            }
        }

        return $data;
    }

    /**
     * Stable Redis key for a collection's cached list responses.
     *
     * All variations (schema × roles × queries) for a single collection live as
     * fields inside this one Redis hash, so purging every cached entry for a
     * collection is a single O(1) DEL regardless of how many variations have
     * been cached.
     */
    protected function getListCacheKey(Database $dbForProject, Document $database, string $collectionId): string
    {
        return \sprintf(
            '%s-cache:%s:%s:%s:database:%s:collection:%s',
            $dbForProject->getCacheName(),
            $dbForProject->getHostname() ?? '',
            $dbForProject->getNamespace(),
            $dbForProject->getTenant(),
            $database->getSequence(),
            $collectionId,
        );
    }

    /**
     * Hash field for a single variation of a cached list response.
     *
     * Scoped by the collection schema (attributes + indexes), the caller's
     * authorization roles, the exact query set, and the field type — so users
     * with different permissions never share entries.
     *
     * @param Document $collection Collection document (for schema hash)
     * @param array<mixed> $roles Caller authorization roles
     * @param array<Query|string> $queries Queries for this list call
     * @param string $type LIST_CACHE_FIELD_DOCUMENTS, LIST_CACHE_FIELD_TOTAL or LIST_CACHE_FIELD_OPERATIONS
     */
    protected function getListCacheField(Document $collection, array $roles, array $queries, string $type): string
    {
        $schemaHash = \md5(
            \json_encode($collection->getAttribute('attributes', []))
            . \json_encode($collection->getAttribute('indexes', []))
        );

        $serialized = \array_map(
            static fn (mixed $query): mixed => $query instanceof Query ? $query->toArray() : $query,
            $queries,
        );

        return \sprintf(
            '%s:%s:%s:%s',
            $schemaHash,
            \md5(\json_encode($roles)),
            \md5(\json_encode($serialized)),
            $type,
        );
    }

    /**
     * Purge every cached list response for a collection.
     *
     * One DEL on the collection's Redis hash, clearing all variations at once.
     */
    protected function purgeListCache(Database $dbForProject, Document $database, string $collectionId): bool
    {
        return $dbForProject->getCache()->purge($this->getListCacheKey($dbForProject, $database, $collectionId));
    }

    /**
     * The library refuses a related document granting a role the caller does not hold with the roles it holds, as
     * main's message did; any other refusal keeps the default message.
     */
    protected function unauthorized(AuthorizationException $error): Exception
    {
        $message = \str_starts_with($error->getMessage(), self::GRANT_REFUSED) ? $error->getMessage() : null;

        return new Exception(Exception::USER_UNAUTHORIZED, $message, previous: $error);
    }

    /**
     * Refuse a write when it is staged, not first when its transaction commits, answering as the write itself would.
     *
     * @param callable(): void $authorize Database::authorizeCreate() or Database::authorizeUpdate() for the staged write, judged on the transaction's staged view
     * @throws Exception
     */
    protected function authorizeStagedWrite(callable $authorize, string $notFoundType, string $collectionId): void
    {
        try {
            $authorize();
        } catch (AuthorizationException $error) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $error->getMessage(), previous: $error);
        } catch (NotFoundException $error) {
            throw new Exception($notFoundType, previous: $error, params: [$collectionId]);
        } catch (RelationshipException $error) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $error->getMessage(), previous: $error);
        } catch (StructureException $error) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, $error->getMessage(), previous: $error);
        }
    }
}
