<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Auth\Auth;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
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
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
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
                    replaceWith: 'tablesdb.getRow',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('documentId', '', new UID(), 'Document ID.')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $documentId, array $queries, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage): void
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

        try {
            $selects = Query::groupByType($queries)['selections'] ?? [];

            if (! empty($selects)) {
                // has selects, allow relationship on documents!
                $document = $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId, $queries);
            } else {
                // has no selects, disable relationship looping on documents!
                $document = $dbForProject->skipRelationships(fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId, $queries));
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
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_READS), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        $response->dynamic($document, $this->getResponseModel());
    }
}
