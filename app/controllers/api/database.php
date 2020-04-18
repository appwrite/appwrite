<?php

global $utopia, $register, $request, $response, $webhook, $audit, $projectDB;

use Utopia\App;
use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\UID;
use Appwrite\Database\Validator\Key;
use Appwrite\Database\Validator\Structure;
use Appwrite\Database\Validator\Collection;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Exception\Authorization as AuthorizationException;
use Appwrite\Database\Exception\Structure as StructureException;

include_once __DIR__ . '/../shared/api.php';

$isDev = (App::ENV_TYPE_PRODUCTION !== $utopia->getEnv());

$utopia->post('/v1/database/collections')
    ->desc('Create Collection')
    ->label('webhook', 'database.collections.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'createCollection')
    ->label('sdk.description', '/docs/references/database/create-collection.md')
    ->param('name', '', function () { return new Text(256); }, 'Collection name.')
    ->param('read', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('rules', [], function () use ($projectDB) { return new ArrayList(new Collection($projectDB, [Database::SYSTEM_COLLECTION_RULES], ['$collection' => Database::SYSTEM_COLLECTION_RULES, '$permissions' => ['read' => [], 'write' => []]])); }, 'Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation.')
    ->action(
        function ($name, $read, $write, $rules) use ($response, $projectDB, $webhook, $audit) {
            $parsedRules = [];

            foreach ($rules as &$rule) {
                $parsedRules[] = array_merge([
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
                    'dateCreated' => time(),
                    'dateUpdated' => time(),
                    'structure' => true,
                    '$permissions' => [
                        'read' => $read,
                        'write' => $write,
                    ],
                    'rules' => $parsedRules,
                ]);
            } catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            } catch (StructureException $exception) {
                throw new Exception('Bad structure. '.$exception->getMessage(), 400);
            } catch (\Exception $exception) {
                throw new Exception('Failed saving document to DB', 500);
            }

            $data = $data->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.collections.create')
                ->setParam('resource', 'database/collection/'.$data['$id'])
                ->setParam('data', $data)
            ;

            /*
             * View
             */
            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($data)
            ;
        }
    );

$utopia->get('/v1/database/collections')
    ->desc('List Collections')
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', '/docs/references/database/list-collections.md')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 40000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(
        function ($search, $limit, $offset, $orderType) use ($response, $projectDB) {
            /*$vl = new Structure($projectDB);

            var_dump($vl->isValid(new Document([
                '$collection' => Database::SYSTEM_COLLECTION_RULES,
                '$permissions' => [
                    'read' => ['*'],
                    'write' => ['*'],
                ],
                'label' => 'Platforms',
                'key' => 'platforms',
                'type' => 'document',
                'default' => [],
                'required' => false,
                'array' => true,
                'options' => [Database::SYSTEM_COLLECTION_PLATFORMS],
            ])));

            var_dump($vl->getDescription());*/

            $results = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'name',
                'orderType' => $orderType,
                'orderCast' => 'string',
                'search' => $search,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_COLLECTIONS,
                ],
            ]);

            $response->json(['sum' => $projectDB->getSum(), 'collections' => $results]);
        }
    );

$utopia->get('/v1/database/collections/:collectionId')
    ->desc('Get Collection')
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'getCollection')
    ->label('sdk.description', '/docs/references/database/get-collection.md')
    ->param('collectionId', '', function () { return new UID(); }, 'Collection unique ID.')
    ->action(
        function ($collectionId) use ($response, $projectDB) {
            $collection = $projectDB->getDocument($collectionId, false);

            if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $response->json($collection->getArrayCopy());
        }
    );

