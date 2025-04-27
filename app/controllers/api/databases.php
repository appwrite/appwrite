<?php

use Appwrite\Auth\Auth;
use Appwrite\Detector\Detector;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Attributes;
use Appwrite\Utopia\Database\Validator\Queries\Collections;
use Appwrite\Utopia\Database\Validator\Queries\Databases;
use Appwrite\Utopia\Database\Validator\Queries\Indexes;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Index as IndexValidator;
use Utopia\Database\Validator\IndexDependency as IndexDependencyValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Integer;
use Utopia\Validator\IP;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

/**
 * * Create column of varying type
 *
 * @param string $databaseId
 * @param string $tableId
 * @param Document $column
 * @param Response $response
 * @param Database $dbForProject
 * @param EventDatabase $queueForDatabase
 * @param Event $queueForEvents
 * @return Document Newly created attribute document
 * @throws AuthorizationException
 * @throws Exception
 * @throws LimitException
 * @throws RestrictedException
 * @throws StructureException
 * @throws \Utopia\Database\Exception
 * @throws ConflictException
 * @throws Exception
 */
function createColumn(string $databaseId, string $tableId, Document $column, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): Document
{
    $key = $column->getAttribute('key');
    $type = $column->getAttribute('type', '');
    $size = $column->getAttribute('size', 0);
    $required = $column->getAttribute('required', true);
    $signed = $column->getAttribute('signed', true); // integers are signed by default
    $array = $column->getAttribute('array', false);
    $format = $column->getAttribute('format', '');
    $formatOptions = $column->getAttribute('formatOptions', []);
    $filters = $column->getAttribute('filters', []); // filters are hidden from the endpoint
    $default = $column->getAttribute('default');
    $options = $column->getAttribute('options', []);

    $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

    if ($db->isEmpty()) {
        throw new Exception(Exception::DATABASE_NOT_FOUND);
    }

    $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

    if ($table->isEmpty()) {
        throw new Exception(Exception::COLLECTION_NOT_FOUND);
    }

    if (!empty($format)) {
        if (!Structure::hasFormat($format, $type)) {
            throw new Exception(Exception::ATTRIBUTE_FORMAT_UNSUPPORTED, "Format {$format} not available for {$type} columns.");
        }
    }

    // Must throw here since dbForProject->createAttribute is performed by db worker
    if ($required && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required column');
    }

    if ($array && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array columns');
    }

    if ($type === Database::VAR_RELATIONSHIP) {
        $options['side'] = Database::RELATION_SIDE_PARENT;
        $relatedTable = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection'] ?? '');
        if ($relatedTable->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND, 'The related table was not found.');
        }
    }

    try {
        $column = new Document([
            '$id' => ID::custom($db->getInternalId() . '_' . $table->getInternalId() . '_' . $key),
            'key' => $key,
            'databaseInternalId' => $db->getInternalId(),
            'databaseId' => $db->getId(),
            'collectionInternalId' => $table->getInternalId(),
            'collectionId' => $tableId,
            'type' => $type,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'size' => $size,
            'required' => $required,
            'signed' => $signed,
            'default' => $default,
            'array' => $array,
            'format' => $format,
            'formatOptions' => $formatOptions,
            'filters' => $filters,
            'options' => $options,
        ]);

        $dbForProject->checkAttribute($table, $column);
        $column = $dbForProject->createDocument('attributes', $column);
    } catch (DuplicateException) {
        throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
    } catch (LimitException) {
        throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
    } catch (\Throwable $e) {
        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $table->getInternalId());
        throw $e;
    }

    $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);
    $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $table->getInternalId());

    if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
        $twoWayKey = $options['twoWayKey'];
        $options['relatedCollection'] = $table->getId();
        $options['twoWayKey'] = $key;
        $options['side'] = Database::RELATION_SIDE_CHILD;

        try {
            $twoWayAttribute = new Document([
                '$id' => ID::custom($db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $twoWayKey),
                'key' => $twoWayKey,
                'databaseInternalId' => $db->getInternalId(),
                'databaseId' => $db->getId(),
                'collectionInternalId' => $relatedTable->getInternalId(),
                'collectionId' => $relatedTable->getId(),
                'type' => $type,
                'status' => 'processing', // processing, available, failed, deleting, stuck
                'size' => $size,
                'required' => $required,
                'signed' => $signed,
                'default' => $default,
                'array' => $array,
                'format' => $format,
                'formatOptions' => $formatOptions,
                'filters' => $filters,
                'options' => $options,
            ]);

            $dbForProject->checkAttribute($relatedTable, $twoWayAttribute);
            $dbForProject->createDocument('attributes', $twoWayAttribute);
        } catch (DuplicateException) {
            $dbForProject->deleteDocument('attributes', $column->getId());
            throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
        } catch (LimitException) {
            $dbForProject->deleteDocument('attributes', $column->getId());
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
        } catch (\Throwable $e) {
            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedTable->getId());
            $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedTable->getInternalId());
            throw $e;
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedTable->getId());
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedTable->getInternalId());
    }

    $queueForDatabase
        ->setType(DATABASE_TYPE_CREATE_ATTRIBUTE)
        ->setDatabase($db)
        ->setTable($table)
        ->setRow($column);

    $queueForEvents
        ->setContext('table', $table)
        ->setContext('database', $db)
        ->setParam('databaseId', $databaseId)
        ->setParam('tableId', $table->getId())
        ->setParam('columnId', $column->getId());

    $response->setStatusCode(Response::STATUS_CODE_CREATED);

    return $column;
}

