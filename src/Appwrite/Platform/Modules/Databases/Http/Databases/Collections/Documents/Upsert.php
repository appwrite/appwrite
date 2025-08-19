<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Auth\Auth;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
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

class Upsert extends Action
{
    public static function getName(): string
    {
        return 'upsertDocument';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
            ->desc('Create or update a document')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].upsert')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'document.upsert')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{response.$id}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: $this->getSdkNamespace(),
                    group: $this->getSdkGroup(),
                    name: self::getName(),
                    description: '/docs/references/databases/upsert-document.md',
                    auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDb.upsertRow',
                    ),
                ),
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('documentId', '', new CustomId(), 'Document ID.')
            ->param('data', [], new JSON(), 'Document data as JSON object. Include all required attributes of the document to be created or updated.')
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $documentId, string|array $data, ?array $permissions, ?\DateTime $requestTimestamp, UtopiaResponse $response, Document $user, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage): void
    {
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data) && \is_null($permissions)) {
            throw new Exception($this->getMissingPayloadException());
        }

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception($this->getParentNotFoundException());
        }

        $allowedPermissions = [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ];

        $permissions = Permission::aggregate($permissions, $allowedPermissions);
        // if no permission, upsert permission from the old document if present (update scenario) else add default permission (create scenario)
        if (\is_null($permissions)) {
            $oldDocument = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence() . '_collection_' . $collection->getSequence(), $documentId));
            if ($oldDocument->isEmpty()) {
                if (!empty($user->getId())) {
                    $defaultPermissions = [];
                    foreach ($allowedPermissions as $permission) {
                        $defaultPermissions[] = (new Permission($permission, 'user', $user->getId()))->toString();
                    }
                    $permissions = $defaultPermissions;
                }
            } else {
                $permissions = $oldDocument->getPermissions();
            }
        }

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = Authorization::getRoles();
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
                    if (!Authorization::isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }
        // Allowing to add createdAt and updatedAt timestamps if server side(api key)
        if (!$isAPIKey && !$isPrivilegedUser) {
            if (isset($data['$createdAt'])) {
                throw new Exception($this->getInvalidStructureException(), 'Attribute "$createdAt" can not be modified. Please use a server SDK with an API key to modify server attributes.');
            }

            if (isset($data['$updatedAt'])) {
                throw new Exception($this->getInvalidStructureException(), 'Attribute "$updatedAt" can not be modified. Please use a server SDK with an API key to modify server attributes.');
            }
        }

        $data['$id'] = $documentId;
        $data['$permissions'] = $permissions ?? [];
        $newDocument = new Document($data);
        $operations = 0;

        $setCollection = (function (Document $collection, Document $document) use (&$setCollection, $dbForProject, $database, &$operations) {

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
                $relatedCollection = Authorization::skip(
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
                        $oldDocument = Authorization::skip(fn () => $dbForProject->getDocument(
                            'database_' . $database->getSequence() . '_collection_' . $relatedCollection->getSequence(),
                            $relation->getId()
                        ));
                        $this->removeReadonlyAttributes($relation);
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
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, \max(1, $operations))
            ->addMetric(str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_OPERATIONS_WRITES), \max(1, $operations));

        $upserted = [];
        try {
            $dbForProject->withPreserveDates(function () use (&$upserted, $dbForProject, $database, $collection, $newDocument) {
                return $dbForProject->createOrUpdateDocuments(
                    'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                    [$newDocument],
                    onNext: function (Document $document) use (&$upserted) {
                        $upserted[] = $document;
                    },
                );
            });
        } catch (ConflictException) {
            throw new Exception($this->getConflictException());
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException());
        } catch (RelationshipException $e) {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
        } catch (StructureException $e) {
            throw new Exception($this->getInvalidStructureException(), $e->getMessage());
        }

        $collectionsCache = [];
        $document = $upserted[0];
        $this->processDocument(
            database: $database,
            collection: $collection,
            document: $document,
            dbForProject: $dbForProject,
            collectionsCache: $collectionsCache,
        );

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setContext('database', $database)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setParam('rowId', $document->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection)
            ->setPayload($response->getPayload(), sensitive: $relationships);

        $response->dynamic(
            $document,
            $this->getResponseModel()
        );
    }
}
