<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Operations;

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Operation;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDatabasesTransactionOperations';
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
            ->desc('Create operations scoped to a transaction')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'createOperations',
                description: '/docs/references/databases/create-operations.md',
                auth: [AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('transactionId', '', new UID(), 'Transaction ID.')
            ->param('operations', [], new ArrayList(new Operation(type: 'legacy')), 'Array of staged operations.', true)
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

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $databases = $collections = $staged = [];
        foreach ($operations as $operation) {
            if (!$isAPIKey && !$isPrivilegedUser && \in_array($operation['action'], [
                'bulkCreate',
                'bulkUpdate',
                'bulkDelete'
            ])) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            $database = $databases[$operation['databaseId']] ??= $dbForProject->getDocument('databases', $operation['databaseId']);
            if ($database->isEmpty()) {
                throw new Exception(Exception::DATABASE_NOT_FOUND);
            }

            $collection = $collections[$operation[$this->getGroupId()]] ??=
                $dbForProject->getDocument('database_' . $database->getSequence(), $operation[$this->getGroupId()]);

            if ($collection->isEmpty()) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND);
            }

            $staged[] = new Document([
                '$id' => ID::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'documentId' => $operation[$this->getResourceId()] ?? null,
                'action' => $operation['action'],
                'data' => $operation['data'] ?? [],
            ]);
        }

        $transaction = $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged, $existing, $operations) {
            $dbForProject->createDocuments('transactionLogs', $staged);
            return $dbForProject->increaseDocumentAttribute(
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