function updateColumn(
    string $databaseId,
    string $tableId,
    string $key,
    Database $dbForProject,
    Event $queueForEvents,
    string $type,
    int $size = null,
    string $filter = null,
    string|bool|int|float $default = null,
    bool $required = null,
    int|float|null $min = null,
    int|float|null $max = null,
    array $elements = null,
    array $options = [],
    string $newKey = null,
): Document {
    $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

    if ($db->isEmpty()) {
        throw new Exception(Exception::DATABASE_NOT_FOUND);
    }

    $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

    if ($table->isEmpty()) {
        throw new Exception(Exception::COLLECTION_NOT_FOUND);
    }

    $column = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key);

    if ($column->isEmpty()) {
        throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
    }

    if ($column->getAttribute('status') !== 'available') {
        throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE);
    }

    if ($column->getAttribute(('type') !== $type)) {
        throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
    }

    if ($column->getAttribute('type') === Database::VAR_STRING && $column->getAttribute(('filter') !== $filter)) {
        throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
    }

    if ($required && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required column');
    }

    if ($column->getAttribute('array', false) && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array columns');
    }

    $tableId =  'database_' . $db->getInternalId() . '_collection_' . $table->getInternalId();

    $column
        ->setAttribute('default', $default)
        ->setAttribute('required', $required);

    if (!empty($size)) {
        $column->setAttribute('size', $size);
    }

    switch ($column->getAttribute('format')) {
        case APP_DATABASE_ATTRIBUTE_INT_RANGE:
        case APP_DATABASE_ATTRIBUTE_FLOAT_RANGE:
            $min ??= $column->getAttribute('formatOptions')['min'];
            $max ??= $column->getAttribute('formatOptions')['max'];

            if ($min > $max) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
            }

            if ($column->getAttribute('format') === APP_DATABASE_ATTRIBUTE_INT_RANGE) {
                $validator = new Range($min, $max, Database::VAR_INTEGER);
            } else {
                $validator = new Range($min, $max, Database::VAR_FLOAT);

                if (!is_null($default)) {
                    $default = \floatval($default);
                }
            }

            if (!is_null($default) && !$validator->isValid($default)) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
            }

            $options = [
                'min' => $min,
                'max' => $max
            ];
            $column->setAttribute('formatOptions', $options);

            break;
        case APP_DATABASE_ATTRIBUTE_ENUM:
            if (empty($elements)) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Enum elements must not be empty');
            }

            foreach ($elements as $element) {
                if (\strlen($element) === 0) {
                    throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Each enum element must not be empty');
                }
            }

            if (!is_null($default) && !in_array($default, $elements)) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
            }

            $options = [
                'elements' => $elements
            ];

            $column->setAttribute('formatOptions', $options);

            break;
    }

    if ($type === Database::VAR_RELATIONSHIP) {
        $primaryRowOptions = \array_merge($column->getAttribute('options', []), $options);
        $column->setAttribute('options', $primaryRowOptions);
        try {
            $dbForProject->updateRelationship(
                collection: $tableId,
                id: $key,
                newKey: $newKey,
                onDelete: $primaryRowOptions['onDelete'],
            );
        } catch (NotFoundException) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        if ($primaryRowOptions['twoWay']) {
            $relatedTable = $dbForProject->getDocument('database_' . $db->getInternalId(), $primaryRowOptions['relatedCollection']);

            $relatedColumn = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $primaryRowOptions['twoWayKey']);

            if (!empty($newKey) && $newKey !== $key) {
                $options['twoWayKey'] = $newKey;
            }

            $relatedOptions = \array_merge($relatedColumn->getAttribute('options'), $options);
            $relatedColumn->setAttribute('options', $relatedOptions);
            $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $primaryRowOptions['twoWayKey'], $relatedColumn);

            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedTable->getId());
        }
    } else {
        try {
            $dbForProject->updateAttribute(
                collection: $tableId,
                id: $key,
                size: $size,
                required: $required,
                default: $default,
                formatOptions: $options,
                newKey: $newKey ?? null
            );
        } catch (TruncateException) {
            throw new Exception(Exception::ATTRIBUTE_INVALID_RESIZE);
        } catch (NotFoundException) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        } catch (LimitException) {
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
        } catch (IndexException $e) {
            throw new Exception(Exception::INDEX_INVALID, $e->getMessage());
        }
    }

    if (!empty($newKey) && $key !== $newKey) {
        $originalUid = $column->getId();

        $column
            ->setAttribute('$id', ID::custom($db->getInternalId() . '_' . $table->getInternalId() . '_' . $newKey))
            ->setAttribute('key', $newKey);

        $dbForProject->updateDocument('attributes', $originalUid, $column);

        /**
         * @var Document $index
         */
        foreach ($table->getAttribute('indexes') as $index) {
            /**
             * @var string[] $columns
             */
            $columns = $index->getAttribute('attributes', []);
            $found = \array_search($key, $columns);

            if ($found !== false) {
                $columns[$found] = $newKey;
                $index->setAttribute('attributes', $columns);
                $dbForProject->updateDocument('indexes', $index->getId(), $index);
            }
        }
    } else {
        $column = $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key, $column);
    }

    $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $table->getId());

    $queueForEvents
        ->setContext('table', $table)
        ->setContext('database', $db)
        ->setParam('databaseId', $databaseId)
        ->setParam('tableId', $table->getId())
        ->setParam('columnId', $column->getId());

    return $column;
}

