<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateDocument';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
            ->desc('Update document')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].update')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'document.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{response.$id}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-document.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.updateRow',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('documentId', '', new UID(), 'Document ID.')
            ->param('data', [], new JSON(), 'Document data as JSON object. Include only attribute and value pairs to be updated.', true, example: '{"username":"walter.obrien","email":"walter.obrien@example.com","fullName":"Walter O\'Brien","age":33,"isAdmin":false}')
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('transactionState')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $documentId, string|array $data, ?array $permissions, ?string $transactionId, ?\DateTime $requestTimestamp, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage, TransactionState $transactionState, array $plan, Authorization $authorization): void
    {
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data) && \is_null($permissions)) {
            throw new Exception($this->getMissingPayloadException());
        }

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));

        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        if ($transactionId === null) {
            $data = $this->parseOperators($data, $collection);
        }

        // Read permission should not be required for update
        /** @var Document $document */
        $collectionTableId = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

        if ($transactionId !== null) {
            // Use transaction-aware document retrieval to see changes from same transaction
            $document = $transactionState->getDocument($collectionTableId, $documentId, $transactionId);
        } else {
            $document = $authorization->skip(fn () => $dbForProject->getDocument($collectionTableId, $documentId));
        }

        if ($document->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$documentId]);
        }

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions, [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ]);

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = $authorization->getRoles();
        if (!$isAPIKey && !$isPrivilegedUser && !\is_null($permissions)) {
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

        if (\is_null($permissions)) {
            $permissions = $document->getPermissions() ?? [];
        }

        $data['$id'] = $documentId;
        $data['$permissions'] = $permissions;
        $data = $this->removeReadonlyAttributes($data, $isAPIKey || $isPrivilegedUser);
        $newDocument = new Document($data);

        $operations = 0;

        $setCollection = (function (Document $collection, Document $document) use ($isAPIKey, $isPrivilegedUser, &$setCollection, $dbForProject, $database, &$operations, $authorization) {
            $operations++;

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }

                $isList = \is_array($related) && \array_values($related) === $related;

                if ($isList) {
                    $relations = $related;
                } else {
                    $relations = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = $authorization->skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId)
                );

                foreach ($relations as &$relation) {
                    // If the relation is an array it can be either update or create a child document.
                    if (
                        \is_array($relation)
                        && \array_values($relation) !== $relation
                        && !isset($relation['$id'])
                    ) {
                        $relation['$id'] = ID::unique();
                        $relation = new Document($relation);
                    }
                    if ($relation instanceof Document) {
                        $relation = $this->removeReadonlyAttributes($relation, $isAPIKey || $isPrivilegedUser);

                        $oldDocument = $authorization->skip(fn () => $dbForProject->getDocument(
                            'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence(),
                            $relation->getId()
                        ));

                        // Attribute $collection is required for Utopia.
                        $relation->setAttribute(
                            '$collection',
                            'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence()
                        );

                        if ($oldDocument->isEmpty()) {
                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        }
                        $setCollection($relatedCollection, $relation);
                    }
                }

                if ($isList) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } else {
                    $document->setAttribute($relationship->getAttribute('key'), \reset($relations));
                }
            }
        });

        $setCollection($collection, $newDocument);

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), $operations);

        // Handle transaction staging
        if ($transactionId !== null) {
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

            // Enforce max operations per transaction
            $maxBatch = $plan['databasesTransactionSize'] ?? APP_LIMIT_DATABASE_TRANSACTION;
            $existing = $transaction->getAttribute('operations', 0);
            if (($existing + 1) > $maxBatch) {
                throw new Exception(
                    Exception::TRANSACTION_LIMIT_EXCEEDED,
                    'Transaction already has ' . $existing . ' operations, adding 1 would exceed the maximum of ' . $maxBatch
                );
            }

            // Stage the operation in transaction logs
            $staged = new Document([
                '$id' => ID::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'documentId' => $documentId,
                'action' => 'update',
                'data' => $data,
            ]);

            $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged) {
                $dbForProject->createDocument('transactionLogs', $staged);
                $dbForProject->increaseDocumentAttribute(
                    'transactions',
                    $transactionId,
                    'operations',
                    1
                );
            });

            // Return successful response without actually updating document
            $groupId = $this->getGroupId();
            $mockDocument = new Document([
                '$id' => $documentId,
                '$' . $groupId => $collectionId,
                '$databaseId' => $databaseId,
                ...$document->getArrayCopy(),
                ...$data
            ]);

            $queueForEvents->reset();

            $response
                ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
                ->dynamic($mockDocument, $this->getResponseModel());
            return;
        }


        try {
            $document = $dbForProject->withRequestTimestamp(
                $requestTimestamp,
                fn () => $dbForProject->withPreserveDates(fn () => $dbForProject->updateDocument(
                    'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                    $document->getId(),
                    $newDocument
                ))
            );
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException(), params: [$documentId]);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception($this->getStructureException(), $e->getMessage());
        }

        $collectionsCache = [];
        $this->processDocument(
            database: $database,
            collection: $collection,
            document: $document,
            dbForProject: $dbForProject,
            collectionsCache: $collectionsCache,
            authorization: $authorization,
        );

        $response->dynamic($document, $this->getResponseModel());

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setParam('rowId', $document->getId())
            ->setContext('database', $database)
            ->setContext($this->getCollectionsEventsContext(), $collection)
            ->setPayload($response->getPayload(), sensitive: $relationships);
    }
}
