<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\UID;
use Appwrite\Database\Validator\Key;
use Appwrite\Database\Validator\Structure;
use Appwrite\Database\Validator\Collection;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Exception\Authorization as AuthorizationException;
use Appwrite\Database\Exception\Structure as StructureException;
use Appwrite\Utopia\Response;

App::post('/v1/database/collections')
    ->desc('Create Collection')
    ->groups(['api', 'database'])
    ->label('event', 'database.collections.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createCollection')
    ->label('sdk.description', '/docs/references/database/create-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('read', [], new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('rules', [], function ($projectDB) { return new ArrayList(new Collection($projectDB, [Database::SYSTEM_COLLECTION_RULES], ['$collection' => Database::SYSTEM_COLLECTION_RULES, '$permissions' => ['read' => [], 'write' => []]])); }, 'Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation.', false, ['projectDB'])
    ->inject('response')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($name, $read, $write, $rules, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $parsedRules = [];

        foreach ($rules as &$rule) {
            $parsedRules[] = \array_merge([
                '$collection' => Database::SYSTEM_COLLECTION_RULES,
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
            ], $rule);
        }

        try {
            $data = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_COLLECTIONS,
                'name' => $name,
                'dateCreated' => \time(),
                'dateUpdated' => \time(),
                'structure' => true,
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'rules' => $parsedRules,
            ]);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB', 500);
        }

        if (false === $data) {
            throw new Exception('Failed saving collection to DB', 500);
        }

        $audits
            ->setParam('event', 'database.collections.create')
            ->setParam('resource', 'database/collection/'.$data->getId())
            ->setParam('data', $data->getArrayCopy())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($data, Response::MODEL_COLLECTION)
        ;
    });

App::get('/v1/database/collections')
    ->desc('List Collections')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', '/docs/references/database/list-collections.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 40000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderType' => $orderType,
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_COLLECTIONS,
            ],
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'collections' => $results
        ]), Response::MODEL_COLLECTION_LIST);
    });

