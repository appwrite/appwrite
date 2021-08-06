<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Integer;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\QueryValidator;
use Utopia\Database\Validator\Queries as QueriesValidator;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Structure as StructureException;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

$attributesCallback = function ($attribute, $response, $dbForExternal, $database, $audits) {
    /** @var Utopia\Database\Document $document*/
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\Database\Database $dbForExternal*/
    /** @var Appwrite\Event\Event $database */
    /** @var Appwrite\Event\Event $audits */

    $collectionId = $attribute->getCollection();
    $attributeId = $attribute->getId();
    $type = $attribute->getAttribute('type', '');
    $size = $attribute->getAttribute('size', 0);
    $required = $attribute->getAttribute('required', true);
    $default = $attribute->getAttribute('default', null);
    $min = $attribute->getAttribute('min', null);
    $max = $attribute->getAttribute('max', null);
    $signed = $attribute->getAttribute('signed', true); // integers are signed by default 
    $array = $attribute->getAttribute('array', false);
    $format = $attribute->getAttribute('format', null);
    $filters = $attribute->getAttribute('filters', []); // filters are hidden from the endpoint 

    $collection = $dbForExternal->getCollection($collectionId);

    if ($collection->isEmpty()) {
        throw new Exception('Collection not found', 404);
    }

    // TODO@kodumbeats how to depend on $size for Text validator length
    // Ensure attribute default is within required size
    if ($size > 0 && !\is_null($default)) {
        $validator = new Text($size);
        if (!$validator->isValid($default)) {
            throw new Exception('Length of default attribute exceeds attribute size', 400);
        }
    } 

    if (!\is_null($format)) {
        $name = \json_decode($format, true)['name'];
        if (!Structure::hasFormat($name, $type)) {
            throw new Exception("Format {$name} not available for {$type} attributes.", 400);
        }
    }

    if (!is_null($min) || !is_null($max)) { // Add range validator if either $min or $max is provided
        switch ($type) {
            case Database::VAR_INTEGER:
                $min = (is_null($min)) ? -INF : \intval($min);
                $max = (is_null($max)) ? INF : \intval($max);
                $format = 'int-range';
                break;
            case Database::VAR_FLOAT:
                $min = (is_null($min)) ? -INF : \floatval($min);
                $max = (is_null($max)) ? INF : \floatval($max);
                $format = 'float-range';
                break;
            default:
                throw new Exception("Format range not available for {$type} attributes.", 400);
        }
    }

    $success = $dbForExternal->addAttributeInQueue($collectionId, $attributeId, $type, $size, $required, $default, $signed, $array, $format, $filters);

    // Database->addAttributeInQueue() does not return a document
    // So we need to create one for the response
    //
    // TODO@kodumbeats should $signed and $filters be part of the response model?
    $attribute = new Document([
        '$collection' => $collectionId,
        '$id' => $attributeId,
        'type' => $type,
        'size' => $size,
        'required' => $required,
        'default' => $default,
        'min' => $min,
        'max' => $max,
        'signed' => $signed,
        'array' => $array,
        'format' => $format,
        'filters' => $filters,
    ]);

    $database
        ->setParam('type', CREATE_TYPE_ATTRIBUTE)
        ->setParam('document', $attribute)
    ;

    $audits
        ->setParam('event', 'database.attributes.create')
        ->setParam('resource', 'database/attributes/'.$attribute->getId())
        ->setParam('data', $attribute)
    ;

    $response->setStatusCode(Response::STATUS_CODE_CREATED);
    $response->dynamic($attribute, Response::MODEL_ATTRIBUTE);
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
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
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

        $dbForExternal->updateDocument(Database::COLLECTIONS, $id, $collection);

        $audits
            ->setParam('event', 'database.collections.create')
            ->setParam('resource', 'database/collection/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

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
    ->param('after', '', new UID(), 'ID of the collection used to return collection listed after. Should be used for efficient pagination working with many collections.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($search, $limit, $offset, $after, $orderType, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $queries = ($search) ? [new Query('name', Query::TYPE_SEARCH, [$search])] : [];

        if (!empty($after)) {
            $afterCollection = $dbForExternal->getDocument('collections', $after);

            if ($afterCollection->isEmpty()) {
                throw new Exception('Collection for after not found', 400);
            }
        }

        $response->dynamic(new Document([
            'collections' => $dbForExternal->find(Database::COLLECTIONS, $queries, $limit, $offset, [], [$orderType], $afterCollection ?? null),
            'sum' => $dbForExternal->count(Database::COLLECTIONS, $queries, APP_LIMIT_COUNT),
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
    ->inject('dbForExternal')
    ->action(function ($collectionId, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $response->dynamic($collection, Response::MODEL_COLLECTION);
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
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('audits')
    ->action(function ($collectionId, $name, $read, $write, $response, $dbForExternal, $audits) {
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
            $collection = $dbForExternal->updateDocument(Database::COLLECTIONS, $collection->getId(), new Document(\array_merge($collection->getArrayCopy(), [
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

        $events
            ->setParam('eventData', $response->output($collection, Response::MODEL_COLLECTION))
        ;

        $audits
            ->setParam('event', 'database.collections.delete')
            ->setParam('resource', 'database/collections/'.$collection->getId())
            ->setParam('data', $collection->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/attributes/string')
    ->desc('Create String Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createStringAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-string.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('size', null, new Integer(), 'Attribute size for text attributes, in number of characters.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $size, $required, $default, $array, $response, $dbForExternal, $database, $audits) use ($attributesCallback) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        return $attributesCallback(new Document([
            '$collection' => $collectionId,
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForExternal, $database, $audits);
    });

App::post('/v1/database/collections/:collectionId/attributes/email')
    ->desc('Create Email Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createEmailAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $required, $default, $array, $response, $dbForExternal, $database, $audits) use ($attributesCallback) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        return $attributesCallback(new Document([
            '$collection' => $collectionId,
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => 254,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => \json_encode(['name'=>'email']),
        ]), $response, $dbForExternal, $database, $audits);
    });

App::post('/v1/database/collections/:collectionId/attributes/ip')
    ->desc('Create IP Address Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIpAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-ip.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $required, $default, $array, $response, $dbForExternal, $database, $audits) use ($attributesCallback) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        return $attributesCallback(new Document([
            '$collection' => $collectionId,
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => 39,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => \json_encode(['name'=>'ip']),
        ]), $response, $dbForExternal, $database, $audits);
    });

App::post('/v1/database/collections/:collectionId/attributes/url')
    ->desc('Create IP Address Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createUrlAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-url.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('size', null, new Integer(), 'Attribute size for text attributes, in number of characters.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $size, $required, $default, $array, $response, $dbForExternal, $database, $audits) use ($attributesCallback) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        return $attributesCallback(new Document([
            '$collection' => $collectionId,
            '$id' => $attributeId,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => \json_encode(['name'=>'url']),
        ]), $response, $dbForExternal, $database, $audits);
    });

App::post('/v1/database/collections/:collectionId/attributes/integer')
    ->desc('Create Integer Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIntegerAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-integer.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Integer(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $required, $min, $max, $default, $array, $response, $dbForExternal, $database, $audits) use ($attributesCallback) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        return $attributesCallback(new Document([
            '$collection' => $collectionId,
            '$id' => $attributeId,
            'type' => Database::VAR_INTEGER,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => \json_encode([
                'name'=>'int-range',
                'min' => $min,
                'max' => $max,
            ]),
        ]), $response, $dbForExternal, $database, $audits);
    });

App::post('/v1/database/collections/:collectionId/attributes/float')
    ->desc('Create Float Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createFloatAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-float.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new FloatValidator(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $required, $min, $max, $default, $array, $response, $dbForExternal, $database, $audits) use ($attributesCallback) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        return $attributesCallback(new Document([
            '$collection' => $collectionId,
            '$id' => $attributeId,
            'type' => Database::VAR_FLOAT,
            'required' => $required,
            'size' => 0,
            'default' => $default,
            'array' => $array,
            'format' => \json_encode([
                'name'=>'float-range',
                'min' => $min,
                'max' => $max,
            ]),
        ]), $response, $dbForExternal, $database, $audits);
    });

