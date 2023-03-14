<?php

use Utopia\App;
use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Utopia\Audit\Audit;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Helpers\ID;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Locale\Locale;
use Appwrite\Auth\Auth;
use Appwrite\Network\Validator\Email;
use Utopia\Validator\IP;
use Utopia\Validator\URL;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Query\Limit;
use Appwrite\Utopia\Database\Validator\Query\Offset;
use Appwrite\Utopia\Response;
use Appwrite\Detector\Detector;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Utopia\Database\Validator\Queries;
use Appwrite\Utopia\Database\Validator\Queries\Collections;
use Appwrite\Utopia\Database\Validator\Queries\Databases;
use Appwrite\Utopia\Database\Validator\Queries\Documents;
use Utopia\Config\Config;
use MaxMind\Db\Reader;

/**
 * Create attribute of varying type
 *
 *
 * @return Document Newly created attribute document
 * @throws Exception
 */
function createAttribute(string $databaseId, string $collectionId, Document $attribute, Response $response, Database $dbForProject, EventDatabase $database, Event $events): Document
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
    $relationshipOptions = $attribute->getAttribute('relationshipOptions', []);

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
        $relatedCollection = $dbForProject->getDocument('database_' . $db->getInternalId(), $relationshipOptions['relatedCollection']);
        if ($relatedCollection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
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
            'relationshipOptions' => $relationshipOptions,
        ]);

        $dbForProject->checkAttribute($collection, $attribute);
        $attribute = $dbForProject->createDocument('attributes', $attribute);
    } catch (DuplicateException $exception) {
        throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
    } catch (LimitException $exception) {
        throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED, 'Attribute limit exceeded');
    }

    $dbForProject->deleteCachedDocument('database_' . $db->getInternalId(), $collectionId);
    $dbForProject->deleteCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());

    $database
        ->setType(DATABASE_TYPE_CREATE_ATTRIBUTE)
        ->setDatabase($db)
        ->setCollection($collection)
        ->setDocument($attribute)
    ;

    $events
        ->setContext('collection', $collection)
        ->setContext('database', $db)
        ->setParam('databaseId', $databaseId)
        ->setParam('collectionId', $collection->getId())
        ->setParam('attributeId', $attribute->getId())
    ;

    $response->setStatusCode(Response::STATUS_CODE_CREATED);

    return $attribute;
}

App::post('/v1/databases')
    ->desc('Create Database')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].create')
    ->label('scope', 'databases.write')
    ->label('audits.event', 'database.create')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('usage.metric', 'databases.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/databases/create.md') // create this file later
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DATABASE) // Model for database needs to be created
    ->param('databaseId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $databaseId, string $name, Response $response, Database $dbForProject, Event $events) {

        $databaseId = $databaseId == 'unique()' ? ID::unique() : $databaseId;

        try {
            $dbForProject->createDocument('databases', new Document([
                '$id' => $databaseId,
                'name' => $name,
                'search' => implode(' ', [$databaseId, $name]),
            ]));
            $database = $dbForProject->getDocument('databases', $databaseId);

            $collections = Config::getParam('collections', [])['collections'] ?? [];
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
        } catch (DuplicateException $th) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS);
        }

        $events->setParam('databaseId', $database->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($database, Response::MODEL_DATABASE);
    });

App::get('/v1/databases')
    ->desc('List Databases')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('usage.metric', 'databases.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/databases/list.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DATABASE_LIST)
    ->param('queries', [], new Databases(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Databases::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
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
    ->desc('Get Database')
    ->groups(['api', 'database'])
    ->label('scope', 'databases.read')
    ->label('usage.metric', 'databases.{scope}.requests.read')
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

        $database =  $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $response->dynamic($database, Response::MODEL_DATABASE);
    });

App::get('/v1/databases/:databaseId/logs')
    ->desc('List Database Logs')
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
    ->param('queries', [], new Queries(new Limit(), new Offset()), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $databaseId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);
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
                'userId' => ID::custom($log['userId']),
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
    ->desc('Update Database')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'databases.write')
    ->label('event', 'databases.[databaseId].update')
    ->label('audits.event', 'database.update')
    ->label('audits.resource', 'database/{response.$id}')
    ->label('usage.metric', 'databases.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/databases/update.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DATABASE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('name', null, new Text(128), 'Database name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $databaseId, string $name, Response $response, Database $dbForProject, Event $events) {

        $database =  $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        try {
            $database = $dbForProject->updateDocument('databases', $databaseId, $database
                ->setAttribute('name', $name)
                ->setAttribute('search', implode(' ', [$databaseId, $name])));
        } catch (AuthorizationException $exception) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (StructureException $exception) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, 'Bad structure. ' . $exception->getMessage());
        }

        $events->setParam('databaseId', $database->getId());

        $response->dynamic($database, Response::MODEL_DATABASE);
    });

