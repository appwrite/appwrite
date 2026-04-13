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
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class XList extends Action
{
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
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('transactionId', null, fn (Database $dbForProject) => new Nullable(new UID($dbForProject->getAdapter()->getMaxUIDLength())), 'Transaction ID to read uncommitted changes within the transaction.', true, ['dbForProject'])
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->param('ttl', 0, new Range(min: 0, max: 86400), 'TTL (seconds) for caching list responses. Responses are stored in an in-memory key-value cache, keyed per project, collection, schema version (attributes and indexes), caller authorization roles, and the exact query — so users with different permissions never share cached entries. Schema changes invalidate cached entries automatically; document writes do not, so choose a TTL you are comfortable serving as stale data. Set to 0 to disable caching. Must be between 0 and 86400 (24 hours).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('getDatabasesDB')
            ->inject('usage')
            ->inject('transactionState')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, array $queries, ?string $transactionId, bool $includeTotal, int $ttl, UtopiaResponse $response, Database $dbForProject, User $user, callable $getDatabasesDB, Context $usage, TransactionState $transactionState, Authorization $authorization): void
    {
        $isAPIKey = $user->isApp($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
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

        $dbForDatabases = $getDatabasesDB($database);
        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $documentId = $cursor->getValue();

            $cursorDocument = $authorization->skip(fn () => $dbForDatabases->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));

            if ($cursorDocument->isEmpty()) {
                $type = ucfirst($this->getContext());
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "$type '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $hasSelects = ! empty(Query::groupByType($queries)['selections'] ?? []);
            $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
            // When there are no select queries, relationship loading is skipped on the
            // underlying find() to avoid pulling related documents the caller did not ask for.
            $find = $hasSelects
                ? fn () => $dbForDatabases->find($collectionTableId, $queries)
                : fn () => $dbForDatabases->skipRelationships(fn () => $dbForDatabases->find($collectionTableId, $queries));

            // Use transaction-aware document retrieval if transactionId is provided
            if ($transactionId !== null) {
                $documents = $transactionState->listDocuments($database, $collectionTableId, $transactionId, $queries);
                $total = $includeTotal ? $transactionState->countDocuments($database, $collectionTableId, $transactionId, $queries) : 0;
            } elseif ((int)$ttl > 0) {
                $cacheKey = $this->getListCacheKey($dbForProject, $collectionId);
                $roles = $dbForProject->getAuthorization()->getRoles();
                $documentsField = $this->getListCacheField($collection, $roles, $queries, self::LIST_CACHE_FIELD_DOCUMENTS);

                $documentsCacheHit = false;
                try {
                    $cachedDocuments = $dbForProject->getCache()->load($cacheKey, $ttl, $documentsField);
                } catch (\Throwable) {
                    $cachedDocuments = null;
                }

                if ($cachedDocuments !== null &&
                    $cachedDocuments !== false &&
                    \is_array($cachedDocuments)) {
                    $documents = \array_map(function ($doc) {
                        return new Document($doc);
                    }, $cachedDocuments);
                    $documentsCacheHit = true;
                } else {
                    $documents = $find();

                    $documentsArray = \array_map(function ($doc) {
                        return $doc->getArrayCopy();
                    }, $documents);
                    try {
                        $dbForProject->getCache()->save($cacheKey, $documentsArray, $documentsField);
                    } catch (\Throwable) {
                    }
                }

                if ($includeTotal) {
                    $totalField = $this->getListCacheField($collection, $roles, $queries, self::LIST_CACHE_FIELD_TOTAL);
                    try {
                        $cachedTotal = $dbForProject->getCache()->load($cacheKey, $ttl, $totalField);
                    } catch (\Throwable) {
                        $cachedTotal = null;
                    }
                    if ($cachedTotal !== null && $cachedTotal !== false) {
                        $total = $cachedTotal;
                    } else {
                        $total = $dbForDatabases->count($collectionTableId, $queries, APP_LIMIT_COUNT);
                        try {
                            $dbForProject->getCache()->save($cacheKey, $total, $totalField);
                        } catch (\Throwable) {
                        }
                    }
                } else {
                    $total = 0;
                }

                $response->addHeader('X-Appwrite-Cache', $documentsCacheHit ? 'hit' : 'miss');
            } else {
                $documents = $find();
                $total = $includeTotal ? $dbForDatabases->count($collectionTableId, $queries, APP_LIMIT_COUNT) : 0;
            }
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

        $operations = 0;
        $collectionsCache = [];
        foreach ($documents as $document) {
            $this->processDocument(
                database: $database,
                collection: $collection,
                document: $document,
                dbForProject: $dbForProject,
                collectionsCache: $collectionsCache,
                authorization: $authorization,
                operations: $operations
            );
        }

        $usage
            ->addMetric($this->getDatabasesOperationReadMetric(), max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), $this->getDatabasesIdOperationReadMetric()), $operations);

        $response->dynamic(new Document([
            'total' => $total,
            // rows or documents
            $this->getSDKGroup() => $documents,
        ]), $this->getResponseModel());
    }
}
