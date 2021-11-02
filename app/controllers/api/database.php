<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Audit\Audit;
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
use Utopia\Database\Query;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\QueryValidator;
use Utopia\Database\Validator\Queries as QueriesValidator;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Appwrite\Database\Validator\CustomId;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\IP;
use Appwrite\Network\Validator\URL;
use Appwrite\Utopia\Response;
use DeviceDetector\DeviceDetector;

/**
 * Create attribute of varying type
 *
 * @param string $collectionId
 * @param Utopia\Database\Document $attribute
 * @param Appwrite\Utopia\Response $response
 * @param Utopia\Database\Database $dbForInternal
 * @param Appwrite\Event\Event $database
 * @param Appwrite\Event\Event $audits
 * @param Appwrite\Stats\Stats $usage
 *
 * @return Document Newly created attribute document
 */
function createAttribute($collectionId, $attribute, $response, $dbForInternal, $database, $audits, $usage): Document
{
    $attributeId = $attribute->getId();
    $type = $attribute->getAttribute('type', '');
    $size = $attribute->getAttribute('size', 0);
    $required = $attribute->getAttribute('required', true);
    $signed = $attribute->getAttribute('signed', true); // integers are signed by default 
    $array = $attribute->getAttribute('array', false);
    $format = $attribute->getAttribute('format', '');
    $formatOptions = $attribute->getAttribute('formatOptions', []);
    $filters = $attribute->getAttribute('filters', []); // filters are hidden from the endpoint 
    $default = $attribute->getAttribute('default', null);

    $collection = $dbForInternal->getDocument('collections', $collectionId);

    if ($collection->isEmpty()) {
        throw new Exception('Collection not found', 404);
    }

    if (!empty($format)) {
        if (!Structure::hasFormat($format, $type)) {
            throw new Exception("Format {$format} not available for {$type} attributes.", 400);
        }
    }

    // Must throw here since dbForExternal->createAttribute is performed by db worker
    if ($required && $default) {
        throw new Exception('Cannot set default value for required attribute', 400);
    }

    if ($array && $default) {
        throw new Exception('Cannot set default value for array attributes', 400);
    }

    try {
        $attribute = new Document([
            '$id' => $collectionId.'_'.$attributeId,
            'key' => $attributeId,
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
        ]);

        $dbForInternal->checkAttribute($collection, $attribute);
        $attribute = $dbForInternal->createDocument('attributes', $attribute);
    }
    catch (DuplicateException $exception) {
        throw new Exception('Attribute already exists', 409);
    }
    catch (LimitException $exception) {
        throw new Exception('Attribute limit exceeded', 400);
    }

    $dbForInternal->deleteCachedDocument('collections', $collectionId);

    // Pass clone of $attribute object to workers
    // so we can later modify Document to fit response model
    $clone = clone $attribute;

    $database
        ->setParam('type', DATABASE_TYPE_CREATE_ATTRIBUTE)
        ->setParam('collection', $collection)
        ->setParam('document', $clone)
    ;

    $usage->setParam('database.collections.update', 1);

    $audits
        ->setParam('event', 'database.attributes.create')
        ->setParam('resource', 'collection/'.$collection->getId())
        ->setParam('data', $clone)
    ;

    $response->setStatusCode(Response::STATUS_CODE_CREATED);

    return $attribute;
};