App::delete('/v1/databases/:databaseId')
    ->desc('Delete Database')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'databases.write')
    ->label('event', 'databases.[databaseId].delete')
    ->label('audits.event', 'database.delete')
    ->label('audits.resource', 'database/{request.databaseId}')
    ->label('usage.metric', 'databases.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/databases/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('deletes')
    ->action(function (string $databaseId, Response $response, Database $dbForProject, Event $events, Delete $deletes) {

        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('databases', $databaseId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove collection from DB');
        }

        $dbForProject->deleteCachedCollection('databases' . $database->getInternalId());

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($database)
        ;

        $events
            ->setParam('databaseId', $database->getId())
            ->setPayload($response->output($database, Response::MODEL_DATABASE))
        ;

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections')
    ->alias('/v1/database/collections', ['databaseId' => 'default'])
    ->desc('Create Collection')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'collection.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{response.$id}')
    ->label('usage.metric', 'collections.{scope}.requests.create')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](/docs/permissions).', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, Response $response, Database $dbForProject, Event $events) {

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
                'enabled' => true,
                'name' => $name,
                'search' => implode(' ', [$collectionId, $name]),
            ]));
            $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

            $dbForProject->createCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());
        } catch (DuplicateException) {
            throw new Exception(Exception::COLLECTION_ALREADY_EXISTS);
        } catch (LimitException) {
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED);
        }

        $events
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/databases/:databaseId/collections')
    ->alias('/v1/database/collections', ['databaseId' => 'default'])
    ->desc('List Collections')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('usage.metric', 'collections.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', '/docs/references/databases/list-collections.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('queries', [], new Collections(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Collections::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, array $queries, string $search, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
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
    ->desc('Get Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('usage.metric', 'collections.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject) {

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
    ->desc('List Collection Logs')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('usage.metric', 'collections.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listCollectionLogs')
    ->label('sdk.description', '/docs/references/databases/get-collection-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('queries', [], new Queries(new Limit(), new Offset()), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Only supported methods are limit and offset', true)
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

        $queries = Query::parseQueries($queries);
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
                'userId' => $log['userId'],
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
    ->desc('Update Collection')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].update')
    ->label('audits.event', 'collection.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](/docs/permissions).', true)
    ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](/docs/permissions).', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, Response $response, Database $dbForProject, Event $events) {

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

        try {
            $collection = $dbForProject->updateDocument('database_' . $database->getInternalId(), $collectionId, $collection
                ->setAttribute('name', $name)
                ->setAttribute('$permissions', $permissions)
                ->setAttribute('documentSecurity', $documentSecurity)
                ->setAttribute('enabled', $enabled)
                ->setAttribute('search', implode(' ', [$collectionId, $name])));
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (StructureException $exception) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, 'Bad structure. ' . $exception->getMessage());
        }

        $events
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId());

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId')
    ->alias('/v1/database/collections/:collectionId', ['databaseId' => 'default'])
    ->desc('Delete Collection')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].delete')
    ->label('audits.event', 'collection.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.delete')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('events')
    ->inject('deletes')
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject, Event $events, Delete $deletes) {

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

        $dbForProject->deleteCachedCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($collection)
        ;

        $events
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setPayload($response->output($collection, Response::MODEL_COLLECTION))
        ;

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/string')
    ->alias('/v1/database/collections/:collectionId/attributes/string', ['databaseId' => 'default'])
    ->desc('Create String Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?int $size, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

        // Ensure attribute default is within required size
        $validator = new Text($size);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $database, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_STRING);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/email')
    ->alias('/v1/database/collections/:collectionId/attributes/email', ['databaseId' => 'default'])
    ->desc('Create Email Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 254,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_EMAIL,
        ]), $response, $dbForProject, $database, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/enum')
    ->alias('/v1/database/collections/:collectionId/attributes/enum', ['databaseId' => 'default'])
    ->desc('Create Enum Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->param('elements', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, array $elements, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

        // use length of longest string as attribute size
        $size = 0;
        foreach ($elements as $element) {
            $length = \strlen($element);
            if ($length === 0) {
                throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Each enum element must not be empty');
            }
            $size = ($length > $size) ? $length : $size;
        }

        if (!is_null($default) && !in_array($default, $elements)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
        }

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_ENUM,
            'formatOptions' => ['elements' => $elements],
        ]), $response, $dbForProject, $database, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/ip')
    ->alias('/v1/database/collections/:collectionId/attributes/ip', ['databaseId' => 'default'])
    ->desc('Create IP Address Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 39,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_IP,
        ]), $response, $dbForProject, $database, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_IP);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/url')
    ->alias('/v1/database/collections/:collectionId/attributes/url', ['databaseId' => 'default'])
    ->desc('Create URL Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 2000,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_URL,
        ]), $response, $dbForProject, $database, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_URL);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/integer')
    ->alias('/v1/database/collections/:collectionId/attributes/integer', ['databaseId' => 'default'])
    ->desc('Create Integer Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

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
        ]), $response, $dbForProject, $database, $events);

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
    ->desc('Create Float Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

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
        ]), $response, $dbForProject, $database, $events);

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
    ->desc('Create Boolean Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?bool $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $database, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });


App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/datetime')
    ->alias('/v1/database/collections/:collectionId/attributes/datetime', ['databaseId' => 'default'])
    ->desc('Create DateTime Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->param('default', null, new DatetimeValidator(), 'Default value for the attribute in ISO 8601 format. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

        $attribute = createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_DATETIME,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'filters' => ['datetime']
        ]), $response, $dbForProject, $database, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_DATETIME);
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/attributes/relationship')
    ->alias('/v1/database/collections/:collectionId/attributes/relationship', ['databaseId' => 'default'])
    ->desc('Create relationship Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'attribute.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createRelationshipAttribute')
    ->label('sdk.description', '/docs/references/databases/create-relationship-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_RELATIONSHIP)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('relatedCollectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->param('type', '', new WhiteList([Database::RELATION_ONE_TO_ONE, Database::RELATION_MANY_TO_ONE, Database::RELATION_MANY_TO_MANY, Database::RELATION_ONE_TO_MANY]), 'Relation type')
    ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
    ->param('twoWayKey', '', new Text(40), 'Two Way Key', true)
    ->param('onUpdate', 'restrict', new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL]), 'Constraints option', true)
    ->param('onDelete', 'restrict', new WhiteList([Database::RELATION_MUTATE_CASCADE, Database::RELATION_MUTATE_RESTRICT, Database::RELATION_MUTATE_SET_NULL]), 'Constraints option', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('events')
    ->action(function (
        string $databaseId,
        string $collectionId,
        string $key,
        string $relatedCollectionId,
        string $type,
        bool $twoWay,
        string $twoWayKey,
        string $onUpdate,
        string $onDelete,
        Response $response,
        Database $dbForProject,
        EventDatabase $database,
        Event $events
) {
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
            'relationshipOptions' => [
                'relatedCollection' => $relatedCollectionId,
                'relationType' => $type,
                'twoWay' => $twoWay,
                'twoWayKey' => $twoWayKey,
                'onUpdate' => $onUpdate,
                'onDelete' => $onDelete
            ]
            ]),
            $response,
            $dbForProject,
            $database,
            $events
        );

        $options = $attribute->getAttribute('relationshipOptions', []);

        if (!empty($options)) {
            $attribute->setAttribute('relatedCollection', $options['relatedCollection']);
            $attribute->setAttribute('relationType', $options['relationType']);
            $attribute->setAttribute('twoWay', $options['twoWay']);
            $attribute->setAttribute('twoWayKey', $options['twoWayKey']);
            $attribute->setAttribute('onUpdate', $options['onUpdate']);
            $attribute->setAttribute('onDelete', $options['onDelete']);
            $attribute->setAttribute('side', $options['side']);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, Response::MODEL_ATTRIBUTE_RELATIONSHIP);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/attributes')
    ->alias('/v1/database/collections/:collectionId/attributes', ['databaseId' => 'default'])
    ->desc('List Attributes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('usage.metric', 'collections.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listAttributes')
    ->label('sdk.description', '/docs/references/databases/list-attributes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $attributes = $collection->getAttribute('attributes');

        $response->dynamic(new Document([
            'total' => \count($attributes),
            'attributes' => $attributes
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
    ->alias('/v1/database/collections/:collectionId/attributes/:key', ['databaseId' => 'default'])
    ->desc('Get Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('usage.metric', 'collections.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getAttribute')
    ->label('sdk.description', '/docs/references/databases/get-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', [
        Response::MODEL_ATTRIBUTE_RELATIONSHIP,
        Response::MODEL_ATTRIBUTE_DATETIME,
        Response::MODEL_ATTRIBUTE_BOOLEAN,
        Response::MODEL_ATTRIBUTE_INTEGER,
        Response::MODEL_ATTRIBUTE_FLOAT,
        Response::MODEL_ATTRIBUTE_EMAIL,
        Response::MODEL_ATTRIBUTE_ENUM,
        Response::MODEL_ATTRIBUTE_URL,
        Response::MODEL_ATTRIBUTE_IP,
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

        $model = match ($type) {
            Database::VAR_RELATIONSHIP => Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            Database::VAR_DATETIME => Response::MODEL_ATTRIBUTE_DATETIME,
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
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

App::delete('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
    ->alias('/v1/database/collections/:collectionId/attributes/:key', ['databaseId' => 'default'])
    ->desc('Delete Attribute')
    ->groups(['api', 'database', 'schema'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].delete')
    ->label('audits.event', 'attribute.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

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
        if ($attribute->getAttribute('status' === 'available')) {
            $attribute = $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'deleting'));
        }

        $dbForProject->deleteCachedDocument('database_' . $db->getInternalId(), $collectionId);
        $dbForProject->deleteCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());

        $database
            ->setType(DATABASE_TYPE_DELETE_ATTRIBUTE)
            ->setCollection($collection)
            ->setDatabase($db)
            ->setDocument($attribute)
        ;

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

        $model = match ($type) {
            Database::VAR_RELATIONSHIP => Response::MODEL_ATTRIBUTE_RELATIONSHIP,
            Database::VAR_DATETIME => Response::MODEL_ATTRIBUTE_DATETIME,
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $events
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('attributeId', $attribute->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $db)
            ->setPayload($response->output($attribute, $model))
        ;

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/indexes')
    ->alias('/v1/database/collections/:collectionId/indexes', ['databaseId' => 'default'])
    ->desc('Create Index')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].create')
    ->label('scope', 'collections.write')
    ->label('audits.event', 'index.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE, Database::INDEX_SPATIAL, Database::INDEX_ARRAY]), 'Index type.')
    ->param('attributes', null, new ArrayList(new Key(true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attributes to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' attributes are allowed, each 32 characters long.')
    ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, string $type, array $attributes, array $orders, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

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

        $limit = 64 - MariaDB::getCountOfDefaultIndexes();

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
                throw new Exception(Exception::ATTRIBUTE_UNKNOWN, 'Unknown attribute: ' . $attribute);
            }

            $attributeStatus = $oldAttributes[$attributeIndex]['status'];
            $attributeType = $oldAttributes[$attributeIndex]['type'];
            $attributeSize = $oldAttributes[$attributeIndex]['size'];

            // ensure attribute is available
            if ($attributeStatus !== 'available') {
                throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE, 'Attribute not available: ' . $oldAttributes[$attributeIndex]['key']);
            }

            // set attribute size as index length only for strings
            $lengths[$i] = ($attributeType === Database::VAR_STRING) ? $attributeSize : null;
        }

        try {
            $index = $dbForProject->createDocument('indexes', new Document([
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
            ]));
        } catch (DuplicateException $th) {
            throw new Exception(Exception::INDEX_ALREADY_EXISTS);
        }

        $dbForProject->deleteCachedDocument('database_' . $db->getInternalId(), $collectionId);

        $database
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($db)
            ->setCollection($collection)
            ->setDocument($index)
        ;

        $events
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $db)
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($index, Response::MODEL_INDEX);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/indexes')
    ->alias('/v1/database/collections/:collectionId/indexes', ['databaseId' => 'default'])
    ->desc('List Indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('usage.metric', 'collections.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'listIndexes')
    ->label('sdk.description', '/docs/references/databases/list-indexes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX_LIST)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $collectionId, Response $response, Database $dbForProject) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $indexes = $collection->getAttribute('indexes');

        $response->dynamic(new Document([
            'total' => \count($indexes),
            'indexes' => $indexes,
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
    ->alias('/v1/database/collections/:collectionId/indexes/:key', ['databaseId' => 'default'])
    ->desc('Get Index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('usage.metric', 'collections.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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

        $indexes = $collection->getAttribute('indexes');

        // Search for index
        $indexIndex = array_search($key, array_map(fn($idx) => $idx['key'], $indexes));

        if ($indexIndex === false) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        $index = $indexes[$indexIndex];
        $index->setAttribute('collectionId', $database->getInternalId() . '_' . $collectionId);

        $response->dynamic($index, Response::MODEL_INDEX);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
    ->alias('/v1/database/collections/:collectionId/indexes/:key', ['databaseId' => 'default'])
    ->desc('Delete Index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].delete')
    ->label('audits.event', 'index.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'collections.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}'])
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
    ->inject('database')
    ->inject('events')
    ->action(function (string $databaseId, string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $database, Event $events) {

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

        $dbForProject->deleteCachedDocument('database_' . $db->getInternalId(), $collectionId);

        $database
            ->setType(DATABASE_TYPE_DELETE_INDEX)
            ->setDatabase($db)
            ->setCollection($collection)
            ->setDocument($index)
        ;

        $events
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $db)
            ->setPayload($response->output($index, Response::MODEL_INDEX))
        ;

        $response->noContent();
    });

App::post('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->alias('/v1/database/collections/:collectionId/documents', ['databaseId' => 'default'])
    ->desc('Create Document')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].create')
    ->label('scope', 'documents.write')
    ->label('audits.event', 'document.create')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
    ->label('usage.metric', 'documents.{scope}.requests.create')
    ->label('usage.params', ['databaseId:{request.databaseId}', 'collectionId:{request.collectionId}'])
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
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](/docs/permissions).', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('events')
    ->inject('mode')
    ->action(function (string $databaseId, string $documentId, string $collectionId, string|array $data, ?array $permissions, Response $response, Database $dbForProject, Document $user, Event $events, string $mode) {

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception(Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        if (isset($data['$id'])) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, '$id is not allowed for creating new documents, try update instead');
        }

        $database = Authorization::skip(fn() => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND);
            }
        }

        $validator = new Authorization(Database::PERMISSION_CREATE);
        if (!$validator->isValid($collection->getCreate())) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
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
        $roles = Authorization::getRoles();
        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
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

        $data['$collection'] = $collection->getId(); // Adding this param to make API easier for developers
        $data['$id'] = $documentId == 'unique()' ? ID::unique() : $documentId;
        $data['$permissions'] = $permissions;

        try {
            $document = $dbForProject->createDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), new Document($data));
            $document->setAttribute('$collectionId', $collectionId);
            $document->setAttribute('$databaseId', $databaseId);
        } catch (StructureException $exception) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $exception->getMessage());
        } catch (DuplicateException $exception) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        }

        $events
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents')
    ->alias('/v1/database/collections/:collectionId/documents', ['databaseId' => 'default'])
    ->desc('List Documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('usage.metric', 'documents.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}', 'collectionId:{request.collectionId}'])
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
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, array $queries, Response $response, Database $dbForProject, string $mode) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND);
            }
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($collection->getRead());
        if (!$documentSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Validate queries
        $queriesValidator = new Documents($collection->getAttribute('attributes'));
        $validQueries = $queriesValidator->isValid($queries);
        if (!$validQueries) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $queriesValidator->getDescription());
        }

        $queries = Query::parseQueries($queries);

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $documentId = $cursor->getValue();

            if ($documentSecurity && !$valid) {
                $cursorDocument = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId);
            } else {
                $cursorDocument = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId));
            }

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Document '{$documentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        if ($documentSecurity && !$valid) {
            $documents = $dbForProject->find('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $queries);
            $total = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $filterQueries, APP_LIMIT_COUNT);
        } else {
            $documents = Authorization::skip(fn () => $dbForProject->find('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $queries));
            $total = Authorization::skip(fn () => $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $filterQueries, APP_LIMIT_COUNT));
        }

        /**
         * Reset $collection attribute to remove prefix.
         */
        $documents = array_map(function (Document $document) use ($collectionId, $databaseId) {
            $document->setAttribute('$collectionId', $collectionId);
            $document->setAttribute('$databaseId', $databaseId);
            return $document;
        }, $documents);

        $response->dynamic(new Document([
            'total' => $total,
            'documents' => $documents,
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId', ['databaseId' => 'default'])
    ->desc('Get Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('usage.metric', 'documents.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}', 'collectionId:{request.collectionId}'])
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
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, string $documentId, Response $response, Database $dbForProject, string $mode) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND);
            }
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($collection->getRead());
        if (!$documentSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if ($documentSecurity && !$valid) {
            $document = $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId);
        } else {
            $document = Authorization::skip(fn () => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId));
        }

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        /**
         * Reset $collection attribute to remove prefix.
         */
        $document->setAttribute('$collectionId', $collectionId);
        $document->setAttribute('$databaseId', $databaseId);

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId/logs')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId/logs', ['databaseId' => 'default'])
    ->desc('List Document Logs')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('usage.metric', 'documents.{scope}.requests.read')
    ->label('usage.params', ['databaseId:{request.databaseId}', 'collectionId:{request.collectionId}'])
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
    ->param('queries', [], new Queries(new Limit(), new Offset()), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Only supported methods are limit and offset', true)
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

        $queries = Query::parseQueries($queries);
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
                'userId' => $log['userId'],
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
    ->desc('Update Document')
    ->groups(['api', 'database'])
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].update')
    ->label('scope', 'documents.write')
    ->label('audits.event', 'document.update')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{response.$id}')
    ->label('usage.metric', 'documents.{scope}.requests.update')
    ->label('usage.params', ['databaseId:{request.databaseId}', 'collectionId:{request.collectionId}'])
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
    ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](/docs/permissions).', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, string $documentId, string|array $data, ?array $permissions, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $events, string $mode) {

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data) && \is_null($permissions)) {
            throw new Exception(Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND);
            }
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $validator = new Authorization(Database::PERMISSION_UPDATE);
        $valid = $validator->isValid($collection->getUpdate());
        if (!$documentSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Read permission should not be required for update
        /** @var Document */
        $document = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId));

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
        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles) && !\is_null($permissions)) {
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

        $data = \array_merge($document->getArrayCopy(), $data);
        $data['$collection'] = $collection->getId();            // Make sure user doesn't switch collectionID
        $data['$createdAt'] = $document->getCreatedAt();        // Make sure user doesn't switch createdAt
        $data['$id'] = $document->getId();                      // Make sure user doesn't switch document unique ID
        $data['$permissions'] = $permissions;

        try {
            $privateCollectionId = 'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId();
            if ($documentSecurity && !$valid) {
                $document = $dbForProject->withRequestTimestamp(
                    $requestTimestamp,
                    function () use ($dbForProject, $privateCollectionId, $document, $data) {
                        return $dbForProject->updateDocument(
                            $privateCollectionId,
                            $document->getId(),
                            new Document($data)
                        );
                    }
                );
            } else {
                $document = Authorization::skip(function () use ($dbForProject, $requestTimestamp, $privateCollectionId, $document, $data) {
                    return $dbForProject->withRequestTimestamp(
                        $requestTimestamp,
                        function () use ($dbForProject, $privateCollectionId, $document, $data) {
                            return $dbForProject->updateDocument(
                                $privateCollectionId,
                                $document->getId(),
                                new Document($data)
                            );
                        }
                    );
                });
            }

            /**
             * Reset $collection attribute to remove prefix.
             */
            $document->setAttribute('$collectionId', $collectionId);
            $document->setAttribute('$databaseId', $databaseId);
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (DuplicateException) {
            throw new Exception(Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (StructureException $exception) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $exception->getMessage());
        }

        $events
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
        ;

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::delete('/v1/databases/:databaseId/collections/:collectionId/documents/:documentId')
    ->alias('/v1/database/collections/:collectionId/documents/:documentId', ['databaseId' => 'default'])
    ->desc('Delete Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('event', 'databases.[databaseId].collections.[collectionId].documents.[documentId].delete')
    ->label('audits.event', 'document.delete')
    ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}/document/{request.documentId}')
    ->label('usage.metric', 'documents.{scope}.requests.delete')
    ->label('usage.params', ['databaseId:{request.databaseId}', 'collectionId:{request.collectionId}'])
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
    ->inject('events')
    ->inject('deletes')
    ->inject('mode')
    ->action(function (string $databaseId, string $collectionId, string $documentId, ?\DateTime $requestTimestamp, Response $response, Database $dbForProject, Event $events, Delete $deletes, string $mode) {

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId(), $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND);
            }
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $validator = new Authorization(Database::PERMISSION_DELETE);
        $valid = $validator->isValid($collection->getDelete());
        if (!$documentSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Read permission should not be required for delete
        $document = Authorization::skip(fn() => $dbForProject->getDocument('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $documentId));

        if ($document->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND);
        }

        $privateCollectionId = 'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId();

        if ($documentSecurity && !$valid) {
            try {
                $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $privateCollectionId, $documentId) {
                    return $dbForProject->deleteDocument($privateCollectionId, $documentId);
                });
            } catch (AuthorizationException) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }
        } else {
            Authorization::skip(function () use ($dbForProject, $requestTimestamp, $privateCollectionId, $documentId) {
                return $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $privateCollectionId, $documentId) {
                    return $dbForProject->deleteDocument($privateCollectionId, $documentId);
                });
            });
        }

        $dbForProject->deleteCachedDocument($privateCollectionId, $documentId);

        /**
         * Reset $collection attribute to remove prefix.
         */
        $document->setAttribute('$collectionId', $collectionId);
        $document->setAttribute('$databaseId', $databaseId);

        $deletes
            ->setType(DELETE_TYPE_AUDIT)
            ->setDocument($document)
        ;

        $events
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext('collection', $collection)
            ->setContext('database', $database)
            ->setPayload($response->output($document, Response::MODEL_DOCUMENT))
        ;

        $response->noContent();
    });

