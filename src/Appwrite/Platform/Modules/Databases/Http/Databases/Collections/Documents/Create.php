<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDocument';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    protected function getBulkResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents')
            ->desc('Create document')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'document.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: $this->getSDKNamespace(),
                    group: $this->getSDKGroup(),
                    name: self::getName(),
                    desc: 'Create document',
                    description: '/docs/references/databases/create-document.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    parameters: [
                        new Parameter('databaseId', optional: false),
                        new Parameter('collectionId', optional: false),
                        new Parameter('documentId', optional: false),
                        new Parameter('data', optional: false),
                        new Parameter('permissions', optional: true),
                        new Parameter('transactionId', optional: true),
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.createRow',
                    ),
                ),
                new Method(
                    namespace: $this->getSDKNamespace(),
                    group: $this->getSDKGroup(),
                    name: $this->getBulkActionName(self::getName()),
                    desc: 'Create documents',
                    description: '/docs/references/databases/create-documents.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getBulkResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    parameters: [
                        new Parameter('databaseId', optional: false),
                        new Parameter('collectionId', optional: false),
                        new Parameter('documents', optional: false),
                        new Parameter('transactionId', optional: true),
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.createRows',
                    ),
                )
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('documentId', '', new CustomId(), 'Document ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', true)
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). Make sure to define attributes before creating documents.')
            ->param('data', [], new JSON(), 'Document data as JSON object.', true, example: '{"username":"walter.obrien","email":"walter.obrien@example.com","fullName":"Walter O\'Brien","age":30,"isAdmin":false}')
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('documents', [], fn (array $plan) => new ArrayList(new JSON(), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of documents data as JSON objects.', true, ['plan'])
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('queueForRealtime')
            ->inject('queueForFunctions')
            ->inject('queueForWebhooks')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }
    public function action(string $databaseId, string $documentId, string $collectionId, string|array $data, ?array $permissions, ?array $documents, ?string $transactionId, UtopiaResponse $response, Database $dbForProject, Document $user, Event $queueForEvents, StatsUsage $queueForStatsUsage, Event $queueForRealtime, Event $queueForFunctions, Event $queueForWebhooks, array $plan, Authorization $authorization): void
    {
        $data = \is_string($data)
            ? \json_decode($data, true)
            : $data;

        /**
         * Determine which internal path to call, single or bulk
         */
        if (empty($data) && empty($documents)) {
            // No single or bulk documents provided
            throw new Exception($this->getMissingDataException());
        }
        if (!empty($data) && !empty($documents)) {
            // Both single and bulk documents provided
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'You can only send one of the following parameters: data, ' . $this->getSDKGroup());
        }
        if (!empty($data) && empty($documentId)) {
            // Single document provided without document ID
            $document = $this->isCollectionsAPI() ? 'Document' : 'Row';
            $message = "$document ID is required when creating a single " . strtolower($document) . '.';
            throw new Exception($this->getMissingDataException(), $message);
        }
        if (!empty($documents) && !empty($documentId)) {
            // Bulk documents provided with document ID
            $documentId = $this->isCollectionsAPI() ? 'documentId' : 'rowId';
            throw new Exception(
                Exception::GENERAL_BAD_REQUEST,
                "Param \"$documentId\" is not allowed when creating multiple " . $this->getSDKGroup() . ', set "$id" on each instead.'
            );
        }
        if (!empty($documents) && !empty($permissions)) {
            // Bulk documents provided with permissions
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Param "permissions" is disallowed when creating multiple ' . $this->getSDKGroup() . ', set "$permissions" on each instead');
        }

        $isBulk = true;
        if (!empty($data)) {
            // Single document provided, convert to single item array
            // But remember that it was single to respond with a single document
            $isBulk = false;
            $documents = [$data];
        }

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($isBulk && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE);
        }

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        $hasRelationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        if ($isBulk && $hasRelationships) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Bulk create is not supported for ' . $this->getSDKNamespace() .' with relationship ' . $this->getStructureContext());
        }

        $setPermissions = function (Document $document, ?array $permissions) use ($user, $isAPIKey, $isPrivilegedUser, $isBulk, $dbForProject, $authorization) {
            $allowedPermissions = [
                Database::PERMISSION_READ,
                Database::PERMISSION_UPDATE,
                Database::PERMISSION_DELETE,
            ];

            // If bulk, we need to validate permissions explicitly per document
            if ($isBulk) {
                $permissions = $document['$permissions'] ?? null;
                if (!empty($permissions)) {
                    $validator = new Permissions();
                    if (!$validator->isValid($permissions)) {
                        throw new Exception(Exception::GENERAL_BAD_REQUEST, $validator->getDescription());
                    }
                }
            }

            $permissions = Permission::aggregate($permissions, $allowedPermissions);

            // Add permissions for current the user if none were provided.
            if (\is_null($permissions)) {
                $permissions = [];
                if (!empty($user->getId())) {
                    foreach ($allowedPermissions as $permission) {
                        $permissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                    }
                }
            }

            // Users can only manage their own roles, API keys and Admin users can manage any
            if (!$isAPIKey && !$isPrivilegedUser) {
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
                            throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $authorization->getRoles()) . ')');
                        }
                    }
                }
            }

            $document->setAttribute('$permissions', $permissions);
        };

        $operations = 0;

        $checkPermissions = function (Document $collection, Document $document, string $permission) use ($isAPIKey, $isPrivilegedUser, &$checkPermissions, $dbForProject, $database, &$operations, $authorization) {
            $operations++;

            $documentSecurity = $collection->getAttribute('documentSecurity', false);

            $validCollection = $authorization->isValid(
                new Input($permission, $collection->getPermissionsByType($permission))
            );
            if (($permission === Database::PERMISSION_UPDATE && !$documentSecurity) || !$validCollection) {
                throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
            }

            if ($permission === Database::PERMISSION_UPDATE) {
                $validDocument = $authorization->isValid(
                    new Input($permission, $document->getUpdate())
                );
                $valid = $validCollection || $validDocument;
                if ($documentSecurity && !$valid) {
                    throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
                }
            }

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

                        $current = $authorization->skip(
                            fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence(), $relation->getId())
                        );

                        if ($current->isEmpty()) {
                            $type = Database::PERMISSION_CREATE;

                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        } else {
                            $relation->setAttribute('$collection', $relatedCollection->getId());
                            $type = Database::PERMISSION_UPDATE;
                        }

                        $checkPermissions($relatedCollection, $relation, $type);
                    }
                }

                if ($isList) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } else {
                    $document->setAttribute($relationship->getAttribute('key'), \reset($relations));
                }
            }
        };

        $documents = \array_map(function ($document) use ($collection, $permissions, $checkPermissions, $isBulk, $documentId, $setPermissions, $isAPIKey, $isPrivilegedUser) {
            $document['$collection'] = $collection->getId();

            // Determine the source ID depending on whether it's a bulk operation.
            $sourceId = $isBulk
                ? ($document['$id'] ?? ID::unique())
                : $documentId;

            // If bulk, we need to validate ID explicitly
            if ($isBulk) {
                $validator = new CustomId();
                if (!$validator->isValid($sourceId)) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, $validator->getDescription());
                }
            }

            // Assign a unique ID if needed, otherwise use the provided ID.
            $document['$id'] = $sourceId === 'unique()' ? ID::unique() : $sourceId;
            $document = $this->removeReadonlyAttributes($document, $isAPIKey || $isPrivilegedUser);
            $document = new Document($document);
            $setPermissions($document, $permissions);
            $checkPermissions($collection, $document, Database::PERMISSION_CREATE);
            return $document;
        }, $documents);

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

            $staged = new Document([
                '$id' => ID::unique(),
                'databaseInternalId' => $database->getSequence(),
                'collectionInternalId' => $collection->getSequence(),
                'transactionInternalId' => $transaction->getSequence(),
                'documentId' => $isBulk ? null : $documentId,
                'action' => $isBulk ? 'bulkCreate' : 'create',
                'data' => $isBulk ? $documents : $documents[0],
            ]);

            $dbForProject->withTransaction(function () use ($dbForProject, $transactionId, $staged) {
                $dbForProject->createDocument('transactionLogs', $staged);
                $dbForProject->increaseDocumentAttribute(
                    'transactions',
                    $transactionId,
                    'operations',
                );
            });

            $queueForEvents->reset();

            // Return successful response without actually creating documents
            if ($isBulk) {
                $response->dynamic(new Document([
                    $this->getSDKGroup() => [],
                    'total' => \count($documents),
                ]), $this->getBulkResponseModel());
            } else {
                $groupId = $this->getGroupId();
                $mockDocument = new Document([
                    '$id' => $documents[0]['$id'] ?? $documentId,
                    '$' . $groupId => $collectionId,
                    '$databaseId' => $databaseId,
                    ...$documents[0]
                ]);
                $response
                    ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
                    ->dynamic($mockDocument, $this->getResponseModel());
            }
            return;
        }

        try {
            $dbForProject->withPreserveDates(
                fn () => $dbForProject->createDocuments(
                    'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                    $documents,
                )
            );
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException(), params: [$documentId]);
        } catch (NotFoundException) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception($this->getStructureException(), $e->getMessage());
        }

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setContext('database', $database)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection);

        $collectionsCache = [];
        foreach ($documents as $document) {
            $this->processDocument(
                database: $database,
                collection: $collection,
                document: $document,
                dbForProject: $dbForProject,
                collectionsCache: $collectionsCache,
                authorization: $authorization
            );
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $operations)); // per collection

        $response->setStatusCode(SwooleResponse::STATUS_CODE_CREATED);

        if ($isBulk) {
            $response->dynamic(new Document([
                'total' => count($documents),
                $this->getSDKGroup() => $documents
            ]), $this->getBulkResponseModel());

            $this->triggerBulk(
                'databases.[databaseId].collections.[collectionId].documents.[documentId].create',
                $database,
                $collection,
                $documents,
                $queueForEvents,
                $queueForRealtime,
                $queueForFunctions,
                $queueForWebhooks
            );
            return;
        }

        $queueForEvents
            ->setParam('documentId', $documents[0]->getId())
            ->setParam('rowId', $documents[0]->getId())
            ->setEvent('databases.[databaseId].collections.[collectionId].documents.[documentId].create');

        $response->dynamic(
            $documents[0],
            $this->getResponseModel()
        );
    }
}