App::post('/v1/database/collections')
    ->desc('Create Collection')
    ->groups(['api', 'database'])
    ->label('event', 'database.collections.create')
    ->label('scope', 'collections.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createCollection')
    ->label('sdk.description', '/docs/references/database/create-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permission', null, new WhiteList(['document', 'collection']), 'Permissions type model to use for reading documents in this collection. You can use collection-level permission set once on the collection using the `read` and `write` params, or you can set document-level permission where each document read and write params will decide who has access to read and write to each document individually. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $name, $permission, $read, $write, $response, $dbForInternal, $dbForExternal, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $collectionId = $collectionId == 'unique()' ? $dbForExternal->getId() : $collectionId;

        try {
            $dbForExternal->createCollection($collectionId);

            $collection = $dbForInternal->createDocument('collections', new Document([
                '$id' => $collectionId,
                '$read' => $read ?? [], // Collection permissions for collection documents (based on permission model)
                '$write' => $write ?? [], // Collection permissions for collection documents (based on permission model)
                'permission' => $permission, // Permissions model type (document vs collection)
                'dateCreated' => time(),
                'dateUpdated' => time(),
                'name' => $name,
                'search' => implode(' ', [$collectionId, $name]),
            ]));
        } catch (DuplicateException $th) {
            throw new Exception('Collection already exists', 409);
        }

        $audits
            ->setParam('event', 'database.collections.create')
            ->setParam('resource', 'collection/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $usage->setParam('database.collections.create', 1);

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/database/collections')
    ->desc('List Collections')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', '/docs/references/database/list-collections.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 40000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('cursor', '', new UID(), 'ID of the collection used as the starting point for the query, excluding the collection itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        if (!empty($cursor)) {
            $cursorCollection = $dbForInternal->getDocument('collections', $cursor);

            if ($cursorCollection->isEmpty()) {
                throw new Exception("Collection '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('name', Query::TYPE_SEARCH, [$search]);
        }

        $usage->setParam('database.collections.read', 1);

        $response->dynamic(new Document([
            'collections' => $dbForInternal->find('collections', $queries, $limit, $offset, [], [$orderType], $cursorCollection ?? null, $cursorDirection),
            'sum' => $dbForInternal->count('collections', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_COLLECTION_LIST);
    });

App::get('/v1/database/collections/:collectionId')
    ->desc('Get Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getCollection')
    ->label('sdk.description', '/docs/references/database/get-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($collectionId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $usage->setParam('database.collections.read', 1);

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/database/usage')
    ->desc('Get usage stats for the database')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_DATABASE)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($range, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Registry\Registry $register */

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $period = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
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
                'database.documents.count',
                'database.collections.count',
                'database.collections.create',
                'database.collections.read',
                'database.collections.update',
                'database.collections.delete',
                'database.documents.create',
                'database.documents.read',
                'database.documents.update',
                'database.documents.delete'
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForInternal, $period, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $requestDocs = $dbForInternal->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period[$range]['period']]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $period[$range]['limit'], 0, ['time'], [Database::ORDER_DESC]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'documentsCount' => $stats["database.documents.count"],
                'collectionsCount' => $stats["database.collections.count"],
                'documentsCreate' =>  $stats["database.documents.create"],
                'documentsRead' =>  $stats["database.documents.read"],
                'documentsUpdate' => $stats["database.documents.update"],
                'documentsDelete' => $stats["database.documents.delete"],
                'collectionsCreate' => $stats["database.collections.create"],
                'collectionsRead' =>  $stats["database.collections.read"],
                'collectionsUpdate' => $stats["database.collections.update"],
                'collectionsDelete' => $stats["database.collections.delete"],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_DATABASE);
    });

App::get('/v1/database/:collectionId/usage')
    ->desc('Get usage stats for a collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getCollectionUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_COLLECTION)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->action(function ($range, $collectionId, $response, $dbForInternal, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Registry\Registry $register */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }
        
        $usage = [];
        if(App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $period = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
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
                "database.collections.$collectionId.documents.count",
                "database.collections.$collectionId.documents.create",
                "database.collections.$collectionId.documents.read",
                "database.collections.$collectionId.documents.update",
                "database.collections.$collectionId.documents.delete",
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForInternal, $period, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $requestDocs = $dbForInternal->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period[$range]['period']]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $period[$range]['limit'], 0, ['time'], [Database::ORDER_DESC]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }    
            });

            $usage = new Document([
                'range' => $range,
                'documentsCount' => $stats["database.collections.$collectionId.documents.count"],
                'documentsCreate' => $stats["database.collections.$collectionId.documents.create"],
                'documentsRead' => $stats["database.collections.$collectionId.documents.read"],
                'documentsUpdate' =>  $stats["database.collections.$collectionId.documents.update"],
                'documentsDelete' =>  $stats["database.collections.$collectionId.documents.delete"]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_COLLECTION);
    });