$utopia->put('/v1/database/collections/:collectionId')
    ->desc('Update Collection')
    ->label('scope', 'collections.write')
    ->label('webhook', 'database.collections.update')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'updateCollection')
    ->label('sdk.description', '/docs/references/database/update-collection.md')
    ->param('collectionId', '', function () { return new UID(); }, 'Collection unique ID.')
    ->param('name', null, function () { return new Text(256); }, 'Collection name.')
    ->param('read', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions(/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('rules', [], function () use ($projectDB) { return new ArrayList(new Collection($projectDB, [Database::SYSTEM_COLLECTION_RULES], ['$collection' => Database::SYSTEM_COLLECTION_RULES, '$permissions' => ['read' => [], 'write' => []]])); }, 'Array of [rule objects](/docs/rules). Each rule define a collection field name, data type and validation.', true)
    ->action(
        function ($collectionId, $name, $read, $write, $rules) use ($response, $projectDB, $webhook, $audit) {
            $collection = $projectDB->getDocument($collectionId, false);

            if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $parsedRules = [];

            foreach ($rules as &$rule) {
                $parsedRules[] = array_merge([
                    '$collection' => Database::SYSTEM_COLLECTION_RULES,
                    '$permissions' => [
                        'read' => $read,
                        'write' => $write,
                    ],
                ], $rule);
            }

            $collection = $projectDB->updateDocument(array_merge($collection->getArrayCopy(), [
                'name' => $name,
                'structure' => true,
                'dateUpdated' => time(),
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'rules' => $rules,
            ]));

            if (false === $collection) {
                throw new Exception('Failed saving collection to DB', 500);
            }

            $data = $collection->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.collections.update')
                ->setParam('resource', 'database/collections/'.$data['$id'])
                ->setParam('data', $data)
            ;

            $response->json($collection->getArrayCopy());
        }
    );

$utopia->delete('/v1/database/collections/:collectionId')
    ->desc('Delete Collection')
    ->label('scope', 'collections.write')
    ->label('webhook', 'database.collections.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'deleteCollection')
    ->label('sdk.description', '/docs/references/database/delete-collection.md')
    ->param('collectionId', '', function () { return new UID(); }, 'Collection unique ID.')
    ->action(
        function ($collectionId) use ($response, $projectDB, $webhook, $audit) {
            $collection = $projectDB->getDocument($collectionId, false);

            if (empty($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            if (!$projectDB->deleteDocument($collectionId)) {
                throw new Exception('Failed to remove collection from DB', 500);
            }
            
            $data = $collection->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.collections.delete')
                ->setParam('resource', 'database/collections/'.$data['$id'])
                ->setParam('data', $data)
            ;

            $response->noContent();
        }
    );

$utopia->post('/v1/database/collections/:collectionId/documents')
    ->desc('Create Document')
    ->label('webhook', 'database.documents.create')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', '/docs/references/database/create-document.md')
    ->param('collectionId', null, function () { return new UID(); }, 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/database?platform=server#createCollection).')
    ->param('data', [], function () { return new \Utopia\Validator\Mock(); }, 'Document data as JSON object.')
    ->param('read', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('parentDocument', '', function () { return new UID(); }, 'Parent document unique ID. Use when you want your new document to be a child of a parent document.', true)
    ->param('parentProperty', '', function () { return new Key(); }, 'Parent document property name. Use when you want your new document to be a child of a parent document.', true)
    ->param('parentPropertyType', Document::SET_TYPE_ASSIGN, function () { return new WhiteList([Document::SET_TYPE_ASSIGN, Document::SET_TYPE_APPEND, Document::SET_TYPE_PREPEND]); }, 'Parent document property connection type. You can set this value to **assign**, **append** or **prepend**, default value is assign. Use when you want your new document to be a child of a parent document.', true)
    ->action(
        function ($collectionId, $data, $read, $write, $parentDocument, $parentProperty, $parentPropertyType) use ($response, $projectDB, $webhook, $audit) {
            $data = (is_string($data) && $result = json_decode($data, true)) ? $result : $data; // Cast to JSON array
            
            if (empty($data)) {
                throw new Exception('Missing payload', 400);
            }

            if (isset($data['$id'])) {
                throw new Exception('$id is not allowed for creating new documents, try update instead', 400);
            }
            
            $collection = $projectDB->getDocument($collectionId/*, $isDev*/);

            if (is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $data['$collection'] = $collectionId; // Adding this param to make API easier for developers
            $data['$permissions'] = [
                'read' => $read,
                'write' => $write,
            ];

            // Read parent document + validate not 404 + validate read / write permission like patch method
            // Add payload to parent document property
            if ((!empty($parentDocument)) && (!empty($parentProperty))) {
                $parentDocument = $projectDB->getDocument($parentDocument);

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
                    throw new Exception('Unauthorized action', 401);
                }

                $parentDocument
                    ->setAttribute($parentProperty, $data, $parentPropertyType);

                $data = $parentDocument->getArrayCopy();
            }

            try {
                $data = $projectDB->createDocument($data);
            } catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            } catch (StructureException $exception) {
                throw new Exception('Bad structure. '.$exception->getMessage(), 400);
            } catch (\Exception $exception) {
                throw new Exception('Failed saving document to DB'.$exception->getMessage(), 500);
            }

            $data = $data->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.documents.create')
                ->setParam('resource', 'database/document/'.$data['$id'])
                ->setParam('data', $data)
            ;

            /*
             * View
             */
            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($data)
            ;
        }
    );

$utopia->get('/v1/database/collections/:collectionId/documents')
    ->desc('List Documents')
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', '/docs/references/database/list-documents.md')
    ->param('collectionId', null, function () { return new UID(); }, 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/database?platform=server#createCollection).')
    ->param('filters', [], function () { return new ArrayList(new Text(128)); }, 'Array of filter strings. Each filter is constructed from a key name, comparison operator (=, !=, >, <, <=, >=) and a value. You can also use a dot (.) separator in attribute names to filter by child document attributes. Examples: \'name=John Doe\' or \'category.$id>=5bed2d152c362\'.', true)
    ->param('offset', 0, function () { return new Range(0, 900000000); }, 'Offset value. Use this value to manage pagination.', true)
    ->param('limit', 50, function () { return new Range(0, 1000); }, 'Maximum number of documents to return in response.  Use this value to manage pagination.', true)
    ->param('orderField', '$id', function () { return new Text(128); }, 'Document field that results will be sorted by.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(array('DESC', 'ASC')); }, 'Order direction. Possible values are DESC for descending order, or ASC for ascending order.', true)
    ->param('orderCast', 'string', function () { return new WhiteList(array('int', 'string', 'date', 'time', 'datetime')); }, 'Order field type casting. Possible values are int, string, date, time or datetime. The database will attempt to cast the order field to the value you pass here. The default value is a string.', true)
    ->param('search', '', function () { return new Text(256); }, 'Search query. Enter any free text search. The database will try to find a match against all document attributes and children.', true)
    ->param('first', 0, function () { return new Range(0, 1); }, 'Return only the first document. Pass 1 for true or 0 for false. The default value is 0.', true)
    ->param('last', 0, function () { return new Range(0, 1); }, 'Return only the last document. Pass 1 for true or 0 for false. The default value is 0.', true)
    ->action(
        function ($collectionId, $filters, $offset, $limit, $orderField, $orderType, $orderCast, $search, $first, $last) use ($response, $projectDB, $isDev) {
            $collection = $projectDB->getDocument($collectionId, $isDev);

            if (is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $list = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => $orderField,
                'orderType' => $orderType,
                'orderCast' => $orderCast,
                'search' => $search,
                'first' => (bool) $first,
                'last' => (bool) $last,
                'filters' => array_merge($filters, [
                    '$collection='.$collectionId,
                ]),
            ]);

            if ($first || $last) {
                $response->json((!empty($list) ? $list->getArrayCopy() : []));
            } else {
                if ($isDev) {
                    $collection
                        ->setAttribute('debug', $projectDB->getDebug())
                        ->setAttribute('limit', $limit)
                        ->setAttribute('offset', $offset)
                        ->setAttribute('orderField', $orderField)
                        ->setAttribute('orderType', $orderType)
                        ->setAttribute('orderCast', $orderCast)
                        ->setAttribute('filters', $filters)
                    ;
                }

                $collection
                    ->setAttribute('sum', $projectDB->getSum())
                    ->setAttribute('documents', $list)
                ;

                /*
                 * View
                 */
                $response->json($collection->getArrayCopy(/*['$id', '$collection', 'name', 'documents']*/[], ['rules']));
            }
        }
    );

$utopia->get('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Get Document')
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'getDocument')
    ->label('sdk.description', '/docs/references/database/get-document.md')
    ->param('collectionId', null, function () { return new UID(); }, 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/database?platform=server#createCollection).')
    ->param('documentId', null, function () { return new UID(); }, 'Document unique ID.')
    ->action(
        function ($collectionId, $documentId) use ($response, $request, $projectDB, $isDev) {
            $document = $projectDB->getDocument($documentId, $isDev);
            $collection = $projectDB->getDocument($collectionId, $isDev);

            if (empty($document->getArrayCopy()) || $document->getCollection() != $collection->getId()) { // Check empty
                throw new Exception('No document found', 404);
            }

            $output = $document->getArrayCopy();

            $paths = explode('/', $request->getParam('q', ''));
            $paths = array_slice($paths, 7, count($paths));
            
            if (count($paths) > 0) {
                if (count($paths) % 2 == 1) {
                    $output = $document->getAttribute(implode('.', $paths));
                } else {
                    $id = (int) array_pop($paths);
                    $output = $document->search('$id', $id, $document->getAttribute(implode('.', $paths)));
                }

                $output = ($output instanceof Document) ? $output->getArrayCopy() : $output;

                if (!is_array($output)) {
                    throw new Exception('No document found', 404);
                }
            }

            /*
             * View
             */
            $response->json($output);
        }
    );

$utopia->patch('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Update Document')
    ->label('webhook', 'database.documents.update')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'updateDocument')
    ->label('sdk.description', '/docs/references/database/update-document.md')
    ->param('collectionId', null, function () { return new UID(); }, 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/database?platform=server#createCollection).')
    ->param('documentId', null, function () { return new UID(); }, 'Document unique ID.')
    ->param('data', [], function () { return new \Utopia\Validator\Mock(); }, 'Document data as JSON object.')
    ->param('read', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', [], function () { return new ArrayList(new Text(64)); }, 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->action(
        function ($collectionId, $documentId, $data, $read, $write) use ($response, $projectDB, &$output, $webhook, $audit, $isDev) {
            $collection = $projectDB->getDocument($collectionId/*, $isDev*/);
            $document = $projectDB->getDocument($documentId, $isDev);

            $data = (is_string($data) && $result = json_decode($data, true)) ? $result : $data; // Cast to JSON array

            if (!is_array($data)) {
                throw new Exception('Data param should be a valid JSON', 400);
            }

            if (is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
                throw new Exception('No document found', 404);
            }

            //TODO check merge read write permissions

            if (!empty($read)) { // Overwrite permissions only when passed
                $data['$permissions']['read'] = $read;
            }

            if (!empty($write)) { // Overwrite permissions only when passed
                $data['$permissions']['write'] = $read;
            }

            $data = array_merge($document->getArrayCopy(), $data);

            $data['$collection'] = $collection->getId(); // Make sure user don't switch collectionID
            $data['$id'] = $document->getId(); // Make sure user don't switch document unique ID

            if (empty($data)) {
                throw new Exception('Missing payload', 400);
            }
            try {
                $data = $projectDB->updateDocument($data);
            } catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            } catch (StructureException $exception) {
                throw new Exception('Bad structure. '.$exception->getMessage(), 400);
            } catch (\Exception $exception) {
                throw new Exception('Failed saving document to DB', 500);
            }

            $data = $data->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.documents.update')
                ->setParam('resource', 'database/document/'.$data['$id'])
                ->setParam('data', $data)
            ;

            /*
             * View
             */
            $response->json($data);
        }
    );

$utopia->delete('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Delete Document')
    ->label('scope', 'documents.write')
    ->label('webhook', 'database.documents.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.method', 'deleteDocument')
    ->label('sdk.description', '/docs/references/database/delete-document.md')
    ->param('collectionId', null, function () { return new UID(); }, 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/database?platform=server#createCollection).')
    ->param('documentId', null, function () { return new UID(); }, 'Document unique ID.')
    ->action(
        function ($collectionId, $documentId) use ($response, $projectDB, $audit, $webhook, $isDev) {
            $collection = $projectDB->getDocument($collectionId, $isDev);
            $document = $projectDB->getDocument($documentId, $isDev);

            if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
                throw new Exception('No document found', 404);
            }

            if (is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            try {
                $projectDB->deleteDocument($documentId);
            } catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            } catch (StructureException $exception) {
                throw new Exception('Bad structure. '.$exception->getMessage(), 400);
            } catch (\Exception $exception) {
                throw new Exception('Failed to remove document from DB', 500);
            }

            $data = $document->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.documents.delete')
                ->setParam('resource', 'database/document/'.$data['$id'])
                ->setParam('data', $data) // Audit document in case of malicious or disastrous action
            ;

            $response->noContent();
        }
    );
