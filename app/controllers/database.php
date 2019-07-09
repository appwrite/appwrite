<?php

global $utopia, $register, $request, $response, $webhook, $audit, $projectDB;

use Utopia\App;
use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Database\Database;
use Database\Document;
use Database\Validator\UID;
use Database\Validator\Key;
use Database\Validator\Structure;
use Database\Validator\Collection;
use Database\Validator\Authorization;
use Database\Exception\Authorization as AuthorizationException;
use Database\Exception\Structure as StructureException;

$output = array();

$isDev = (App::ENV_TYPE_PRODUCTION !== $utopia->getEnv());

$utopia
    ->shutdown(
        function() use ($request, $response, $projectDB, $register, &$output)
        {
            /*$lastModified       = $projectDB->lastModified();
            $etag               = md5(json_encode($output));
            $ifNotModelledSince = strtotime($request->getServer('HTTP_IF_MODIFIED_SINCE', 'now'));
            $ifNoneMatch        = trim($request->getServer('HTTP_IF_NONE_MATCH', ''));

            // exit if not modified
            if ($ifNotModelledSince === $lastModified || $ifNoneMatch == $etag) {
                return $response
                    ->setStatusCode(304)
                    ->send('', 100);
            }
            else {
                $response
                    ->addHeader('Cache-Control', 'private, max-age=0') // max-age=' . (60 * 60 * 24 * 30) 1 month
                    ->addHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified) . ' GMT')
                    ->addHeader('Etag', $etag)
                ;
            }
            */

            // Debug Stats
            //$cache = $register->get('cache'); /* @var $cache Debug\Redis */

            //$response
            //    ->addHeader('X-Debug-Cache-Hit', $cache::getCounter('get') + $cache::getCounter('mget'))
            //    ->addHeader('X-Debug-Cache-Miss', $cache::getCounter('set'));
        }
    );

$utopia->get('/v1/database')
    ->desc('List Collections')
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', 'Get a list of all the user collections. You can use the query params to filter your results. On admin mode, this endpoint will return a list of all of the project collections. [Learn more about different API modes](/docs/modes).')
    ->param('search', '', function () {return new Text(256);}, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () {return new Range(0, 100);}, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0 , function () {return new Range(0, 40000);}, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () {return new WhiteList(['ASC', 'DESC']);}, 'Order result by ASC or DESC order.', true)
    ->action(
        function($search, $limit, $offset, $orderType) use ($response, $projectDB)
        {
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
                    '$collection=' . Database::SYSTEM_COLLECTION_COLLECTIONS
                ],
            ]);

            $response->json(['sum' => $projectDB->getSum(), 'collections' => $results]);
        }
    );

