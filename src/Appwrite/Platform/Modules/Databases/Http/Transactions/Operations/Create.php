<?php

namespace Appwrite\Platform\Modules\Databases\Http\Transactions\Operations;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Operation;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createOperations';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TRANSACTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/transactions/:transactionId/operations')
            ->desc('Add operations to transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'transactions.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'createOperations',
                description: '/docs/references/databases/create-operations.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('transactionId', '', new UID(), 'Transaction ID.')
            ->param('operations', [], new ArrayList(new Operation()), 'Array of staged operations.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('plan')
            ->callback($this->action(...));
    }

    public function action(string $transactionId, array $operations, UtopiaResponse $response, Database $dbForProject, array $plan): void
    {
        $transaction = $dbForProject->getDocument('transactions', $transactionId);
        if ($transaction->isEmpty() || $transaction->getAttribute('status', '') !== 'pending') {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid or non‑pending transaction');
        }

        $maxBatch = $plan['databasesTransactionSize'] ?? APP_LIMIT_DATABASE_TRANSACTION;
        $existing = $transaction->getAttribute('operations', 0);

        if (($existing + \count($operations)) > $maxBatch) {
            throw new Exception(
                Exception::TRANSACTION_LIMIT_EXCEEDED,
                'Transaction already has ' . $existing . ' operations, adding ' . \count($operations) . ' would exceed the maximum of ' . $maxBatch
            );
        }

        $databases = $collections = $staged = [];
        foreach ($operations as $operation) {
            $database = $databases[$operation['databaseId']] ??= $dbForProject->getDocument('databases', $operation['databaseId']);
            if ($database->isEmpty()) {
                throw new Exception(Exception::DATABASE_NOT_FOUND);
            }

            $collection = $collections[$operation['collectionId']] ??= $dbForProject->getDocument('database_' . $database->getSequence(), $operation['collectionId']);
            if ($collection->isEmpty()) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND);
            }

            $staged[] = new Document([
                '$id' => ID::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'documentId' => $operation['documentId'] ?? ID::unique(),
                'action' => $operation['action'],
                'data' => $operation['data'] ?? new \stdClass(),
            ]);
        }

        $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged, $existing, $operations) {
            $dbForProject->createDocuments('transactionLogs', $staged);
            $dbForProject->increaseDocumentAttribute(
                'transactions',
                $transactionId,
                'operations',
                \count($operations)
            );
        });

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }
}