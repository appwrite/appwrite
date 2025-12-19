<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Bulk;

use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateDocuments';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents')
            ->desc('Update documents')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'documents.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-documents.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.updateRows',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('data', [], new JSON(), 'Document data as JSON object. Include only attribute and value pairs to be updated.', true, example: '{"username":"walter.obrien","email":"walter.obrien@example.com","fullName":"Walter O\'Brien","age":33,"isAdmin":false}')
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForStatsUsage')
            ->inject('queueForEvents')
            ->inject('queueForRealtime')
            ->inject('queueForFunctions')
            ->inject('queueForWebhooks')
            ->inject('plan')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string|array $data, array $queries, ?string $transactionId, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage, Event $queueForEvents, Event $queueForRealtime, Event $queueForFunctions, Event $queueForWebhooks, array $plan): void
    {
        $data = \is_string($data)
            ? \json_decode($data, true)
            : $data;

        if (empty($data)) {
            throw new Exception($this->getMissingPayloadException());
        }

        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        if ($transactionId === null) {
            $data = $this->parseOperators($data, $collection);
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk update is not supported for ' . $this->getSDKNamespace() . ' with relationship attributes');
        }

        $originalQueries = $queries;

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (isset($data['$permissions'])) {
            $validator = new Permissions();
            if (!$validator->isValid($data['$permissions'])) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, $validator->getDescription());
            }
        }

        $data = $this->removeReadonlyAttributes($data, privileged: true);

        // Handle transaction staging
        if ($transactionId !== null) {
            $transaction = $dbForProject->getDocument('transactions', $transactionId);
            if ($transaction->isEmpty() || $transaction->getAttribute('status', '') !== 'pending') {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid or non‑pending transaction');
            }

            // Enforce max operations per transaction
            $maxBatch = $plan['databasesTransactionSize'] ?? APP_LIMIT_DATABASE_TRANSACTION;
            $existing = $transaction->getAttribute('operations', 0);
            if (($existing + 1) > $maxBatch) {
                throw new Exception(
                    Exception::TRANSACTION_LIMIT_EXCEEDED,
                    'Transaction already has ' . $existing . ' operations, adding 1 would exceed the maximum of ' . $maxBatch
                );
            }

            // Stage the operation in transaction logs
            $staged = new Document([
                '$id' => ID::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'action' => 'bulkUpdate',
                'data' => [
                    'data' => $data,
                    'queries' => $originalQueries,
                ],
            ]);

            $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged) {
                $dbForProject->createDocument('transactionLogs', $staged);
                $dbForProject->increaseDocumentAttribute(
                    'transactions',
                    $transactionId,
                    'operations',
                );
            });

            $queueForEvents->reset();

            // Return successful response without actually updating documents
            $response->dynamic(new Document([
                $this->getSDKGroup() => [],
                'total' => 0, // Can't predict how many would be updated
            ]), $this->getResponseModel());
            return;
        }

        $documents = [];

        try {
            $modified = $dbForProject->withPreserveDates(function () use ($plan, &$documents, $dbForProject, $database, $collection, $data, $queries) {
                return $dbForProject->updateDocuments(
                    'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                    new Document($data),
                    $queries,
                    onNext: function (Document $document) use ($plan, &$documents) {
                        if (\count($documents) < ($plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH)) {
                            $documents[] = $document;
                        }
                    },
                );
            });
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception($this->getStructureException(), $e->getMessage());
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        foreach ($documents as $document) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$'.$this->getCollectionsEventsContext().'Id', $collection->getId());
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $modified))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $modified));

        $response->dynamic(new Document([
            'total' => $modified,
            $this->getSDKGroup() => $documents
        ]), $this->getResponseModel());

        $this->triggerBulk(
            'databases.[databaseId].collections.[collectionId].documents.[documentId].update',
            $database,
            $collection,
            $documents,
            $queueForEvents,
            $queueForRealtime,
            $queueForFunctions,
            $queueForWebhooks
        );
    }
}
