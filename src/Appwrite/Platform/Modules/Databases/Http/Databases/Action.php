<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\Query;

class Action extends AppwriteAction
{
    public const LIST_CACHE_FIELD_DOCUMENTS = 'documents';
    public const LIST_CACHE_FIELD_TOTAL = 'total';

    private string $context = DATABASE_TYPE_LEGACY;

    public function getDatabaseType(): string
    {
        return $this->context;
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
            if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
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
    protected function getListCacheKey(Database $dbForProject, string $collectionId): string
    {
        return \sprintf(
            '%s-cache:%s:%s:%s:collection:%s',
            $dbForProject->getCacheName(),
            $dbForProject->getAdapter()->getHostname(),
            $dbForProject->getNamespace(),
            $dbForProject->getTenant(),
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
     * @param string $type LIST_CACHE_FIELD_DOCUMENTS or LIST_CACHE_FIELD_TOTAL
     */
    protected function getListCacheField(Document $collection, array $roles, array $queries, string $type): string
    {
        $schemaHash = \md5(
            \json_encode($collection->getAttribute('attributes', []))
            . \json_encode($collection->getAttribute('indexes', []))
        );

        $serialized = \array_map(
            static fn ($query) => $query instanceof Query ? $query->toArray() : $query,
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
    protected function purgeListCache(Database $dbForProject, string $collectionId): bool
    {
        return $dbForProject->getCache()->purge($this->getListCacheKey($dbForProject, $collectionId));
    }
}