App::init()
    ->groups(['api', 'database'])
    ->inject('request')
    ->inject('dbForProject')
    ->action(function (Request $request, Database $dbForProject) {
        $timeout = \intval($request->getHeader('x-appwrite-timeout'));

        if (!empty($timeout) && App::isDevelopment()) {
            $dbForProject->setTimeout($timeout);
        }
    });

App::post('/v1/databases')
    ->desc('Create database')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].create')
    ->label('scope', 'databases.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'database.create')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'create',
        description: '/docs/references/databases/create.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_DATABASE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Database name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(), 'Is the database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $name, bool $enabled, Response $response, Database $dbForProject, Event $queueForEvents) {

        $databaseId = $databaseId == 'unique()' ? ID::unique() : $databaseId;

        try {
            $dbForProject->createDocument('databases', new Document([
                '$id' => $databaseId,
                'name' => $name,
                'enabled' => $enabled,
                'search' => implode(' ', [$databaseId, $name]),
            ]));
            $database = $dbForProject->getDocument('databases', $databaseId);

            $collections = (Config::getParam('collections', [])['databases'] ?? [])['collections'] ?? [];
            if (empty($collections)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The "collections" collection is not configured.');
            }

            $attributes = [];
            $indexes = [];

            foreach ($collections['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => $attribute['$id'],
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                    'default' => $attribute['default'] ?? null,
                    'format' => $attribute['format'] ?? ''
                ]);
            }

            foreach ($collections['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => $index['$id'],
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }
            $dbForProject->createCollection('database_' . $database->getInternalId(), $attributes, $indexes);
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('databaseId', $database->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($database, Response::MODEL_DATABASE);
    });

App::get('/v1/databases')
    ->desc('List databases')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'list',
        description: '/docs/references/databases/list.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DATABASE_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('queries', [], new Databases(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Databases::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {
        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $databaseId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('databases', $databaseId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Database '{$databaseId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $databases = $dbForProject->find('databases', $queries);
            $total = $dbForProject->count('databases', $filterQueries, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $response->dynamic(new Document([
            'databases' => $databases,
            'total' => $total,
        ]), Response::MODEL_DATABASE_LIST);
    });

App::get('/v1/databases/:databaseId')
    ->desc('Get database')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'get',
        description: '/docs/references/databases/get.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DATABASE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, Response $response, Database $dbForProject) {

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $response->dynamic($database, Response::MODEL_DATABASE);
    });

App::get('/v1/databases/:databaseId/logs')
    ->desc('List database logs')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'logs',
        name: 'listLogs',
        description: '/docs/references/databases/get-logs.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
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
        $resource = 'database/' . $databaseId;
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
                'userId' => ID::custom($log['data']['userId']),
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


App::put('/v1/databases/:databaseId')
    ->desc('Update database')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'databases.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].update')
    ->label('audits.event', 'database.update')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'update',
        description: '/docs/references/databases/update.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_DATABASE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('name', null, new Text(128), 'Database name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(), 'Is database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $name, bool $enabled, Response $response, Database $dbForProject, Event $queueForEvents) {

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $database = $dbForProject->updateDocument('databases', $databaseId, $database
            ->setAttribute('name', $name)
            ->setAttribute('enabled', $enabled)
            ->setAttribute('search', implode(' ', [$databaseId, $name])));

        $queueForEvents->setParam('databaseId', $database->getId());

        $response->dynamic($database, Response::MODEL_DATABASE);
    });

App::delete('/v1/databases/:databaseId')
    ->desc('Delete database')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'databases.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].delete')
    ->label('audits.event', 'database.delete')
    ->label('audits.resource', 'database/{request.databaseId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'databases',
        name: 'delete',
        description: '/docs/references/databases/delete.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, StatsUsage $queueForStatsUsage) {

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('databases', $databaseId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove collection from DB');
        }

        $dbForProject->purgeCachedDocument('databases', $database->getId());
        $dbForProject->purgeCachedCollection('databases_' . $database->getInternalId());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_DATABASE)
            ->setDatabase($database);

        $queueForEvents
            ->setParam('databaseId', $database->getId())
            ->setPayload($response->output($database, Response::MODEL_DATABASE));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/tables')
    ->alias('/v1/databases/:databaseId/collections')
    ->desc('Create table')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].tables.[tableId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'table.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'tables',
        name: 'createTable',
        description: '/docs/references/databases/create-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_COLLECTION,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Table name. Max length: 128 chars.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, Response $response, Database $dbForProject, string $mode, Event $queueForEvents) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $tableId = $tableId == 'unique()' ? ID::unique() : $tableId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions) ?? [];

        try {
            $table = $dbForProject->createDocument('database_' . $database->getInternalId(), new Document([
                '$id' => $tableId,
                'databaseInternalId' => $database->getInternalId(),
                'databaseId' => $databaseId,
                '$permissions' => $permissions,
                'documentSecurity' => $documentSecurity,
                'enabled' => $enabled,
                'name' => $name,
                'search' => implode(' ', [$tableId, $name]),
            ]));

            $dbForProject->createCollection('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), permissions: $permissions, documentSecurity: $documentSecurity);
        } catch (DuplicateException) {
            throw new Exception(Exception::COLLECTION_ALREADY_EXISTS);
        } catch (LimitException) {
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED);
        }

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($table, Response::MODEL_COLLECTION);
    });

