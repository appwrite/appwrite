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
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
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

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRow';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/documents')
            ->desc('Create row')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].create')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'row.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: 'rows',
                    name: 'createRow',
                    description: '/docs/references/databases/create-document.md',
                    auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: UtopiaResponse::MODEL_ROW,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('rowId', '', new CustomId(), 'Row ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). Make sure to define columns before creating rows.')
            ->param('data', [], new JSON(), 'Row data as JSON object.')
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $rowId, string $tableId, string|array $data, ?array $permissions, UtopiaResponse $response, Database $dbForProject, Document $user, Event $queueForEvents, StatsUsage $queueForStatsUsage): void
    {
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception(Exception::DOCUMENT_MISSING_DATA);
        }

        if (isset($data['$id'])) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, '$id is not allowed for creating new rows, try update instead');
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

        $allowedPermissions = [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ];

        // Map aggregate permissions to into the set of individual permissions they represent.
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
                    if (!Authorization::isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', Authorization::getRoles()) . ')');
                    }
                }
            }
        }

        $data['$collection'] = $table->getId(); // Adding this param to make API easier for developers
        $data['$id'] = $rowId == 'unique()' ? ID::unique() : $rowId;
        $data['$permissions'] = $permissions;
        $row = new Document($data);

        $operations = 0;

        $checkPermissions = function (Document $table, Document $row, string $permission) use (&$checkPermissions, $dbForProject, $database, &$operations) {
            $operations++;

            $documentSecurity = $table->getAttribute('documentSecurity', false);
            $validator = new Authorization($permission);

            $valid = $validator->isValid($table->getPermissionsByType($permission));
            if (($permission === Database::PERMISSION_UPDATE && !$documentSecurity) || !$valid) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            if ($permission === Database::PERMISSION_UPDATE) {
                $valid = $valid || $validator->isValid($row->getUpdate());
                if ($documentSecurity && !$valid) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }
            }

            $relationships = \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $row->getAttribute($relationship->getAttribute('key'));

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
                    if (
                        \is_array($relation)
                        && \array_values($relation) !== $relation
                        && !isset($relation['$id'])
                    ) {
                        $relation['$id'] = ID::unique();
                        $relation = new Document($relation);
                    }
                    if ($relation instanceof Document) {
                        $current = Authorization::skip(
                            fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $relatedTable->getInternalId(), $relation->getId())
                        );

                        if ($current->isEmpty()) {
                            $type = Database::PERMISSION_CREATE;

                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        } else {
                            $relation->removeAttribute('$collectionId');
                            $relation->removeAttribute('$databaseId');
                            $relation->setAttribute('$collection', $relatedTable->getId());
                            $type = Database::PERMISSION_UPDATE;
                        }

                        $checkPermissions($relatedTable, $relation, $type);
                    }
                }

                if ($isList) {
                    $row->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } else {
                    $row->setAttribute($relationship->getAttribute('key'), \reset($relations));
                }
            }
        };

        $checkPermissions($table, $row, Database::PERMISSION_CREATE);

        try {
            $row = $dbForProject->createDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $row);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
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

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_WRITES), $operations); // per collection

        $response->addHeader('X-Debug-Operations', $operations);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($row, UtopiaResponse::MODEL_ROW);

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
