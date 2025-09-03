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
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
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

                        // Check if this operation depends on documents created in same transaction
                        $dependent = \in_array($action, ['update', 'increment', 'decrement'])
                            && isset($state[$collectionId][$documentId]);

                        if ($dependent) {
                            // Don't use timestamp wrapper for dependent operations
                            switch ($action) {
                                case 'update':
                                    // Update the state document directly
                                    $existing = $state[$collectionId][$documentId];
                                    foreach ($data as $key => $value) {
                                        $existing->setAttribute($key, $value);
                                    }
                                    $state[$collectionId][$documentId] = $dbForProject->updateDocument(
                                        $collectionId,
                                        $documentId,
                                        $existing
                                    );
                                    break;

                                case 'increment':
                                    $state[$collectionId][$documentId] = $dbForProject->increaseDocumentAttribute(
                                        collection: $collectionId,
                                        id: $documentId,
                                        attribute: $data['attribute'],
                                        value: $data['value'] ?? 1,
                                        max: $data['max'] ?? null
                                    );
                                    break;

                                case 'decrement':
                                    $state[$collectionId][$documentId] = $dbForProject->decreaseDocumentAttribute(
                                        collection: $collectionId,
                                        id: $documentId,
                                        attribute: $data['attribute'],
                                        value: $data['value'] ?? 1,
                                        min: $data['min'] ?? null
                                    );
                                    break;
                            }
                        } else {
                            // Use timestamp wrapper for independent operations
                            $dbForProject->withRequestTimestamp($createdAt, function () use ($dbForProject, $queueForDeletes, $action, $collectionId, $documentId, $data, &$state) {
                                switch ($action) {
                                    case 'create':
                                        if ($documentId && !isset($data['$id'])) {
                                            $data['$id'] = $documentId;
                                        }
                                        $state[$collectionId][$documentId] = $dbForProject->createDocument(
                                            $collectionId,
                                            new Document($data),
                                        );
                                        break;

                                    case 'update':
                                        $document = new Document($data);
                                        $state[$collectionId][$documentId] = $dbForProject->updateDocument(
                                            $collectionId,
                                            $documentId,
                                            $document,
                                        );
                                        break;

                                    case 'upsert':
                                        $document = new Document($data);
                                        $dbForProject->createOrUpdateDocuments(
                                            $collectionId,
                                            [$document],
                                            onNext: function (Document $document) use (&$state, $collectionId) {
                                                $state[$collectionId][$document->getId()] = $document;
                                            }
                                        );
                                        break;

                                    case 'delete':
                                        $dbForProject->deleteDocument($collectionId, $documentId);

                                        if (isset($state[$collectionId][$documentId])) {
                                            unset($state[$collectionId][$documentId]);
                                        }
                                        break;

                                    case 'increment':
                                        $dbForProject->increaseDocumentAttribute(
                                            collection: $collectionId,
                                            id: $documentId,
                                            attribute: $data['attribute'],
                                            value: $data['value'] ?? 1,
                                            max: $data['max'] ?? null
                                        );
                                        break;

                                    case 'decrement':
                                        $dbForProject->decreaseDocumentAttribute(
                                            collection: $collectionId,
                                            id: $documentId,
                                            attribute: $data['attribute'],
                                            value: $data['value'] ?? 1,
                                            min: $data['min'] ?? null
                                        );
                                        break;

                                    case 'bulkCreate':
                                        $dbForProject->createDocuments(
                                            $collectionId,
                                            array_map(fn ($data) => new Document($data), $data),
                                            onNext: function (Document $document) use (&$state, $collectionId) {
                                                $state[$collectionId][$document->getId()] = $document;

                                            }
                                        );
                                        break;

                                    case 'bulkUpdate':
                                        $dbForProject->updateDocuments(
                                            $collectionId,
                                            $data['data'] ?? null,
                                            Query::parseQueries($data['queries'] ?? [])
                                        );
                                        break;

                                    case 'bulkUpsert':
                                        $dbForProject->createOrUpdateDocuments(
                                            $collectionId,
                                            array_map(fn ($data) => new Document($data), $data),
                                            onNext: function (Document $document) use (&$state, $collectionId) {
                                                $state[$collectionId][$document->getId()] = $document;

                                            }
                                        );
                                        break;

                                    case 'bulkDelete':
                                        $dbForProject->deleteDocuments(
                                            $collectionId,
                                            Query::parseQueries($data['queries'] ?? [])
                                        );
                                        break;
                                }
                            });
                        }
                    }

                    $transaction = $dbForProject->updateDocument(
                        'transactions',
                        $transactionId,
                        new Document(
                            [
                                'status' => 'committed',
                            ]
                        )
                    );

                    // Clear the transaction logs
                    $queueForDeletes
                        ->setType(DELETE_TYPE_DOCUMENT)
                        ->setDocument($transaction);

                } catch (NotFoundException $e) {
                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'failed',
                    ]));

                    throw new Exception(Exception::DOCUMENT_NOT_FOUND);
                } catch (DuplicateException|ConflictException $e) {
                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'failed',
                    ]));

                    throw new Exception(Exception::TRANSACTION_CONFLICT);
                } catch (TransactionException $e) {
                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'failed',
                    ]));

                    throw new Exception(Exception::TRANSACTION_FAILED, $e->getMessage());
                }
            });
        }

        if ($rollback) {
            $queueForDeletes
                ->setType(DELETE_TYPE_DOCUMENT)
                ->setDocument($transaction);

            $transaction = $dbForProject->updateDocument('transactions', $transactionId, new Document([
                'status' => 'rolledBack',
            ]));
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }
}
