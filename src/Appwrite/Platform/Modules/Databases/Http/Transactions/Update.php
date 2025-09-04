<?php

namespace Appwrite\Platform\Modules\Databases\Http\Transactions;

use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateTransaction';
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
            ->label('scope', 'transactions.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'updateTransaction',
                description: '/docs/references/databases/update-transaction.md',
                auth: [AuthType::KEY],
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
            ->inject('queueForDeletes')
            ->callback($this->action(...));
    }

    /**
     * @param string $transactionId
     * @param bool $commit
     * @param bool $rollback
     * @param UtopiaResponse $response
     * @param Database $dbForProject
     * @param Delete $queueForDeletes
     * @return void
     * @throws ConflictException
     * @throws Exception
     * @throws \DateMalformedStringException
     * @throws \Throwable
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Structure
     * @throws \Utopia\Exception
     */
    public function action(string $transactionId, bool $commit, bool $rollback, UtopiaResponse $response, Database $dbForProject, Delete $queueForDeletes): void
    {
        if (!$commit && !$rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Either commit or rollback must be true');
        }
        if ($commit && $rollback) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Cannot commit and rollback at the same time');
        }

        $transaction = $dbForProject->getDocument('transactions', $transactionId);
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
            $dbForProject->withTransaction(function () use ($dbForProject, $queueForDeletes, $transactionId, &$transaction) {
                $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'committing',
                ]));

                // Fetch operations ordered by sequence by default to
                // replay operations in exact order they were created
                $operations = $dbForProject->find('transactionLogs', [
                    Query::equal('transactionInternalId', [$transaction->getSequence()]),
                ]);

                // Track transaction state for cross-operation visibility
                $state = [];

                try {
                    foreach ($operations as $operation) {
                        $databaseInternalId = $operation['databaseInternalId'];
                        $collectionInternalId = $operation['collectionInternalId'];
                        $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";
                        $documentId = $operation['documentId'];
                        $createdAt = new \DateTime($operation['$createdAt']);
                        $action = $operation['action'];
                        $data = $operation['data'];

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
                                $this->handleBulkUpdateOperation($dbForProject, $collectionId, $data, $createdAt, $state);
                                break;
                            case 'bulkUpsert':
                                $this->handleBulkUpsertOperation($dbForProject, $collectionId, $data, $createdAt, $state);
                                break;
                            case 'bulkDelete':
                                $this->handleBulkDeleteOperation($dbForProject, $collectionId, $data, $createdAt, $state);
                                break;
                        }
                    }

                    $transaction = $dbForProject->updateDocument(
                        'transactions',
                        $transactionId,
                        new Document(['status' => 'committed'])
                    );

                    // Clear the transaction logs
                    $queueForDeletes
                        ->setType(DELETE_TYPE_DOCUMENT)
                        ->setDocument($transaction);
                } catch (NotFoundException $e) {
                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'failed',
                    ]));
                    throw new Exception(Exception::DOCUMENT_NOT_FOUND, previous: $e);
                } catch (DuplicateException|ConflictException $e) {
                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'failed',
                    ]));
                    throw new Exception(Exception::TRANSACTION_CONFLICT, previous: $e);
                } catch (TransactionException $e) {
                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'failed',
                    ]));
                    throw new Exception(Exception::TRANSACTION_FAILED, $e->getMessage());
                }
            });
        }

        if ($rollback) {
            $transaction = $dbForProject->updateDocument('transactions', $transactionId, new Document([
                'status' => 'rolledBack',
            ]));

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
     * @throws \Utopia\Database\Exception
     */
    private function handleCreateOperation(
        Database $dbForProject,
        string $collectionId,
        ?string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
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
    ): void
    {
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
                throw new ConflictException('');
            }
            $state[$collectionId][$documentId] = $document;
        });
    }

    /**
     * Handle upsert operation
     * @throws \Utopia\Database\Exception
     */
    private function handleUpsertOperation(
        Database $dbForProject,
        string $collectionId,
        ?string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
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
     */
    private function handleDeleteOperation(
        Database $dbForProject,
        string $collectionId,
        string $documentId,
        \DateTime $createdAt,
        array &$state
    ): void
    {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Delete without timestamp wrapper
            $dbForProject->deleteDocument($collectionId, $documentId);
            unset($state[$collectionId][$documentId]);
            return;
        }

        // Use timestamp wrapper for independent operations
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $documentId, &$state) {
            $dbForProject->deleteDocument($collectionId, $documentId);
            if (isset($state[$collectionId][$documentId])) {
                unset($state[$collectionId][$documentId]);
            }
        });
    }

    /**
     * Handle increment operation
     */
    private function handleIncrementOperation(
        Database $dbForProject,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Increment without timestamp wrapper
            $state[$collectionId][$documentId] = $dbForProject->increaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $data['attribute'],
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
                attribute: $data['attribute'],
                value: $data['value'] ?? 1,
                max: $data['max'] ?? null
            );
        });
    }

    /**
     * Handle decrement operation
     */
    private function handleDecrementOperation(
        Database $dbForProject,
        string $collectionId,
        string $documentId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
        $dependent = isset($state[$collectionId][$documentId]);

        if ($dependent) {
            // Decrement without timestamp wrapper
            $state[$collectionId][$documentId] = $dbForProject->decreaseDocumentAttribute(
                collection: $collectionId,
                id: $documentId,
                attribute: $data['attribute'],
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
                attribute: $data['attribute'],
                value: $data['value'] ?? 1,
                min: $data['min'] ?? null
            );
        });
    }

    /**
     * Handle bulk create operation
     */
    private function handleBulkCreateOperation(
        Database $dbForProject,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
        $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $collectionId, $data, &$state) {
            $dbForProject->createDocuments(
                $collectionId,
                $data,
                onNext: function (Document $document) use (&$state, $collectionId) {
                    $state[$collectionId][$document->getId()] = $document;
                }
            );
        });
    }

    /**
     * Handle bulk update operation with manual timestamp checking
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Query
     * @throws ConflictException
     */
    private function handleBulkUpdateOperation(
        Database $dbForProject,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
        $queries = Query::parseQueries($data['queries'] ?? []);

        $dbForProject->updateDocuments(
            $collectionId,
            new Document($data['data']),
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
    }

    /**
     * Handle bulk upsert operation with manual timestamp checking
     * @throws ConflictException
     */
    private function handleBulkUpsertOperation(
        Database $dbForProject,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
        // Run bulk upsert without timestamp wrapper, checking manually in callback
        $dbForProject->upsertDocuments(
            $collectionId,
            $data,
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
     * @throws \Utopia\Database\Exception\Query
     * @throws ConflictException
     */
    private function handleBulkDeleteOperation(
        Database $dbForProject,
        string $collectionId,
        array $data,
        \DateTime $createdAt,
        array &$state
    ): void
    {
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
    }
}