App::get('/v1/database/collections/:collectionId/logs')
    ->desc('List Collection Logs')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listCollectionLogs')
    ->label('sdk.description', '/docs/references/database/get-collection-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('locale')
    ->inject('geodb')
    ->action(function ($collectionId, $response, $dbForInternal, $dbForExternal, $locale, $geodb) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $audit = new Audit($dbForInternal);

        $logs = $audit->getLogsByResource('collection/'.$collection->getId());

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $dd = new DeviceDetector($log['userAgent']);

            $dd->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $dd->parse();

            $os = $dd->getOs();
            $osCode = (isset($os['short_name'])) ? $os['short_name'] : '';
            $osName = (isset($os['name'])) ? $os['name'] : '';
            $osVersion = (isset($os['version'])) ? $os['version'] : '';

            $client = $dd->getClient();
            $clientType = (isset($client['type'])) ? $client['type'] : '';
            $clientCode = (isset($client['short_name'])) ? $client['short_name'] : '';
            $clientName = (isset($client['name'])) ? $client['name'] : '';
            $clientVersion = (isset($client['version'])) ? $client['version'] : '';
            $clientEngine = (isset($client['engine'])) ? $client['engine'] : '';
            $clientEngineVersion = (isset($client['engine_version'])) ? $client['engine_version'] : '';

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],

                'osCode' => $osCode,
                'osName' => $osName,
                'osVersion' => $osVersion,
                'clientType' => $clientType,
                'clientCode' => $clientCode,
                'clientName' => $clientName,
                'clientVersion' => $clientVersion,
                'clientEngine' => $clientEngine,
                'clientEngineVersion' => $clientEngineVersion,
                'deviceName' => $dd->getDeviceName(),
                'deviceBrand' => $dd->getBrandName(),
                'deviceModel' => $dd->getModel(),
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document(['logs' => $output]), Response::MODEL_LOG_LIST);
    });

App::put('/v1/database/collections/:collectionId')
    ->desc('Update Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.collections.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'updateCollection')
    ->label('sdk.description', '/docs/references/database/update-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permission', null, new WhiteList(['document', 'collection']), 'Permissions type model to use for reading documents in this collection. You can use collection-level permission set once on the collection using the `read` and `write` params, or you can set document-level permission where each document read and write params will decide who has access to read and write to each document individually. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $name, $permission, $read, $write, $response, $dbForInternal, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $read = (is_null($read)) ? ($collection->getRead() ?? []) : $read; // By default inherit read permissions
        $write = (is_null($write)) ? ($collection->getWrite() ?? []) : $write; // By default inherit write permissions

        try {
            $collection = $dbForInternal->updateDocument('collections', $collection->getId(), $collection
                ->setAttribute('$write', $write)
                ->setAttribute('$read', $read)
                ->setAttribute('name', $name)
                ->setAttribute('permission', $permission)
                ->setAttribute('dateUpdated', time())
                ->setAttribute('search', implode(' ', [$collectionId, $name]))
            );
        }
        catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        }
        catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        }

        $usage->setParam('database.collections.update', 1);

        $audits
            ->setParam('event', 'database.collections.update')
            ->setParam('resource', 'collection/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::delete('/v1/database/collections/:collectionId')
    ->desc('Delete Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.collections.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteCollection')
    ->label('sdk.description', '/docs/references/database/delete-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->inject('usage')
    ->action(function ($collectionId, $response, $dbForInternal, $dbForExternal, $events, $audits, $deletes, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $audits */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        if (!$dbForInternal->deleteDocument('collections', $collectionId)) {
            throw new Exception('Failed to remove collection from DB', 500);
        }

        $dbForExternal->deleteCachedCollection($collection->getId());

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $collection)
        ;

        $usage->setParam('database.collections.delete', 1);

        $events
            ->setParam('eventData', $response->output($collection, Response::MODEL_COLLECTION))
        ;

        $audits
            ->setParam('event', 'database.collections.delete')
            ->setParam('resource', 'collection/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/attributes/string')
    ->desc('Create String Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createStringAttribute')
    ->label('sdk.description', '/docs/references/database/create-string-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_STRING)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Range::TYPE_INTEGER), 'Attribute size for text attributes, in number of characters.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $size, $required, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        // Ensure attribute default is within required size
        $validator = new Text($size);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception($validator->getDescription(), 400);
        }

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_STRING);
    });