App::get('/v1/databases/:databaseId/tables')
    ->alias('/v1/databases/:databaseId/collections')
    ->desc('List tables')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'tables',
        name: 'listTables',
        description: '/docs/references/databases/list-collections.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COLLECTION_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('queries', [], new Collections(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Collections::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $databaseId, array $queries, string $search, Response $response, Database $dbForProject, string $mode) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $tableId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Table '{$tableId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        try {
            $tables = $dbForProject->find('database_' . $database->getInternalId(), $queries);
            $total = $dbForProject->count('database_' . $database->getInternalId(), $filterQueries, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        // TODO: collections > tables
        $response->dynamic(new Document([
            'collections' => $tables,
            'total' => $total,
        ]), Response::MODEL_COLLECTION_LIST);
    });

App::get('/v1/databases/:databaseId/tables/:tableId')
    ->alias('/v1/databases/:databaseId/collections/:tableId')
    ->desc('Get table')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'tables',
        name: 'getTable',
        description: '/docs/references/databases/get-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COLLECTION,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $tableId, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $response->dynamic($table, Response::MODEL_COLLECTION);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/logs')
    ->alias('/v1/databases/:databaseId/collections/:tableId/logs')
    ->desc('List table logs')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'tables',
        name: 'listTableLogs',
        description: '/docs/references/databases/get-collection-logs.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, string $tableId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $tableDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        $table = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $tableDocument->getInternalId());

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
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
        $resource = 'database/' . $databaseId . '/table/' . $tableId;
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


App::put('/v1/databases/:databaseId/tables/:tableId')
    ->alias('/v1/databases/:databaseId/collections/:tableId')
    ->desc('Update table')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].update')
    ->label('audits.event', 'table.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'tables',
        name: 'updateTable',
        description: '/docs/references/databases/update-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COLLECTION,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID.')
    ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, Response $response, Database $dbForProject, string $mode, Event $queueForEvents) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $permissions ??= $table->getPermissions() ?? [];

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        $enabled ??= $table->getAttribute('enabled', true);

        $table = $dbForProject->updateDocument(
            'database_' . $database->getInternalId(),
            $tableId,
            $table
            ->setAttribute('name', $name)
            ->setAttribute('$permissions', $permissions)
            ->setAttribute('documentSecurity', $documentSecurity)
            ->setAttribute('enabled', $enabled)
            ->setAttribute('search', \implode(' ', [$tableId, $name]))
        );

        $dbForProject->updateCollection('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $permissions, $documentSecurity);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId());

        $response->dynamic($table, Response::MODEL_COLLECTION);
    });

App::delete('/v1/databases/:databaseId/tables/:tableId')
    ->alias('/v1/databases/:databaseId/collections/:tableId')
    ->desc('Delete table')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].delete')
    ->label('audits.event', 'table.delete')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'tables',
        name: 'deleteTable',
        description: '/docs/references/databases/delete-collection.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->inject('mode')
    ->action(function (string $databaseId, string $tableId, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, string $mode) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('database_' . $database->getInternalId(), $tableId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove collection from DB');
        }

        $dbForProject->purgeCachedCollection('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_COLLECTION)
            ->setDatabase($database)
            ->setTable($table);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setPayload($response->output($table, Response::MODEL_COLLECTION));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/string')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/string')
    ->desc('Create string column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createStringColumn',
        description: '/docs/references/databases/create-string-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_STRING
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Range::TYPE_INTEGER), 'Attribute size for text attributes, in number of characters.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Text(0, 0), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->param('encrypt', false, new Boolean(), 'Toggle encryption for the column. Encryption enhances security by not storing any plain text values in the database. However, encrypted columns cannot be queried.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?int $size, ?bool $required, ?string $default, bool $array, bool $encrypt, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        // Ensure attribute default is within required size
        $validator = new Text($size, 0);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $filters = [];

        if ($encrypt) {
            $filters[] = 'encrypt';
        }

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'filters' => $filters,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_STRING);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/email')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/email')
    ->desc('Create email column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createEmailColumn',
        description: '/docs/references/databases/create-email-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_EMAIL,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Email(), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 254,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_EMAIL,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::post('/v1/databases/:databaseId/collections/:tableId/attributes/enum')
    ->alias('/v1/database/collections/:tableId/attributes/enum')
    ->desc('Create enum column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createEnumColumn',
        description: '/docs/references/databases/create-attribute-enum.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_ENUM,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('elements', [], new ArrayList(new Text(DATABASE::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each ' . DATABASE::LENGTH_KEY . ' characters long.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Text(0), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, array $elements, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        if (!is_null($default) && !in_array($default, $elements)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
        }

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => Database::LENGTH_KEY,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_ENUM,
            'formatOptions' => ['elements' => $elements],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/ip')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/ip')
    ->desc('Create IP address column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createIpColumn',
        description: '/docs/references/databases/create-ip-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_IP,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new IP(), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 39,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_IP,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_IP);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/url')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/url')
    ->desc('Create URL column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createUrlColumn',
        description: '/docs/references/databases/create-url-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_URL,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new URL(), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 2000,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_URL,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_URL);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/integer')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/integer')
    ->desc('Create integer column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createIntegerColumn',
        description: '/docs/references/databases/create-integer-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_INTEGER,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Integer(), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        // Ensure attribute default is within range
        $min ??= PHP_INT_MIN;
        $max ??= PHP_INT_MAX;

        if ($min > $max) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Database::VAR_INTEGER);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $size = $max > 2147483647 ? 8 : 4; // Automatically create BigInt depending on max value

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_INTEGER,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
            'formatOptions' => [
                'min' => $min,
                'max' => $max,
            ],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $formatOptions = $column->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $column->setAttribute('min', \intval($formatOptions['min']));
            $column->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/float')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/float')
    ->desc('Create float column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createFloatColumn',
        description: '/docs/references/databases/create-float-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_FLOAT,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new FloatValidator(), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        // Ensure attribute default is within range
        $min ??= -PHP_FLOAT_MAX;
        $max ??= PHP_FLOAT_MAX;

        if ($min > $max) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Database::VAR_FLOAT);

        if (!\is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_FLOAT,
            'required' => $required,
            'size' => 0,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_FLOAT_RANGE,
            'formatOptions' => [
                'min' => $min,
                'max' => $max,
            ],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $formatOptions = $column->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $column->setAttribute('min', \floatval($formatOptions['min']));
            $column->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/boolean')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/boolean')
    ->desc('Create boolean column')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createBooleanColumn',
        description: '/docs/references/databases/create-boolean-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_BOOLEAN,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Boolean(), 'Default value for column when not provided. Cannot be set when column is required.', true)
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?bool $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/datetime')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/datetime')
    ->desc('Create datetime column')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createDatetimeColumn',
        description: '/docs/references/databases/create-datetime-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_DATETIME,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, fn (Database $dbForProject) => new DatetimeValidator($dbForProject->getAdapter()->getMinDateTime(), $dbForProject->getAdapter()->getMaxDateTime()), 'Default value for the attribute in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Cannot be set when column is required.', true, ['dbForProject'])
    ->param('array', false, new Boolean(), 'Is column an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $filters[] = 'datetime';

        $column = createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_DATETIME,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'filters' => $filters,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_DATETIME);
    });

