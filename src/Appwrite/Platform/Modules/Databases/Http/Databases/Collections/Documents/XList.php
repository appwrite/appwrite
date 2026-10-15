<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Usage\Operations;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\ParsedQuery;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Http\Http;
use Utopia\Query\CursorDirection;
use Utopia\Query\Method as QueryMethod;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class XList extends Action
{
    private const array PAGING_METHODS = [
        QueryMethod::Limit,
        QueryMethod::Offset,
        QueryMethod::CursorAfter,
        QueryMethod::CursorBefore,
        QueryMethod::OrderAsc,
        QueryMethod::OrderDesc,
        QueryMethod::OrderRandom,
    ];

    public static function getName(): string
    {
        return 'listDocuments';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents')
            ->desc('List documents')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('usage.resource', 'database/{request.databaseId}/collection/{request.collectionId}/documents')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/list-documents.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.listRows',
                ),
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('transactionId', null, fn (Database $dbForProject) => new Nullable(new UID($dbForProject->getMaxUidLength())), 'Transaction ID to read uncommitted changes within the transaction.', true, ['dbForProject'])
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->param('ttl', 0, new Range(min: 0, max: 86400), 'TTL (seconds) for caching list responses. Responses are stored in an in-memory key-value cache, keyed per project, collection, schema version (attributes and indexes), caller authorization roles, and the exact query — so users with different permissions never share cached entries. Schema changes invalidate cached entries automatically; document writes do not, so choose a TTL you are comfortable serving as stale data. Set to 0 to disable caching. Lists with join queries are not cached. Must be between 0 and 86400 (24 hours).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('usage')
            ->inject('transactionState')
            ->inject('authorization')
            ->inject('utopia')
            ->inject('operations')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, array $queries, ?string $transactionId, bool $includeTotal, int $ttl, UtopiaResponse $response, Database $dbForProject, User $user, callable $getDatabasesDB, Context $usage, TransactionState $transactionState, Authorization $authorization, ?Http $utopia = null, Operations $operations = new Operations()): void
    {
        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || $this->isDatabaseTypeMismatch($database) || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $dbForDatabases = $getDatabasesDB($database, $collection);
        $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $documentId = $cursor->getValue();

            try {
                $cursorDocument = $dbForDatabases->resolveCursor(
                    $collectionTableId,
                    (string) $documentId,
                    $cursor->getMethod() === QueryMethod::CursorAfter ? CursorDirection::After : CursorDirection::Before,
                    $queries,
                );
            } catch (NotFoundException) {
                // The collection metadata document exists but the backing store (e.g. a
                // dedicated DocumentsDB shard) has no table for it. Treat this as a
                // not-found on the collection so the caller sees a 404 instead of a 500.
                throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
            } catch (QueryException $e) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }

            if ($cursorDocument->isEmpty()) {
                $type = ucfirst($this->getContext());
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "$type '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $dbStart = \microtime(true);

        try {
            $parsed = Query::groupByType($queries);
            $paged = $parsed->limit === null ? [...$queries, Query::limit(APP_LIMIT_LIST_DEFAULT)] : $queries;
            $find = match (true) {
                $parsed->isAggregation() => fn (): array => \array_map(
                    static fn (array $row): Document => new Document($row),
                    $dbForDatabases->aggregate($collectionTableId, $paged),
                ),
                $parsed->selections !== [] => fn (): array => $dbForDatabases->find($collectionTableId, $paged),
                default => fn (): array => $dbForDatabases->skipRelationships(fn (): array => $dbForDatabases->find($collectionTableId, $paged)),
            };

            if ($transactionId !== null) {
                $documents = \array_map(
                    fn (Document $document): Document => $this->stampTransactionRead($document, $databaseId, $collectionId),
                    $transactionState->listDocuments($database, $collectionTableId, $transactionId, $queries),
                );
                $total = $includeTotal ? $transactionState->countDocuments($database, $collectionTableId, $transactionId, $queries) : 0;
            } elseif ((int)$ttl > 0 && $parsed->joins === []) {
                $cache = $dbForProject->getCache();
                $cacheKey = $this->getListCacheKey($dbForProject, $database, $collectionId);
                $roles = $dbForProject->getAuthorization()->getRoles();
                $documentsField = $this->getListCacheField($collection, $roles, $queries, self::LIST_CACHE_FIELD_DOCUMENTS);
                $operationsField = $this->getListCacheField($collection, $roles, $queries, self::LIST_CACHE_FIELD_OPERATIONS);

                $documentsCacheHit = false;
                try {
                    $cachedDocuments = $cache->load($cacheKey, $ttl, $documentsField);
                } catch (\Throwable) {
                    $cachedDocuments = null;
                }

                if (\is_array($cachedDocuments)) {
                    $documents = \array_map(fn (array $document): Document => new Document($document), $cachedDocuments);
                    $documentsCacheHit = true;

                    try {
                        $cachedOperations = $cache->load($cacheKey, $ttl, $operationsField);
                    } catch (\Throwable) {
                        $cachedOperations = null;
                    }
                    $this->recordCachedOperations($operations, $documents, $cachedOperations);
                } else {
                    $documents = $find();

                    try {
                        if ($documents !== []) {
                            $cache->saveMany($cacheKey, [
                                $documentsField => \array_map(fn (Document $document): array => $document->getArrayCopy(), $documents),
                                $operationsField => $operations->counts($documents),
                            ]);
                        }
                    } catch (\Throwable) {
                    }
                }

                $total = 0;
                if ($includeTotal) {
                    $totalField = $this->getListCacheField($collection, $roles, $queries, self::LIST_CACHE_FIELD_TOTAL);
                    try {
                        $cachedTotal = $cache->load($cacheKey, $ttl, $totalField);
                    } catch (\Throwable) {
                        $cachedTotal = null;
                    }
                    if ($cachedTotal !== null && $cachedTotal !== false) {
                        $total = (int) $cachedTotal;
                    } else {
                        $total = $this->countRows($dbForDatabases, $collectionTableId, $queries, $parsed, $documents);
                        try {
                            $cache->save($cacheKey, (string) $total, $totalField);
                        } catch (\Throwable) {
                        }
                    }
                }

                $response->addHeader('X-Appwrite-Cache', $documentsCacheHit ? 'hit' : 'miss');
            } else {
                $documents = $find();
                $total = $includeTotal ? $this->countRows($dbForDatabases, $collectionTableId, $queries, $parsed, $documents) : 0;
            }
        } catch (NotFoundException) {
            // The collection metadata document exists but the backing store (e.g. a
            // dedicated DocumentsDB shard) has no table for it. Treat this as a
            // not-found on the collection so the caller sees a 404 instead of a 500.
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        } catch (OrderException $e) {
            $documents = $this->isCollectionsAPI() ? 'documents' : 'rows';
            $attribute = $this->isCollectionsAPI() ? 'attribute' : 'column';
            $message = "The order $attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all $documents order $attribute values are non-null.";
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, $message);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        } catch (Timeout) {
            throw new Exception(Exception::DATABASE_TIMEOUT);
        }

        $dbDurationMs = (\microtime(true) - $dbStart) * 1000;

        $usage
            ->setResource('database')
            ->setResourceId($database->getId())
            ->setResourceInternalId((string) $database->getSequence())
            ->addMetric($this->getDatabasesOperationReadMetric(), \max($operations->reads($documents), 1));

        $response->dynamic(new Document([
            'total' => $total,
            $this->getSDKGroup() => $documents,
        ]), $this->getResponseModel());

        try {
            $this->afterQuery($dbDurationMs, $database, $collection, $queries, $utopia);
        } catch (\Throwable) {
            // Observers must never break the response.
        }
    }

    /**
     * After query hook.
     *
     * @param array<Query> $queries
     */
    protected function afterQuery(float $dbDurationMs, Document $database, Document $collection, array $queries, ?Http $utopia): void
    {
    }

    /**
     * @param array<Query> $queries
     * @param array<Document> $page
     */
    private function countRows(Database $dbForDatabases, string $collectionTableId, array $queries, ParsedQuery $parsed, array $page): int
    {
        if (!$parsed->isAggregation() && !$parsed->distinct) {
            return $dbForDatabases->count($collectionTableId, $queries, APP_LIMIT_COUNT);
        }

        if (!$parsed->offset && $parsed->cursor === null && \count($page) < ($parsed->limit ?? APP_LIMIT_LIST_DEFAULT)) {
            return \count($page);
        }

        $unpaged = \array_values(\array_filter(
            $queries,
            static fn (Query $query): bool => !\in_array($query->getMethod(), self::PAGING_METHODS, true),
        ));
        $unpaged[] = Query::limit(APP_LIMIT_COUNT);

        $read = $parsed->isAggregation()
            ? fn (): array => $dbForDatabases->aggregate($collectionTableId, $unpaged)
            : fn (): array => $dbForDatabases->skipFilters(fn (): array => $dbForDatabases->skipRelationships(fn (): array => $dbForDatabases->find($collectionTableId, $unpaged)));

        return \count($dbForDatabases->silent($read));
    }

    /**
     * @param array<Document> $documents
     */
    private function recordCachedOperations(Operations $operations, array $documents, mixed $counts): void
    {
        $documents = \array_values($documents);
        if (!\is_array($counts) || !\array_is_list($counts) || \count($counts) !== \count($documents)) {
            return;
        }

        foreach ($counts as $count) {
            if (!\is_int($count) || $count < 1) {
                return;
            }
        }

        foreach ($documents as $index => $document) {
            $operations->record($document, $counts[$index]);
        }
    }
}
