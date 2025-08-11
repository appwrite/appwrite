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
            ->label('scope', 'collections.write')
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
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(string $transactionId, bool $commit, bool $rollback, ?\DateTime $requestTimestamp, UtopiaResponse $response, Database $dbForProject, Document $project): void
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
            $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $transaction, $requestTimestamp) {
                $dbForProject->updateDocument('transactions', $transactionId, new Document([
                    'status' => 'committing',
                ]));

                $operations = $dbForProject->find('transactionLogs', [
                    Query::equal('transactionInternalId', [$transaction->getSequence()]),
                ]);

                $creates
                    = $updates
                    = $deletes
                    = $increments
                    = $decrements
                    = $bulkUpdates
                    = $bulkDeletes
                    = [];

                foreach ($operations as $operation) {
                    $databaseInternalId = $operation['databaseInternalId'];
                    $collectionInternalId = $operation['collectionInternalId'];
                    $documentId = $operation['documentId'];

                    switch ($operation['action']) {
                        case 'create':
                            $creates[$databaseInternalId][$collectionInternalId][] = new Document([
                                '$id' => $documentId ?? ID::unique(),
                                ...$operation['data']
                            ]);
                            break;
                        case 'update':
                        case 'upsert':
                            $updates[$databaseInternalId][$collectionInternalId][] = new Document([
                                '$id' => $documentId,
                                ...$operation['data'],
                            ]);
                            break;
                        case 'delete':
                            $deletes[$databaseInternalId][$collectionInternalId][] = $documentId;
                            break;
                        case 'increment':
                            $increments[$databaseInternalId][$collectionInternalId][] = [
                                'attribute' => $operation['data']['attribute'],
                                'value' => $operation['data']['value'] ?? 1,
                                'max' => $operation['data']['max'] ?? null,
                            ];
                            break;
                        case 'decrement':
                            $decrements[$databaseInternalId][$collectionInternalId][] = [
                                'attribute' => $operation['data']['attribute'],
                                'value' => $operation['data']['value'] ?? 1,
                                'min' => $operation['data']['min'] ?? null,
                            ];
                            break;
                        case 'bulkUpdate':
                            $bulkUpdates[$databaseInternalId][$collectionInternalId][] = [
                                'data' => $operation['data']['data'] ?? null,
                                'queries' => $operation['data']['queries'] ?? [],
                            ];
                            break;
                        case 'bulkDelete':
                            $bulkDeletes[$databaseInternalId][$collectionInternalId][] = [
                                'queries' => $operation['data']['queries'] ?? [],
                            ];
                            break;
                    }
                }

                try {
                    foreach ($creates as $dbId => $cols) {
                        foreach ($cols as $colId => $docs) {
                            $dbForProject->createDocuments("database_{$dbId}_collection_{$colId}", $docs);
                        }
                    }
                    foreach ($updates as $dbId => $cols) {
                        foreach ($cols as $colId => $docs) {
                            $dbForProject->createOrUpdateDocuments("database_{$dbId}_collection_{$colId}", $docs);
                        }
                    }
                    foreach ($deletes as $dbId => $cols) {
                        foreach ($cols as $colId => $ids) {
                            $dbForProject->deleteDocuments("database_{$dbId}_collection_{$colId}", [
                                Query::equal('$id', $ids),
                            ]);
                        }
                    }
                    foreach ($increments as $dbId => $cols) {
                        foreach ($cols as $colId => $increments) {
                            foreach ($increments as $increment) {
                                $dbForProject->increaseDocumentAttribute(
                                    "database_{$dbId}_collection_{$colId}",
                                    $increment['attribute'],
                                    $increment['value'],
                                    $increment['max']
                                );
                            }
                        }
                    }
                    foreach ($decrements as $dbId => $cols) {
                        foreach ($cols as $colId => $decrements) {
                            foreach ($decrements as $decrement) {
                                $dbForProject->decreaseDocumentAttribute(
                                    "database_{$dbId}_collection_{$colId}",
                                    $decrement['attribute'],
                                    $decrement['value'],
                                    $decrement['min']
                                );
                            }
                        }
                    }
                    foreach ($bulkUpdates as $dbId => $cols) {
                        foreach ($cols as $colId => $updates) {
                            foreach ($updates as $update) {
                                $dbForProject->updateDocuments("database_{$dbId}_collection_{$colId}", $update['data'], $update['queries']);
                            }
                        }
                    }
                    foreach ($bulkDeletes as $dbId => $cols) {
                        foreach ($cols as $colId => $deletes) {
                            foreach ($deletes as $delete) {
                                $dbForProject->deleteDocuments("database_{$dbId}_collection_{$colId}", $delete['queries']);
                            }
                        }
                    }

                    $dbForProject->updateDocument('transactions', $transactionId, new Document([
                        'status' => 'committed',
                    ]));

                    $dbForProject->deleteDocuments('transactionLogs', [
                        Query::equal('transactionInternalId', [$transaction->getSequence()]),
                    ]);
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
            $dbForProject->deleteDocuments('transactionLogs', [
                Query::equal('transactionInternalId', [$transaction->getSequence()]),
            ]);

            $transaction = $dbForProject->updateDocument('transactions', $transactionId, new Document([
                'status' => 'rolledBack',
            ]));
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }
}