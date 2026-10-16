<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Operations;

use Appwrite\Databases\TransactionState;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Operation;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Query\Schema\ColumnType;
use Utopia\Validator\ArrayList;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDatabasesTransactionOperations';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/transactions/:transactionId/operations')
            ->desc('Create operations')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'createOperations',
                description: '/docs/references/databases/create-operations.md',
                requestExamples: [
                    'createDocument' => [
                        'summary' => 'Stage a document creation',
                        'value' => ['operations' => [[
                            'action' => 'create',
                            'databaseId' => '<DATABASE_ID>',
                            'collectionId' => '<COLLECTION_ID>',
                            'documentId' => '<DOCUMENT_ID>',
                            'data' => ['username' => 'walter.obrien'],
                        ]]],
                    ],
                ],
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: UtopiaResponse::MODEL_TRANSACTION,
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createOperations',
                )
            ))
            ->param('transactionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Transaction ID.', false, ['dbForProject'])
            ->param('operations', [], new ArrayList(new Operation(type: 'legacy')), 'Array of staged operations.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('transactionState')
            ->inject('plan')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(string $transactionId, array $operations, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, TransactionState $transactionState, array $plan, Authorization $authorization, User $user): void
    {
        if (empty($operations)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Operations array cannot be empty');
        }

        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        // API keys and admins can read any transaction, regular users need permissions
        $transaction = ($isAPIKey || $isPrivilegedUser)
            ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
            : $dbForProject->getDocument('transactions', $transactionId);
        if ($transaction->isEmpty()) {
            throw new Exception(Exception::TRANSACTION_NOT_FOUND, params: [$transactionId]);
        }
        if ($transaction->getAttribute('status', '') !== 'pending') {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid or non‑pending transaction');
        }

        $now = new \DateTime();
        $expiresAt = new \DateTime($transaction->getAttribute('expiresAt', 'now'));
        if ($now > $expiresAt) {
            throw new Exception(Exception::TRANSACTION_EXPIRED);
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
            if (!$isAPIKey && !$isPrivilegedUser && \in_array($operation['action'], [
                'bulkCreate',
                'bulkUpdate',
                'bulkUpsert',
                'bulkDelete'
            ])) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            $database = $databases[$operation['databaseId']] ??= $authorization->skip(fn () => $dbForProject->getDocument('databases', $operation['databaseId']));
            if ($database->isEmpty() || $this->isDatabaseTypeMismatch($database) || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
                throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$operation['databaseId']]);
            }

            $collection = $collections[$database->getId()][$operation[$this->getGroupId()]] ??=
                $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $operation[$this->getGroupId()]));

            if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
                throw new Exception($this->getParentNotFoundException(), params: [$operation[$this->getGroupId()]]);
            }

            if (\in_array($operation['action'], ['bulkCreate', 'bulkUpdate', 'bulkUpsert', 'bulkDelete'])) {
                $hasRelationships = \array_filter(
                    $collection->getAttribute('attributes', []),
                    fn ($attribute) => $attribute->getAttribute('type') === ColumnType::Relationship->value
                );
                if ($hasRelationships) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk operations are not supported for ' . $this->getGroupId() . ' with relationship attributes');
                }
            }

            $document = null;
            if (\in_array($operation['action'], ['update', 'delete', 'upsert', 'increment', 'decrement'])) {
                $documentId = $operation[$this->getResourceId()] ?? null;
                if (empty($documentId)) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, \ucfirst($this->getResource()) . ' ID is required for ' . $operation['action'] . ' operations');
                }

                $collectionKey = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

                $document = $transactionState->getDocument($database, $collectionKey, $documentId, $transactionId, pending: $staged);
                if ($document->isEmpty() && $operation['action'] !== 'upsert') {
                    throw new Exception($this->getNotFoundException(), params: [$documentId]);
                }
            }

            // Bulk operations skip permission validation entirely (API key/admin only, already checked above)
            if (!\in_array($operation['action'], ['bulkCreate', 'bulkUpdate', 'bulkUpsert', 'bulkDelete'])) {
                if (!\in_array($operation['action'], ['create', 'update', 'upsert', 'increment', 'decrement', 'delete'])) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid action: ' . $operation['action']);
                }

                // For individual operations, enforce permissions unless using API key/admin
                if (!$isAPIKey && !$isPrivilegedUser) {
                    $collectionKey = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
                    $exists = $document !== null && !$document->isEmpty();

                    if ($operation['action'] === 'delete') {
                        $permissions = $collection->getPermissionsByType(PermissionType::Delete);
                        if ($collection->getAttribute('documentSecurity', false) && $exists) {
                            $permissions = [...$permissions, ...$document->getPermissionsByType(PermissionType::Delete)];
                        }
                        if (!$authorization->isValid(new Input(PermissionType::Delete, $permissions))) {
                            throw new Exception(Exception::USER_UNAUTHORIZED);
                        }
                    }

                    // Users can only set permissions for roles they have
                    if (isset($operation['data']['$permissions'])) {
                        $permissions = $operation['data']['$permissions'];
                        $roles = $authorization->getRoles();
                        foreach ([PermissionType::Read, PermissionType::Create, PermissionType::Update, PermissionType::Delete] as $type) {
                            foreach ($permissions as $permission) {
                                $permission = Permission::parse($permission);
                                if ($permission->getPermission() != $type->value) {
                                    continue;
                                }
                                $role = (new Role(
                                    $permission->getRole(),
                                    $permission->getIdentifier(),
                                    $permission->getDimension()
                                ))->toString();
                                if (!$authorization->hasRole($role)) {
                                    throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                                }
                            }
                        }
                    }

                    if ($operation['action'] !== 'delete') {
                        $dbForDatabases = $getDatabasesDB($database);
                        $data = \is_array($operation['data'] ?? null) ? $operation['data'] : [];
                        $creates = $operation['action'] === 'create' || ($operation['action'] === 'upsert' && !$exists);

                        if ($creates && isset($operation[$this->getResourceId()])) {
                            $data['$id'] = $operation[$this->getResourceId()];
                        }
                        if (\in_array($operation['action'], ['increment', 'decrement'])) {
                            $data = [];
                        }

                        $stagedView = $transactionState->stagedView($database, $transaction, $staged);
                        $this->authorizeStagedWrite(
                            fn () => $creates
                                ? $dbForDatabases->authorizeCreate($collectionKey, new Document($data), $stagedView)
                                : $dbForDatabases->authorizeUpdate($collectionKey, $stagedView($collectionKey, $operation[$this->getResourceId()]) ?? $document ?? new Document(), new Document($data), $stagedView),
                            $this->getParentNotFoundException(),
                            $operation[$this->getGroupId()],
                        );
                    }
                }
            }

            $staged[] = new Document([
                '$id' => Id::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'documentId' => $operation[$this->getResourceId()] ?? null,
                'action' => $operation['action'],
                'data' => $operation['data'] ?? [],
            ]);
        }

        $transaction = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged, $operations) {
            $dbForProject->createDocuments('transactionLogs', $staged);
            return $dbForProject->increaseDocumentAttribute(
                'transactions',
                $transactionId,
                'operations',
                \count($operations)
            );
        }));

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($transaction, UtopiaResponse::MODEL_TRANSACTION);
    }
}