App::post('/v1/database/collections/:collectionId/attributes/email')
    ->desc('Create Email Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createEmailAttribute')
    ->label('sdk.description', '/docs/references/database/create-email-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_EMAIL)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Email(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $required, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => 254,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_EMAIL,
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::post('/v1/database/collections/:collectionId/attributes/enum')
    ->desc('Create Enum Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createEnumAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-enum.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_ENUM)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('elements', [], new ArrayList(new Text(0)), 'Array of elements in enumerated type. Uses length of longest element to determine size.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $elements, $required, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        // use length of longest string as attribute size
        $size = 0;
        foreach ($elements as $element) {
            $length = \strlen($element);
            if ($length === 0) {
                throw new Exception('Each enum element must not be empty', 400);

            }
            $size = ($length > $size) ? $length : $size;
        }

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_ENUM,
            'formatOptions' => ['elements' => $elements],
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::post('/v1/database/collections/:collectionId/attributes/ip')
    ->desc('Create IP Address Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIpAttribute')
    ->label('sdk.description', '/docs/references/database/create-ip-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_IP)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new IP(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $required, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => 39,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_IP,
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_IP);
    });

App::post('/v1/database/collections/:collectionId/attributes/url')
    ->desc('Create URL Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createUrlAttribute')
    ->label('sdk.description', '/docs/references/database/create-url-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_URL)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new URL(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $required, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => 2000,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_URL,
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_URL);
    });