App::get('/v1/databases/usage')
    ->desc('Get usage stats for the database')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_DATABASES)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), '`Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '1h',
                    'limit' => 24,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                'databases.$all.count.total',
                'documents.$all.count.total',
                'collections.$all.count.total',
                'databases.$all.requests.create',
                'databases.$all.requests.read',
                'databases.$all.requests.update',
                'databases.$all.requests.delete',
                'collections.$all.requests.create',
                'collections.$all.requests.read',
                'collections.$all.requests.update',
                'collections.$all.requests.delete',
                'documents.$all.requests.create',
                'documents.$all.requests.read',
                'documents.$all.requests.update',
                'documents.$all.requests.delete'
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '1h' => 3600,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::formatTz(DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff)),
                        ];
                        $backfill--;
                    }
                    // Added 3'rd level to Index [period, metric, time] because of order by.
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'databasesCount' => $stats['databases.$all.count.total'] ?? [],
                'documentsCount' => $stats['documents.$all.count.total'] ?? [],
                'collectionsCount' => $stats['collections.$all.count.total'] ?? [],
                'documentsCreate' =>  $stats['documents.$all.requests.create'] ?? [],
                'documentsRead' =>  $stats['documents.$all.requests.read'] ?? [],
                'documentsUpdate' => $stats['documents.$all.requests.update'] ?? [],
                'documentsDelete' => $stats['documents.$all.requests.delete'] ?? [],
                'collectionsCreate' => $stats['collections.$all.requests.create'] ?? [],
                'collectionsRead' =>  $stats['collections.$all.requests.read'] ?? [],
                'collectionsUpdate' => $stats['collections.$all.requests.update'] ?? [],
                'collectionsDelete' => $stats['collections.$all.requests.delete'] ?? [],
                'databasesCreate' => $stats['databases.$all.requests.create'] ?? [],
                'databasesRead' =>  $stats['databases.$all.requests.read'] ?? [],
                'databasesUpdate' => $stats['databases.$all.requests.update'] ?? [],
                'databasesDelete' => $stats['databases.$all.requests.delete'] ?? [],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_DATABASES);
    });

