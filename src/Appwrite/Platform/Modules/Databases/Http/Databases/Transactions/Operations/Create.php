<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Operations;

use Appwrite\Databases\TransactionState;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Operation;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
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
            ->desc('Create operations')
            ->groups(['api', 'database', 'transactions'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'transactions',
                name: 'createOperations',
                description: '/docs/references/databases/create-operations.md',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
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
            ->inject('transactionState')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $transactionId, array $operations, UtopiaResponse $response, Database $dbForProject, TransactionState $transactionState, array $plan, Authorization $authorization): void
    {
        if (empty($operations)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Operations array cannot be empty');
        }

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

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

        $databases = $collections = $staged = $dependants = [];
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
            if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
                throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$operation['databaseId']]);
            }

            $collection = $collections[$operation[$this->getGroupId()]] ??=
                $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $operation[$this->getGroupId()]));

            if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND, params: [$operation[$this->getGroupId()]]);
            }

            if (\in_array($operation['action'], ['bulkCreate', 'bulkUpdate', 'bulkUpsert', 'bulkDelete'])) {
                $hasRelationships = \array_filter(
                    $collection->getAttribute('attributes', []),
                    fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
                );
                if ($hasRelationships) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk operations are not supported for ' . $this->getGroupId() . ' with relationship attributes');
                }
            }

            // For update, upsert, delete, increment, decrement, check document existence first
            $document = null;
            if (\in_array($operation['action'], ['update', 'delete', 'upsert', 'increment', 'decrement'])) {
                $documentId = $operation[$this->getResourceId()] ?? null;
                if (empty($documentId)) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Document ID is required for ' . $operation['action'] . ' operations');
                }

                $collectionKey = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
                $isDependant = isset($dependants[$collectionKey][$documentId]);

                $document = $transactionState->getDocument($collectionKey, $documentId, $transactionId);
                if ($document->isEmpty() && !$isDependant && $operation['action'] !== 'upsert') {
                    throw new Exception(Exception::DOCUMENT_NOT_FOUND, params: [$documentId]);
                }
            }

            // Bulk operations skip permission validation entirely (API key/admin only, already checked above)
            if (!\in_array($operation['action'], ['bulkCreate', 'bulkUpdate', 'bulkUpsert', 'bulkDelete'])) {
                $permissionType = match ($operation['action']) {
                    'create' => Database::PERMISSION_CREATE,
                    'update', 'increment', 'decrement' => Database::PERMISSION_UPDATE,
                    'delete' => Database::PERMISSION_DELETE,
                    'upsert' => ($document && !$document->isEmpty()) ? Database::PERMISSION_UPDATE : Database::PERMISSION_CREATE,
                    default => throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid action: ' . $operation['action'])
                };

                // For individual operations, enforce permissions unless using API key/admin
                if (!$isAPIKey && !$isPrivilegedUser) {
                    $documentSecurity = $collection->getAttribute('documentSecurity', false);

                    $collectionValid = $authorization->isValid(
                        new Input($permissionType, $collection->getPermissionsByType($permissionType))
                    );
                    $documentValid = false;
                    if ($document !== null && !$document->isEmpty() && $documentSecurity) {
                        if ($permissionType === Database::PERMISSION_UPDATE) {
                            $documentValid = $authorization->isValid(
                                new Input(Database::PERMISSION_UPDATE, $document->getUpdate())
                            );
                        } elseif ($permissionType === Database::PERMISSION_DELETE) {
                            $documentValid = $authorization->isValid(
                                new Input(Database::PERMISSION_DELETE, $document->getDelete())
                            );
                        }
                    }

                    if ($permissionType === Database::PERMISSION_CREATE || !$documentSecurity) {
                        if (!$collectionValid) {
                            throw new Exception(Exception::USER_UNAUTHORIZED);
                        }
                    } else {
                        if (!$collectionValid && !$documentValid) {
                            throw new Exception(Exception::USER_UNAUTHORIZED);
                        }
                    }

                    // Users can only set permissions for roles they have
                    if (isset($operation['data']['$permissions'])) {
                        $permissions = $operation['data']['$permissions'];
                        $roles = $authorization->getRoles();
                        foreach (Database::PERMISSIONS as $type) {
                            foreach ($permissions as $permission) {
                                $permission = Permission::parse($permission);
                                if ($permission->getPermission() != $type) {
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
                }
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

            // Track create operations for dependent update/increment/decrement/delete operations in same batch
            if ($operation['action'] === 'create') {
                $collectionKey = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
                $documentId = $operation[$this->getResourceId()] ?? null;
                if ($documentId) {
                    $dependants[$collectionKey][$documentId] = true;
                }
            }
        }

        $transaction = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged, $existing, $operations) {
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
