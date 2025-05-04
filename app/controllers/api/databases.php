<?php

use Appwrite\Auth\Auth;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/databases/:databaseId/tables/:tableId/rows')
    ->alias('/v1/databases/:databaseId/collections/:tableId/documents')
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
    ->label(
        'sdk',
        [
            new Method(
                namespace: 'databases',
                group: 'rows',
                name: 'createRow',
                description: '/docs/references/databases/create-document.md',
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DOCUMENT,
                    )
                ],
                contentType: ContentType::JSON
            )
        ]
    )
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
    ->action(function (string $databaseId, string $rowId, string $tableId, string|array $data, ?array $permissions, Response $response, Database $dbForProject, Document $user, Event $queueForEvents, StatsUsage $queueForStatsUsage) {

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception(Exception::DOCUMENT_MISSING_DATA);
        }

        if (isset($data['$id'])) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, '$id is not allowed for creating new documents, try update instead');
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
        } catch (DuplicateException $e) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (NotFoundException $e) {
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
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($row, Response::MODEL_DOCUMENT);

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
    });

App::get('/v1/databases/:databaseId/tables/:tableId/rows')
    ->alias('/v1/databases/:databaseId/collections/:tableId/documents')
    ->desc('List rows')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'rows',
        name: 'listRows',
        description: '/docs/references/databases/list-documents.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $tableId, array $queries, Response $response, Database $dbForProject, StatsUsage $queueForStatsUsage) {
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

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = \reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $rowId = $cursor->getValue();

            $cursorRow = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId));

            if ($cursorRow->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Row '{$rowId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorRow);
        }
        try {
            $rows = $dbForProject->find('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $queries);
            $total = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $queries, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        }

        $operations = 0;

        // Add $tableId and $databaseId for all rows
        $processRow = (function (Document $table, Document $row) use (&$processRow, $dbForProject, $database, &$operations): bool {
            if ($row->isEmpty()) {
                return false;
            }

            $operations++;

            $row->removeAttribute('$collection');
            $row->setAttribute('$databaseId', $database->getId());
            $row->setAttribute('$collectionId', $table->getId());

            $relationships = \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $row->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    if (\in_array(\gettype($related), ['array', 'object'])) {
                        $operations++;
                    }

                    continue;
                }

                if (!\is_array($related)) {
                    $relations = [$related];
                } else {
                    $relations = $related;
                }

                $relatedTableId = $relationship->getAttribute('relatedCollection');
                // todo: Use local cache for this getDocument
                $relatedTable = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedTableId));

                foreach ($relations as $index => $doc) {
                    if ($doc instanceof Document) {
                        if (!$processRow($relatedTable, $doc)) {
                            unset($relations[$index]);
                        }
                    }
                }

                if (\is_array($related)) {
                    $row->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } elseif (empty($relations)) {
                    $row->setAttribute($relationship->getAttribute('key'), null);
                }
            }

            return true;
        });

        foreach ($rows as $row) {
            $processRow($table, $row);
        }

        $queueForStatsUsage
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_READS), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        $select = \array_reduce($queries, function ($result, $query) {
            return $result || ($query->getMethod() === Query::TYPE_SELECT);
        }, false);

        // Check if the SELECT query includes $databaseId and $collectionId
        $hasDatabaseId = false;
        $hasTableId = false;
        if ($select) {
            $hasDatabaseId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$databaseId', $query->getValues()));
            }, false);
            $hasTableId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$collectionId', $query->getValues()));
            }, false);
        }

        if ($select) {
            foreach ($rows as $row) {
                if (!$hasDatabaseId) {
                    $row->removeAttribute('$databaseId');
                }
                if (!$hasTableId) {
                    $row->removeAttribute('$collectionId');
                }
            }
        }

        $response->dynamic(new Document([
            'total' => $total,
            'documents' => $rows,
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/rows/:rowId')
    ->alias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId')
    ->desc('Get row')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'rows',
        name: 'getRow',
        description: '/docs/references/databases/get-document.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('rowId', '', new UID(), 'Row ID.')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $tableId, string $rowId, array $queries, Response $response, Database $dbForProject, StatsUsage $queueForStatsUsage) {
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

        try {
            $queries = Query::parseQueries($queries);
            $row = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId, $queries);
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if ($row->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $operations = 0;

        // Add $tableId and $databaseId for all rows
        $processRow = function (Document $table, Document $row) use (&$processRow, $dbForProject, $database, &$operations) {
            if ($row->isEmpty()) {
                return;
            }

            $operations++;

            $row->setAttribute('$databaseId', $database->getId());
            $row->setAttribute('$collectionId', $table->getId());

            $relationships = \array_filter(
                $table->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $row->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    if (\in_array(\gettype($related), ['array', 'object'])) {
                        $operations++;
                    }

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
            ->addMetric(METRIC_DATABASES_OPERATIONS_READS, max($operations, 1))
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_READS), $operations);

        $response->addHeader('X-Debug-Operations', $operations);

        $response->dynamic($row, Response::MODEL_DOCUMENT);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/rows/:rowId/logs')
    ->alias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId/logs')
    ->desc('List row logs')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'logs',
        name: 'listRowLogs',
        description: '/docs/references/databases/get-document-logs.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Collection ID.')
    ->param('rowId', '', new UID(), 'Row ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, string $tableId, string $rowId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $row = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId);

        if ($row->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Temp fix for logs
        $queries[] = Query::or([
            Query::greaterThan('$createdAt', DateTime::format(new \DateTime('2025-02-26T01:30+00:00'))),
            Query::lessThan('$createdAt', DateTime::format(new \DateTime('2025-02-13T00:00+00:00'))),
        ]);

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId . '/table/' . $tableId . '/row/' . $row->getId();
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['data']['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource, $queries),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/rows/:rowId')
    ->alias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId')
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
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DOCUMENT,
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
    ->action(function (string $databaseId, string $tableId, string $rowId, string|array $data, ?array $permissions, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage) {

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
        } catch (NotFoundException $e) {
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

        $response->dynamic($row, Response::MODEL_DOCUMENT);

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
    });

App::delete('/v1/databases/:databaseId/tables/:tableId/rows/:rowId')
    ->alias('/v1/databases/:databaseId/collections/:tableId/documents/:rowId')
    ->desc('Delete row')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].delete')
    ->label('audits.event', 'row.delete')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}/row/{request.rowId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'rows',
        name: 'deleteRow',
        description: '/docs/references/databases/delete-document.md',
        auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('rowId', '', new UID(), 'Row ID.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $tableId, string $rowId, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
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

        // Read permission should not be required for delete
        $row = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $rowId));

        if ($row->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        try {
            $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $database, $table, $rowId) {
                $dbForProject->deleteDocument(
                    'database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(),
                    $rowId
                );
            });
        } catch (NotFoundException $e) {
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
            ->addMetric(METRIC_DATABASES_OPERATIONS_WRITES, 1)
            ->addMetric(str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_OPERATIONS_WRITES), 1); // per collection

        $response->addHeader('X-Debug-Operations', 1);

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
            ->setPayload($response->output($row, Response::MODEL_DOCUMENT), sensitive: $relationships);

        $response->noContent();
    });