App::get('/v1/databases/:databaseId/usage')
    ->desc('Get usage stats for the database')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getDatabaseUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_DATABASE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), '`Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $databaseId, string $range, Response $response, Database $dbForProject) {

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '1h',
                    'limit' => 24,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                'collections.' . $databaseId . '.count.total',
                'collections.' . $databaseId . '.requests.create',
                'collections.' . $databaseId . '.requests.read',
                'collections.' . $databaseId . '.requests.update',
                'collections.' . $databaseId . '.requests.delete',
                'documents.' . $databaseId . '.count.total',
                'documents.' . $databaseId . '.requests.create',
                'documents.' . $databaseId . '.requests.read',
                'documents.' . $databaseId . '.requests.update',
                'documents.' . $databaseId . '.requests.delete'
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '1h' => 3600,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::formatTz(DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff)),
                        ];
                        $backfill--;
                    }
                    // TODO@kodumbeats explore performance if query is ordered by time ASC
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'collectionsCount' => $stats["collections.{$databaseId}.count.total"] ?? [],
                'collectionsCreate' => $stats["collections.{$databaseId}.requests.create"] ?? [],
                'collectionsRead' =>  $stats["collections.{$databaseId}.requests.read"] ?? [],
                'collectionsUpdate' => $stats["collections.{$databaseId}.requests.update"] ?? [],
                'collectionsDelete' => $stats["collections.{$databaseId}.requests.delete"] ?? [],
                'documentsCount' => $stats["documents.{$databaseId}.count.total"] ?? [],
                'documentsCreate' =>  $stats["documents.{$databaseId}.requests.create"] ?? [],
                'documentsRead' =>  $stats["documents.{$databaseId}.requests.read"] ?? [],
                'documentsUpdate' => $stats["documents.{$databaseId}.requests.update"] ?? [],
                'documentsDelete' => $stats["documents.{$databaseId}.requests.delete"] ?? [],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_DATABASE);
    });

