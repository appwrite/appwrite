<?php

namespace Appwrite\Platform\Modules\Databases\Http\Rows;

use Appwrite\Auth\Auth;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\JSON;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRow';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows/:rowId')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId')
            ->desc('Update row')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].update')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'row.update')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}/row/{response.$id}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'rows',
                name: 'updateRow',
                description: '/docs/references/databases/update-document.md',
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_ROW,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Collection ID.')
            ->param('rowId', '', new UID(), 'Row ID.')
            ->param('data', [], new JSON(), 'Row data as JSON object. Include only columns and value pairs to be updated.', true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, string $rowId, string|array $data, ?array $permissions, ?\DateTime $requestTimestamp, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage): void
    {

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data) && \is_null($permissions)) {
            throw new Exception(Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId));

        if ($table->isEmpty() || (!$table->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        // Read permission should not be required for update
        /** @var Document $row */
        $row = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId));

        if ($row->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions, [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ]);

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

        if (\is_null($permissions)) {
            $permissions = $row->getPermissions() ?? [];
        }

        $data['$id'] = $rowId;
        $data['$permissions'] = $permissions;
        $newRow = new Document($data);

        $operations = 0;

        $setTable = (function (Document $collection, Document $document) use (&$setTable, $dbForProject, $database, &$operations) {

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

                $relatedTableId = $relationship->getAttribute('relatedCollection');
                $relatedTable = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedTableId)
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
                        $oldRow = Authorization::skip(fn () => $dbForProject->getDocument(
                            'database_' . $database->getInternalId() . '_collection_' . $relatedTable->getInternalId(),
                            $relation->getId()
                        ));
                        $relation->removeAttribute('$collectionId');
                        $relation->removeAttribute('$databaseId');
                        // Attribute $collection is required for Utopia.
                        $relation->setAttribute(
                            '$collection',
                            'database_' . $database->getInternalId() . '_collection_' . $relatedTable->getInternalId()
                        );

                        if ($oldRow->isEmpty()) {
                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        }
                        $setTable($relatedTable, $relation);
                    }
                }

                if ($isList) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } else {
                    $document->setAttribute($relationship->getAttribute('key'), \reset($relations));
                }
            }
        });

        $setTable($table, $newRow);

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_WRITES), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        try {
            $row = $dbForProject->withRequestTimestamp(
                $requestTimestamp,
                fn () => $dbForProject->updateDocument(
                    'database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(),
                    $row->getId(),
                    $newRow
                )
            );
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        } catch (NotFoundException) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        // Add $tableId and $databaseId for all rows
        $processRow = function (Document $table, Document $row) use (&$processRow, $dbForProject, $database) {
            $row->setAttribute('$databaseId', $database->getId());
            $row->setAttribute('$collectionId', $table->getId());

            $relationships = \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $row->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedTableId = $relationship->getAttribute('relatedCollection');
                $relatedTable = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedTableId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processRow($relatedTable, $relation);
                    }
                }
            }
        };

        $processRow($table, $row);

        $response->dynamic($row, UtopiaResponse::MODEL_ROW);

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('rowId', $row->getId())
            ->setContext('table', $table)
            ->setContext('database', $database)
            ->setPayload($response->getPayload(), sensitive: $relationships);
    }
}
