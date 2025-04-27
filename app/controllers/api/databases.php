<?php

use Appwrite\Auth\Auth;
use Appwrite\Detector\Detector;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email;
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
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
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
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Queries;
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
 * * Create attribute of varying type
 *
 * @param string $databaseId
 * @param string $collectionId
 * @param Document $attribute
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
function createAttribute(string $databaseId, string $collectionId, Document $attribute, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): Document
{
    $key = $attribute->getAttribute('key');
    $type = $attribute->getAttribute('type', '');
    $size = $attribute->getAttribute('size', 0);
    $required = $attribute->getAttribute('required', true);
    $signed = $attribute->getAttribute('signed', true); // integers are signed by default
    $array = $attribute->getAttribute('array', false);
    $format = $attribute->getAttribute('format', '');
    $formatOptions = $attribute->getAttribute('formatOptions', []);
    $filters = $attribute->getAttribute('filters', []); // filters are hidden from the endpoint
    $default = $attribute->getAttribute('default');
    $options = $attribute->getAttribute('options', []);

    $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

    if ($db->isEmpty()) {
        throw new Exception(Exception::DATABASE_NOT_FOUND);
    }

    $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);

    if ($collection->isEmpty()) {
        throw new Exception(Exception::COLLECTION_NOT_FOUND);
    }

    if (!empty($format)) {
        if (!Structure::hasFormat($format, $type)) {
            throw new Exception(Exception::ATTRIBUTE_FORMAT_UNSUPPORTED, "Format {$format} not available for {$type} attributes.");
        }
    }

    // Must throw here since dbForProject->createAttribute is performed by db worker
    if ($required && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required attribute');
    }

    if ($array && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array attributes');
    }

    if ($type === Database::VAR_RELATIONSHIP) {
        $options['side'] = Database::RELATION_SIDE_PARENT;
        $relatedCollection = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection'] ?? '');
        if ($relatedCollection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND, 'The related collection was not found.');
        }
    }

    try {
        $attribute = new Document([
            '$id' => ID::custom($db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key),
            'key' => $key,
            'databaseInternalId' => $db->getInternalId(),
            'databaseId' => $db->getId(),
            'collectionInternalId' => $collection->getInternalId(),
            'collectionId' => $collectionId,
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

        $dbForProject->checkAttribute($collection, $attribute);
        $attribute = $dbForProject->createDocument('attributes', $attribute);
    } catch (DuplicateException) {
        throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
    } catch (LimitException) {
        throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, 'Attribute limit exceeded');
    } catch (\Throwable $e) {
        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());
        throw $e;
    }

    $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);
    $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());

    if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
        $twoWayKey = $options['twoWayKey'];
        $options['relatedCollection'] = $collection->getId();
        $options['twoWayKey'] = $key;
        $options['side'] = Database::RELATION_SIDE_CHILD;

        try {
            $twoWayAttribute = new Document([
                '$id' => ID::custom($db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $twoWayKey),
                'key' => $twoWayKey,
                'databaseInternalId' => $db->getInternalId(),
                'databaseId' => $db->getId(),
                'collectionInternalId' => $relatedCollection->getInternalId(),
                'collectionId' => $relatedCollection->getId(),
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

            $dbForProject->checkAttribute($relatedCollection, $twoWayAttribute);
            $dbForProject->createDocument('attributes', $twoWayAttribute);
        } catch (DuplicateException) {
            $dbForProject->deleteDocument('attributes', $attribute->getId());
            throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
        } catch (LimitException) {
            $dbForProject->deleteDocument('attributes', $attribute->getId());
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, 'Attribute limit exceeded');
        } catch (\Throwable $e) {
            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedCollection->getId());
            $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
            throw $e;
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedCollection->getId());
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
    }

    $queueForDatabase
        ->setType(DATABASE_TYPE_CREATE_ATTRIBUTE)
        ->setDatabase($db)
        ->setCollection($collection)
        ->setDocument($attribute);

    $queueForEvents
        ->setContext('collection', $collection)
        ->setContext('database', $db)
        ->setParam('databaseId', $databaseId)
        ->setParam('collectionId', $collection->getId())
        ->setParam('attributeId', $attribute->getId());

    $response->setStatusCode(Response::STATUS_CODE_CREATED);

    return $attribute;
}

