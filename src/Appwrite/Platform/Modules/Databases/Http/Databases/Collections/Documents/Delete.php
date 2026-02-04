<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Event\Event;
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
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Nullable;

class Delete extends Action
{
    public static function getName(): string
    {
        return 'deleteDocument';
    }

    /**
     * 1. `SDKResponse` uses `UtopiaResponse::MODEL_NONE`.
     * 2. But we later need the actual return type for events queue below!
     */
    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
            ->desc('Delete document')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].delete')
            ->label('audits.event', 'document.delete')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{request.documentId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/delete-document.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.deleteRow',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('documentId', '', new UID(), 'Document ID.')
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('transactionState')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $databaseId,
        string $collectionId,
        string $documentId,
        ?string $transactionId,
        ?\DateTime $requestTimestamp,
        UtopiaResponse $response,
        Database $dbForProject,
        Event $queueForEvents,
        StatsUsage $queueForStatsUsage,
        TransactionState $transactionState,
        array $plan,
        Authorization $authorization
    ): void {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));

        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        // Read permission should not be required for delete
        $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

        if ($transactionId !== null) {
            // Use transaction-aware document retrieval to see changes from same transaction
            $document = $transactionState->getDocument($collectionTableId, $documentId, $transactionId);
        } else {
            $document = $authorization->skip(fn () => $dbForProject->getDocument($collectionTableId, $documentId));
        }

        if ($document->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$documentId]);
        }

        // Handle transaction staging
        if ($transactionId !== null) {
            $transaction = ($isAPIKey || $isPrivilegedUser)
                ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
                : $dbForProject->getDocument('transactions', $transactionId);
            if ($transaction->isEmpty()) {
                throw new Exception(Exception::TRANSACTION_NOT_FOUND, params: [$transactionId]);
            }
            if ($transaction->getAttribute('status', '') !== 'pending') {
                throw new Exception(Exception::TRANSACTION_NOT_READY);
            }

            $now = new \DateTime();
            $expiresAt = new \DateTime($transaction->getAttribute('expiresAt', 'now'));
            if ($now > $expiresAt) {
                throw new Exception(Exception::TRANSACTION_EXPIRED);
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
                'documentId' => $documentId,
                'action' => 'delete',
                'data' => [],
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

            // Return successful response without actually deleting document
            $response->noContent();
            return;
        }

        try {
            $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $database, $collection, $documentId) {
                $dbForProject->deleteDocument(
                    'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                    $documentId
                );
            });
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (RestrictedException) {
            throw new Exception($this->getRestrictedException());
        }

        $collectionsCache = [];

        $this->processDocument(
            database: $database,
            collection: $collection,
            document: $document,
            dbForProject: $dbForProject,
            collectionsCache: $collectionsCache,
            authorization: $authorization
        );

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, 1)
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), 1); // per collection

        $response->addHeader('X-Debug-Operations', 1);

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setContext('database', $database)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setParam('rowId', $document->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection)
            ->setPayload($response->output($document, $this->getResponseModel()), sensitive: $relationships);

        $response->noContent();
    }
}
