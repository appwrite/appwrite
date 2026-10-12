<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Databases\Engine;
use Appwrite\Databases\IntegerOperators;
use Appwrite\Databases\ListCache;
use Appwrite\Databases\RelatedPermissions;
use Appwrite\Databases\Support;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Appwrite\Utopia\Database\Attribute as AttributeDefinition;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Feature\Relationships as FeatureRelationships;
use Utopia\Database\Capability;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Operator;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

class Action extends AppwriteAction
{
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
        return Support::spatial($adapter);
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
        $integerArrays = $this->integerKeys($collection, array: true);

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
                        $data[$key] = $this->wholeItems(Operator::parse($value), isset($integerArrays[$key]));
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
                    $data[$key] = $this->wholeItems(Operator::parseOperator($value), isset($integerArrays[$key]));
                } catch (\Exception $e) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid operator for attribute "' . $key . '": ' . $e->getMessage());
                }
            } elseif (\is_array($value)) {
                $data[$key] = $this->parseOperators($value, $collection);
            }
        }

        return $data;
    }

    private function wholeItems(Operator $operator, bool $integerArray): Operator
    {
        return $integerArray ? IntegerOperators::wholeItems($operator) : $operator;
    }

    /**
     * The integer columns of a collection, keyed by attribute key: the arrays, or the single values.
     *
     * @return array<string, true>
     */
    private function integerKeys(Document $collection, bool $array): array
    {
        $keys = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            $type = $attribute->getAttribute('type');
            if (
                \is_string($type)
                && \in_array(AttributeDefinition::columnType($type), [ColumnType::Integer, ColumnType::BigInteger], true)
                && (bool) $attribute->getAttribute('array', false) === $array
            ) {
                $keys[$attribute->getAttribute('key')] = true;
            }
        }

        return $keys;
    }

    /**
     * Whether $data holds a numeric operator with a fractional amount or limit on an integer column, which needs
     * the stored value to answer as main did.
     *
     * @param array<string, mixed> $data
     */
    protected function hasFractionalIntegerOperators(array $data, Document $collection): bool
    {
        $keys = $this->integerKeys($collection, array: false);
        foreach ($data as $key => $value) {
            if ($value instanceof Operator && isset($keys[$key]) && IntegerOperators::fractional($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Answer each numeric operator with a fractional amount or limit on an integer column from $stored, as main's
     * engine did, with the whole-number change it amounts to.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function resolveIntegerOperators(array $data, Document $collection, Document $stored, Database $dbForDatabases): array
    {
        $keys = $this->integerKeys($collection, array: false);
        $operators = null;
        foreach ($data as $key => $value) {
            if ($value instanceof Operator && isset($keys[$key]) && IntegerOperators::fractional($value)) {
                $operators ??= new IntegerOperators(Engine::of($dbForDatabases));
                $data[$key] = $operators->resolve($value, $stored->getAttribute($key));
            }
        }

        return $data;
    }

    /**
     * resolveIntegerOperators() for every document of a bulk upsert, each against its own stored version, read in
     * one query per batch; a document not stored yet is answered from an empty one.
     *
     * @param array<Document> $documents
     * @return array<Document>
     */
    protected function resolveIntegerOperatorsOfDocuments(Database $dbForDatabases, string $collectionTableId, Document $collection, array $documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            if ($this->hasFractionalIntegerOperators($document->getArrayCopy(), $collection)) {
                $ids[] = $document->getId();
            }
        }
        if ($ids === []) {
            return $documents;
        }

        $stored = [];
        foreach (\array_chunk($ids, APP_DATABASE_QUERY_MAX_VALUES) as $chunk) {
            foreach ($dbForDatabases->getAuthorization()->skip(fn (): array => $dbForDatabases->find($collectionTableId, [Query::equal('$id', $chunk), Query::limit(\count($chunk))])) as $document) {
                $stored[$document->getId()] = $document;
            }
        }

        return \array_map(
            fn (Document $document): Document => \in_array($document->getId(), $ids, true)
                ? new Document($this->resolveIntegerOperators($document->getArrayCopy(), $collection, $stored[$document->getId()] ?? new Document(), $dbForDatabases))
                : $document,
            $documents,
        );
    }

    /**
     * A bulk update whose fractional integer operators change each document by its own amount, split into one
     * update per distinct change, each naming the documents it applies to.
     *
     * @param array<string, mixed> $data
     * @param array<Query> $queries
     * @return list<array{data: array<string, mixed>, queries: list<array<Query>>}>
     */
    protected function groupIntegerOperators(Database $dbForDatabases, string $collectionTableId, Document $collection, array $data, array $queries): array
    {
        $groups = [];
        foreach ($dbForDatabases->cursor($collectionTableId, $queries) as $document) {
            $resolved = $this->resolveIntegerOperators($data, $collection, $document, $dbForDatabases);
            $signature = \serialize(\array_map(
                fn (mixed $value): mixed => $value instanceof Operator ? $value->toArray() : null,
                $resolved,
            ));
            $groups[$signature]['data'] = $resolved;
            $groups[$signature]['ids'][] = $document->getId();
        }

        return \array_map(fn (array $group): array => [
            'data' => $group['data'],
            'queries' => \array_map(
                fn (array $ids): array => [Query::equal('$id', $ids)],
                \array_chunk($group['ids'], APP_DATABASE_QUERY_MAX_VALUES),
            ),
        ], \array_values($groups));
    }

    /**
     * Purge every cached list response for a collection.
     *
     * One DEL on the collection's Redis hash, clearing all variations at once.
     */
    protected function purgeListCache(Database $dbForProject, Document $database, string $collectionId): bool
    {
        return $dbForProject->getCache()->purge(ListCache::key($dbForProject, $database, $collectionId));
    }

    /**
     * @throws Exception
     */
    protected function validateRelatedPermissions(mixed $permissions, Document $current, Authorization $authorization): void
    {
        (new RelatedPermissions($authorization))->validate($permissions, $current);
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
        }
    }
}
