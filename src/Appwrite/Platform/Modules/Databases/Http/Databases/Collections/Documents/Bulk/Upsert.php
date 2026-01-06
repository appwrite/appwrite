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
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;

class Upsert extends Action
{
    public static function getName(): string
    {
        return 'upsertDocuments';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents')
            ->desc('Upsert documents')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'document.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: $this->getSDKNamespace(),
                    group: $this->getSDKGroup(),
                    name: self::getName(),
                    description: '/docs/references/databases/upsert-documents.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.upsertRows',
                    ),
                )
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('documents', [], fn (array $plan) => new ArrayList(new JSON(), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of document data as JSON objects. May contain partial documents.', false, ['plan'])
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

    public function action(string $databaseId, string $collectionId, array $documents, ?string $transactionId, UtopiaResponse $response, Database $dbForProject, StatsUsage $queueForStatsUsage, Event $queueForEvents, Event $queueForRealtime, Event $queueForFunctions, Event $queueForWebhooks, array $plan): void
    {
        $database = $dbForProject->getDocument('databases', $databaseId);
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk upsert is not supported for ' . $this->getSDKNamespace() .  ' with relationship attributes');
        }

        foreach ($documents as $key => $document) {
            if ($transactionId === null) {
                $document = $this->parseOperators($document, $collection);
            }
            $document = $this->removeReadonlyAttributes($document, privileged: true);
            $documents[$key] = new Document($document);
        }

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

            // Stage the operations in transaction logs
            $staged = new Document([
                '$id' => ID::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'action' => 'bulkUpsert',
                'data' => $documents,
            ]);

            $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged) {
                $dbForProject->createDocument('transactionLogs', $staged);
                $dbForProject->increaseDocumentAttribute(
                    'transactions',
                    $transactionId,
                    'operations',
                    1
                );
            });

            $queueForEvents->reset();

            // Return successful response without actually upserting documents
            $response->dynamic(new Document([
                $this->getSDKGroup() => [],
                'total' => \count($documents),
            ]), $this->getResponseModel());

            return;
        }

        $upserted = [];

        try {
            $modified = $dbForProject->withPreserveDates(function () use ($dbForProject, $database, $collection, $documents, $plan, &$upserted) {
                return $dbForProject->upsertDocuments(
                    'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                    $documents,
                    onNext: function (Document $document) use ($plan, &$upserted) {
                        if (\count($upserted) < ($plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH)) {
                            $upserted[] = $document;
                        }
                    },
                );
            });
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException(), params: ['multiple']);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception($this->getStructureException(), $e->getMessage());
        }

        foreach ($upserted as $document) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$'.$this->getCollectionsEventsContext().'Id', $collection->getId());
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $modified))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $modified));

        $response->dynamic(new Document([
            'total' => $modified,
            $this->getSDKGroup() => $upserted
        ]), $this->getResponseModel());

        $this->triggerBulk(
            'databases.[databaseId].collections.[collectionId].documents.[documentId].upsert',
            $database,
            $collection,
            $upserted,
            $queueForEvents,
            $queueForRealtime,
            $queueForFunctions,
            $queueForWebhooks
        );
    }
}