App::get('/v1/databases/usage')
    ->desc('Get databases usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: null,
        name: 'getUsage',
        description: '/docs/references/databases/get-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_DATABASES,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), '`Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_DATABASES,
            METRIC_COLLECTIONS,
            METRIC_DOCUMENTS,
            METRIC_DATABASES_STORAGE,
            METRIC_DATABASES_OPERATIONS_READS,
            METRIC_DATABASES_OPERATIONS_WRITES,
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result = $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] = $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }
        $response->dynamic(new Document([
            'range' => $range,
            'databasesTotal' => $usage[$metrics[0]]['total'],
            'collectionsTotal' => $usage[$metrics[1]]['total'],
            'documentsTotal' => $usage[$metrics[2]]['total'],
            'storageTotal' => $usage[$metrics[3]]['total'],
            'databasesReadsTotal' => $usage[$metrics[4]]['total'],
            'databasesWritesTotal' => $usage[$metrics[5]]['total'],
            'databases' => $usage[$metrics[0]]['data'],
            'collections' => $usage[$metrics[1]]['data'],
            'documents' => $usage[$metrics[2]]['data'],
            'storage' => $usage[$metrics[3]]['data'],
            'databasesReads' => $usage[$metrics[4]]['data'],
            'databasesWrites' => $usage[$metrics[5]]['data'],
        ]), Response::MODEL_USAGE_DATABASES);
    });

App::get('/v1/databases/:databaseId/usage')
    ->desc('Get database usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: null,
        name: 'getDatabaseUsage',
        description: '/docs/references/databases/get-database-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_DATABASE,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), '`Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $range, Response $response, Database $dbForProject) {

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_COLLECTIONS),
            str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_DOCUMENTS),
            str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_STORAGE),
            str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASES_OPERATIONS_READS),
            str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASES_OPERATIONS_WRITES)
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result = $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] = $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $response->dynamic(new Document([
            'range' => $range,
            'collectionsTotal' => $usage[$metrics[0]]['total'],
            'documentsTotal' => $usage[$metrics[1]]['total'],
            'storageTotal' => $usage[$metrics[2]]['total'],
            'databaseReadsTotal' => $usage[$metrics[3]]['total'],
            'databaseWritesTotal' => $usage[$metrics[4]]['total'],
            'collections' => $usage[$metrics[0]]['data'],
            'documents' => $usage[$metrics[1]]['data'],
            'storage' => $usage[$metrics[2]]['data'],
            'databaseReads' => $usage[$metrics[3]]['data'],
            'databaseWrites' => $usage[$metrics[4]]['data'],
        ]), Response::MODEL_USAGE_DATABASE);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/usage')
    ->alias('/v1/databases/:databaseId/collections/:tableId/usage')
    ->desc('Get table usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: null,
        name: 'getTableUsage',
        description: '/docs/references/databases/get-collection-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_COLLECTION,
            )
        ],
        contentType: ContentType::JSON,
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
    ->param('tableId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $range, string $tableId, Response $response, Database $dbForProject) {

        $database = $dbForProject->getDocument('databases', $databaseId);
        $tableDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        $table = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $tableDocument->getInternalId());

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getInternalId(), $tableDocument->getInternalId()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS),
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result = $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] = $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $response->dynamic(new Document([
            'range' => $range,
            'documentsTotal' => $usage[$metrics[0]]['total'],
            'documents' => $usage[$metrics[0]]['data'],
        ]), Response::MODEL_USAGE_COLLECTION);
    });