App::post('/v1/databases/:databaseId/tables/:tableId/columns/relationship')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/relationship')
    ->desc('Create relationship column')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'column.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'createRelationshipColumn',
        description: '/docs/references/databases/create-relationship-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('relatedTableId', '', new UID(), 'Related Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('type', '', new WhiteList([Database::RELATION_ONE_TO_ONE, Database::RELATION_MANY_TO_ONE, Database::RELATION_MANY_TO_MANY, Database::RELATION_ONE_TO_MANY], true), 'Relation type')
    ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
    ->param('key', null, new Key(), 'Column Key.', true)
    ->param('twoWayKey', null, new Key(), 'Two Way Column Key.', true)
    ->param('onDelete', Database::RELATION_MUTATE_RESTRICT, new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL], true), 'Constraints option', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (
        string $databaseId,
        string $tableId,
        string $relatedTableId,
        string $type,
        bool $twoWay,
        ?string $key,
        ?string $twoWayKey,
        string $onDelete,
        Response $response,
        Database $dbForProject,
        EventDatabase $queueForDatabase,
        Event $queueForEvents
    ) {
        $key ??= $relatedTableId;
        $twoWayKey ??= $tableId;

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        $table = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId());

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $relatedTableDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedTableId);
        $relatedTable = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $relatedTableDocument->getInternalId());

        if ($relatedTable->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $columns = $table->getAttribute('attributes', []);
        /** @var Document[] $columns */
        foreach ($columns as $column) {
            if ($column->getAttribute('type') !== Database::VAR_RELATIONSHIP) {
                continue;
            }

            if (\strtolower($column->getId()) === \strtolower($key)) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
            }

            if (
                \strtolower($column->getAttribute('options')['twoWayKey']) === \strtolower($twoWayKey) &&
                $column->getAttribute('options')['relatedCollection'] === $relatedTable->getId()
            ) {
                // Console should provide a unique twoWayKey input!
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Attribute with the requested key already exists. Attribute keys must be unique, try again with a different key.');
            }

            if (
                $type === Database::RELATION_MANY_TO_MANY &&
                $column->getAttribute('options')['relationType'] === Database::RELATION_MANY_TO_MANY &&
                $column->getAttribute('options')['relatedCollection'] === $relatedTable->getId()
            ) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Creating more than one "manyToMany" relationship on the same table is currently not permitted.');
            }
        }

        $column = createColumn(
            $databaseId,
            $tableId,
            new Document([
                'key' => $key,
                'type' => Database::VAR_RELATIONSHIP,
                'size' => 0,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
                'options' => [
                    'relatedCollection' => $relatedTableId,
                    'relationType' => $type,
                    'twoWay' => $twoWay,
                    'twoWayKey' => $twoWayKey,
                    'onDelete' => $onDelete,
                ]
            ]),
            $response,
            $dbForProject,
            $queueForDatabase,
            $queueForEvents
        );

        $options = $column->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $column->setAttribute($key, $option);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_RELATIONSHIP);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/columns')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes')
    ->desc('List columns')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'listColumns',
        description: '/docs/references/databases/list-attributes.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_LIST
            )
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new Attributes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Attributes::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $tableId, array $queries, Response $response, Database $dbForProject) {
        /** @var Document $database */
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);

        \array_push(
            $queries,
            Query::equal('databaseInternalId', [$database->getInternalId()]),
            Query::equal('collectionInternalId', [$table->getInternalId()]),
        );

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

            $columnId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->find('attributes', [
                Query::equal('databaseInternalId', [$database->getInternalId()]),
                Query::equal('collectionInternalId', [$table->getInternalId()]),
                Query::equal('key', [$columnId]),
                Query::limit(1),
            ]));

            if (empty($cursorDocument) || $cursorDocument[0]->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Column '{$columnId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        $filters = Query::groupByType($queries)['filters'];
        try {
            $columns = $dbForProject->find('attributes', $queries);
            $total = $dbForProject->count('attributes', $filters, APP_LIMIT_COUNT);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        }

        $response->dynamic(new Document([
            'attributes' => $columns,
            'total' => $total,
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/columns/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/:key')
    ->desc('Get column')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'getColumn',
        description: '/docs/references/databases/get-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: [
                    Response::MODEL_ATTRIBUTE_BOOLEAN,
                    Response::MODEL_ATTRIBUTE_INTEGER,
                    Response::MODEL_ATTRIBUTE_FLOAT,
                    Response::MODEL_ATTRIBUTE_EMAIL,
                    Response::MODEL_ATTRIBUTE_ENUM,
                    Response::MODEL_ATTRIBUTE_URL,
                    Response::MODEL_ATTRIBUTE_IP,
                    Response::MODEL_ATTRIBUTE_DATETIME,
                    Response::MODEL_ATTRIBUTE_RELATIONSHIP,
                    Response::MODEL_ATTRIBUTE_STRING
                ]
            ),
        ]
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $tableId, string $key, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $column = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $table->getInternalId() . '_' . $key);

        if ($column->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        // Select response model based on type and format
        $type = $column->getAttribute('type');
        $format = $column->getAttribute('format');
        $options = $column->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $column->setAttribute($key, $option);
        }

        $model = match ($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_DATETIME => Response::MODEL_ATTRIBUTE_DATETIME,
            Database::VAR_RELATIONSHIP => Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $response->dynamic($column, $model);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/string/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/string/:key')
    ->desc('Update string column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateStringColumn',
        description: '/docs/references/databases/update-string-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_STRING,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Range::TYPE_INTEGER), 'Maximum size of the string attribute.', true)
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, ?int $size, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {

        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            size: $size,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_STRING);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/email/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/email/:key')
    ->desc('Update email column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateEmailColumn',
        description: '/docs/references/databases/update-email-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_EMAIL,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Nullable(new Email()), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_EMAIL,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/enum/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/enum/:key')
    ->desc('Update enum column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateEnumColumn',
        description: '/docs/references/databases/update-enum-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_ENUM,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('elements', null, new ArrayList(new Text(DATABASE::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each ' . DATABASE::LENGTH_KEY . ' characters long.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Nullable(new Text(0)), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?array $elements, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_ENUM,
            default: $default,
            required: $required,
            elements: $elements,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/ip/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/ip/:key')
    ->desc('Update IP address column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateIpColumn',
        description: '/docs/references/databases/update-ip-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_IP,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Nullable(new IP()), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_IP,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_IP);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/url/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/url/:key')
    ->desc('Update URL column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateUrlColumn',
        description: '/docs/references/databases/update-url-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_URL,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Nullable(new URL()), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_URL,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_URL);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/integer/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/integer/:key')
    ->desc('Update integer column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateIntegerColumn',
        description: '/docs/references/databases/update-integer-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_INTEGER,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Nullable(new Integer()), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_INTEGER,
            default: $default,
            required: $required,
            min: $min,
            max: $max,
            newKey: $newKey
        );

        $formatOptions = $column->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $column->setAttribute('min', \intval($formatOptions['min']));
            $column->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/float/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/float/:key')
    ->desc('Update float column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateFloatColumn',
        description: '/docs/references/databases/update-float-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_FLOAT,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Nullable(new FloatValidator()), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_FLOAT,
            default: $default,
            required: $required,
            min: $min,
            max: $max,
            newKey: $newKey
        );

        $formatOptions = $column->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $column->setAttribute('min', \floatval($formatOptions['min']));
            $column->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/boolean/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/boolean/:key')
    ->desc('Update boolean column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateBooleanColumn',
        description: '/docs/references/databases/update-boolean-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_BOOLEAN,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, new Nullable(new Boolean()), 'Default value for column when not provided. Cannot be set when column is required.')
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?bool $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_BOOLEAN,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/datetime/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/datetime/:key')
    ->desc('Update dateTime column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateDatetimeColumn',
        description: '/docs/references/databases/update-datetime-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_DATETIME,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('required', null, new Boolean(), 'Is column required?')
    ->param('default', null, fn (Database $dbForProject) => new Nullable(new DatetimeValidator($dbForProject->getAdapter()->getMinDateTime(), $dbForProject->getAdapter()->getMaxDateTime())), 'Default value for column when not provided. Cannot be set when column is required.', injections: ['dbForProject'])
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $column = updateColumn(
            databaseId: $databaseId,
            tableId: $tableId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_DATETIME,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_DATETIME);
    });

App::patch('/v1/databases/:databaseId/tables/:tableId/columns/:key/relationship')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/:key/relationship')
    ->desc('Update relationship column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.update')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'updateRelationshipColumn',
        description: '/docs/references/databases/update-relationship-attribute.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Column Key.')
    ->param('onDelete', null, new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL], true), 'Constraints option', true)
    ->param('newKey', null, new Key(), 'New Column Key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (
        string $databaseId,
        string $tableId,
        string $key,
        ?string $onDelete,
        ?string $newKey,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $column = updateColumn(
            $databaseId,
            $tableId,
            $key,
            $dbForProject,
            $queueForEvents,
            type: Database::VAR_RELATIONSHIP,
            required: false,
            options: [
                'onDelete' => $onDelete
            ],
            newKey: $newKey
        );

        $options = $column->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $column->setAttribute($key, $option);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($column, Response::MODEL_ATTRIBUTE_RELATIONSHIP);
    });

