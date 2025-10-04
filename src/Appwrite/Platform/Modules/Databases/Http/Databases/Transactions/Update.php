<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions;

use Appwrite\Databases\TransactionState;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateDatabasesTransaction';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TRANSACTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/transactions/:transactionId')
            ->desc('Update transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'updateTransaction',
                description: '/docs/references/databases/update-transaction.md',
                auth: [AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('transactionId', '', new UID(), 'Transaction ID.')
            ->param('commit', false, new Boolean(), 'Commit transaction?', true)
            ->param('rollback', false, new Boolean(), 'Rollback transaction?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('transactionState')
            ->inject('queueForDeletes')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('queueForRealtime')
            ->inject('queueForFunctions')
            ->inject('queueForWebhooks')
            ->callback($this->action(...));
    }

    /**
     * @param string $transactionId
     * @param bool $commit
     * @param bool $rollback
     * @param UtopiaResponse $response
     * @param Database $dbForProject
     * @param Document $user
     * @param TransactionState $transactionState
     * @param Delete $queueForDeletes
     * @param Event $queueForEvents
     * @param StatsUsage $queueForStatsUsage
     * @param Event $queueForRealtime
     * @param Event $queueForFunctions
     * @param Event $queueForWebhooks
     * @return void
     * @throws ConflictException
     * @throws Exception
     * @throws \Throwable
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Structure
     * @throws \Utopia\Exception
     */
    public function action(string $transactionId, bool $commit, bool $rollback, UtopiaResponse $response, Database $dbForProject, Document $user, TransactionState $transactionState, Delete $queueForDeletes, Event $queueForEvents, StatsUsage $queueForStatsUsage, Event $queueForRealtime, Event $queueForFunctions, Event $queueForWebhooks): void
    {

        if (!$commit && !$rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Either commit or rollback must be true');
        }
        if ($commit && $rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Cannot commit and rollback at the same time');
        }

        $transaction = Authorization::skip(fn () => $dbForProject->getDocument('transactions', $transactionId));
        if ($transaction->isEmpty()) {
            throw new Exception(Exception::TRANSACTION_NOT_FOUND);
        }
        if ($transaction->getAttribute('status', '') !== 'pending') {
            throw new Exception(Exception::TRANSACTION_NOT_READY);
        }

        $now = new \DateTime();
        $expiresAt = new \DateTime($transaction->getAttribute('expiresAt', 'now'));
        if ($now > $expiresAt) {
            throw new Exception(Exception::TRANSACTION_EXPIRED);
        }

        if ($commit) {

            $operations = [];

            // Track metrics for usage stats
            $totalOperations = 0;
            $databaseOperations = [];

            try {
                $dbForProject->withTransaction(function () use ($dbForProject, $transactionState, $queueForDeletes, $transactionId, &$transaction, &$operations, &$totalOperations, &$databaseOperations, $queueForEvents, $queueForStatsUsage, $queueForRealtime, $queueForFunctions, $queueForWebhooks) {
                    Authorization::skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'committing',
                    ])));

                    // Fetch operations ordered by sequence by default to replay operations in exact order they were created
                    $operations = Authorization::skip(fn () => $dbForProject->find('transactionLogs', [
                        Query::equal('transactionInternalId', [$transaction->getSequence()]),
                        Query::orderAsc(),
                        Query::limit(PHP_INT_MAX),
                    ]));


                    // Track transaction state for cross-operation visibility
                    $state = [];

                    foreach ($operations as $operation) {
                        $databaseInternalId = $operation['databaseInternalId'];
                        $collectionInternalId = $operation['collectionInternalId'];
                        $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
                        $documentId = $operation['documentId'];
                        $createdAt = new \DateTime($operation['$createdAt']);
                        $action = $operation['action'];
                        $data = $operation['data'];

                        // For delete operations, fetch the document before deleting for realtime events
                        if ($action === 'delete' && $documentId && empty($data)) {
                            $doc = $dbForProject->getDocument($collectionId, $documentId);
                            if (!$doc->isEmpty()) {
                                $operation['data'] = $doc->getArrayCopy();
                                $data = $operation['data'];
                            }
                        }

                        // Track operations for stats
                        $totalOperations++;
                        $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + 1;

                        if ($data instanceof Document) {
                            $data = $data->getArrayCopy();
                        }

                        // Execute the operation based on its type
                        switch ($action) {
                            case 'create':
                                $this->handleCreateOperation($dbForProject, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'update':
                                $this->handleUpdateOperation($dbForProject, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'upsert':
                                $this->handleUpsertOperation($dbForProject, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'delete':
                                $this->handleDeleteOperation($dbForProject, $collectionId, $documentId, $createdAt, $state);
                                break;
                            case 'increment':
                                $this->handleIncrementOperation($dbForProject, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'decrement':
                                $this->handleDecrementOperation($dbForProject, $collectionId, $documentId, $data, $createdAt, $state);
                                break;
                            case 'bulkCreate':
                                $this->handleBulkCreateOperation($dbForProject, $collectionId, $data, $createdAt, $state);
                                break;
                            case 'bulkUpdate':
                                $this->handleBulkUpdateOperation($dbForProject, $transactionState, $collectionId, $data, $createdAt, $state);
                                break;
                            case 'bulkUpsert':
                                $this->handleBulkUpsertOperation($dbForProject, $transactionState, $collectionId, $data, $createdAt, $state);
                                break;
                            case 'bulkDelete':
                                $this->handleBulkDeleteOperation($dbForProject, $transactionState, $collectionId, $data, $createdAt, $state);
                                break;
                        }
                    }

                    $transaction = Authorization::skip(fn () => $dbForProject->updateDocument(
                        'transactions',
                        $transactionId,
                        new Document(['status' => 'committed'])
                    ));

                    // Clear the transaction logs
                    $queueForDeletes
                        ->setType(DELETE_TYPE_DOCUMENT)
                        ->setDocument($transaction);
                });

            } catch (NotFoundException $e) {
                // Transaction has been rolled back, now mark it as failed
                Authorization::skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::DOCUMENT_NOT_FOUND, previous: $e);
            } catch (DuplicateException|ConflictException $e) {
                // Transaction has been rolled back, now mark it as failed
                Authorization::skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::TRANSACTION_CONFLICT, previous: $e);
            } catch (StructureException $e) {
                // Transaction has been rolled back, now mark it as failed
                Authorization::skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
            } catch (LimitException $e) {
                // Transaction has been rolled back, now mark it as failed
                Authorization::skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, $e->getMessage());
            } catch (TransactionException $e) {
                // Transaction has been rolled back, now mark it as failed
                Authorization::skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::TRANSACTION_FAILED, $e->getMessage());
            } catch (QueryException $e) {
                // Transaction has been rolled back, now mark it as failed
                Authorization::skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }

            $queueForStatsUsage
                ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, $totalOperations);

            // Add per-database metrics
            foreach ($databaseOperations as $sequence => $count) {
                $queueForStatsUsage->addMetric(
                    str_replace('{databaseInternalId}', $sequence, METRIC_DATABASE_ID_OPERATIONS_WRITES),
                    $count
                );
            }

            // Trigger realtime events for each operation

            foreach ($operations as $operation) {
                $databaseInternalId = $operation['databaseInternalId'];
                $collectionInternalId = $operation['collectionInternalId'];
                $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
                $action = $operation['action'];
                $documentId = $operation['documentId'];
                $data = $operation['data'];


                if ($data instanceof Document) {
                    $data = $data->getArrayCopy();
                }

                $database = Authorization::skip(fn () => $dbForProject->findOne('databases', [
                    Query::equal('$sequence', [$databaseInternalId])
                ]));

                $collection = Authorization::skip(fn () => $dbForProject->findOne('database_' . $databaseInternalId, [
                    Query::equal('$sequence', [$collectionInternalId])
                ]));

                $groupId = $this->getGroupId();
                $resourceId = $this->getResourceId();
                $contextKey = $this->getContext();
                $resource = $this->getResource();
                $resourcePlural = $resource . 's';

                $queueForEvents
                    ->setParam('databaseId', $database->getId())
                    ->setContext('database', $database)
                    ->setParam('collectionId', $collection->getId())
                    ->setParam('tableId', $collection->getId())
                    ->setContext($contextKey, $collection);

                $eventAction = '';
                $documentsToTrigger = [];

                switch ($action) {
                    case 'create':
                        $eventAction = 'create';
                        $docId = $documentId ?? $data['$id'] ?? null;
                        if ($docId) {
                            // Fetch the created document from the database
                            $doc = $dbForProject->getDocument($collectionId, $docId);
                            if (!$doc->isEmpty()) {
                                $documentsToTrigger[] = $doc;
                            }
                        }
                        break;
                    case 'update':
                    case 'increment':
                    case 'decrement':
                        $eventAction = 'update';
                        if ($documentId) {
                            // Fetch the updated document from the database
                            $doc = $dbForProject->getDocument($collectionId, $documentId);
                            if (!$doc->isEmpty()) {
                                $documentsToTrigger[] = $doc;
                            }
                        }
                        break;
                    case 'delete':
                        $eventAction = 'delete';
                        if ($documentId && !empty($data)) {
                            // For delete, use the fetched document data (fetched before deletion)
                            $documentsToTrigger[] = new Document(array_merge($data, ['$id' => $documentId]));
                        }
                        break;
                    case 'upsert':
                        $eventAction = 'update';  // Upsert is treated as update for events
                        $docId = $documentId ?? $data['$id'] ?? null;
                        if ($docId) {
                            // Fetch the upserted document from the database
                            $doc = $dbForProject->getDocument($collectionId, $docId);
                            if (!$doc->isEmpty()) {
                                $documentsToTrigger[] = $doc;
                            }
                        }
                        break;
                    case 'bulkCreate':
                    case 'bulkUpdate':
                    case 'bulkUpsert':
                    case 'bulkDelete':
                        break;
                }

                // Trigger events for each document

                $eventString = "databases.[databaseId].{$contextKey}s.[{$groupId}].{$resourcePlural}.[{$resourceId}]." . $eventAction;

                $queueForEvents->setEvent($eventString);

                foreach ($documentsToTrigger as $doc) {

                    // Add table/collection IDs to the payload for realtime channels
                    $payload = $doc->getArrayCopy();
                    $payload['$tableId'] = $collection->getId();
                    $payload['$collectionId'] = $collection->getId();

                    $queueForEvents
                        ->setParam('documentId', $doc->getId())
                        ->setParam('rowId', $doc->getId())
                        ->setPayload($payload);

                    $project = $queueForEvents->getProject();
                    $result = $queueForRealtime->from($queueForEvents)->trigger();

                    $queueForFunctions->from($queueForEvents)->trigger();
                    $queueForWebhooks->from($queueForEvents)->trigger();
                }

                $queueForEvents->reset();
                $queueForRealtime->reset();
                $queueForFunctions->reset();
                $queueForWebhooks->reset();
            }
        }

        if ($rollback) {
            $transaction = Authorization::skip(fn () => $dbForProject->updateDocument(
                'transactions',
                $transactionId,
                new Document(['status' => 'failed'])
            ));

            $queueForDeletes
                ->setType(DELETE_TYPE_DOCUMENT)
                ->setDocument($transaction);
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }

    /**
     * Handle create operation
     *
     * @param Database $dbForProject
     * @param string $collectionId
     * @param string|null $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     */
    private function handleCreateOperation(
        Database $dbForProject,
        string $collectionId,
        ?string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        if ($documentId && !isset($data['$id'])) {
            $data['$id'] = $documentId;
        }
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $data, &$state) {
            $state[$collectionId][$data['$id']] = $dbForProject->createDocument(
                $collectionId,
                new Document($data),
            );
        });
    }

    /**
     * Handle update operation
     *
     * @param Database $dbForProject
     * @param string $collectionId
     * @param string $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleUpdateOperation(
        Database $dbForProject,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Update the state document directly without timestamp wrapper
            $state[$collectionId][$documentId] = $dbForProject->updateDocument(
                $collectionId,
                $documentId,
                new Document($data),
            );
            return;
        }

        // Use timestamp wrapper for independent operations
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, $data, &$state) {
            $document = $dbForProject->updateDocument(
                $collectionId,
                $documentId,
                new Document($data),
            );
            if ($document->isEmpty()) {
                throw new NotFoundException('');
            }
            $state[$collectionId][$documentId] = $document;
        });
    }

    /**
     * Handle upsert operation
     *
     * @param Database $dbForProject
     * @param string $collectionId
     * @param string|null $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     */
    private function handleUpsertOperation(
        Database $dbForProject,
        string $collectionId,
        ?string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Upsert the state document directly without timestamp wrapper
            $state[$collectionId][$documentId] = $dbForProject->upsertDocument(
                $collectionId,
                new Document($data),
            );
            return;
        }

        // Use timestamp wrapper for independent operations
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, $data, &$state) {
            $state[$collectionId][$documentId] = $dbForProject->upsertDocument(
                $collectionId,
                new Document($data),
            );
        });
    }

    /**
     * Handle delete operation
     *
     * @param Database $dbForProject
     * @param string $collectionId
     * @param string $documentId
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws NotFoundException
     */
    private function handleDeleteOperation(
        Database $dbForProject,
        string $collectionId,
        string $documentId,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Delete without timestamp wrapper
            $dbForProject->deleteDocument($collectionId, $documentId);
            unset($state[$collectionId][$documentId]);
            return;
        }

        // Use timestamp wrapper for independent operations
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, &$state) {
            $deleted = $dbForProject->deleteDocument($collectionId, $documentId);
            if (!$deleted) {
                throw new NotFoundException('');
            }
            if (isset($state[$collectionId][$documentId])) {
                unset($state[$collectionId][$documentId]);
            }
        });
    }

    /**
     * Handle increment operation
     *
     * @param Database $dbForProject
     * @param string $collectionId
     * @param string $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleIncrementOperation(
        Database $dbForProject,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Increment without timestamp wrapper
            $state[$collectionId][$documentId] = $dbForProject->increaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $data[$this->getAttributeKey()],
                value: $data['value'] ?? 1,
                max: $data['max'] ?? null
            );
            return;
        }

        // Use timestamp wrapper for independent operations
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, $data) {
            $dbForProject->increaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $data[$this->getAttributeKey()],
                value: $data['value'] ?? 1,
                max: $data['max'] ?? null
            );
        });
    }

    /**
     * Handle decrement operation
     *
     * @param Database $dbForProject
     * @param string $collectionId
     * @param string $documentId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleDecrementOperation(
        Database $dbForProject,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Decrement without timestamp wrapper
            $state[$collectionId][$documentId] = $dbForProject->decreaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $data[$this->getAttributeKey()],
                value: $data['value'] ?? 1,
                min: $data['min'] ?? null
            );
            return;
        }

        // Use timestamp wrapper for independent operations
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, $data) {
            $dbForProject->decreaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $data[$this->getAttributeKey()],
                value: $data['value'] ?? 1,
                min: $data['min'] ?? null
            );
        });
    }

    /**
     * Handle bulk create operation
     *
     * @param Database $dbForProject
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     */
    private function handleBulkCreateOperation(
        Database $dbForProject,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $data, &$state) {
            // Convert data arrays to Document objects if needed
            $documents = \array_map(function ($doc) {
                return $doc instanceof Document ? $doc : new Document($doc);
            }, $data);

            $dbForProject->createDocuments(
                $collectionId,
                $documents,
                onNext: function (Document $document) use (&$state, $collectionId) {
                    $state[$collectionId][$document->getId()] = $document;
                }
            );
        });
    }

    /**
     * Handle bulk update operation with manual timestamp checking
     *
     * @param Database $dbForProject
     * @param TransactionState $transactionState
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     * @throws ConflictException
     */
    private function handleBulkUpdateOperation(
        Database $dbForProject,
        TransactionState $transactionState,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $queries = Query::parseQueries($data['queries'] ?? []);
        $updateData = new Document($data['data']);

        // First, update documents in the committed database
        $dbForProject->updateDocuments(
            $collectionId,
            $updateData,
            $queries,
            onNext: function (Document $updated, Document $old) use (&$state, $collectionId, $createdAt) {
                // Check if this document was created/modified in this transaction
                $dependent = isset($state[$collectionId][$updated->getId()]);

                // If not in transaction state, check for timestamp conflicts
                if (!$dependent) {
                    $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                    if ($oldUpdatedAt > $createdAt) {
                        throw new ConflictException('Document was updated after the request timestamp');
                    }
                }

                $state[$collectionId][$updated->getId()] = $updated;
            }
        );

        // Also update documents in the transaction state that match the query
        $transactionState->applyBulkUpdateToState($collectionId, $updateData, $queries, $state);
    }

    /**
     * Handle bulk upsert operation with manual timestamp checking
     *
     * @param Database $dbForProject
     * @param TransactionState $transactionState
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleBulkUpsertOperation(
        Database $dbForProject,
        TransactionState $transactionState,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        // Convert data arrays to Document objects if needed
        $documents = \array_map(function ($doc) {
            return $doc instanceof Document ? $doc : new Document($doc);
        }, $data);

        // First, apply upserts to documents in the transaction state
        // This ensures documents created in this transaction are updated properly
        $transactionState->applyBulkUpsertToState($collectionId, $documents, $state);

        // Then run bulk upsert on committed database, checking manually in callback
        $dbForProject->upsertDocuments(
            $collectionId,
            $documents,
            onNext: function (Document $upserted, ?Document $old) use (&$state, $collectionId, $createdAt) {
                if ($old !== null) {
                    // This is an update - check if document was created/modified in this transaction
                    $dependent = isset($state[$collectionId][$upserted->getId()]);

                    // If not in transaction state, check for timestamp conflicts
                    if (!$dependent) {
                        $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                        if ($oldUpdatedAt > $createdAt) {
                            throw new ConflictException('Document was updated after the request timestamp');
                        }
                    }
                }

                // If $old is null, this is a create operation - no timestamp check needed
                $state[$collectionId][$upserted->getId()] = $upserted;
            }
        );
    }

    /**
     * Handle bulk delete operation with manual timestamp checking
     *
     * @param Database $dbForProject
     * @param TransactionState $transactionState
     * @param string $collectionId
     * @param array $data
     * @param \DateTime $createdAt
     * @param array &$state
     * @return void
     * @throws \Utopia\Database\Exception\Query
     * @throws ConflictException
     * @throws \Utopia\Database\Exception
     */
    private function handleBulkDeleteOperation(
        Database $dbForProject,
        TransactionState $transactionState,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void {
        $queries = Query::parseQueries($data['queries'] ?? []);

        $dbForProject->deleteDocuments(
            $collectionId,
            $queries,
            onNext: function (Document $deleted, Document $old) use (&$state, $collectionId, $createdAt) {
                $dependent = isset($state[$collectionId][$deleted->getId()]);

                // If not in transaction state, check for timestamp conflicts
                if (!$dependent) {
                    $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                    if ($oldUpdatedAt > $createdAt) {
                        throw new ConflictException('Document was updated after the transaction operation');
                    }
                }

                // Remove from state after successful deletion
                if (isset($state[$collectionId][$deleted->getId()])) {
                    unset($state[$collectionId][$deleted->getId()]);
                }
            }
        );

        // Also delete documents in the transaction state that match the query
        $transactionState->applyBulkDeleteToState($collectionId, $queries, $state);
    }
}