$utopia->get('/v1/database/:collectionId')
    ->desc('Get Collection')
    ->label('scope', 'collections.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getCollection')
    ->label('sdk.description', 'Get collection by its unique ID. This endpoint response returns a JSON object with the collection metadata.')
    ->param('collectionId', '', function () {return new UID();}, 'Collection unique ID.')
    ->action(
        function($collectionId) use ($response, $projectDB) {
            $collection = $projectDB->getDocument($collectionId, false);

            if(empty($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $response->json($collection->getArrayCopy());
        }
    );

$utopia->post('/v1/database')
    ->desc('Create Collection')
    ->label('webhook', 'database.collections.create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createCollection')
    ->label('sdk.description', 'Create a new Collection.')
    ->param('name', '', function () {return new Text(256);}, 'Collection name.')
    ->param('read', [], function () {return new ArrayList(new Text(64));}, 'An array of read permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->param('write', [], function () {return new ArrayList(new Text(64));}, 'An array of write permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->param('rules', [], function () use ($projectDB) {return new ArrayList(new Collection($projectDB, [Database::SYSTEM_COLLECTION_RULES]));}, 'Array of collection structure rules. Each rule define a collection field name, data type and validation', true)
    ->action(
        function($name, $read, $write, $rules) use ($response, $projectDB, &$output, $webhook, $audit, $isDev)
        {
            try {
                $data = $projectDB->createDocument([
                    '$collection' => Database::SYSTEM_COLLECTION_COLLECTIONS,
                    'name' => $name,
                    'structure' => true,
                    '$permissions' => [
                        'read' => $read,
                        'write' => $write,
                    ],
                    'rules' => $rules,
                ]);
            }
            catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            }
            catch (StructureException $exception) {
                throw new Exception('Bad structure. ' . $exception->getMessage(), 400);
            }
            catch (\Exception $exception) {
                throw new Exception('Failed saving document to DB', 500);
            }

            $data = $data->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.collections.create')
                ->setParam('resource', 'database/collection/' . $data['$uid'])
                ->setParam('data', $data)
            ;

            /**
             * View
             */
            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($data)
            ;
        }
    );

$utopia->put('/v1/database/:collectionId')
    ->desc('Update Collection')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'updateCollection')
    ->label('sdk.description', 'Update collection by its unique ID.')
    ->param('collectionId', '', function () {return new UID();}, 'Collection unique ID.')
    ->param('name', null, function () {return new Text(256);}, 'Collection name.')
    ->param('read', [], function () {return new ArrayList(new Text(64));}, 'An array of read permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->param('write', [], function () {return new ArrayList(new Text(64));}, 'An array of write permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->param('rules', [], function () use ($projectDB) {return new ArrayList(new Collection($projectDB, [Database::SYSTEM_COLLECTION_RULES]));}, 'Array of collection structure rules. Each rule define a collection field name, data type and validation', true)
    ->action(
        function($collectionId, $name, $read, $write, $rules) use ($response, $projectDB)
        {
            $collection = $projectDB->getDocument($collectionId, false);

            if(empty($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $collection = $projectDB->updateDocument(array_merge($collection->getArrayCopy(), [
                'name' => $name,
                'structure' => true,
                '$permissions' => [
                    'read' => $read,
                    'write' => $write,
                ],
                'rules' => $rules,
            ]));

            if(false === $collection) {
                throw new Exception('Failed saving collection to DB', 500);
            }

            $response->json($collection->getArrayCopy());
        }
    );

$utopia->delete('/v1/database/:collectionId')
    ->desc('Delete Collection')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteCollection')
    ->label('sdk.description', 'Delete a collection by its unique ID. Only users with write permissions have access to delete this resource.')
    ->param('collectionId', '', function () {return new UID();}, 'Collection unique ID.')
    ->action(
        function($collectionId) use ($response, $projectDB, $audit) {
            $collection = $projectDB->getDocument($collectionId, false);

            if(empty($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            if(!$projectDB->deleteDocument($collectionId)) {
                throw new Exception('Failed to remove collection from DB', 500);
            }

            $audit
                ->setParam('event', 'database.collections.create')
                ->setParam('resource', 'database/collection/' . $collection->getUid())
                ->setParam('data', $collection->getArrayCopy()) // Audit document in case of malicious or disastrous action
            ;

            $response->noContent();
        }
    );

$utopia->get('/v1/database/:collectionId')
    ->desc('List Documents')
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', 'Get a list of all the user documents. You can use the query params to filter your results. On admin mode, this endpoint will return a list of all of the project documents. [Learn more about different API modes](/docs/modes).')
    ->param('collectionId', null, function () {return new UID();}, 'Collection unique ID.')
    ->param('filters', array(), function () {return new ArrayList(new Text(128));}, 'Array of filter strings. Each filter is constructed from a key name, comparison operator (=, !=, >, <, <=, >=) and a value. You can also use a dot (.) separator in attribute names to filter by child document attributes. Examples: \'name=John Doe\' or \'category.$uid>=5bed2d152c362\'', true)
    ->param('offset', 0, function () {return new Range(0, 900000000);}, 'Offset value. Use this value to manage pagination.', true)
    ->param('limit', 50, function () {return new Range(0, 1000);}, 'Maximum number of documents to return in response.  Use this value to manage pagination.', true)
    ->param('order-field', '$uid', function () {return new Text(128);}, 'Document field that results will be sorted by.', true)
    ->param('order-type', 'ASC', function () {return new WhiteList(array('DESC', 'ASC'));}, 'Order direction. Possible values are DESC for descending order, or ASC for ascending order.', true)
    ->param('order-cast', 'string', function () {return new WhiteList(array('int', 'string', 'date', 'time', 'datetime'));}, 'Order field type casting. Possible values are int, string, date, time or datetime. The database will attempt to cast the order field to the value you pass here. The default value is a string.', true)
    ->param('search', '', function () {return new Text(256);}, 'Search query. Enter any free text search. The database will try to find a match against all document attributes and children.', true)
    ->param('first', 0, function () {return new Range(0, 1);}, 'Return only first document. Pass 1 for true or 0 for false. The default value is 0.', true)
    ->param('last', 0, function () {return new Range(0, 1);}, 'Return only last document. Pass 1 for true or 0 for false. The default value is 0.', true)
    ->action(
        function($collectionId, $filters, $offset, $limit, $orderField, $orderType, $orderCast, $search, $first, $last) use ($response, $request, $projectDB, &$output, $isDev)
        {
            $collection = $projectDB->getDocument($collectionId, $isDev);

            if(is_null($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $list = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => $orderField,
                'orderType' => $orderType,
                'orderCast' => $orderCast,
                'search' => $search,
                'first' => (bool)$first,
                'last' => (bool)$last,
                'filters' => array_merge($filters, [
                    '$collection=' . $collectionId
                ]),
            ]);

            if($first || $last) {
                $response->json((!empty($list) ? $list->getArrayCopy() : []));
            }
            else {
                if($isDev) {
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

                /**
                 * View
                 */
                $response->json($collection->getArrayCopy(/*['$uid', '$collection', 'name', 'documents']*/[], ['rules']));
            }
        }
    );

$utopia->get('/v1/database/:collectionId/:documentId')
    ->desc('Get Document')
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getDocument')
    ->label('sdk.description', 'Get document by its unique ID. This endpoint response returns a JSON object with the document data.')
    ->param('collectionId', null, function () {return new UID();}, 'Collection unique ID')
    ->param('documentId', null, function () {return new UID();}, 'Document unique ID')
    ->action(
        function($collectionId, $documentId) use ($response, $request, $projectDB, &$output, $isDev)
        {
            $document   = $projectDB->getDocument($documentId, $isDev);
            $collection = $projectDB->getDocument($collectionId, $isDev);

            if (empty($document->getArrayCopy()) || $document->getCollection() != $collection->getUid()) { // Check empty
                throw new Exception('No document found', 404);
            }

            $output = $document->getArrayCopy();

            $paths = explode('/', $request->getParam('q', ''));
            $paths = array_slice($paths, 5, count($paths));

            if(count($paths) > 0) {
                if(count($paths) % 2 == 1) {
                    $output = $document->getAttribute(implode('.', $paths));
                }
                else {
                    $id = (int)array_pop($paths);
                    $output = $document->search('$uid', $id, $document->getAttribute(implode('.', $paths)));
                }

                $output = ($output instanceof Document) ? $output->getArrayCopy() : $output;

                if(!is_array($output)) {
                    throw new Exception('No document found', 404);
                }
            }

            /**
             * View
             */
            $response->json($output);
        }
    );

$utopia->post('/v1/database/:collectionId')
    ->desc('Create Document')
    ->label('webhook', 'database.documents.create')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', 'Create a new Document.')
    ->param('collectionId', null, function () {return new UID();}, 'Collection unique ID.')
    ->param('data', [], function () {return new \Utopia\Validator\Mock();}, 'Document data as JSON string.')
    ->param('read', [], function () {return new ArrayList(new Text(64));}, 'An array of read permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->param('write', [], function () {return new ArrayList(new Text(64));}, 'An array of write permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->param('parentDocument', '', function () {return new UID();}, 'Parent document unique ID. Use when you want your new document to be a child of a parent document.', true)
    ->param('parentProperty', '', function () {return new Key();}, 'Parent document property name. Use when you want your new document to be a child of a parent document.', true)
    ->param('parentPropertyType', Document::SET_TYPE_ASSIGN, function () {return new WhiteList([Document::SET_TYPE_ASSIGN, Document::SET_TYPE_APPEND, Document::SET_TYPE_PREPEND]);}, 'Parent document property connection type. You can set this value to **assign**, **append** or **prepend**, default value is assign. Use when you want your new document to be a child of a parent document.', true)
    ->action(
        function($collectionId, $data, $read, $write, $parentDocument, $parentProperty, $parentPropertyType) use ($response, $projectDB, &$output, $webhook, $audit, $isDev)
        {
            if(empty($data)) {
                throw new Exception('Missing payload', 400);
            }

            if(isset($data['$uid'])) {
                throw new Exception('$uid is not allowed for creating new documents, try update instead', 400);
            }

            $collection = $projectDB->getDocument($collectionId/*, $isDev*/);

            if(is_null($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            $data['$permissions'] = [
                'read' => $read,
                'write' => $write,
            ];

            // Read parent document + validate not 404 + validate read / write permission like patch method
            // Add payload to parent document property
            if((!empty($parentDocument)) && (!empty($parentProperty))) {
                $parentDocument = $projectDB->getDocument($parentDocument);

                if (empty($parentDocument->getArrayCopy())) { // Check empty
                    throw new Exception('No parent document found', 404);
                }

                /**
                 * 1. Check child has valid structure,
                 * 2. Check user have write permission for parent document
                 * 3. Assign parent data (including child) to $data
                 * 4. Validate the combined result has valid structure (inside $projectDB->createDocument method)
                 */

                $new = new Document($data);

                $structure = new Structure($projectDB);

                if(!$structure->isValid($new)) {
                    throw new Exception('Invalid data structure: ' . $structure->getDescription(), 400);
                }

                $authorization = new Authorization($parentDocument, 'write');

                if(!$authorization->isValid($new->getPermissions())) {
                    throw new Exception('Unauthorized action', 401);
                }

                $parentDocument
                    ->setAttribute($parentProperty, $data, $parentPropertyType);

                $data = $parentDocument->getArrayCopy();
            }

            try {
                $data = $projectDB->createDocument($data);
            }
            catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            }
            catch (StructureException $exception) {
                throw new Exception('Bad structure. ' . $exception->getMessage(), 400);
            }
            catch (\Exception $exception) {
                throw new Exception('Failed saving document to DB', 500);
            }

            $data = $data->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.documents.create')
                ->setParam('resource', 'database/document/' . $data['$uid'])
                ->setParam('data', $data)
            ;

            /**
             * View
             */
            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($data)
            ;
        }
    );

$utopia->patch('/v1/database/:collectionId/:documentId')
    ->desc('Update Document')
    ->label('webhook', 'database.documents.patch')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'updateDocument')
    ->param('collectionId', null, function () {return new UID();}, 'Collection unique ID')
    ->param('documentId', null, function () {return new UID();}, 'Document unique ID')
    ->param('data', [], function () {return new \Utopia\Validator\Mock();}, 'Document data as JSON string')
    ->param('read', [], function () {return new ArrayList(new Text(64));}, 'An array of read permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->param('write', [], function () {return new ArrayList(new Text(64));}, 'An array of write permissions. [Learn more about permissions and roles](/docs/permissions).', true)
    ->action(
        function($collectionId, $documentId, $data, $read, $write) use ($response, $projectDB, &$output, $webhook, $audit, $isDev)
        {
            $collection = $projectDB->getDocument($collectionId/*, $isDev*/);
            $document   = $projectDB->getDocument($documentId, $isDev);

            if(is_null($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
                throw new Exception('No document found', 404);
            }

            //TODO check merge read write permissions

            if(!empty($read)) { // Overwrite permissions only when passed
                $data['$permissions']['read'] = $read;
            }

            if(!empty($write)) { // Overwrite permissions only when passed
                $data['$permissions']['write'] = $read;
            }

            $data = array_merge($document->getArrayCopy(), $data);

            $data['$collection']    = $collection->getUid(); // Make sure user don't switch collectionID
            $data['$uid']            = $document->getUid(); // Make sure user don't switch document unique ID

            if(empty($data)) {
                throw new Exception('Missing payload', 400);
            }
            try {
                $data = $projectDB->updateDocument($data);
            }
            catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            }
            catch (StructureException $exception) {
                throw new Exception('Bad structure. ' . $exception->getMessage(), 400);
            }
            catch (\Exception $exception) {
                throw new Exception('Failed saving document to DB', 500);
            }

            $data = $data->getArrayCopy();

            $webhook
                ->setParam('payload', $data)
            ;

            $audit
                ->setParam('event', 'database.documents.patch')
                ->setParam('resource', 'database/document/' . $data['$uid'])
                ->setParam('data', $data)
            ;

            /**
             * View
             */
            $response->json($data);
        }
    );

$utopia->delete('/v1/database/:collectionId/:documentId')
    ->desc('Delete Document')
    ->label('scope', 'documents.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteDocument')
    ->label('sdk.description', 'Delete document by its unique ID. This endpoint deletes only the parent documents, his attributes and relations to other documents. Child documents **will not** be deleted.')
    ->param('collectionId', null, function () {return new UID();}, 'Collection unique ID')
    ->param('documentId', null, function () {return new UID();}, 'Document unique ID')
    ->action(
        function($collectionId, $documentId) use ($response, $projectDB, $audit, $isDev) {

            $collection = $projectDB->getDocument($collectionId, $isDev);
            $document   = $projectDB->getDocument($documentId, $isDev);

            if (empty($document->getArrayCopy()) || $document->getCollection() != $collectionId) { // Check empty
                throw new Exception('No document found', 404);
            }

            if(is_null($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
                throw new Exception('Collection not found', 404);
            }

            try {
                $projectDB->deleteDocument($documentId);
            }
            catch (AuthorizationException $exception) {
                throw new Exception('Unauthorized action', 401);
            }
            catch (StructureException $exception) {
                throw new Exception('Bad structure. ' . $exception->getMessage(), 400);
            }
            catch (\Exception $exception) {
                throw new Exception('Failed to remove document from DB', 500);
            }

            $audit
                ->setParam('event', 'database.documents.delete')
                ->setParam('resource', 'database/document/' . $documentId)
                ->setParam('data', $document->getArrayCopy()) // Audit document in case of malicious or disastrous action
            ;

            $response->noContent();
        }
    );