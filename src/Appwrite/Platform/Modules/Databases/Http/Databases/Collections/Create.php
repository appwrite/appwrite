<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Attributes as AttributesValidator;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Indexes as IndexesValidator;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Index as IndexValidator;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createCollection';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections')
            ->desc('Create collections')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'collection.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-collection.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createTable',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
            ->param('attributes', [], new AttributesValidator(), 'Array of attribute definitions to create. Each attribute should contain: key (string), type (string: string, integer, float, boolean, datetime, relationship), size (integer, required for string type), required (boolean, optional), default (mixed, optional), array (boolean, optional), and type-specific options.', true)
            ->param('indexes', [], new IndexesValidator(), 'Array of index definitions to create. Each index should contain: key (string), type (string: key, fulltext, unique, spatial), attributes (array of attribute keys), orders (array of ASC/DESC, optional), and lengths (array of integers, optional).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, array $attributes, array $indexes, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionId = $collectionId === 'unique()' ? ID::unique() : $collectionId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions) ?? [];

        try {
            $collection = $dbForProject->createDocument('database_' . $database->getSequence(), new Document([
                '$id' => $collectionId,
                'databaseInternalId' => $database->getSequence(),
                'databaseId' => $databaseId,
                '$permissions' => $permissions,
                'documentSecurity' => $documentSecurity,
                'enabled' => $enabled,
                'name' => $name,
                'search' => \implode(' ', [$collectionId, $name]),
            ]));
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException());
        } catch (NotFoundException) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionKey = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
        $databaseKey = 'database_' . $database->getSequence();

        $collectionAttributes = [];
        $attributeDocuments = [];
        try {
            foreach ($attributes as $attributeDef) {
                $attrDoc = $this->buildAttributeDocument($database, $collection, $attributeDef, $dbForProject);
                $collectionAttributes[] = $attrDoc['collection'];
                $attributeDocuments[] = $attrDoc['document'];
            }
        } catch (\Throwable $e) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw $e;
        }

        $indexLimit = $dbForProject->getLimitForIndexes();
        if (\count($indexes) > $indexLimit) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception($this->getLimitException(), "Cannot create more than $indexLimit indexes for a collection");
        }

        $collectionIndexes = [];
        $indexDocuments = [];
        try {
            foreach ($indexes as $indexDef) {
                $idxDoc = $this->buildIndexDocument($database, $collection, $indexDef, $collectionAttributes, $dbForProject);
                $collectionIndexes[] = $idxDoc['collection'];
                $indexDocuments[] = $idxDoc['document'];
            }
        } catch (\Throwable $e) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw $e;
        }

        try {
            $dbForProject->createCollection(
                id: $collectionKey,
                attributes: $collectionAttributes,
                indexes: $collectionIndexes,
                permissions: $permissions,
                documentSecurity: $documentSecurity
            );
        } catch (DuplicateException) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception($this->getDuplicateException());
        } catch (IndexException $e) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception($this->getInvalidIndexException(), $e->getMessage());
        } catch (LimitException) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception($this->getLimitException());
        } catch (\Throwable $e) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw $e;
        }

        // Create documents in attributes and indexes collections
        try {
            if (!empty($attributeDocuments)) {
                $dbForProject->createDocuments('attributes', $attributeDocuments);
            }
            if (!empty($indexDocuments)) {
                $dbForProject->createDocuments('indexes', $indexDocuments);
            }
        } catch (DuplicateException) {
            $this->cleanup($dbForProject, $databaseKey, $collectionKey, $collection->getId());
            throw new Exception($this->getDuplicateException());
        } catch (\Throwable $e) {
            $this->cleanup($dbForProject, $databaseKey, $collectionKey, $collection->getId());
            throw $e;
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getSequence(), $collection->getId());
        $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam($this->getEventsParamKey(), $collection->getId());

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($collection, $this->getResponseModel());
    }

    /**
     * Build attribute Document objects from a definition array
     *
     * @return array{collection: Document, document: Document}
     */
    protected function buildAttributeDocument(Document $database, Document $collection, array $attributeDef, Database $dbForProject): array
    {
        $key = $attributeDef['key'];
        $type = $attributeDef['type'];
        $size = $attributeDef['size'] ?? 0;
        $required = $attributeDef['required'] ?? false;
        $signed = $attributeDef['signed'] ?? true;
        $array = $attributeDef['array'] ?? false;
        $format = $attributeDef['format'] ?? '';
        $formatOptions = [];
        $filters = $attributeDef['filters'] ?? [];
        $default = $attributeDef['default'] ?? null;
        $options = [];

        if ($type === Database::VAR_STRING) {
            if ($size === 0) {
                $size = 256; // Default size for strings
            }
        }

        if ($format === APP_DATABASE_ATTRIBUTE_ENUM && isset($attributeDef['elements'])) {
            $formatOptions = ['elements' => $attributeDef['elements']];
        }

        if (isset($attributeDef['min']) || isset($attributeDef['max'])) {
            $format = $type === Database::VAR_INTEGER ? APP_DATABASE_ATTRIBUTE_INT_RANGE : APP_DATABASE_ATTRIBUTE_FLOAT_RANGE;
            $formatOptions = [
                'min' => $attributeDef['min'] ?? ($type === Database::VAR_INTEGER ? \PHP_INT_MIN : -\PHP_FLOAT_MAX),
                'max' => $attributeDef['max'] ?? ($type === Database::VAR_INTEGER ? \PHP_INT_MAX : \PHP_FLOAT_MAX),
            ];
        }

        if ($type === Database::VAR_RELATIONSHIP) {
            $options = [
                'relatedCollection' => $attributeDef['relatedCollection'] ?? '',
                'relationType' => $attributeDef['relationType'] ?? Database::RELATION_ONE_TO_ONE,
                'twoWay' => $attributeDef['twoWay'] ?? false,
                'twoWayKey' => $attributeDef['twoWayKey'] ?? '',
                'onDelete' => $attributeDef['onDelete'] ?? Database::RELATION_MUTATE_RESTRICT,
            ];
        }

        if (!empty($format)) {
            if (!Structure::hasFormat($format, $type)) {
                throw new Exception($this->getFormatUnsupportedException(), "Format $format not available for $type attributes.");
            }
        }

        if ($required && isset($default) && $default !== null) {
            throw new Exception($this->getDefaultUnsupportedException(), 'Cannot set default value for required ' . $this->getContext());
        }

        if ($array && isset($default) && $default !== null) {
            throw new Exception($this->getDefaultUnsupportedException(), 'Cannot set default value for array ' . $this->getContext() . 's');
        }

        if (\in_array($type, Database::SPATIAL_TYPES)) {
            if (!$dbForProject->getAdapter()->getSupportForSpatialIndex()) {
                throw new Exception($this->getFormatUnsupportedException(), "Spatial attributes are not supported by the current database");
            }
        }

        if ($type === Database::VAR_RELATIONSHIP) {
            $options['side'] = Database::RELATION_SIDE_PARENT;
            $relatedCollection = $dbForProject->getDocument('database_' . $database->getSequence(), $options['relatedCollection'] ?? '');
            if ($relatedCollection->isEmpty()) {
                $parent = $this->isCollectionsAPI() ? 'collection' : 'table';
                throw new Exception($this->getParentNotFoundException(), "The related $parent was not found.");
            }
        }

        $collectionDoc = new Document([
            '$id' => $key,
            'key' => $key,
            'type' => $type,
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

        $document = new Document([
            '$id' => ID::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $key),
            'key' => $key,
            'databaseInternalId' => $database->getSequence(),
            'databaseId' => $database->getId(),
            'collectionInternalId' => $collection->getSequence(),
            'collectionId' => $collection->getId(),
            'type' => $type,
            'status' => 'available',
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

        return [
            'collection' => $collectionDoc,
            'document' => $document,
        ];
    }

    /**
     * Build index Document objects from a definition array
     *
     * @return array{collection: Document, document: Document}
     */
    protected function buildIndexDocument(Document $database, Document $collection, array $indexDef, array $attributeDocuments, Database $dbForProject): array
    {
        $key = $indexDef['key'];
        $type = $indexDef['type'];
        $indexAttributes = $indexDef['attributes'];
        $orders = $indexDef['orders'] ?? [];
        $lengths = $indexDef['lengths'] ?? [];

        $attrKeys = array_map(fn ($a) => $a->getAttribute('key'), $attributeDocuments);

        $systemAttrs = ['$id', '$createdAt', '$updatedAt'];

        foreach ($indexAttributes as $i => $attr) {
            if (!in_array($attr, $attrKeys) && !in_array($attr, $systemAttrs)) {
                throw new Exception($this->getParentUnknownException(), "Unknown attribute: " . $attr . ". Verify the attribute name or ensure it's in the attributes list.");
            }

            $attrIndex = array_search($attr, $attrKeys);
            if ($attrIndex !== false) {
                $attrDoc = $attributeDocuments[$attrIndex];
                $attrType = $attrDoc->getAttribute('type');
                $attrArray = $attrDoc->getAttribute('array', false);

                if ($attrType === Database::VAR_RELATIONSHIP) {
                    throw new Exception($this->getParentInvalidTypeException(), "Cannot create an index for a relationship attribute: " . $attr);
                }

                if (empty($lengths[$i])) {
                    $lengths[$i] = null;
                }

                if ($attrArray === true) {
                    $lengths[$i] = Database::MAX_ARRAY_INDEX_LENGTH;
                    $orders[$i] = null;
                }
            } else {
                if (empty($lengths[$i])) {
                    $lengths[$i] = null;
                }
            }
        }

        $collectionDoc = new Document([
            '$id' => $key,
            'key' => $key,
            'type' => $type,
            'attributes' => $indexAttributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $document = new Document([
            '$id' => ID::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $key),
            'key' => $key,
            'status' => 'available',
            'databaseInternalId' => $database->getSequence(),
            'databaseId' => $database->getId(),
            'collectionInternalId' => $collection->getSequence(),
            'collectionId' => $collection->getId(),
            'type' => $type,
            'attributes' => $indexAttributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $indexValidator = new IndexValidator(
            $attributeDocuments,
            [],
            $dbForProject->getAdapter()->getMaxIndexLength(),
            $dbForProject->getAdapter()->getInternalIndexesKeys(),
            $dbForProject->getAdapter()->getSupportForIndexArray(),
            $dbForProject->getAdapter()->getSupportForSpatialIndexNull(),
            $dbForProject->getAdapter()->getSupportForSpatialIndexOrder(),
            $dbForProject->getAdapter()->getSupportForVectors(),
            $dbForProject->getAdapter()->getSupportForAttributes(),
            $dbForProject->getAdapter()->getSupportForMultipleFulltextIndexes(),
            $dbForProject->getAdapter()->getSupportForIdenticalIndexes()
        );

        if (!$indexValidator->isValid($collectionDoc)) {
            throw new Exception($this->getInvalidIndexException(), $indexValidator->getDescription());
        }

        return [
            'collection' => $collectionDoc,
            'document' => $document,
        ];
    }

    /**
     * Cleanup on failure: delete the collection document and the underlying DB collection
     */
    protected function cleanup(Database $dbForProject, string $databaseKey, string $collectionKey, string $collectionId): void
    {
        try {
            $dbForProject->deleteCollection($collectionKey);
        } catch (\Throwable) {
            // Ignore cleanup errors for collection deletion
        }

        try {
            $dbForProject->deleteDocument($databaseKey, $collectionId);
        } catch (\Throwable) {
            // Ignore cleanup errors for document deletion
        }
    }
}
