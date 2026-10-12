<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Databases\ListCache;
use Appwrite\Databases\RelatedPermissions;
use Appwrite\Databases\Support;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
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
     * The library judges the write against stored documents, so a refusal that may rest on a document the
     * transaction already stages a change to (a delete, a create, an update) is left to the commit, which sees it.
     *
     * @param callable(): void $authorize Database::authorizeCreate() or Database::authorizeUpdate() for the staged write
     * @param array<string, mixed> $data The staged write's data
     * @param array<Document> $pending Operations staged earlier in the same request, not stored yet
     * @throws Exception
     */
    protected function authorizeStagedWrite(callable $authorize, Database $dbForProject, Document $transaction, ?string $documentId, array $data, array $pending, string $notFoundType, string $collectionId): void
    {
        try {
            $authorize();
        } catch (AuthorizationException|NotFoundException|RelationshipException $error) {
            if ($this->stagesAny($dbForProject, $transaction, [$documentId, ...$this->values($data)], $pending)) {
                return;
            }

            throw match (true) {
                $error instanceof AuthorizationException => new Exception(Exception::USER_UNAUTHORIZED, $error->getMessage(), previous: $error),
                $error instanceof NotFoundException => new Exception($notFoundType, previous: $error, params: [$collectionId]),
                default => new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $error->getMessage(), previous: $error),
            };
        }
    }

    /**
     * @param array<mixed> $references
     * @param array<Document> $pending
     */
    private function stagesAny(Database $dbForProject, Document $transaction, array $references, array $pending): bool
    {
        $references = \array_flip(\array_filter($references, fn (mixed $reference): bool => \is_string($reference) && $reference !== ''));
        $stored = (int) $transaction->getAttribute('operations', 0);
        $operations = $stored === 0 ? [] : $dbForProject->getAuthorization()->skip(fn (): array => $dbForProject->find('transactionLogs', [
            Query::equal('transactionInternalId', [$transaction->getSequence()]),
            Query::limit($stored),
        ]));

        foreach ([...$operations, ...$pending] as $operation) {
            $data = $operation->getAttribute('data', []);
            $data = $data instanceof Document ? $data->getArrayCopy() : $data;

            foreach ([$operation->getAttribute('documentId'), ...$this->ids(\is_array($data) ? $data : [])] as $id) {
                if (\is_string($id) && isset($references[$id])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every `$id` in staged data, at any depth: the documents the operation writes, nested ones included.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function ids(array $data): array
    {
        $ids = [$data['$id'] ?? null];
        foreach ($data as $value) {
            if ($value instanceof Document) {
                $value = $value->getArrayCopy();
            }
            if (\is_array($value)) {
                \array_push($ids, ...$this->ids($value));
            }
        }

        return $ids;
    }

    /**
     * Every scalar in a write's data, at any depth: whatever documents it may name, by `$id` or by a linked ID.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function values(array $data): array
    {
        $values = [];
        foreach ($data as $value) {
            if ($value instanceof Document) {
                $value = $value->getArrayCopy();
            }
            if (\is_array($value)) {
                \array_push($values, ...$this->values($value));
            } else {
                $values[] = $value;
            }
        }

        return $values;
    }
}
