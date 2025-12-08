<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Get extends Action
{
    public static function getName(): string
    {
        return 'getDocument';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
            ->desc('Get document')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-document.md',
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
                    replaceWith: 'tablesDB.getRow',
                ),
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).', false, ['dbForProject'])
            ->param('documentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Document ID.', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID to read uncommitted changes within the transaction.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForStatsUsage')
            ->inject('transactionState')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $documentId, array $queries, ?string $transactionId, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, StatsUsage $queueForStatsUsage, TransactionState $transactionState): void
    {
        $isAPIKey = User::isApp(Authorization::getRoles());
        $isPrivilegedUser = User::isPrivileged(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));

        $dbForDatabases = $getDatabasesDB($database);
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException());
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        try {
            $selects = Query::groupByType($queries)['selections'] ?? [];
            $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
            $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

            // Use transaction-aware document retrieval if transactionId is provided
            if ($transactionId !== null) {
                $document = $transactionState->getDocument($database, $collectionTableId, $documentId, $transactionId, $queries);
            } elseif (! empty($selects)) {
                // has selects, allow relationship on documents!
                $document = $dbForDatabases->getDocument($collectionTableId, $documentId, $queries);
            } else {
                // has no selects, disable relationship looping on documents!
                $document = $dbForDatabases->skipRelationships(fn () => $dbForDatabases->getDocument($collectionTableId, $documentId, $queries));
            }
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if ($document->isEmpty()) {
            throw new Exception($this->getNotFoundException());
        }

        $operations = 0;
        $collectionsCache = [];
        $this->processDocument(
            database: $database,
            collection: $collection,
            document: $document,
            dbForProject: $dbForProject,
            collectionsCache: $collectionsCache,
            operations: $operations
        );

        $queueForStatsUsage
            ->addMetric($this->getDatabasesOperationReadMetric(), max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), $this->getDatabasesIdOperationReadMetric()), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        $response->dynamic($document, $this->getResponseModel());
    }
}