App::post('/v1/database/collections/:collectionId/attributes/integer')
    ->desc('Create Integer Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIntegerAttribute')
    ->label('sdk.description', '/docs/references/database/create-integer-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_INTEGER)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Integer(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $required, $min, $max, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        // Ensure attribute default is within range
        $min = (is_null($min)) ? PHP_INT_MIN : \intval($min);
        $max = (is_null($max)) ? PHP_INT_MAX : \intval($max);

        if ($min > $max) {
            throw new Exception('Minimum value must be lesser than maximum value', 400);
        }

        $validator = new Range($min, $max, Database::VAR_INTEGER);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception($validator->getDescription(), 400);
        }

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
            'type' => Database::VAR_INTEGER,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
            'formatOptions' => [
                'min' => $min,
                'max' => $max,
            ],
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \intval($formatOptions['min']));
            $attribute->setAttribute('max', \intval($formatOptions['max']));
        }

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::post('/v1/database/collections/:collectionId/attributes/float')
    ->desc('Create Float Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createFloatAttribute')
    ->label('sdk.description', '/docs/references/database/create-float-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_FLOAT)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new FloatValidator(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $required, $min, $max, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        // Ensure attribute default is within range
        $min = (is_null($min)) ? -PHP_FLOAT_MAX : \floatval($min);
        $max = (is_null($max)) ? PHP_FLOAT_MAX : \floatval($max);

        if ($min > $max) {
            throw new Exception('Minimum value must be lesser than maximum value', 400);
        }

        $validator = new Range($min, $max, Database::VAR_FLOAT);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception($validator->getDescription(), 400);
        }

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
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
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \floatval($formatOptions['min']));
            $attribute->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::post('/v1/database/collections/:collectionId/attributes/boolean')
    ->desc('Create Boolean Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createBooleanAttribute')
    ->label('sdk.description', '/docs/references/database/create-boolean-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_BOOLEAN)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Boolean(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $required, $default, $array, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $attribute = createAttribute($collectionId, new Document([
            '$id' => $attributeId,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForInternal, $database, $audits, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::get('/v1/database/collections/:collectionId/attributes')
    ->desc('List Attributes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listAttributes')
    ->label('sdk.description', '/docs/references/database/list-attributes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($collectionId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $attributes = $collection->getAttribute('attributes');

        $usage->setParam('database.collections.read', 1);

        $response->dynamic(new Document([
            'sum' => \count($attributes),
            'attributes' => $attributes
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('/v1/database/collections/:collectionId/attributes/:attributeId')
    ->desc('Get Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getAttribute')
    ->label('sdk.description', '/docs/references/database/get-attribute.md')
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
        Response::MODEL_ATTRIBUTE_STRING,])// needs to be last, since its condition would dominate any other string attribute
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if (empty($collection)) {
            throw new Exception('Collection not found', 404);
        }

        $attribute = $collection->find('$id', $attributeId, 'attributes');

        if (!$attribute) {
            throw new Exception('Attribute not found', 404);
        }

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

        $model = match($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_STRING => match($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $usage->setParam('database.collections.read', 1);

        $response->dynamic($attribute, $model);
    });

App::delete('/v1/database/collections/:collectionId/attributes/:attributeId')
    ->desc('Delete Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.attributes.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteAttribute')
    ->label('sdk.description', '/docs/references/database/delete-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $attributeId, $response, $dbForInternal, $database, $events, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $attribute = $dbForInternal->getDocument('attributes', $collectionId.'_'.$attributeId);

        if (empty($attribute->getId())) {
            throw new Exception('Attribute not found', 404);
        }

        // Only update status if removing available attribute
        if ($attribute->getAttribute('status' === 'available')) {
            $attribute = $dbForInternal->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'deleting'));
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);

        $database
            ->setParam('type', DATABASE_TYPE_DELETE_ATTRIBUTE)
            ->setParam('collection', $collection)
            ->setParam('document', $attribute)
        ;

        $usage->setParam('database.collections.update', 1);

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

        $model = match($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_STRING => match($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $events
            ->setParam('payload', $response->output($attribute, $model))
        ;

        $audits
            ->setParam('event', 'database.attributes.delete')
            ->setParam('resource', 'collection/'.$collection->getId())
            ->setParam('data', $attribute->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/indexes')
    ->desc('Create Index')
    ->groups(['api', 'database'])
    ->label('event', 'database.indexes.create')
    ->label('scope', 'collections.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createIndex')
    ->label('sdk.description', '/docs/references/database/create-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('indexId', null, new Key(), 'Index ID.')
    ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE, Database::INDEX_SPATIAL, Database::INDEX_ARRAY]), 'Index type.')
    ->param('attributes', null, new ArrayList(new Key()), 'Array of attributes to index.')
    ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING)), 'Array of index orders.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $indexId, $type, $attributes, $orders, $response, $dbForInternal, $database, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $audits */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $count = $dbForInternal->count('indexes', [
            new Query('collectionId', Query::TYPE_EQUAL, [$collectionId])
        ], 61);

        $limit = 64 - MariaDB::getNumberOfDefaultIndexes();

        if ($count >= $limit) {
            throw new Exception('Index limit exceeded', 400);
        }

        // Convert Document[] to array of attribute metadata
        $oldAttributes = \array_map(function ($a) {
            return $a->getArrayCopy();
        }, $collection->getAttribute('attributes'));

        // lengths hidden by default
        $lengths = [];

        foreach ($attributes as $key => $attribute) {
            // find attribute metadata in collection document
            $attributeIndex = \array_search($attribute, array_column($oldAttributes, 'key'));

            if ($attributeIndex === false) {
                throw new Exception('Unknown attribute: ' . $attribute, 400);
            }

            $attributeStatus = $oldAttributes[$attributeIndex]['status'];
            $attributeType = $oldAttributes[$attributeIndex]['type'];
            $attributeSize = $oldAttributes[$attributeIndex]['size'];

            // ensure attribute is available
            if ($attributeStatus !== 'available') {
                throw new Exception ('Attribute not available: ' . $oldAttributes[$attributeIndex]['key'], 400);
            }

            // set attribute size as index length only for strings
            $lengths[$key] = ($attributeType === Database::VAR_STRING) ? $attributeSize : null;
        }

        try {
            $index = $dbForInternal->createDocument('indexes', new Document([
                '$id' => $collectionId.'_'.$indexId,
                'key' => $indexId,
                'status' => 'processing', // processing, available, failed, deleting, stuck
                'collectionId' => $collectionId,
                'type' => $type,
                'attributes' => $attributes,
                'lengths' => $lengths,
                'orders' => $orders,
            ]));
        } catch (DuplicateException $th) {
            throw new Exception('Index already exists', 409);
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);

        $database
            ->setParam('type', DATABASE_TYPE_CREATE_INDEX)
            ->setParam('collection', $collection)
            ->setParam('document', $index)
        ;

        $usage->setParam('database.collections.update', 1);

        $audits
            ->setParam('event', 'database.indexes.create')
            ->setParam('resource', 'collection/'.$collection->getId())
            ->setParam('data', $index->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($index, Response::MODEL_INDEX);
    });