App::get('/v1/databases/:databaseId/collections/:collectionId/usage')
    ->alias('/v1/database/:collectionId/usage', ['databaseId' => 'default'])
    ->desc('Get usage stats for a collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getCollectionUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_COLLECTION)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
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

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '1h',
                    'limit' => 24,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                "documents.{$databaseId}/{$collectionId}.count.total",
                "documents.{$databaseId}/{$collectionId}.requests.create",
                "documents.{$databaseId}/{$collectionId}.requests.read",
                "documents.{$databaseId}/{$collectionId}.requests.update",
                "documents.{$databaseId}/{$collectionId}.requests.delete",
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '1h' => 3600,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::formatTz(DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff)),
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'documentsCount' => $stats["documents.{$databaseId}/{$collectionId}.count.total"] ?? [],
                'documentsCreate' => $stats["documents.{$databaseId}/{$collectionId}.requests.create"] ?? [],
                'documentsRead' => $stats["documents.{$databaseId}/{$collectionId}.requests.read"] ?? [],
                'documentsUpdate' =>  $stats["documents.{$databaseId}/{$collectionId}.requests.update"] ?? [],
                'documentsDelete' =>  $stats["documents.{$databaseId}/{$collectionId}.requests.delete" ?? []]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_COLLECTION);
    });
