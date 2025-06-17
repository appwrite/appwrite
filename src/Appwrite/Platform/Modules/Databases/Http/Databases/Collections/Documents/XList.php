<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Auth\Auth;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
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
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class XList extends Action
{
    use HTTP;

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
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/list-documents.md',
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, array $queries, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

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
            $documents = $dbForProject->find('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $queries);
            $total = $dbForProject->count('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $queries, APP_LIMIT_COUNT);
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
        $trackOperations = true;
        $removeCollection = true;
        $context = compact('database', 'dbForProject', 'operations', 'collectionsCache', 'removeCollection', 'trackOperations');

        // Add $collectionId and $databaseId for all documents
        foreach ($documents as $document) {
            $this->resolveDocumentRelations(document: $document, collection: $collection, context: $context);
        }

        // get updated from the context
        $operations = $context['operations'];

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_READS), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        $select = \array_reduce($queries, function ($result, $query) {
            return $result || ($query->getMethod() === Query::TYPE_SELECT);
        }, false);

        // Check if the SELECT query includes $databaseId and $collectionId
        $hasDatabaseId = false;
        $hasCollectionId = false;
        if ($select) {
            $hasDatabaseId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$databaseId', $query->getValues()));
            }, false);
            $hasCollectionId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$collectionId', $query->getValues()));
            }, false);
        }

        if ($select) {
            foreach ($documents as $document) {
                if (!$hasDatabaseId) {
                    $document->removeAttribute('$databaseId');
                }
                if (!$hasCollectionId) {
                    $document->removeAttribute('$collectionId');
                }
            }
        }

        $response->dynamic(new Document([
            'total' => $total,
            // rows or documents
            $this->getSdkGroup() => $documents,
        ]), $this->getResponseModel());
    }
}