App::delete('/v1/databases/:databaseId/tables/:tableId/columns/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/attributes/:key')
    ->desc('Delete column')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
    ->label('audits.event', 'column.delete')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'columns',
        name: 'deleteColumn',
        description: '/docs/references/databases/delete-attribute.md',
        auth: [AuthType::KEY],
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
    ->param('key', '', new Key(), 'Column Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->action(function (string $databaseId, string $tableId, string $key, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, StatsUsage $queueForStatsUsage) {

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $column = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key);

        if ($column->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        /**
         * Check index dependency
         */
        $validator = new IndexDependencyValidator(
            $table->getAttribute('indexes'),
            $dbForProject->getAdapter()->getSupportForCastIndexArray(),
        );

        if (! $validator->isValid($column)) {
            throw new Exception(Exception::INDEX_DEPENDENCY);
        }

        // Only update status if removing available attribute
        if ($column->getAttribute('status') === 'available') {
            $column = $dbForProject->updateDocument('attributes', $column->getId(), $column->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $table->getInternalId());

        if ($column->getAttribute('type') === Database::VAR_RELATIONSHIP) {
            $options = $column->getAttribute('options');
            if ($options['twoWay']) {
                $relatedTable = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection']);

                if ($relatedTable->isEmpty()) {
                    throw new Exception(Exception::COLLECTION_NOT_FOUND);
                }

                $relatedColumn = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $options['twoWayKey']);

                if ($relatedColumn->isEmpty()) {
                    throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
                }

                if ($relatedColumn->getAttribute('status') === 'available') {
                    $dbForProject->updateDocument('attributes', $relatedColumn->getId(), $relatedColumn->setAttribute('status', 'deleting'));
                }

                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $options['relatedCollection']);
                $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedTable->getInternalId());
            }
        }

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_ATTRIBUTE)
            ->setTable($table)
            ->setDatabase($db)
            ->setRow($column);

        // Select response model based on type and format
        $type = $column->getAttribute('type');
        $format = $column->getAttribute('format');

        $model = match ($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_DATETIME => Response::MODEL_ATTRIBUTE_DATETIME,
            Database::VAR_RELATIONSHIP => Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('columnId', $column->getId())
            ->setContext('table', $table)
            ->setContext('database', $db)
            ->setPayload($response->output($column, $model));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/tables/:tableId/indexes')
    ->alias('/v1/databases/:databaseId/collections/:tableId/indexes')
    ->desc('Create index')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].tables.[tableId].indexes.[indexId].create')
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('audits.event', 'index.create')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'tables',
        name: 'createIndex',
        description: '/docs/references/databases/create-index.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_ACCEPTED,
                model: Response::MODEL_INDEX,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE]), 'Index type.')
    ->param('columns', null, new ArrayList(new Key(true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of columns to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' columns are allowed, each 32 characters long.')
    ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, string $type, array $columns, array $orders, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $count = $dbForProject->count('indexes', [
            Query::equal('collectionInternalId', [$table->getInternalId()]),
            Query::equal('databaseInternalId', [$db->getInternalId()])
        ], 61);

        $limit = $dbForProject->getLimitForIndexes();

        if ($count >= $limit) {
            throw new Exception(Exception::INDEX_LIMIT_EXCEEDED, 'Index limit exceeded');
        }

        // Convert Document[] to array of attribute metadata
        $oldColumns = \array_map(fn ($a) => $a->getArrayCopy(), $table->getAttribute('attributes'));

        $oldColumns[] = [
            'key' => '$id',
            'type' => Database::VAR_STRING,
            'status' => 'available',
            'required' => true,
            'array' => false,
            'default' => null,
            'size' => Database::LENGTH_KEY
        ];

        $oldColumns[] = [
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        $oldColumns[] = [
            'key' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        // lengths hidden by default
        $lengths = [];

        foreach ($columns as $i => $column) {
            // find attribute metadata in collection document
            $columnIndex = \array_search($column, array_column($oldColumns, 'key'));

            if ($columnIndex === false) {
                throw new Exception(Exception::ATTRIBUTE_UNKNOWN, 'Unknown column: ' . $column . '. Verify the column name or create the column.');
            }

            $columnStatus = $oldColumns[$columnIndex]['status'];
            $columnType = $oldColumns[$columnIndex]['type'];
            $columnArray = $oldColumns[$columnIndex]['array'] ?? false;

            if ($columnType === Database::VAR_RELATIONSHIP) {
                throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, 'Cannot create an index for a relationship column: ' . $oldColumns[$columnIndex]['key']);
            }

            // ensure attribute is available
            if ($columnStatus !== 'available') {
                throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE, 'Column not available: ' . $oldColumns[$columnIndex]['key']);
            }

            $lengths[$i] = null;

            if ($columnArray === true) {
                $lengths[$i] = Database::ARRAY_INDEX_LENGTH;
                $orders[$i] = null;
            }
        }

        $index = new Document([
            '$id' => ID::custom($db->getInternalId() . '_' . $table->getInternalId() . '_' . $key),
            'key' => $key,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'databaseInternalId' => $db->getInternalId(),
            'databaseId' => $databaseId,
            'collectionInternalId' => $table->getInternalId(),
            'collectionId' => $tableId,
            'type' => $type,
            'attributes' => $columns,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $validator = new IndexValidator(
            $table->getAttribute('attributes'),
            $dbForProject->getAdapter()->getMaxIndexLength(),
            $dbForProject->getAdapter()->getInternalIndexesKeys(),
        );
        if (!$validator->isValid($index)) {
            throw new Exception(Exception::INDEX_INVALID, $validator->getDescription());
        }

        try {
            $index = $dbForProject->createDocument('indexes', $index);
        } catch (DuplicateException) {
            throw new Exception(Exception::INDEX_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($db)
            ->setTable($table)
            ->setRow($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('table', $table)
            ->setContext('database', $db);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($index, Response::MODEL_INDEX);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/indexes')
    ->alias('/v1/databases/:databaseId/collections/:tableId/indexes')
    ->desc('List indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'indexes',
        name: 'listIndexes',
        description: '/docs/references/databases/list-indexes.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_INDEX_LIST,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new Indexes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Indexes::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $tableId, array $queries, Response $response, Database $dbForProject) {
        /** @var Document $database */
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);

        \array_push(
            $queries,
            Query::equal('databaseId', [$databaseId]),
            Query::equal('collectionId', [$tableId]),
        );

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $indexId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->find('indexes', [
                Query::equal('collectionInternalId', [$table->getInternalId()]),
                Query::equal('databaseInternalId', [$database->getInternalId()]),
                Query::equal('key', [$indexId]),
                Query::limit(1)
            ]));

            if (empty($cursorDocument) || $cursorDocument[0]->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Index '{$indexId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        try {
            $total = $dbForProject->count('indexes', $filterQueries, APP_LIMIT_COUNT);
            $indexes = $dbForProject->find('indexes', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order column '{$e->getAttribute()}' had a null value. Cursor pagination requires all rows order column values are non-null.");
        }

        $response->dynamic(new Document([
            'total' => $total,
            'indexes' => $indexes,
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('/v1/databases/:databaseId/tables/:tableId/indexes/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/indexes/:key')
    ->desc('Get index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'indexes',
        name: 'getIndex',
        description: '/docs/references/databases/get-index.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_INDEX,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $tableId, string $key, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $index = $table->find('key', $key, 'indexes');
        if (empty($index)) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        $response->dynamic($index, Response::MODEL_INDEX);
    });


App::delete('/v1/databases/:databaseId/tables/:tableId/indexes/:key')
    ->alias('/v1/databases/:databaseId/collections/:tableId/indexes/:key')
    ->desc('Delete index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('resourceType', RESOURCE_TYPE_DATABASES)
    ->label('event', 'databases.[databaseId].tables.[tableId].indexes.[indexId].update')
    ->label('audits.event', 'index.delete')
    ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
    ->label('sdk', new Method(
        namespace: 'databases',
        group: 'indexes',
        name: 'deleteIndex',
        description: '/docs/references/databases/delete-index.md',
        auth: [AuthType::KEY],
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
    ->param('key', '', new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $tableId, string $key, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $index = $dbForProject->getDocument('indexes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key);

        if (empty($index->getId())) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        // Only update status if removing available index
        if ($index->getAttribute('status') === 'available') {
            $index = $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_INDEX)
            ->setDatabase($db)
            ->setTable($table)
            ->setRow($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('table', $table)
            ->setContext('database', $db)
            ->setPayload($response->output($index, Response::MODEL_INDEX));

        $response->noContent();
    });

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
                $result =  $dbForProject->findOne('stats', [
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
            $usage[$metric]['total'] =  $stats[$metric]['total'];
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
            'databasesTotal'   => $usage[$metrics[0]]['total'],
            'collectionsTotal' => $usage[$metrics[1]]['total'],
            'documentsTotal'   => $usage[$metrics[2]]['total'],
            'storageTotal'   => $usage[$metrics[3]]['total'],
            'databasesReadsTotal' => $usage[$metrics[4]]['total'],
            'databasesWritesTotal' => $usage[$metrics[5]]['total'],
            'databases'   => $usage[$metrics[0]]['data'],
            'collections' => $usage[$metrics[1]]['data'],
            'documents'   => $usage[$metrics[2]]['data'],
            'storage'   => $usage[$metrics[3]]['data'],
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

        $database =  $dbForProject->getDocument('databases', $databaseId);

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
                $result =  $dbForProject->findOne('stats', [
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
            $usage[$metric]['total'] =  $stats[$metric]['total'];
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
            'collectionsTotal'   => $usage[$metrics[0]]['total'],
            'documentsTotal'   => $usage[$metrics[1]]['total'],
            'storageTotal'   => $usage[$metrics[2]]['total'],
            'databaseReadsTotal' => $usage[$metrics[3]]['total'],
            'databaseWritesTotal' => $usage[$metrics[4]]['total'],
            'collections'   => $usage[$metrics[0]]['data'],
            'documents'   => $usage[$metrics[1]]['data'],
            'storage'   => $usage[$metrics[2]]['data'],
            'databaseReads'   => $usage[$metrics[3]]['data'],
            'databaseWrites'   => $usage[$metrics[4]]['data'],
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
                $result =  $dbForProject->findOne('stats', [
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
                        'value' =>  $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] =  $stats[$metric]['total'];
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
            'documentsTotal'   => $usage[$metrics[0]]['total'],
            'documents'   =>  $usage[$metrics[0]]['data'],
        ]), Response::MODEL_USAGE_COLLECTION);
    });