function updateAttribute(
    string $databaseId,
    string $collectionId,
    string $key,
    Database $dbForProject,
    Event $queueForEvents,
    string $type,
    int $size = null,
    string $filter = null,
    string|bool|int|float $default = null,
    bool $required = null,
    int|float $min = null,
    int|float $max = null,
    array $elements = null,
    array $options = [],
    string $newKey = null,
): Document {
    $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

    if ($db->isEmpty()) {
        throw new Exception(Exception::DATABASE_NOT_FOUND);
    }

    $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);

    if ($collection->isEmpty()) {
        throw new Exception(Exception::COLLECTION_NOT_FOUND);
    }

    $attribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key);

    if ($attribute->isEmpty()) {
        throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
    }

    if ($attribute->getAttribute('status') !== 'available') {
        throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE);
    }

    if ($attribute->getAttribute(('type') !== $type)) {
        throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
    }

    if ($attribute->getAttribute('type') === Database::VAR_STRING && $attribute->getAttribute(('filter') !== $filter)) {
        throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
    }

    if ($required && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required attribute');
    }

    if ($attribute->getAttribute('array', false) && isset($default)) {
        throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array attributes');
    }

    $collectionId =  'database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId();

    $attribute
        ->setAttribute('default', $default)
        ->setAttribute('required', $required);

    if (!empty($size)) {
        $attribute->setAttribute('size', $size);
    }

    $formatOptions = $attribute->getAttribute('formatOptions');

    switch ($attribute->getAttribute('format')) {
        case APP_DATABASE_ATTRIBUTE_INT_RANGE:
        case APP_DATABASE_ATTRIBUTE_FLOAT_RANGE:
            if ($min === $formatOptions['min'] && $max === $formatOptions['max']) {
                break;
            }

            if ($min > $max) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
            }

            if ($attribute->getAttribute('format') === APP_DATABASE_ATTRIBUTE_INT_RANGE) {
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
            $attribute->setAttribute('formatOptions', $options);

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

            $attribute->setAttribute('formatOptions', $options);

            break;
    }

    if ($type === Database::VAR_RELATIONSHIP) {
        $primaryDocumentOptions = \array_merge($attribute->getAttribute('options', []), $options);
        $attribute->setAttribute('options', $primaryDocumentOptions);

        $dbForProject->updateRelationship(
            collection: $collectionId,
            id: $key,
            newKey: $newKey,
            onDelete: $primaryDocumentOptions['onDelete'],
        );

        if ($primaryDocumentOptions['twoWay']) {
            $relatedCollection = $dbForProject->getDocument('database_' . $db->getInternalId(), $primaryDocumentOptions['relatedCollection']);

            $relatedAttribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $primaryDocumentOptions['twoWayKey']);

            if (!empty($newKey) && $newKey !== $key) {
                $options['twoWayKey'] = $newKey;
            }

            $relatedOptions = \array_merge($relatedAttribute->getAttribute('options'), $options);
            $relatedAttribute->setAttribute('options', $relatedOptions);
            $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $primaryDocumentOptions['twoWayKey'], $relatedAttribute);

            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedCollection->getId());
        }
    } else {
        try {
            $dbForProject->updateAttribute(
                collection: $collectionId,
                id: $key,
                size: $size,
                required: $required,
                default: $default,
                formatOptions: $options ?? null,
                newKey: $newKey ?? null
            );
        } catch (TruncateException) {
            throw new Exception(Exception::ATTRIBUTE_INVALID_RESIZE);
        }
    }

    if (!empty($newKey) && $key !== $newKey) {
        // Delete attribute and recreate since we can't modify IDs
        $original = clone $attribute;

        $dbForProject->deleteDocument('attributes', $attribute->getId());

        $attribute
            ->setAttribute('$id', ID::custom($db->getInternalId() . '_' . $collection->getInternalId() . '_' . $newKey))
            ->setAttribute('key', $newKey);

        try {
            $attribute = $dbForProject->createDocument('attributes', $attribute);
        } catch (DatabaseException|PDOException) {
            $attribute = $dbForProject->createDocument('attributes', $original);
        }
    } else {
        $attribute = $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key, $attribute);
    }

    $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collection->getId());

    $queueForEvents
        ->setContext('collection', $collection)
        ->setContext('database', $db)
        ->setParam('databaseId', $databaseId)
        ->setParam('collectionId', $collection->getId())
        ->setParam('attributeId', $attribute->getId());

    return $attribute;
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
    ->label('audits.event', 'database.create')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/databases/create.md') // create this file later
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DATABASE) // Model for database needs to be created
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/databases/list.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DATABASE_LIST)
    ->param('queries', [], new Databases(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Databases::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

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
            $databaseId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('databases', $databaseId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Database '{$databaseId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'databases' => $dbForProject->find('databases', $queries),
            'total' => $dbForProject->count('databases', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_DATABASE_LIST);
    });

App::get('/v1/databases/:databaseId')
    ->desc('Get database')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/databases/get.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DATABASE)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listLogs')
    ->label('sdk.description', '/docs/references/databases/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
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

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId;
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

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
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });


