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
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
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
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
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
            ->inject('authorization')
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
    public function action(string $transactionId, bool $commit, bool $rollback, UtopiaResponse $response, Database $dbForProject, Document $user, TransactionState $transactionState, Delete $queueForDeletes, Event $queueForEvents, StatsUsage $queueForStatsUsage, Event $queueForRealtime, Event $queueForFunctions, Event $queueForWebhooks, Authorization $authorization): void
    {
        if (!$commit && !$rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Either commit or rollback must be true');
        }
        if ($commit && $rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Cannot commit and rollback at the same time');
        }

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

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

        if ($commit) {

            $operations = [];
            $totalOperations = 0;
            $databaseOperations = [];
            $currentDocumentId = null;

            try {
                $dbForProject->withTransaction(function () use ($dbForProject, $transactionState, $queueForDeletes, $transactionId, &$transaction, &$operations, &$totalOperations, &$databaseOperations, &$currentDocumentId, $queueForEvents, $queueForStatsUsage, $queueForRealtime, $queueForFunctions, $queueForWebhooks, $authorization) {
                    $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'committing',
                    ])));

                    $operations = $authorization->skip(fn () => $dbForProject->find('transactionLogs', [
                        Query::equal('transactionInternalId', [$transaction->getSequence()]),
                        Query::orderAsc(),
                        Query::limit(PHP_INT_MAX),
                    ]));

                    $state = [];
                    $collections = [];

                    foreach ($operations as $operation) {
                        $databaseInternalId = $operation['databaseInternalId'];
                        $collectionInternalId = $operation['collectionInternalId'];
                        $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
                        $documentId = $operation['documentId'];
                        $currentDocumentId = $documentId;
                        $createdAt = new \DateTime($operation['$createdAt']);
                        $action = $operation['action'];
                        $data = $operation['data'];

                        if ($data instanceof Document) {
                            $data = $data->getArrayCopy();
                        }

                        if (!isset($collections[$collectionId])) {
                            $collections[$collectionId] = $authorization->skip(
                                fn () => $dbForProject->getCollection($collectionId)
                            );
                        }
                        $collection = $collections[$collectionId];

                        if (\is_array($data) && !empty($data)) {
                            $data = $this->parseOperators($data, $collection);
                        }

                        if ($action === 'delete' && $documentId && empty($data)) {
                            $doc = $dbForProject->getDocument($collectionId, $documentId);
                            if (!$doc->isEmpty()) {
                                $operation['data'] = $doc->getArrayCopy();
                                $data = $operation['data'];
                            }
                        }

                        if (!\in_array($action, ['bulkCreate', 'bulkUpdate', 'bulkUpsert', 'bulkDelete'])) {
                            $totalOperations++;
                            $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + 1;
                        }

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
                                $count = $this->handleBulkCreateOperation($dbForProject, $collectionId, $data, $createdAt, $state);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                            case 'bulkUpdate':
                                $count = $this->handleBulkUpdateOperation($dbForProject, $transactionState, $collectionId, $data, $createdAt, $state);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                            case 'bulkUpsert':
                                $count = $this->handleBulkUpsertOperation($dbForProject, $transactionState, $collectionId, $data, $createdAt, $state);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                            case 'bulkDelete':
                                $count = $this->handleBulkDeleteOperation($dbForProject, $transactionState, $collectionId, $data, $createdAt, $state);
                                $totalOperations += $count;
                                $databaseOperations[$databaseInternalId] = ($databaseOperations[$databaseInternalId] ?? 0) + $count;
                                break;
                        }
                    }

                    $transaction = $authorization->skip(fn () => $dbForProject->updateDocument(
                        'transactions',
                        $transactionId,
                        new Document(['status' => 'committed'])
                    ));

                    $queueForDeletes
                        ->setType(DELETE_TYPE_DOCUMENT)
                        ->setDocument($transaction);
                });
            } catch (NotFoundException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));

                throw new Exception(Exception::DOCUMENT_NOT_FOUND, previous: $e, params: [$currentDocumentId ?? 'unknown']);
            } catch (DuplicateException | ConflictException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::TRANSACTION_CONFLICT, previous: $e);
            } catch (StructureException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
            } catch (LimitException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, $e->getMessage());
            } catch (TransactionException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::TRANSACTION_FAILED, $e->getMessage());
            } catch (QueryException $e) {
                $authorization->skip(fn () => $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'failed',
                ])));
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }

            $queueForStatsUsage
                ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, $totalOperations);

            foreach ($databaseOperations as $sequence => $count) {
                $queueForStatsUsage->addMetric(
                    str_replace('{databaseInternalId}', $sequence, METRIC_DATABASE_ID_OPERATIONS_WRITES),
                    $count
                );
            }

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

                $database = $authorization->skip(fn () => $dbForProject->findOne('databases', [
                    Query::equal('$sequence', [$databaseInternalId])
                ]));

                $collection = $authorization->skip(fn () => $dbForProject->findOne('database_' . $databaseInternalId, [
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
                            $doc = $dbForProject->getDocument($collectionId, $documentId);
                            if (!$doc->isEmpty()) {
                                $documentsToTrigger[] = $doc;
                            }
                        }
                        break;
                    case 'delete':
                        $eventAction = 'delete';
                        if ($documentId && !empty($data)) {
                            $documentsToTrigger[] = new Document(array_merge($data, ['$id' => $documentId]));
                        }
                        break;
                    case 'upsert':
                        $eventAction = 'update';
                        $docId = $documentId ?? $data['$id'] ?? null;
                        if ($docId) {
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

                $eventString = "databases.[databaseId].{$contextKey}s.[{$groupId}].{$resourcePlural}.[{$resourceId}]." . $eventAction;

                $queueForEvents->setEvent($eventString);

                foreach ($documentsToTrigger as $doc) {
                    $payload = $doc->getArrayCopy();
                    $payload['$tableId'] = $collection->getId();
                    $payload['$collectionId'] = $collection->getId();

                    $queueForEvents
                        ->setParam('documentId', $doc->getId())
                        ->setParam('rowId', $doc->getId())
                        ->setPayload($payload);

                    $queueForRealtime->from($queueForEvents)->trigger();
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
            $transaction = $authorization->skip(fn () => $dbForProject->updateDocument(
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
            $doc = $dbForProject->createDocument(
                $collectionId,
                new Document($data),
            );
            $state[$collectionId][$doc->getId()] = $doc;
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
            $state[$collectionId][$documentId] = $dbForProject->updateDocument(
                $collectionId,
                $documentId,
                new Document($data),
            );
            return;
        }

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
            // Merge partial upsert data with full document from transaction state
            $existingDoc = $state[$collectionId][$documentId];
            foreach ($data as $key => $value) {
                if ($key !== '$id') {
                    $existingDoc->setAttribute($key, $value);
                }
            }

            $state[$collectionId][$documentId] = $dbForProject->upsertDocument(
                $collectionId,
                $existingDoc,
            );
            return;
        }

        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $data, &$state) {
            $doc = $dbForProject->upsertDocument(
                $collectionId,
                new Document($data),
            );
            $state[$collectionId][$doc->getId()] = $doc;
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
            $dbForProject->deleteDocument($collectionId, $documentId);
            unset($state[$collectionId][$documentId]);
            return;
        }

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
     * Get the attribute/column name from data, with fallback for cross-API compatibility
     *
     * @param array $data The operation data
     * @return string The attribute/column name
     */
    private function getAttributeNameFromData(array $data): string
    {
        $expectedKey = $this->getAttributeKey();
        if (isset($data[$expectedKey])) {
            return $data[$expectedKey];
        }

        // Try the opposite key for cross-API compatibility
        $fallbackKey = $expectedKey === 'attribute' ? 'column' : 'attribute';
        if (isset($data[$fallbackKey])) {
            return $data[$fallbackKey];
        }

        return '';
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
        $attribute = $this->getAttributeNameFromData($data);

        if ($dependent) {
            $state[$collectionId][$documentId] = $dbForProject->increaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $attribute,
                value: $data['value'] ?? 1,
                max: $data['max'] ?? null
            );
            return;
        }

        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, $data, &$state, $attribute) {
            $state[$collectionId][$documentId] = $dbForProject->increaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $attribute,
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
        $attribute = $this->getAttributeNameFromData($data);

        if ($dependent) {
            $state[$collectionId][$documentId] = $dbForProject->decreaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $attribute,
                value: $data['value'] ?? 1,
                min: $data['min'] ?? null
            );
            return;
        }

        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, $data, &$state, $attribute) {
            $state[$collectionId][$documentId] = $dbForProject->decreaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $attribute,
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
     * @return int Number of documents created
     * @throws \Utopia\Database\Exception
     */
    private function handleBulkCreateOperation(
        Database $dbForProject,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): int {
        $count = 0;
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $data, &$state, &$count) {
            $documents = \array_map(function ($doc) {
                return $doc instanceof Document ? $doc : new Document($doc);
            }, $data);

            $count = $dbForProject->createDocuments(
                $collectionId,
                $documents,
                onNext: function (Document $document) use (&$state, $collectionId) {
                    $state[$collectionId][$document->getId()] = $document;
                }
            );
        });
        return $count;
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
     * @return int Number of documents updated
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
    ): int {
        $queries = Query::parseQueries($data['queries'] ?? []);
        $updateData = new Document($data['data']);

        $dependentDocs = [];

        $transactionState->applyBulkUpdateToState($collectionId, $updateData, $queries, $state);

        // Clone the document before passing to updateDocuments to prevent mutation
        // The database layer mutates the input document, which would corrupt transaction state
        $count = $dbForProject->updateDocuments(
            $collectionId,
            clone $updateData,
            $queries,
            onNext: function (Document $updated, Document $old) use (&$state, $collectionId, $createdAt, &$dependentDocs) {
                $dependent = isset($state[$collectionId][$updated->getId()]);

                if ($dependent) {
                    $dependentDocs[] = $updated->getId();
                } else {
                    $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                    if ($oldUpdatedAt > $createdAt) {
                        throw new ConflictException('Document was updated after the request timestamp');
                    }
                    $state[$collectionId][$updated->getId()] = $updated;
                }
            }
        );

        // Re-write dependent documents from state to database to fix partial updates
        if (!empty($dependentDocs)) {
            $documentsToRewrite = [];
            foreach ($dependentDocs as $docId) {
                if (isset($state[$collectionId][$docId])) {
                    $documentsToRewrite[] = $state[$collectionId][$docId];
                }
            }

            if (!empty($documentsToRewrite)) {
                $dbForProject->upsertDocuments(
                    $collectionId,
                    $documentsToRewrite,
                    onNext: function (Document $upserted) use (&$state, $collectionId) {
                        $state[$collectionId][$upserted->getId()] = $upserted;
                    }
                );
            }
        }

        return $count;
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
     * @return int Number of documents upserted
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
    ): int {
        $documents = \array_map(function ($doc) {
            return $doc instanceof Document ? $doc : new Document($doc);
        }, $data);

        $mergedDocuments = $transactionState->applyBulkUpsertToState($collectionId, $documents, $state);

        $count = $dbForProject->upsertDocuments(
            $collectionId,
            $mergedDocuments,
            onNext: function (Document $upserted, ?Document $old) use (&$state, $collectionId, $createdAt) {
                if ($old !== null) {
                    $dependent = isset($state[$collectionId][$upserted->getId()]);

                    if (!$dependent) {
                        $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                        if ($oldUpdatedAt > $createdAt) {
                            throw new ConflictException('Document was updated after the request timestamp');
                        }
                    }
                }

                $state[$collectionId][$upserted->getId()] = $upserted;
            }
        );

        return $count;
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
     * @return int Number of documents deleted
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
    ): int {
        $queries = Query::parseQueries($data['queries'] ?? []);

        $count = $dbForProject->deleteDocuments(
            $collectionId,
            $queries,
            onNext: function (Document $deleted, Document $old) use (&$state, $collectionId, $createdAt) {
                $dependent = isset($state[$collectionId][$deleted->getId()]);

                if (!$dependent) {
                    $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                    if ($oldUpdatedAt > $createdAt) {
                        throw new ConflictException('Document was updated after the transaction operation');
                    }
                }

                if (isset($state[$collectionId][$deleted->getId()])) {
                    unset($state[$collectionId][$deleted->getId()]);
                }
            }
        );

        $transactionState->applyBulkDeleteToState($collectionId, $queries, $state);

        return $count;
    }
}
