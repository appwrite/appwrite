<?php

namespace Appwrite\Platform\Modules\Databases\Http\Transactions;

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
use Utopia\Database\Helpers\ID;
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
            $dbForProject->withTransaction(function () use ($dbForProject, $queueForDeletes, $transactionId, $transaction) {
                $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'committing',
                ]));

                // Fetch operations ordered by sequence by default
                $operations = $dbForProject->find('transactionLogs', [
                    Query::equal('transactionInternalId', [$transaction->getSequence()]),
                ]);

                try {
                    // Replay operations in exact order they were created
                    foreach ($operations as $operation) {
                        $databaseInternalId = $operation['databaseInternalId'];
                        $collectionInternalId = $operation['collectionInternalId'];
                        $documentId = $operation['documentId'];
                        $action = $operation['action'];
                        $data = $operation['data'];
                        $operationCreatedAt = new \DateTime($operation['$createdAt']);
                        
                        $collectionName = "database_{$databaseInternalId}_collection_{$collectionInternalId}";

                        // Wrap each operation with the timestamp from when it was logged
                        $dbForProject->withRequestTimestamp($operationCreatedAt, function () use ($dbForProject, $action, $collectionName, $documentId, $data) {
                            switch ($action) {
                                case 'create':
                                    $document = new Document([
                                        '$id' => $documentId ?? ID::unique(),
                                        ...$data
                                    ]);
                                    $dbForProject->createDocument($collectionName, $document);
                                    break;
                                    
                                case 'update':
                                case 'upsert':
                                    $document = new Document([
                                        '$id' => $documentId,
                                        ...$data,
                                    ]);
                                    $dbForProject->createOrUpdateDocument($collectionName, $document);
                                    break;
                                    
                                case 'delete':
                                    $dbForProject->deleteDocument($collectionName, $documentId);
                                    break;
                                    
                                case 'increment':
                                    $dbForProject->increaseDocumentAttribute(
                                        collection: $collectionName,
                                        id: $documentId,
                                        attribute: $data['attribute'],
                                        value: $data['value'] ?? 1,
                                        max: $data['max'] ?? null
                                    );
                                    break;
                                    
                                case 'decrement':
                                    $dbForProject->decreaseDocumentAttribute(
                                        collection: $collectionName,
                                        id: $documentId,
                                        attribute: $data['attribute'],
                                        value: $data['value'] ?? 1,
                                        min: $data['min'] ?? null
                                    );
                                    break;
                                    
                                case 'bulkUpdate':
                                    $dbForProject->updateDocuments(
                                        $collectionName,
                                        $data['data'] ?? null,
                                        $data['queries'] ?? []
                                    );
                                    break;
                                    
                                case 'bulkDelete':
                                    $dbForProject->deleteDocuments(
                                        $collectionName,
                                        $data['queries'] ?? []
                                    );
                                    break;
                            }
                        });
                    }

                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'committed',
                    ]));

                    // Clear the transaction logs
                    $queueForDeletes
                        ->setType(DELETE_TYPE_DOCUMENT)
                        ->setDocument($transaction);
                } catch (DuplicateException|ConflictException) {
                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'failed',
                    ]));

                    throw new Exception(Exception::TRANSACTION_CONFLICT);
                }
            });

            $transaction = $dbForProject->getDocument('transactions', $transactionId);
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