App::post('/v1/database/collections/:collectionId/attributes/boolean')
    ->desc('Create Boolean Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'database.attributes.create')
    ->label('scope', 'attributes.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createBooleanAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-boolean.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Boolean(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $required, $default, $array, $response, $dbForExternal, $database, $audits) use ($attributesCallback) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal*/
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        return $attributesCallback(new Document([
            '$collection' => $collectionId,
            '$id' => $attributeId,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForExternal, $database, $audits);
    });

App::get('/v1/database/collections/:collectionId/attributes')
    ->desc('List Attributes')
    ->groups(['api', 'database'])
    ->label('scope', 'attributes.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listAttributes')
    ->label('sdk.description', '/docs/references/database/list-attributes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
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
            return new Document([\array_merge($attribute, [
                'collectionId' => $collection->getId(),
            ])]);
        }, $attributes);

        $response->dynamic(new Document([
            'sum' => \count($attributes),
            'attributes' => $attributes
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('/v1/database/collections/:collectionId/attributes/:attributeId')
    ->desc('Get Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'attributes.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getAttribute')
    ->label('sdk.description', '/docs/references/database/get-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('attributeId', '', new Key(), 'Attribute ID.')
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

        $attribute = new Document([\array_merge($attributes[$attributeIndex], [
            'collectionId' => $collectionId,
        ])]);
        
        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE);
    });

App::delete('/v1/database/collections/:collectionId/attributes/:attributeId')
    ->desc('Delete Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'attributes.write')
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
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('events')
    ->inject('audits')
    ->action(function ($collectionId, $attributeId, $response, $dbForExternal, $database, $events, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $database */
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

        $attribute = new Document([\array_merge($attributes[$attributeIndex], [
            'collectionId' => $collectionId,
        ])]);

        $type = $attribute->getAttribute('type', '');
        $format = $attribute->getAttribute('format', '');

        $database
            ->setParam('type', DELETE_TYPE_ATTRIBUTE)
            ->setParam('document', $attribute)
        ;

        $events
            ->setParam('payload', $response->output($attribute, Response::MODEL_ATTRIBUTE))
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createIndex')
    ->label('sdk.description', '/docs/references/database/create-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('id', null, new Key(), 'Index ID.')
    ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE, Database::INDEX_SPATIAL, Database::INDEX_ARRAY]), 'Index type.')
    ->param('attributes', null, new ArrayList(new Key()), 'Array of attributes to index.')
    ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING)), 'Array of index orders.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('audits')
    ->action(function ($collectionId, $id, $type, $attributes, $orders, $response, $dbForExternal, $database, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $database */
        /** @var Appwrite\Event\Event $audits */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        // Convert Document[] to array of attribute metadata
        $oldAttributes = \array_map(function ($a) {
            return $a->getArrayCopy();
        }, $collection->getAttribute('attributes'));

        // lengths hidden by default
        $lengths = [];

        // set attribute size as length for strings, null otherwise
        foreach ($attributes as $key => $attribute) {
            // find attribute metadata in collection document
            $attributeIndex = \array_search($attribute, array_column($oldAttributes, '$id'));

            if ($attributeIndex === false) {
                throw new Exception('Unknown attribute: ' . $attribute, 400);
            }

            $attributeType = $oldAttributes[$attributeIndex]['type'];
            $attributeSize = $oldAttributes[$attributeIndex]['size'];

            // Only set length for indexes on strings
            $lengths[$key] = ($attributeType === Database::VAR_STRING) ? $attributeSize : null;
        }

        $success = $dbForExternal->addIndexInQueue($collectionId, $id, $type, $attributes, $lengths, $orders);

        // Database->createIndex() does not return a document
        // So we need to create one for the response
        // 
        // TODO@kodumbeats should $lengths be a part of the response model?
        $index = new Document([
            '$collection' => $collectionId,
            '$id' => $id,
            'type' => $type,
            'attributes' => $attributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $database
            ->setParam('type', CREATE_TYPE_INDEX)
            ->setParam('document', $index)
        ;

        $audits
            ->setParam('event', 'database.indexes.create')
            ->setParam('resource', 'database/indexes/'.$index->getId())
            ->setParam('data', $index->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($index, Response::MODEL_INDEX);
       
    });

App::get('/v1/database/collections/:collectionId/indexes')
    ->desc('List Indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'indexes.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listIndexes')
    ->label('sdk.description', '/docs/references/database/list-indexes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX_LIST)
    ->param('collectionId', '', new UID(), 'Collection unique ID. You can create a new collection using the Database service [server integration](/docs/server/database#createCollection).')
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
            return new Document([\array_merge($index, [
                'collectionId' => $collection->getId(),
            ])]);
        }, $indexes);

        $response->dynamic(new Document([
            'sum' => \count($indexes),
            'attributes' => $indexes,
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('/v1/database/collections/:collectionId/indexes/:indexId')
    ->desc('Get Index')
    ->groups(['api', 'database'])
    ->label('scope', 'indexes.read')
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

        $index = new Document([\array_merge($indexes[$indexIndex], [
            'collectionId' => $collectionId,
        ])]);
        
        $response->dynamic($index, Response::MODEL_INDEX);
    });

App::delete('/v1/database/collections/:collectionId/indexes/:indexId')
    ->desc('Delete Index')
    ->groups(['api', 'database'])
    ->label('scope', 'indexes.write')
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
    ->inject('dbForExternal')
    ->inject('database')
    ->inject('events')
    ->inject('audits')
    ->action(function ($collectionId, $indexId, $response, $dbForExternal, $database, $events, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */
        /** @var Appwrite\Event\Event $database */
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

        $index = new Document([\array_merge($indexes[$indexIndex], [
            'collectionId' => $collectionId,
        ])]);

        $database
            ->setParam('type', DELETE_TYPE_INDEX)
            ->setParam('document', $index)
        ;

        $events
            ->setParam('payload', $response->output($index, Response::MODEL_INDEX))
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
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', '/docs/references/database/create-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection unique ID. You can create a new collection with validation rules using the Database service [server integration](/docs/server/database#createCollection).')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.', true)
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

        try {
            $document = $dbForExternal->createDocument($collectionId, new Document($data));
        } catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400);
        }

        $audits
            ->setParam('event', 'database.documents.create')
            ->setParam('resource', 'database/document/'.$document->getId())
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
    ->param('after', '', new UID(), 'ID of the document used to return documents listed after. Should be used for efficient pagination working with many documents.', true)
    ->param('orderAttributes', [], new ArrayList(new Text(128)), 'Array of attributes used to sort results.', true)
    ->param('orderTypes', [], new ArrayList(new WhiteList(['DESC', 'ASC'], true)), 'Array of order directions for sorting attribtues. Possible values are DESC for descending order, or ASC for ascending order.', true)
    ->inject('response')
    ->inject('dbForExternal')
    ->action(function ($collectionId, $queries, $limit, $offset, $after, $orderAttributes, $orderTypes, $response, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForExternal */

        $collection = $dbForExternal->getCollection($collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404);
        }

        $queries = \array_map(function ($query) {
            return Query::parse($query);
        }, $queries);

        // TODO@kodumbeats find a more efficient alternative to this
        $schema = $collection->getArrayCopy()['attributes'];
        $indexes = $collection->getArrayCopy()['indexes'];
        $indexesInQueue = $collection->getArrayCopy()['indexesInQueue'];

        // TODO@kodumbeats use strict query validation
        $validator = new QueriesValidator(new QueryValidator($schema), $indexes, $indexesInQueue, false);

        if (!$validator->isValid($queries)) {
            throw new Exception($validator->getDescription(), 400);
        }

        if (!empty($after)) {
            $afterDocument = $dbForExternal->getDocument($collectionId, $after);

            if ($afterDocument->isEmpty()) {
                throw new Exception('Document for orderAfter not found', 400);
            }
        }

        $documents = $dbForExternal->find($collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes, $afterDocument ?? null);

        $response->dynamic(new Document([
            'sum' => \count($documents),
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

        $response->dynamic($document, Response::MODEL_DOCUMENT);
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
            $document = $dbForExternal->updateDocument($collection->getId(), $document->getId(), new Document($data));
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
            ->setParam('eventData', $response->output($document, Response::MODEL_DOCUMENT))
        ;

        $audits
            ->setParam('event', 'database.documents.delete')
            ->setParam('resource', 'database/document/'.$document->getId())
            ->setParam('data', $document->getArrayCopy()) // Audit document in case of malicious or disastrous action
        ;

        $response->noContent();
    });