App::put('/v1/databases/:databaseId')
    ->desc('Update database')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'databases.write')
    ->label('event', 'databases.[databaseId].update')
    ->label('audits.event', 'database.update')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/databases/update.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DATABASE)
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
    ->label('event', 'databases.[databaseId].delete')
    ->label('audits.event', 'database.delete')
    ->label('audits.resource', 'database/{request.databaseId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/databases/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

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

App::post('/v1/databases/:databaseId/collections')
    ->desc('Create collection')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'collection.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'createCollection')
    ->label('sdk.description', '/docs/references/databases/create-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, Response $response, Database $dbForProject, string $mode, Event $queueForEvents) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionId = $collectionId == 'unique()' ? ID::unique() : $collectionId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        try {
            $dbForProject->createDocument('database_' . $database->getInternalId(), new Document([
                '$id' => $collectionId,
                'databaseInternalId' => $database->getInternalId(),
                'databaseId' => $databaseId,
                '$permissions' => $permissions ?? [],
                'documentSecurity' => $documentSecurity,
                'enabled' => $enabled,
                'name' => $name,
                'search' => implode(' ', [$collectionId, $name]),
            ]));
            $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

            $dbForProject->createCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), permissions: $permissions ?? [], documentSecurity: $documentSecurity);
        } catch (DuplicateException) {
            throw new Exception(Exception::COLLECTION_ALREADY_EXISTS);
        } catch (LimitException) {
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED);
        }

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/databases/:databaseId/collections')
    ->alias('/v1/database/collections', ['databaseId' => 'default'])
    ->desc('List collections')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', '/docs/references/databases/list-collections.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION_LIST)
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

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

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
            $collectionId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Collection '{$collectionId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'collections' => $dbForProject->find('database_' . $database->getInternalId(), $queries),
            'total' => $dbForProject->count('database_' . $database->getInternalId(), $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_COLLECTION_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId')
    ->alias('/v1/database/collections/:collectionId', ['databaseId' => 'default'])
    ->desc('Get collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getCollection')
    ->label('sdk.description', '/docs/references/databases/get-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject, string $mode) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/logs')
    ->alias('/v1/database/collections/:collectionId/logs', ['databaseId' => 'default'])
    ->desc('List collection logs')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listCollectionLogs')
    ->label('sdk.description', '/docs/references/databases/get-collection-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $collectionDocument->getInternalId());

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId . '/collection/' . $collectionId;
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

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
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });


