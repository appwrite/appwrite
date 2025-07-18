<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Bulk;

use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Delete extends Action
{
    public static function getName(): string
    {
        return 'deleteDocuments';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents')
            ->desc('Delete documents')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'documents.delete')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/delete-documents.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
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
            ->inject('plan')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, array $queries, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage, array $plan): void
    {
        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException());
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk delete is not supported for ' . $this->getSdkNamespace() . ' with relationship attributes');
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $documents = [];

        try {
            $modified = $dbForProject->deleteDocuments(
                'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                $queries,
                onNext: function (Document $document) use ($plan, &$documents) {
                    if (\count($documents) < ($plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH)) {
                        $documents[] = $document;
                    }
                },
            );
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (RestrictedException) {
            throw new Exception($this->getRestrictedException());
        }

        foreach ($documents as $document) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $modified))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $modified));

        $response->dynamic(new Document([
            'total' => $modified,
            $this->getSdkGroup() => $documents,
        ]), $this->getResponseModel());
    }
}