App::get('/v1/database/collections/:collectionId/indexes')
    ->desc('List Indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listIndexes')
    ->label('sdk.description', '/docs/references/database/list-indexes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($collectionId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $indexes = $collection->getAttribute('indexes');

        $indexes = array_map(function ($index) use ($collection) {
            return new Document([\array_merge($index, [
                'collectionId' => $collection->getId(),
            ])]);
        }, $indexes);

        $usage->setParam('database.collections.read', 1);

        $response->dynamic(new Document([
            'sum' => \count($indexes),
            'attributes' => $indexes,
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('/v1/database/collections/:collectionId/indexes/:indexId')
    ->desc('Get Index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getIndex')
    ->label('sdk.description', '/docs/references/database/get-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('indexId', null, new Key(), 'Index ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($collectionId, $indexId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $indexes = $collection->getAttribute('indexes');

        // Search for index
        $indexIndex = array_search($indexId, array_column($indexes, '$id'));

        if ($indexIndex === false) {
            throw new Exception('Index not found', 404);
        }

        $index = new Document([\array_merge($indexes[$indexIndex], [
            'collectionId' => $collectionId,
        ])]);

        $usage->setParam('database.collections.read', 1);
        
        $response->dynamic($index, Response::MODEL_INDEX);
    });

App::delete('/v1/database/collections/:collectionId/indexes/:indexId')
    ->desc('Delete Index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.indexes.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteIndex')
    ->label('sdk.description', '/docs/references/database/delete-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('indexId', '', new Key(), 'Index ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('database')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $indexId, $response, $dbForInternal, $database, $events, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $index = $dbForInternal->getDocument('indexes', $collectionId.'_'.$indexId);

        if (empty($index->getId())) {
            throw new Exception('Index not found', 404);
        }

        // Only update status if removing available index
        if ($index->getAttribute('status') === 'available') {
            $index = $dbForInternal->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'deleting'));
        }

        $dbForInternal->deleteCachedDocument('collections', $collectionId);

        $database
            ->setParam('type', DATABASE_TYPE_DELETE_INDEX)
            ->setParam('collection', $collection)
            ->setParam('document', $index)
        ;

        $usage->setParam('database.collections.update', 1);

        $events
            ->setParam('payload', $response->output($index, Response::MODEL_INDEX))
        ;

        $audits
            ->setParam('event', 'database.indexes.delete')
            ->setParam('resource', 'collection/'.$collection->getId())
            ->setParam('data', $index->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/documents')
    ->desc('Create Document')
    ->groups(['api', 'database'])
    ->label('event', 'database.documents.create')
    ->label('scope', 'documents.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', '/docs/references/database/create-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('documentId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($documentId, $collectionId, $data, $read, $write, $response, $dbForInternal, $dbForExternal, $user, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Utopia\Database\Document $user */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception('Missing payload', 400);
        }

        if (isset($data['$id'])) {
            throw new Exception('$id is not allowed for creating new documents, try update instead', 400);
        }

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('write');
            if (!$validator->isValid($collection->getWrite())) {
                throw new Exception('Unauthorized permissions', 401);
            }
        }

        $data['$collection'] = $collection->getId(); // Adding this param to make API easier for developers
        $data['$id'] = $documentId == 'unique()' ? $dbForExternal->getId() : $documentId;
        $data['$read'] = (is_null($read) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $read ?? []; //  By default set read permissions for user
        $data['$write'] = (is_null($write) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $write ?? []; //  By default set write permissions for user

        try {
            if ($collection->getAttribute('permission') === 'collection') {
                /** @var Document $document */
                $document = Authorization::skip(function() use ($dbForExternal, $collectionId, $data) {
                    return $dbForExternal->createDocument($collectionId, new Document($data));
                });
            } else {
                $document = $dbForExternal->createDocument($collectionId, new Document($data));
            }
        }
        catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400);
        }
        catch (DuplicateException $exception) {
            throw new Exception('Document already exists', 409);
        }

        $usage
            ->setParam('database.documents.create', 1)
            ->setParam('collectionId', $collectionId)
        ;

        $audits
            ->setParam('event', 'database.documents.create')
            ->setParam('resource', 'document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/database/collections/:collectionId/documents')
    ->desc('List Documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', '/docs/references/database/list-documents.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('queries', [], new ArrayList(new Text(128)), 'Array of query strings.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of documents to return in response.  Use this value to manage pagination. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 900000000), 'Offset value. The default value is 0. Use this param to manage pagination.', true)
    ->param('cursor', '', new UID(), 'ID of the document used as the starting point for the query, excluding the document itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderAttributes', [], new ArrayList(new Text(128)), 'Array of attributes used to sort results.', true)
    ->param('orderTypes', [], new ArrayList(new WhiteList(['DESC', 'ASC'], true)), 'Array of order directions for sorting attribtues. Possible values are DESC for descending order, or ASC for ascending order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('usage')
    ->action(function ($collectionId, $queries, $limit, $offset, $cursor, $cursorDirection, $orderAttributes, $orderTypes, $response, $dbForInternal, $dbForExternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Stats\Stats $usage */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('read');
            if (!$validator->isValid($collection->getRead())) {
                throw new Exception('Unauthorized permissions', 401);
            }
        }

        $queries = \array_map(function ($query) {
            return Query::parse($query);
        }, $queries);

        // TODO@kodumbeats use strict query validation
        $validator = new QueriesValidator(new QueryValidator($collection->getAttribute('attributes', [])), $collection->getAttribute('indexes', []), false);

        if (!$validator->isValid($queries)) {
            throw new Exception($validator->getDescription(), 400);
        }

        $cursorDocument = null;
        if (!empty($cursor)) {
            $cursorDocument = $dbForExternal->getDocument($collectionId, $cursor);

            if ($cursorDocument->isEmpty()) {
                throw new Exception("Document '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        if ($collection->getAttribute('permission') === 'collection') {
            /** @var Document[] $documents */
            $documents = Authorization::skip(function() use ($dbForExternal, $collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursorDocument, $cursorDirection) {
                return $dbForExternal->find($collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursorDocument ?? null, $cursorDirection);
            });
        } else {
            $documents = $dbForExternal->find($collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursorDocument ?? null, $cursorDirection);
        }

        $usage
            ->setParam('database.documents.read', 1)
            ->setParam('collectionId', $collectionId)
        ;

        $response->dynamic(new Document([
            'sum' => $dbForExternal->count($collectionId, $queries, APP_LIMIT_COUNT),
            'documents' => $documents,
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Get Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getDocument')
    ->label('sdk.description', '/docs/references/database/get-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('usage')
    ->action(function ($collectionId, $documentId, $response, $dbForInternal, $dbForExternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $$dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('read');
            if (!$validator->isValid($collection->getRead())) {
                throw new Exception('Unauthorized permissions', 401);
            }
        }

        if ($collection->getAttribute('permission') === 'collection') {
            /** @var Document $document */
            $document = Authorization::skip(function() use ($dbForExternal, $collectionId, $documentId) {
                return $dbForExternal->getDocument($collectionId, $documentId);
            });
        } else {
            $document = $dbForExternal->getDocument($collectionId, $documentId);
        }

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404);
        }

        $usage
            ->setParam('database.documents.read', 1)
            ->setParam('collectionId', $collectionId)
            ;

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/database/collections/:collectionId/documents/:documentId/logs')
    ->desc('List Document Logs')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listDocumentLogs')
    ->label('sdk.description', '/docs/references/database/get-document-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('locale')
    ->inject('geodb')
    ->action(function ($collectionId, $documentId, $response, $dbForInternal, $dbForExternal, $locale, $geodb) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $document = $dbForExternal->getDocument($collectionId, $documentId);

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404);
        }

        $audit = new Audit($dbForInternal);

        $logs = $audit->getLogsByResource('document/'.$document->getId());

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $dd = new DeviceDetector($log['userAgent']);

            $dd->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $dd->parse();

            $os = $dd->getOs();
            $osCode = (isset($os['short_name'])) ? $os['short_name'] : '';
            $osName = (isset($os['name'])) ? $os['name'] : '';
            $osVersion = (isset($os['version'])) ? $os['version'] : '';

            $client = $dd->getClient();
            $clientType = (isset($client['type'])) ? $client['type'] : '';
            $clientCode = (isset($client['short_name'])) ? $client['short_name'] : '';
            $clientName = (isset($client['name'])) ? $client['name'] : '';
            $clientVersion = (isset($client['version'])) ? $client['version'] : '';
            $clientEngine = (isset($client['engine'])) ? $client['engine'] : '';
            $clientEngineVersion = (isset($client['engine_version'])) ? $client['engine_version'] : '';

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],

                'osCode' => $osCode,
                'osName' => $osName,
                'osVersion' => $osVersion,
                'clientType' => $clientType,
                'clientCode' => $clientCode,
                'clientName' => $clientName,
                'clientVersion' => $clientVersion,
                'clientEngine' => $clientEngine,
                'clientEngineVersion' => $clientEngineVersion,
                'deviceName' => $dd->getDeviceName(),
                'deviceBrand' => $dd->getBrandName(),
                'deviceModel' => $dd->getModel(),
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document(['logs' => $output]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Update Document')
    ->groups(['api', 'database'])
    ->label('event', 'database.documents.update')
    ->label('scope', 'documents.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'updateDocument')
    ->label('sdk.description', '/docs/references/database/update-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $documentId, $data, $read, $write, $response, $dbForInternal, $dbForExternal, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('write');
            if (!$validator->isValid($collection->getWrite())) {
                throw new Exception('Unauthorized permissions', 401);
            }
        }

        $document = $dbForExternal->getDocument($collectionId, $documentId);

        if ($document->isEmpty()) {
            throw new Exception('Document not found', 404);
        }

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception('Missing payload', 400);
        }
 
        if (!\is_array($data)) {
            throw new Exception('Data param should be a valid JSON object', 400);
        }

        $data = \array_merge($document->getArrayCopy(), $data);

        $data['$collection'] = $collection->getId(); // Make sure user don't switch collectionID
        $data['$id'] = $document->getId(); // Make sure user don't switch document unique ID
        $data['$read'] = (is_null($read)) ? ($document->getRead() ?? []) : $read; // By default inherit read permissions
        $data['$write'] = (is_null($write)) ? ($document->getWrite() ?? []) : $write; // By default inherit write permissions

        try {
            if ($collection->getAttribute('permission') === 'collection') {
                /** @var Document $document */
                $document = Authorization::skip(function() use ($dbForExternal, $collection, $document, $data) {
                    return $dbForExternal->updateDocument($collection->getId(), $document->getId(), new Document($data));
                });
            } else {
                $document = $dbForExternal->updateDocument($collection->getId(), $document->getId(), new Document($data));
            }
        }
        catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        }
        catch (DuplicateException $exception) {
            throw new Exception('Document already exists', 409);
        }
        catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400);
        }
        
        $usage
            ->setParam('database.documents.update', 1)
            ->setParam('collectionId', $collectionId)
            ;

        $audits
            ->setParam('event', 'database.documents.update')
            ->setParam('resource', 'document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy())
        ;

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::delete('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Delete Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('event', 'database.documents.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteDocument')
    ->label('sdk.description', '/docs/references/database/delete-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->action(function ($collectionId, $documentId, $response, $dbForInternal, $dbForExternal, $events, $audits, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Stats\Stats $usage */

        $collection = $dbForInternal->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('write');
            if (!$validator->isValid($collection->getWrite())) {
                throw new Exception('Unauthorized permissions', 401);
            }
        }

        if ($collection->getAttribute('permission') === 'collection') {
            /** @var Document $document */
            $document = Authorization::skip(function() use ($dbForExternal, $collectionId, $documentId) {
                return $dbForExternal->getDocument($collectionId, $documentId);
            });
        } else {
            $document = $dbForExternal->getDocument($collectionId, $documentId);
        }

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404);
        }

        $dbForExternal->deleteDocument($collectionId, $documentId);
        $dbForExternal->deleteCachedDocument($collectionId, $documentId);

        $usage
            ->setParam('database.documents.delete', 1)
            ->setParam('collectionId', $collectionId)
            ;

        $events
            ->setParam('eventData', $response->output($document, Response::MODEL_DOCUMENT))
        ;

        $audits
            ->setParam('event', 'database.documents.delete')
            ->setParam('resource', 'document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy()) // Audit document in case of malicious or disastrous action
        ;

        $response->noContent();
    });