App::get('/v1/database/collections/:collectionId')
    ->desc('Get Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'getCollection')
    ->label('sdk.description', '/docs/references/database/get-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($collectionId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        
        $collection = $projectDB->getDocument($collectionId, false);

        if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::put('/v1/database/collections/:collectionId')
    ->desc('Update Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.collections.update')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'updateCollection')
    ->label('sdk.description', '/docs/references/database/update-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('rules', [], function ($projectDB) { return new ArrayList(new Collection($projectDB, [Database::SYSTEM_COLLECTION_RULES], ['$collection' => Database::SYSTEM_COLLECTION_RULES, '$permissions' => ['read' => [], 'write' => []]])); }, 'Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation.', true, ['projectDB'])
    ->inject('response')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($collectionId, $name, $read, $write, $rules, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $collection = $projectDB->getDocument($collectionId, false);

        if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $parsedRules = [];
        $read = (is_null($read)) ? ($collection->getPermissions()['read'] ?? []) : $read; // By default inherit read permissions
        $write = (is_null($write)) ? ($collection->getPermissions()['write'] ?? []) : $write; // By default inherit write permissions

        foreach ($rules as &$rule) {
            $parsedRules[] = \array_merge([
                '$collection' => Database::SYSTEM_COLLECTION_RULES,
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
            ], $rule);
        }

        try {
            $collection = $projectDB->updateDocument(\array_merge($collection->getArrayCopy(), [
                'name' => $name,
                'structure' => true,
                'dateUpdated' => \time(),
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'rules' => $parsedRules,
            ]));
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB', 500);
        }

        if (false === $collection) {
            throw new Exception('Failed saving collection to DB', 500);
        }

        $audits
            ->setParam('event', 'database.collections.update')
            ->setParam('resource', 'database/collections/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::delete('/v1/database/collections/:collectionId')
    ->desc('Delete Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'database.collections.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'deleteCollection')
    ->label('sdk.description', '/docs/references/database/delete-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->action(function ($collectionId, $response, $projectDB, $events, $audits, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */

        $collection = $projectDB->getDocument($collectionId, false);

        if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        if (!$projectDB->deleteDocument($collectionId)) {
            throw new Exception('Failed to remove collection from DB', 500);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $collection)
        ;

        $events
            ->setParam('eventData', $response->output($collection, Response::MODEL_COLLECTION))
        ;

        $audits
            ->setParam('event', 'database.collections.delete')
            ->setParam('resource', 'database/collections/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->dynamic(new Document(), Response::MODEL_NONE);
    });

App::post('/v1/database/collections/:collectionId/documents')
    ->desc('Create Document')
    ->groups(['api', 'database'])
    ->label('event', 'database.documents.create')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', '/docs/references/database/create-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('parentDocument', '', new UID(), 'Parent document unique ID. Use when you want your new document to be a child of a parent document.', true)
    ->param('parentProperty', '', new Key(), 'Parent document property name. Use when you want your new document to be a child of a parent document.', true)
    ->param('parentPropertyType', Document::SET_TYPE_ASSIGN, new WhiteList([Document::SET_TYPE_ASSIGN, Document::SET_TYPE_APPEND, Document::SET_TYPE_PREPEND], true), 'Parent document property connection type. You can set this value to **assign**, **append** or **prepend**, default value is assign. Use when you want your new document to be a child of a parent document.', true)
    ->inject('response')
    ->inject('projectDB')
    ->inject('user')
    ->inject('audits')
    ->action(function ($collectionId, $data, $read, $write, $parentDocument, $parentProperty, $parentPropertyType, $response, $projectDB, $user, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Event\Event $audits */
    
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception('Missing payload', 400);
        }

        if (isset($data['$id'])) {
            throw new Exception('$id is not allowed for creating new documents, try update instead', 400);
        }
        
        $collection = $projectDB->getDocument($collectionId, false);

        if (\is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $data['$collection'] = $collectionId; // Adding this param to make API easier for developers
        $data['$permissions'] = [
            'read' => (is_null($read) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $read ?? [], //  By default set read permissions for user
            'write' => (is_null($write) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $write ?? [], //  By default set write permissions for user
        ];

        // Read parent document + validate not 404 + validate read / write permission like patch method
        // Add payload to parent document property
        if ((!empty($parentDocument)) && (!empty($parentProperty))) {
            $parentDocument = $projectDB->getDocument($parentDocument, false);

            if (empty($parentDocument->getArrayCopy())) { // Check empty
                throw new Exception('No parent document found', 404);
            }

            /*
             * 1. Check child has valid structure,
             * 2. Check user have write permission for parent document
             * 3. Assign parent data (including child) to $data
             * 4. Validate the combined result has valid structure (inside $projectDB->createDocument method)
             */

            $new = new Document($data);

            $structure = new Structure($projectDB);

            if (!$structure->isValid($new)) {
                throw new Exception('Invalid data structure: '.$structure->getDescription(), 400);
            }

            $authorization = new Authorization($parentDocument, 'write');

            if (!$authorization->isValid($new->getPermissions())) {
                throw new Exception('Unauthorized permissions', 401);
            }

            $parentDocument
                ->setAttribute($parentProperty, $data, $parentPropertyType);

            $data = $parentDocument->getArrayCopy();
            $collection = $projectDB->getDocument($parentDocument->getCollection(), false);
        }

        /**
         * Set default collection values
         */
        foreach ($collection->getAttribute('rules') as $key => $rule) {
            $key = $rule['key'] ?? '';
            $default = $rule['default'] ?? null;

            if (!isset($data[$key])) {
                $data[$key] = $default;
            }
        }

        try {
            $data = $projectDB->createDocument($data);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB'.$exception->getMessage(), 500);
        }

        $audits
            ->setParam('event', 'database.documents.create')
            ->setParam('resource', 'database/document/'.$data['$id'])
            ->setParam('data', $data->getArrayCopy())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($data, Response::MODEL_DOCUMENT)
        ;
    });

App::get('/v1/database/collections/:collectionId/documents')
    ->desc('List Documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', '/docs/references/database/list-documents.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT_LIST)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('filters', [], new ArrayList(new Text(128)), 'Array of filter strings. Each filter is constructed from a key name, comparison operator (=, !=, >, <, <=, >=) and a value. You can also use a dot (.) separator in attribute names to filter by child document attributes. Examples: \'name=John Doe\' or \'category.$id>=5bed2d152c362\'.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of documents to return in response.  Use this value to manage pagination. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 900000000), 'Offset value. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderField', '', new Text(128), 'Document field that results will be sorted by.', true)
    ->param('orderType', 'ASC', new WhiteList(['DESC', 'ASC'], true), 'Order direction. Possible values are DESC for descending order, or ASC for ascending order.', true)
    ->param('orderCast', 'string', new WhiteList(['int', 'string', 'date', 'time', 'datetime'], true), 'Order field type casting. Possible values are int, string, date, time or datetime. The database will attempt to cast the order field to the value you pass here. The default value is a string.', true)
    ->param('search', '', new Text(256), 'Search query. Enter any free text search. The database will try to find a match against all document attributes and children. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($collectionId, $filters, $limit, $offset, $orderField, $orderType, $orderCast, $search, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $collection = $projectDB->getDocument($collectionId, false);

        if (\is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        $list = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => $orderField,
            'orderType' => $orderType,
            'orderCast' => $orderCast,
            'search' => $search,
            'filters' => \array_merge($filters, [
                '$collection='.$collectionId,
            ]),
        ]);

        // if (App::isDevelopment()) {
        //     $collection
        //         ->setAttribute('debug', $projectDB->getDebug())
        //         ->setAttribute('limit', $limit)
        //         ->setAttribute('offset', $offset)
        //         ->setAttribute('orderField', $orderField)
        //         ->setAttribute('orderType', $orderType)
        //         ->setAttribute('orderCast', $orderCast)
        //         ->setAttribute('filters', $filters)
        //     ;
        // }

        $collection
            ->setAttribute('sum', $projectDB->getSum())
            ->setAttribute('documents', $list)
        ;

        $response->dynamic($collection, Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Get Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.method', 'getDocument')
    ->label('sdk.description', '/docs/references/database/get-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($collectionId, $documentId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $document = $projectDB->getDocument($documentId, false);
        $collection = $projectDB->getDocument($collectionId, false);

        if (empty($document->getArrayCopy()) || $document->getCollection() != $collection->getId()) { // Check empty
            throw new Exception('No document found', 404);
        }

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::patch('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Update Document')
    ->groups(['api', 'database'])
    ->label('event', 'database.documents.update')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.method', 'updateDocument')
    ->label('sdk.description', '/docs/references/database/update-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($collectionId, $documentId, $data, $read, $write, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $collection = $projectDB->getDocument($collectionId, false);
        $document = $projectDB->getDocument($documentId, false);

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array
 
        if (!\is_array($data)) {
            throw new Exception('Data param should be a valid JSON object', 400);
        }

        if (\is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
            throw new Exception('No document found', 404);
        }

        $data = \array_merge($document->getArrayCopy(), $data);

        $data['$collection'] = $collection->getId(); // Make sure user don't switch collectionID
        $data['$id'] = $document->getId(); // Make sure user don't switch document unique ID
        $data['$permissions']['read'] = (is_null($read)) ? ($document->getPermissions()['read'] ?? []) : $read; // By default inherit read permissions
        $data['$permissions']['write'] = (is_null($write)) ? ($document->getPermissions()['write'] ?? []) : $write; // By default inherit write permissions

        if (empty($data)) {
            throw new Exception('Missing payload', 400);
        }

        try {
            $data = $projectDB->updateDocument($data);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed saving document to DB', 500);
        }

        $audits
            ->setParam('event', 'database.documents.update')
            ->setParam('resource', 'database/document/'.$data->getId())
            ->setParam('data', $data->getArrayCopy())
        ;

        $response->dynamic($data, Response::MODEL_DOCUMENT);
    });

App::delete('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Delete Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('event', 'database.documents.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.method', 'deleteDocument')
    ->label('sdk.description', '/docs/references/database/delete-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('events')
    ->inject('audits')
    ->action(function ($collectionId, $documentId, $response, $projectDB, $events, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */

        $collection = $projectDB->getDocument($collectionId, false);
        $document = $projectDB->getDocument($documentId, false);

        if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
            throw new Exception('No document found', 404);
        }

        if (\is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            throw new Exception('Collection not found', 404);
        }

        try {
            $projectDB->deleteDocument($documentId);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } catch (\Exception $exception) {
            throw new Exception('Failed to remove document from DB', 500);
        }

        $events
            ->setParam('eventData', $response->output($document, Response::MODEL_DOCUMENT))
        ;

        $audits
            ->setParam('event', 'database.documents.delete')
            ->setParam('resource', 'database/document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy()) // Audit document in case of malicious or disastrous action
        ;

        $response->dynamic(new Document(), Response::MODEL_NONE);
    });