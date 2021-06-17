<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Validator\Boolean;
use Utopia\Validator\Numeric;
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
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Structure as StructureException;
use Appwrite\Utopia\Response;
use Utopia\Database\Database as Database2;
use Utopia\Database\Document as Document2;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization as Authorization2;

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
    ->param('read', null, new ArrayList(new Text(64)), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', null, new ArrayList(new Text(64)), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('audits')
    ->action(function ($name, $read, $write, $response, $dbForExternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $audits */

        $id = $dbForExternal->getId();
        
        $collection = $dbForExternal->createCollection($id);

        // TODO@kodumbeats what should the default permissions be?
        $read = (is_null($read)) ? ($collection->getRead() ?? []) : $read; // By default inherit read permissions
        $write = (is_null($write)) ? ($collection->getWrite() ?? []) : $write; // By default inherit write permissions

        $collection->setAttribute('name', $name);
        $collection->setAttribute('$read', $read);
        $collection->setAttribute('$write', $write);

        $dbForExternal->updateDocument(Database2::COLLECTIONS, $id, $collection);

        $audits
            ->setParam('event', 'database.collections.create')
            ->setParam('resource', 'database/collection/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($collection, Response::MODEL_COLLECTION);
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
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 40000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($limit, $offset, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collections = $dbForExternal->listCollections($limit, $offset);

        $response->dynamic2(new Document2([
            'collections' => $collections,
            'sum' => \count($collections),
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
    ->inject('dbForExternal')
    ->action(function ($collectionId, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $response->dynamic2($collection, Response::MODEL_COLLECTION);
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
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('audits')
    ->action(function ($collectionId, $name, $read, $write, $rules, $response, $dbForExternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $read = (is_null($read)) ? ($collection->getRead() ?? []) : $read; // By default inherit read permissions
        $write = (is_null($write)) ? ($collection->getWrite() ?? []) : $write; // By default inherit write permissions

        try {
            $collection = $dbForExternal->updateDocument(Database2::COLLECTIONS, $collection->getId(), new Document2(\array_merge($collection->getArrayCopy(), [
                'name' => $name,
                '$read' => $read,
                '$write' => $write
            ])));
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } 

        $audits
            ->setParam('event', 'database.collections.update')
            ->setParam('resource', 'database/collections/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->dynamic2($collection, Response::MODEL_COLLECTION);
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
    ->inject('dbForExternal')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->action(function ($collectionId, $response, $dbForExternal, $events, $audits, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $dbForExternal->deleteCollection($collectionId);

        // TODO@kodumbeats use worker to handle this
        // $deletes
        //     ->setParam('type', DELETE_TYPE_DOCUMENT)
        //     ->setParam('document', $collection)
        // ;

        $events
            ->setParam('eventData', $response->output2($collection, Response::MODEL_COLLECTION))
        ;

        $audits
            ->setParam('event', 'database.collections.delete')
            ->setParam('resource', 'database/collections/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/attributes')
    ->desc('Create Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'createAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('id', '', new Text(256), 'Attribute ID.')
    ->param('type', null, new Text(256), 'Attribute type.')
    ->param('size', null, new Numeric(), 'Attribute size for text attributes, in number of characters. For integers, floats, or bools, use 0.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('signed', true, new Boolean(), 'Is attribute signed?', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->param('filters', [], new ArrayList(new Text(256)), 'Array of filters.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('audits')
    ->action(function ($collectionId, $id, $type, $size, $required, $signed, $array, $filters, $response, $dbForExternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $success = $dbForExternal->createAttribute($collectionId, $id, $type, $size, $required, $signed, $array, $filters);

        // Database->createAttribute() does not return a document
        // So we need to create one for the response
        $attribute = new Document2([
            '$collection' => $collectionId,
            '$id' => $id,
            'type' => $type,
            'size' => $size,
            'required' => $required,
            'signed' => $signed,
            'array' => $array,
            'filters' => $filters
        ]);

        $audits
            ->setParam('event', 'database.attributes.create')
            ->setParam('resource', 'database/attributes/'.$attribute->getId())
            ->setParam('data', $attribute)
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($attribute, Response::MODEL_ATTRIBUTE);
    });

App::get('v1/database/collections/:collectionId/attributes')
    ->desc('List Attributes')
    ->groups(['api', 'database'])
    ->label('scope', 'attributes.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listAttributes')
    ->label('sdk.description', '/docs/references/database/list-attributes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($collectionId, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $attributes = $collection->getAttributes();

        $attributes = array_map(function ($attribute) use ($collection) {
            return new Document2([\array_merge($attribute, [
            'collectionId' => $collection->getId(),
            ])]);
        }, $attributes);

        $response->dynamic2(new Document2([
            'sum' => \count($attributes),
            'attributes' => $attributes
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('v1/database/collections/:collectionId/attributes/:attributeId')
    ->desc('Get Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'attributes.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listAttributes')
    ->label('sdk.description', '/docs/references/database/get-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Text(256), 'Attribute ID.')
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($collectionId, $attributeId, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if (empty($collection)) {
            throw new Exception('Collection not found', 404);
        }

        $attributes = $collection->getAttributes();

        // Search for attribute
        $attributeIndex = array_search($attributeId, array_column($attributes, '$id'));

        if ($attributeIndex === false) {
            throw new Exception('Attribute not found', 404);
        }

        $attribute = new Document2([\array_merge($attributes[$attributeIndex], [
            'collectionId' => $collectionId,
        ])]);
        
        $response->dynamic2($attribute, Response::MODEL_ATTRIBUTE);
    });

App::delete('/v1/database/collections/:collectionId/attributes/:attributeId')
    ->desc('Delete Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'attributes.write')
    ->label('event', 'database.attributes.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'deleteAttribute')
    ->label('sdk.description', '/docs/references/database/delete-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Text(256), 'Attribute ID.')
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->action(function ($collectionId, $attributeId, $response, $dbForExternal, $events, $audits, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $attributes = $collection->getAttributes();

        // Search for attribute
        $attributeIndex = array_search($attributeId, array_column($attributes, '$id'));

        if ($attributeIndex === false) {
            throw new Exception('Attribute not found', 404);
        }

        $attribute = new Document2([\array_merge($attributes[$attributeIndex], [
            'collectionId' => $collectionId,
        ])]);

        // TODO@kodumbeats use the deletes worker to handle this
        $success = $dbForExternal->deleteAttribute($collectionId, $attributeId);

        // $deletes
        //     ->setParam('type', DELETE_TYPE_DOCUMENT)
        //     ->setParam('document', $attribute)
        // ;

        $events
            ->setParam('payload', $response->output2($attribute, Response::MODEL_ATTRIBUTE))
        ;

        $audits
            ->setParam('event', 'database.attributes.delete')
            ->setParam('resource', 'database/attributes/'.$attribute->getId())
            ->setParam('data', $attribute->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/indexes')
    ->desc('Create Index')
    ->groups(['api', 'database'])
    ->label('event', 'database.indexes.create')
    ->label('scope', 'indexes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'createIndex')
    ->label('sdk.description', '/docs/references/database/create-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('id', null, new Text(256), 'Index ID.')
    ->param('type', null, new WhiteList([Database2::INDEX_KEY, Database2::INDEX_FULLTEXT, Database2::INDEX_UNIQUE, Database2::INDEX_SPATIAL, Database2::INDEX_ARRAY]), 'Index type.')
    ->param('attributes', null, new ArrayList(new Text(256)), 'Array of attributes to index.')
    ->param('lengths', [], new ArrayList(new Text(256)), 'Array of index lengths.', true)
    ->param('orders', [], new ArrayList(new Text(256)), 'Array of index orders.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('audits')
    ->action(function ($collectionId, $id, $type, $attributes, $lengths, $orders, $response, $dbForExternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $success = $dbForExternal->createIndex($collectionId, $id, $type, $attributes, $lengths, $orders);

        // Database->createIndex() does not return a document
        // So we need to create one for the response
        $index = new Document2([
            '$collection' => $collectionId,
            '$id' => $id,
            'type' => $type,
            'attributes' => $attributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $audits
            ->setParam('event', 'database.indexes.create')
            ->setParam('resource', 'database/indexes/'.$index->getId())
            ->setParam('data', $index->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($index, Response::MODEL_INDEX);
       
    });

App::get('v1/database/collections/:collectionId/indexes')
    ->desc('List Indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'indexes.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listIndexes')
    ->label('sdk.description', '/docs/references/database/list-indexes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($collectionId, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $indexes = $collection->getAttribute('indexes');

        $indexes = array_map(function ($index) use ($collection) {
            return new Document2([\array_merge($index, [
            'collectionId' => $collection->getId(),
            ])]);
        }, $indexes);

        $response->dynamic2(new Document2([
            'sum' => \count($indexes),
            'attributes' => $indexes,
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('v1/database/collections/:collectionId/indexes/:indexId')
    ->desc('Get Index')
    ->groups(['api', 'database'])
    ->label('scope', 'indexes.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'listIndexes')
    ->label('sdk.description', '/docs/references/database/get-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('indexId', null, new Text(256), 'Index ID.')
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($collectionId, $indexId, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $indexes = $collection->getAttribute('indexes');

        // // Search for index
        $indexIndex = array_search($indexId, array_column($indexes, '$id'));

        if ($indexIndex === false) {
            throw new Exception('Index not found', 404);
        }

        $index = new Document2([\array_merge($indexes[$indexIndex], [
            'collectionId' => $collectionId,
        ])]);
        
        $response->dynamic2($index, Response::MODEL_INDEX);
    });

App::delete('/v1/database/collections/:collectionId/indexes/:indexId')
    ->desc('Delete Index')
    ->groups(['api', 'database'])
    ->label('scope', 'indexes.write')
    ->label('event', 'database.indexes.delete')
    ->label('sdk.namespace', 'database')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.method', 'deleteIndex')
    ->label('sdk.description', '/docs/references/database/delete-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('indexId', '', new UID(), 'Index unique ID.')
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->action(function ($collectionId, $indexId, $response, $dbForExternal, $events, $audits, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $indexes = $collection->getAttribute('indexes');

        // // Search for index
        $indexIndex = array_search($indexId, array_column($indexes, '$id'));

        if ($indexIndex === false) {
            throw new Exception('Index not found', 404);
        }

        $index = new Document2([\array_merge($indexes[$indexIndex], [
            'collectionId' => $collectionId,
        ])]);

        // TODO@kodumbeats use the deletes worker to handle this
        $success = $dbForExternal->deleteIndex($collectionId, $indexId);

        // $deletes
        //     ->setParam('type', DELETE_TYPE_DOCUMENT)
        //     ->setParam('document', $attribute)
        // ;

        $events
            ->setParam('payload', $response->output2($index, Response::MODEL_INDEX))
        ;

        $audits
            ->setParam('event', 'database.indexes.delete')
            ->setParam('resource', 'database/indexes/'.$index->getId())
            ->setParam('data', $index->getArrayCopy())
        ;

        $response->noContent();
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
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('user')
    ->inject('audits')
    ->action(function ($collectionId, $data, $read, $write, $response, $dbForExternal, $user, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Utopia\Database\Document $user */
        /** @var Appwrite\Event\Event $audits */
    
        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception('Missing payload', 400);
        }

        if (isset($data['$id'])) {
            throw new Exception('$id is not allowed for creating new documents, try update instead', 400);
        }
        
        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $data['$collection'] = $collection->getId(); // Adding this param to make API easier for developers
        $data['$id'] = $dbForExternal->getId();
        $data['$read'] = (is_null($read) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $read ?? []; //  By default set read permissions for user
        $data['$write'] = (is_null($write) && !$user->isEmpty()) ? ['user:'.$user->getId()] : $write ?? []; //  By default set write permissions for user

        /**
         * TODO@kodumbeats How to assign default values to attributes
         */
        // foreach ($collection->getAttributes() as $key => $attribute) {
        //     $key = $attribute['$id'] ?? '';
        //     if ($attribute['array'] === true) {
        //         $default = [];
        //     } elseif ($attribute['type'] === Database2::VAR_STRING) {
        //         $default = '';
        //     } elseif ($attribute['type'] === Database2::VAR_BOOLEAN) {
        //         $default = false;
        //     } elseif ($attribute['type'] === Database2::VAR_INTEGER 
        //         || $attribute['type'] === Database2::VAR_FLOAT) {
        //         $default = 0;
        //     }

        //     if (!isset($data[$key])) {
        //         $data[$key] = $default;
        //     }
        // }

        // TODO@kodumbeats catch other exceptions
        try {
            $document = $dbForExternal->createDocument($collectionId, new Document2($data));
        } catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400);
        }

        $audits
            ->setParam('event', 'database.documents.create')
            ->setParam('resource', 'database/document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/database/collections/:collectionId/documents')
    ->desc('List Documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT]) ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', '/docs/references/database/list-documents.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('queries', [], new ArrayList(new Text(128)), 'Array of query strings.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of documents to return in response.  Use this value to manage pagination. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 900000000), 'Offset value. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderAttributes', [], new ArrayList(new Text(128)), 'Array of attributes used to sort results.', true)
    ->param('orderTypes', [], new ArrayList(new WhiteList(['DESC', 'ASC'], true)), 'Array of order directions for sorting attribtues. Possible values are DESC for descending order, or ASC for ascending order.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $queries = \array_map(function ($query) {
            return Query::parse($query);
        }, $queries);

        $documents = $dbForExternal->find($collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes);

        $response->dynamic2(new Document2([
            'sum' => \count($documents),
            'documents' => $documents,
        ]), Response::MODEL_DOCUMENT_LIST);
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
    ->inject('dbForExternal')
    ->action(function ($collectionId, $documentId, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $document = $dbForExternal->getDocument($collectionId, $documentId);

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404);
        }

        $response->dynamic2($document, Response::MODEL_DOCUMENT);
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
    ->inject('dbForExternal')
    ->inject('audits')
    ->action(function ($collectionId, $documentId, $data, $read, $write, $response, $dbForExternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
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
            $document = $dbForExternal->updateDocument($collection->getId(), $document->getId(), new Document2($data));
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. '.$exception->getMessage(), 400);
        } 

        $audits
            ->setParam('event', 'database.documents.update')
            ->setParam('resource', 'database/document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy())
        ;

        $response->dynamic2($document, Response::MODEL_DOCUMENT);
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
    ->inject('dbForExternal')
    ->inject('events')
    ->inject('audits')
    ->action(function ($collectionId, $documentId, $response, $dbForExternal, $events, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $document = $dbForExternal->getDocument($collectionId, $documentId);

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404);
        }

        $success = $dbForExternal->deleteDocument($collectionId, $documentId);

        $events
            ->setParam('eventData', $response->output2($document, Response::MODEL_DOCUMENT))
        ;

        $audits
            ->setParam('event', 'database.documents.delete')
            ->setParam('resource', 'database/document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy()) // Audit document in case of malicious or disastrous action
        ;

        $response->noContent();
    });