App::put('/v1/databases/:databaseId/collections/:collectionId')
    ->alias('/v1/database/collections/:collectionId', ['databaseId' => 'default'])
    ->desc('Update collection')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].update')
    ->label('audits.event', 'collection.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateCollection')
    ->label('sdk.description', '/docs/references/databases/update-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, Response $response, Database $dbForProject, string $mode, Event $queueForEvents) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $permissions ??= $collection->getPermissions() ?? [];

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        $enabled ??= $collection->getAttribute('enabled', true);

        $collection = $dbForProject->updateDocument('database_' . $database->getInternalId(), $collectionId, $collection
            ->setAttribute('name', $name)
            ->setAttribute('$permissions', $permissions)
            ->setAttribute('documentSecurity', $documentSecurity)
            ->setAttribute('enabled', $enabled)
            ->setAttribute('search', implode(' ', [$collectionId, $name])));

        $dbForProject->updateCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $permissions, $documentSecurity);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId());

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId')
    ->alias('/v1/database/collections/:collectionId', ['databaseId' => 'default'])
    ->desc('Delete collection')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].delete')
    ->label('audits.event', 'collection.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'deleteCollection')
    ->label('sdk.description', '/docs/references/databases/delete-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, string $mode) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('database_' . $database->getInternalId(), $collectionId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove collection from DB');
        }

        $dbForProject->purgeCachedCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_COLLECTION)
            ->setDatabase($database)
            ->setCollection($collection);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setPayload($response->output($collection, Response::MODEL_COLLECTION));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/string')
    ->alias('/v1/database/collections/:collectionId/attributes/string', ['databaseId' => 'default'])
    ->desc('Create string attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'createStringAttribute')
    ->label('sdk.description', '/docs/references/databases/create-string-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_STRING)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Range::TYPE_INTEGER), 'Attribute size for text attributes, in number of characters.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0, 0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->param('encrypt', false, new Boolean(), 'Toggle encryption for the attribute. Encryption enhances security by not storing any plain text values in the database. However, encrypted attributes cannot be queried.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?int $size, ?bool $required, ?string $default, bool $array, bool $encrypt, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        // Ensure attribute default is within required size
        $validator = new Text($size, 0);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $filters = [];

        if ($encrypt) {
            $filters[] = 'encrypt';
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_STRING);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/email')
    ->alias('/v1/database/collections/:collectionId/attributes/email', ['databaseId' => 'default'])
    ->desc('Create email attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createEmailAttribute')
    ->label('sdk.description', '/docs/references/databases/create-email-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_EMAIL)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Email(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/enum')
    ->alias('/v1/database/collections/:collectionId/attributes/enum', ['databaseId' => 'default'])
    ->desc('Create enum attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createEnumAttribute')
    ->label('sdk.description', '/docs/references/databases/create-attribute-enum.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_ENUM)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('elements', [], new ArrayList(new Text(DATABASE::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each ' . DATABASE::LENGTH_KEY . ' characters long.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, array $elements, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
        if (!is_null($default) && !in_array($default, $elements)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/ip')
    ->alias('/v1/database/collections/:collectionId/attributes/ip', ['databaseId' => 'default'])
    ->desc('Create IP address attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIpAttribute')
    ->label('sdk.description', '/docs/references/databases/create-ip-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_IP)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new IP(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_IP);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/url')
    ->alias('/v1/database/collections/:collectionId/attributes/url', ['databaseId' => 'default'])
    ->desc('Create URL attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createUrlAttribute')
    ->label('sdk.description', '/docs/references/databases/create-url-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_URL)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new URL(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_URL);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/integer')
    ->alias('/v1/database/collections/:collectionId/attributes/integer', ['databaseId' => 'default'])
    ->desc('Create integer attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIntegerAttribute')
    ->label('sdk.description', '/docs/references/databases/create-integer-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_INTEGER)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Integer(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        // Ensure attribute default is within range
        $min = (is_null($min)) ? PHP_INT_MIN : \intval($min);
        $max = (is_null($max)) ? PHP_INT_MAX : \intval($max);

        if ($min > $max) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Database::VAR_INTEGER);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $size = $max > 2147483647 ? 8 : 4; // Automatically create BigInt depending on max value

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \intval($formatOptions['min']));
            $attribute->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/float')
    ->alias('/v1/database/collections/:collectionId/attributes/float', ['databaseId' => 'default'])
    ->desc('Create float attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createFloatAttribute')
    ->label('sdk.description', '/docs/references/databases/create-float-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_FLOAT)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new FloatValidator(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        // Ensure attribute default is within range
        $min = (is_null($min)) ? -PHP_FLOAT_MAX : \floatval($min);
        $max = (is_null($max)) ? PHP_FLOAT_MAX : \floatval($max);

        if ($min > $max) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
        }

        // Ensure default value is a float
        if (!is_null($default)) {
            $default = \floatval($default);
        }

        $validator = new Range($min, $max, Database::VAR_FLOAT);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \floatval($formatOptions['min']));
            $attribute->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/boolean')
    ->alias('/v1/database/collections/:collectionId/attributes/boolean', ['databaseId' => 'default'])
    ->desc('Create boolean attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createBooleanAttribute')
    ->label('sdk.description', '/docs/references/databases/create-boolean-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_BOOLEAN)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Boolean(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?bool $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/datetime')
    ->alias('/v1/database/collections/:collectionId/attributes/datetime', ['databaseId' => 'default'])
    ->desc('Create datetime attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createDatetimeAttribute')
    ->label('sdk.description', '/docs/references/databases/create-datetime-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_DATETIME)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new DatetimeValidator(), 'Default value for the attribute in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $filters[] = 'datetime';

        $attribute = createAttribute($databaseId, $collectionId, new Document([
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_DATETIME);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/relationship')
    ->alias('/v1/database/collections/:collectionId/attributes/relationship', ['databaseId' => 'default'])
    ->desc('Create relationship attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createRelationshipAttribute')
    ->label('sdk.description', '/docs/references/databases/create-relationship-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_RELATIONSHIP)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('relatedCollectionId', '', new UID(), 'Related Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('type', '', new WhiteList([Database::RELATION_ONE_TO_ONE, Database::RELATION_MANY_TO_ONE, Database::RELATION_MANY_TO_MANY, Database::RELATION_ONE_TO_MANY], true), 'Relation type')
    ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
    ->param('key', null, new Key(), 'Attribute Key.', true)
    ->param('twoWayKey', null, new Key(), 'Two Way Attribute Key.', true)
    ->param('onDelete', Database::RELATION_MUTATE_RESTRICT, new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL], true), 'Constraints option', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (
        string $databaseId,
        string $collectionId,
        string $relatedCollectionId,
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
        $key ??= $relatedCollectionId;
        $twoWayKey ??= $collectionId;

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $relatedCollectionDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId);
        $relatedCollection = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $relatedCollectionDocument->getInternalId());

        if ($relatedCollection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $attributes = $collection->getAttribute('attributes', []);
        /** @var Document[] $attributes */
        foreach ($attributes as $attribute) {
            if ($attribute->getAttribute('type') !== Database::VAR_RELATIONSHIP) {
                continue;
            }

            if (\strtolower($attribute->getId()) === \strtolower($key)) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
            }

            if (
                \strtolower($attribute->getAttribute('options')['twoWayKey']) === \strtolower($twoWayKey) &&
                $attribute->getAttribute('options')['relatedCollection'] === $relatedCollection->getId()
            ) {
                // Console should provide a unique twoWayKey input!
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Attribute with the requested key already exists. Attribute keys must be unique, try again with a different key.');
            }

            if (
                $type === Database::RELATION_MANY_TO_MANY &&
                $attribute->getAttribute('options')['relationType'] === Database::RELATION_MANY_TO_MANY &&
                $attribute->getAttribute('options')['relatedCollection'] === $relatedCollection->getId()
            ) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Creating more than one "manyToMany" relationship on the same collection is currently not permitted.');
            }
        }

        $attribute = createAttribute(
            $databaseId,
            $collectionId,
            new Document([
                'key' => $key,
                'type' => Database::VAR_RELATIONSHIP,
                'size' => 0,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
                'options' => [
                    'relatedCollection' => $relatedCollectionId,
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

        $options = $attribute->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $attribute->setAttribute($key, $option);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_RELATIONSHIP);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/attributes')
    ->alias('/v1/database/collections/:collectionId/attributes', ['databaseId' => 'default'])
    ->desc('List attributes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listAttributes')
    ->label('sdk.description', '/docs/references/databases/list-attributes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new Attributes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Attributes::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject) {
        /** @var Document $database */
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        \array_push(
            $queries,
            Query::equal('collectionInternalId', [$collection->getInternalId()]),
            Query::equal('databaseInternalId', [$database->getInternalId()])
        );

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = \reset($cursor);

        if ($cursor) {
            $attributeId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->find('attributes', [
                Query::equal('collectionInternalId', [$collection->getInternalId()]),
                Query::equal('databaseInternalId', [$database->getInternalId()]),
                Query::equal('key', [$attributeId]),
                Query::limit(1),
            ]));

            if (empty($cursorDocument) || $cursorDocument[0]->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Attribute '{$attributeId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        $filters = Query::groupByType($queries)['filters'];

        $attributes = $dbForProject->find('attributes', $queries);
        $total = $dbForProject->count('attributes', $filters, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'attributes' => $attributes,
            'total' => $total,
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
    ->alias('/v1/database/collections/:collectionId/attributes/:key', ['databaseId' => 'default'])
    ->desc('Get attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getAttribute')
    ->label('sdk.description', '/docs/references/databases/get-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', [
        Response::MODEL_ATTRIBUTE_BOOLEAN,
        Response::MODEL_ATTRIBUTE_INTEGER,
        Response::MODEL_ATTRIBUTE_FLOAT,
        Response::MODEL_ATTRIBUTE_EMAIL,
        Response::MODEL_ATTRIBUTE_ENUM,
        Response::MODEL_ATTRIBUTE_URL,
        Response::MODEL_ATTRIBUTE_IP,
        Response::MODEL_ATTRIBUTE_DATETIME,
        Response::MODEL_ATTRIBUTE_RELATIONSHIP,
        Response::MODEL_ATTRIBUTE_STRING])// needs to be last, since its condition would dominate any other string attribute
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $attribute = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $collection->getInternalId() . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');
        $options = $attribute->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $attribute->setAttribute($key, $option);
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

        $response->dynamic($attribute, $model);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/string/:key')
    ->desc('Update string attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateStringAttribute')
    ->label('sdk.description', '/docs/references/databases/update-string-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_STRING)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('size', null, new Integer(), 'Maximum size of the string attribute.', true)
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?int $size, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {

        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_STRING);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/email/:key')
    ->desc('Update email attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateEmailAttribute')
    ->label('sdk.description', '/docs/references/databases/update-email-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_EMAIL)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Email()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/enum/:key')
    ->desc('Update enum attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateEnumAttribute')
    ->label('sdk.description', '/docs/references/databases/update-enum-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_ENUM)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('elements', null, new ArrayList(new Text(DATABASE::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each ' . DATABASE::LENGTH_KEY . ' characters long.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Text(0)), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?array $elements, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/ip/:key')
    ->desc('Update IP address attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateIpAttribute')
    ->label('sdk.description', '/docs/references/databases/update-ip-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_IP)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new IP()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_IP);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/url/:key')
    ->desc('Update URL attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateUrlAttribute')
    ->label('sdk.description', '/docs/references/databases/update-url-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_URL)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new URL()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_URL);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/integer/:key')
    ->desc('Update integer attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateIntegerAttribute')
    ->label('sdk.description', '/docs/references/databases/update-integer-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_INTEGER)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents')
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents')
    ->param('default', null, new Nullable(new Integer()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \intval($formatOptions['min']));
            $attribute->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/float/:key')
    ->desc('Update float attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateFloatAttribute')
    ->label('sdk.description', '/docs/references/databases/update-float-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_FLOAT)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents')
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents')
    ->param('default', null, new Nullable(new FloatValidator()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \floatval($formatOptions['min']));
            $attribute->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/boolean/:key')
    ->desc('Update boolean attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateBooleanAttribute')
    ->label('sdk.description', '/docs/references/databases/update-boolean-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_BOOLEAN)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new Boolean()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?bool $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/datetime/:key')
    ->desc('Update dateTime attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateDatetimeAttribute')
    ->label('sdk.description', '/docs/references/databases/update-datetime-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_DATETIME)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Nullable(new DatetimeValidator()), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, Response $response, Database $dbForProject, Event $queueForEvents) {
        $attribute = updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
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
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_DATETIME);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/attributes/:key/relationship')
    ->desc('Update relationship attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateRelationshipAttribute')
    ->label('sdk.description', '/docs/references/databases/update-relationship-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_RELATIONSHIP)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('onDelete', null, new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL], true), 'Constraints option', true)
    ->param('newKey', null, new Key(), 'New attribute key.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (
        string $databaseId,
        string $collectionId,
        string $key,
        ?string $onDelete,
        ?string $newKey,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $attribute = updateAttribute(
            $databaseId,
            $collectionId,
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

        $options = $attribute->getAttribute('options', []);

        foreach ($options as $key => $option) {
            $attribute->setAttribute($key, $option);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_RELATIONSHIP);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
    ->alias('/v1/database/collections/:collectionId/attributes/:key', ['databaseId' => 'default'])
    ->desc('Delete attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
    ->label('audits.event', 'attribute.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'deleteAttribute')
    ->label('sdk.description', '/docs/references/databases/delete-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $attribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        // Only update status if removing available attribute
        if ($attribute->getAttribute('status') === 'available') {
            $attribute = $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());

        if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
            $options = $attribute->getAttribute('options');
            if ($options['twoWay']) {
                $relatedCollection = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection']);

                if ($relatedCollection->isEmpty()) {
                    throw new Exception(Exception::COLLECTION_NOT_FOUND);
                }

                $relatedAttribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $options['twoWayKey']);

                if ($relatedAttribute->isEmpty()) {
                    throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
                }

                if ($relatedAttribute->getAttribute('status') === 'available') {
                    $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'deleting'));
                }

                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $options['relatedCollection']);
                $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
            }
        }

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_ATTRIBUTE)
            ->setCollection($collection)
            ->setDatabase($db)
            ->setDocument($attribute);

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

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
            ->setParam('collectionId', $collection->getId())
            ->setParam('attributeId', $attribute->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $db)
            ->setPayload($response->output($attribute, $model));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/indexes')
    ->alias('/v1/database/collections/:collectionId/indexes', ['databaseId' => 'default'])
    ->desc('Create index')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'index.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'createIndex')
    ->label('sdk.description', '/docs/references/databases/create-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE]), 'Index type.')
    ->param('attributes', null, new ArrayList(new Key(true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attributes to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' attributes are allowed, each 32 characters long.')
    ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, string $type, array $attributes, array $orders, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $count = $dbForProject->count('indexes', [
            Query::equal('collectionInternalId', [$collection->getInternalId()]),
            Query::equal('databaseInternalId', [$db->getInternalId()])
        ], 61);

        $limit = $dbForProject->getLimitForIndexes();

        if ($count >= $limit) {
            throw new Exception(Exception::INDEX_LIMIT_EXCEEDED, 'Index limit exceeded');
        }

        // Convert Document[] to array of attribute metadata
        $oldAttributes = \array_map(fn ($a) => $a->getArrayCopy(), $collection->getAttribute('attributes'));

        $oldAttributes[] = [
            'key' => '$id',
            'type' => Database::VAR_STRING,
            'status' => 'available',
            'required' => true,
            'array' => false,
            'default' => null,
            'size' => 36
        ];

        $oldAttributes[] = [
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        $oldAttributes[] = [
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

        foreach ($attributes as $i => $attribute) {
            // find attribute metadata in collection document
            $attributeIndex = \array_search($attribute, array_column($oldAttributes, 'key'));

            if ($attributeIndex === false) {
                throw new Exception(Exception::ATTRIBUTE_UNKNOWN, 'Unknown attribute: ' . $attribute . '. Verify the attribute name or create the attribute.');
            }

            $attributeStatus = $oldAttributes[$attributeIndex]['status'];
            $attributeType = $oldAttributes[$attributeIndex]['type'];
            $attributeSize = $oldAttributes[$attributeIndex]['size'];
            $attributeArray = $oldAttributes[$attributeIndex]['array'] ?? false;

            if ($attributeType === Database::VAR_RELATIONSHIP) {
                throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID, 'Cannot create an index for a relationship attribute: ' . $oldAttributes[$attributeIndex]['key']);
            }

            // ensure attribute is available
            if ($attributeStatus !== 'available') {
                throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE, 'Attribute not available: ' . $oldAttributes[$attributeIndex]['key']);
            }

            $lengths[$i] = null;

            if ($attributeType === Database::VAR_STRING) {
                $lengths[$i] = $attributeSize; // set attribute size as index length only for strings
            }

            if ($attributeArray === true) {
                $lengths[$i] = Database::ARRAY_INDEX_LENGTH;
                $orders[$i] = null;
            }
        }

        $index = new Document([
            '$id' => ID::custom($db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key),
            'key' => $key,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'databaseInternalId' => $db->getInternalId(),
            'databaseId' => $databaseId,
            'collectionInternalId' => $collection->getInternalId(),
            'collectionId' => $collectionId,
            'type' => $type,
            'attributes' => $attributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $validator = new IndexValidator(
            $collection->getAttribute('attributes'),
            $dbForProject->getAdapter()->getMaxIndexLength()
        );
        if (!$validator->isValid($index)) {
            throw new Exception(Exception::INDEX_INVALID, $validator->getDescription());
        }

        try {
            $index = $dbForProject->createDocument('indexes', $index);
        } catch (DuplicateException) {
            throw new Exception(Exception::INDEX_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($db)
            ->setCollection($collection)
            ->setDocument($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $db);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($index, Response::MODEL_INDEX);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/indexes')
    ->alias('/v1/database/collections/:collectionId/indexes', ['databaseId' => 'default'])
    ->desc('List indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listIndexes')
    ->label('sdk.description', '/docs/references/databases/list-indexes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new Indexes(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Indexes::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject) {
        /** @var Document $database */
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        \array_push($queries, Query::equal('collectionId', [$collectionId]), Query::equal('databaseId', [$databaseId]));

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $indexId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->find('indexes', [
                Query::equal('collectionInternalId', [$collection->getInternalId()]),
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
        $response->dynamic(new Document([
            'total' => $dbForProject->count('indexes', $filterQueries, APP_LIMIT_COUNT),
            'indexes' => $dbForProject->find('indexes', $queries),
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
    ->alias('/v1/database/collections/:collectionId/indexes/:key', ['databaseId' => 'default'])
    ->desc('Get index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getIndex')
    ->label('sdk.description', '/docs/references/databases/get-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $index = $collection->find('key', $key, 'indexes');
        if (empty($index)) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        $response->dynamic($index, Response::MODEL_INDEX);
    });


App::delete('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
    ->alias('/v1/database/collections/:collectionId/indexes/:key', ['databaseId' => 'default'])
    ->desc('Delete index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].update')
    ->label('audits.event', 'index.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'deleteIndex')
    ->label('sdk.description', '/docs/references/databases/delete-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDatabase')
    ->inject('queueForEvents')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $index = $dbForProject->getDocument('indexes', $db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key);

        if (empty($index->getId())) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        // Only update status if removing available index
        if ($index->getAttribute('status') === 'available') {
            $index = $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_INDEX)
            ->setDatabase($db)
            ->setCollection($collection)
            ->setDocument($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $db)
            ->setPayload($response->output($index, Response::MODEL_INDEX));

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->alias('/v1/database/collections/:collectionId/documents', ['databaseId' => 'default'])
    ->desc('Create document')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].create')
    ->label('scope', 'documents.write')
    ->label('audits.event', 'document.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', '/docs/references/databases/create-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->label('sdk.offline.model', '/databases/{databaseId}/collections/{collectionId}/documents')
    ->label('sdk.offline.key', '{documentId}')
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('documentId', '', new CustomId(), 'Document ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). Make sure to define attributes before creating documents.')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('mode')
    ->action(function (string $databaseId, string $documentId, string $collectionId, string|array $data, ?array $permissions, Response $response, Database $dbForProject, Document $user, Event $queueForEvents, string $mode) {

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

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
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

        $data['$collection'] = $collection->getId(); // Adding this param to make API easier for developers
        $data['$id'] = $documentId == 'unique()' ? ID::unique() : $documentId;
        $data['$permissions'] = $permissions;
        $document = new Document($data);

        $checkPermissions = function (Document $collection, Document $document, string $permission) use (&$checkPermissions, $dbForProject, $database) {
            $documentSecurity = $collection->getAttribute('documentSecurity', false);
            $validator = new Authorization($permission);

            $valid = $validator->isValid($collection->getPermissionsByType($permission));
            if (($permission === Database::PERMISSION_UPDATE && !$documentSecurity) || !$valid) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            if ($permission === Database::PERMISSION_UPDATE) {
                $valid = $valid || $validator->isValid($document->getUpdate());
                if ($documentSecurity && !$valid) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
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
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId)
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
                            fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId(), $relation->getId())
                        );

                        if ($current->isEmpty()) {
                            $type = Database::PERMISSION_CREATE;

                            if (isset($relation['$id']) && $relation['$id'] === 'unique()') {
                                $relation['$id'] = ID::unique();
                            }
                        } else {
                            $relation->removeAttribute('$collectionId');
                            $relation->removeAttribute('$databaseId');
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

        $checkPermissions($collection, $document, Database::PERMISSION_CREATE);

        try {
            $document = $dbForProject->createDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $document);
        } catch (StructureException $exception) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $exception->getMessage());
        } catch (DuplicateException $exception) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        }

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($document, Response::MODEL_DOCUMENT);

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
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->getPayload(), sensitive: $relationships);

    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->alias('/v1/database/collections/:collectionId/documents', ['databaseId' => 'default'])
    ->desc('List documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', '/docs/references/databases/list-documents.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT_LIST)
    ->label('sdk.offline.model', '/databases/{databaseId}/collections/{collectionId}/documents')
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject, string $mode) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
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
            $documentId = $cursor->getValue();

            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Document '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $documents = $dbForProject->find('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $queries);
            $total = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $queries, APP_LIMIT_COUNT);
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        // Add $collectionId and $databaseId for all documents
        $processDocument = (function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database): bool {
            if ($document->isEmpty()) {
                return false;
            }

            $document->removeAttribute('$collection');
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $relations = [$related];
                } else {
                    $relations = $related;
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId));

                foreach ($relations as $index => $doc) {
                    if ($doc instanceof Document) {
                        if (!$processDocument($relatedCollection, $doc)) {
                            unset($relations[$index]);
                        }
                    }
                }

                if (\is_array($related)) {
                    $document->setAttribute($relationship->getAttribute('key'), \array_values($relations));
                } elseif (empty($relations)) {
                    $document->setAttribute($relationship->getAttribute('key'), null);
                }
            }

            return true;
        });

        foreach ($documents as $document) {
            $processDocument($collection, $document);
        }

        $select = \array_reduce($queries, function ($result, $query) {
            return $result || ($query->getMethod() === Query::TYPE_SELECT);
        }, false);

        // Check if the SELECT query includes $databaseId and $collectionId
        $hasDatabaseId = false;
        $hasCollectionId = false;
        if ($select) {
            $hasDatabaseId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$databaseId', $query->getValues()));
            }, false);
            $hasCollectionId = \array_reduce($queries, function ($result, $query) {
                return $result || ($query->getMethod() === Query::TYPE_SELECT && \in_array('$collectionId', $query->getValues()));
            }, false);
        }

        if ($select) {
            foreach ($documents as $document) {
                if (!$hasDatabaseId) {
                    $document->removeAttribute('$databaseId');
                }
                if (!$hasCollectionId) {
                    $document->removeAttribute('$collectionId');
                }
            }
        }

        $response->dynamic(new Document([
            'total' => $total,
            'documents' => $documents,
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId', ['databaseId' => 'default'])
    ->desc('Get document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getDocument')
    ->label('sdk.description', '/docs/references/databases/get-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->label('sdk.offline.model', '/databases/{databaseId}/collections/{collectionId}/documents')
    ->label('sdk.offline.key', '{documentId}')
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, string $documentId, array $queries, Response $response, Database $dbForProject, string $mode) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
            $document = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId, $queries);
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database) {
            if ($document->isEmpty()) {
                return;
            }

            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId/logs')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId/logs', ['databaseId' => 'default'])
    ->desc('List document logs')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listDocumentLogs')
    ->label('sdk.description', '/docs/references/databases/get-document-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, string $collectionId, string $documentId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $document = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId);

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);
        $resource = 'database/' . $databaseId . '/collection/' . $collectionId . '/document/' . $document->getId();
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

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
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId', ['databaseId' => 'default'])
    ->desc('Update document')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].update')
    ->label('scope', 'documents.write')
    ->label('audits.event', 'document.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{response.$id}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'updateDocument')
    ->label('sdk.description', '/docs/references/databases/update-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->label('sdk.offline.model', '/databases/{databaseId}/collections/{collectionId}/documents')
    ->label('sdk.offline.key', '{documentId}')
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object. Include only attribute and value pairs to be updated.', true)
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, string $documentId, string|array $data, ?array $permissions, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $queueForEvents, string $mode) {

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

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        // Read permission should not be required for update
        /** @var Document $document */
        $document = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId));

        if ($document->isEmpty()) {
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
            $permissions = $document->getPermissions() ?? [];
        }

        $data['$id'] = $documentId;
        $data['$permissions'] = $permissions;
        $newDocument = new Document($data);

        $setCollection = (function (Document $collection, Document $document) use (&$setCollection, $dbForProject, $database) {
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
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId)
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
                            'database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId(),
                            $relation->getId()
                        ));
                        $relation->removeAttribute('$collectionId');
                        $relation->removeAttribute('$databaseId');
                        // Attribute $collection is required for Utopia.
                        $relation->setAttribute(
                            '$collection',
                            'database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId()
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

        try {
            $document = $dbForProject->withRequestTimestamp(
                $requestTimestamp,
                fn () => $dbForProject->updateDocument(
                    'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(),
                    $document->getId(),
                    $newDocument
                )
            );
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (StructureException $exception) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $exception->getMessage());
        }

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $response->dynamic($document, Response::MODEL_DOCUMENT);

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
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->getPayload(), sensitive: $relationships);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId', ['databaseId' => 'default'])
    ->desc('Delete document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].delete')
    ->label('audits.event', 'document.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{request.documentId}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'deleteDocument')
    ->label('sdk.description', '/docs/references/databases/delete-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('sdk.offline.model', '/databases/{databaseId}/collections/{collectionId}/documents')
    ->label('sdk.offline.key', '{documentId}')
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('documentId', '', new UID(), 'Document ID.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForDeletes')
    ->inject('queueForEvents')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, string $documentId, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Delete $queueForDeletes, Event $queueForEvents, string $mode) {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($database->isEmpty() || (!$database->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || (!$collection->getAttribute('enabled', false) && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        // Read permission should not be required for delete
        $document = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId));

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $database, $collection, $documentId) {
            $dbForProject->deleteDocument(
                'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(),
                $documentId
            );
        });

        // Add $collectionId and $databaseId for all documents
        $processDocument = function (Document $collection, Document $document) use (&$processDocument, $dbForProject, $database) {
            $document->setAttribute('$databaseId', $database->getId());
            $document->setAttribute('$collectionId', $collection->getId());

            $relationships = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            );

            foreach ($relationships as $relationship) {
                $related = $document->getAttribute($relationship->getAttribute('key'));

                if (empty($related)) {
                    continue;
                }
                if (!\is_array($related)) {
                    $related = [$related];
                }

                $relatedCollectionId = $relationship->getAttribute('relatedCollection');
                $relatedCollection = Authorization::skip(
                    fn () => $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedCollectionId)
                );

                foreach ($related as $relation) {
                    if ($relation instanceof Document) {
                        $processDocument($relatedCollection, $relation);
                    }
                }
            }
        };

        $processDocument($collection, $document);

        $relationships = \array_map(
            fn ($document) => $document->getAttribute('key'),
            \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
            )
        );

        $queueForDeletes
            ->setType(DELETE_TYPE_AUDIT)
            ->setDocument($document);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->output($document, Response::MODEL_DOCUMENT), sensitive: $relationships);

        $response->noContent();
    });

App::get('/v1/databases/usage')
    ->desc('Get databases usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_DATABASES)
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
            'databases'   => $usage[$metrics[0]]['data'],
            'collections' => $usage[$metrics[1]]['data'],
            'documents'   => $usage[$metrics[2]]['data'],
        ]), Response::MODEL_USAGE_DATABASES);
    });

App::get('/v1/databases/:databaseId/usage')
    ->desc('Get database usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getDatabaseUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_DATABASE)
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
            'collections'   => $usage[$metrics[0]]['data'],
            'documents'   => $usage[$metrics[1]]['data'],
        ]), Response::MODEL_USAGE_DATABASE);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/usage')
    ->alias('/v1/database/:collectionId/usage', ['databaseId' => 'default'])
    ->desc('Get collection usage stats')
    ->groups(['api', 'database', 'usage'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getCollectionUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_COLLECTION)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $range, string $collectionId, Response $response, Database $dbForProject) {

        $database = $dbForProject->getDocument('databases', $databaseId);
        $collectionDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $collectionDocument->getInternalId());

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getInternalId(), $collectionDocument->getInternalId()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS),
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
