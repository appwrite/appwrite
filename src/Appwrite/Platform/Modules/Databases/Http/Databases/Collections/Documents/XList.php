<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Auth\Auth;
use Appwrite\Databases\TransactionState;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
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
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
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
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('transactionId', null, new UID(), 'Transaction ID to read uncommitted changes within the transaction.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->inject('transactionState')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, array $queries, ?string $transactionId, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage, TransactionState $transactionState): void
    {
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException());
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = \reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $documentId = $cursor->getValue();

            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));

            if ($cursorDocument->isEmpty()) {
                $type = ucfirst($this->getContext());
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "$type '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $selectQueries = Query::groupByType($queries)['selections'] ?? [];
            $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

            // Use transaction-aware document retrieval if transactionId is provided
            if ($transactionId !== null) {
                $documents = $transactionState->listDocuments($collectionTableId, $transactionId, $queries);
                $total = $transactionState->countDocuments($collectionTableId, $transactionId, $queries);
            } elseif (! empty($selectQueries)) {
                // has selects, allow relationship on documents
                $documents = $dbForProject->find($collectionTableId, $queries);
                $total = $dbForProject->count($collectionTableId, $queries, APP_LIMIT_COUNT);
            } else {
                // has no selects, disable relationship loading on documents
                /* @type Document[] $documents */
                $documents = $dbForProject->skipRelationships(fn () => $dbForProject->find($collectionTableId, $queries));
                $total = $dbForProject->count($collectionTableId, $queries, APP_LIMIT_COUNT);
            }
        } catch (OrderException $e) {
            $documents = $this->isCollectionsAPI() ? 'documents' : 'rows';
            $attribute = $this->isCollectionsAPI() ? 'attribute' : 'column';
            $message = "The order $attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all $documents order $attribute values are non-null.";
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, $message);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
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
                operations: $operations,
            );
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_READS), $operations);

        $response->dynamic(new Document([
            'total' => $total,
            // rows or documents
            $this->getSDKGroup() => $documents,
        ]), $this->getResponseModel());
    }
}
