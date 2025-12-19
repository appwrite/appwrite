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
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON;
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
                auth: [AuthType::ADMIN, AuthType::KEY],
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
            ->param('attributes', [], new ArrayList(new JSON(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attribute definitions to create. Each attribute should contain: key (string), type (string: string, integer, float, boolean, datetime), size (integer, required for string type), required (boolean, optional), default (mixed, optional), array (boolean, optional), and type-specific options.', true)
            ->param('indexes', [], new ArrayList(new JSON(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index definitions to create. Each index should contain: key (string), type (string: key, fulltext, unique, spatial), attributes (array of attribute keys), orders (array of ASC/DESC, optional), and lengths (array of integers, optional).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $name, ?array $permissions, bool $documentSecurity, bool $enabled, array $attributes, array $indexes, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
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
            throw new Exception($this->getDuplicateException(), params: [$collectionId]);
        } catch (LimitException) {
            throw new Exception($this->getLimitException(), params: [$databaseId]);
        } catch (NotFoundException) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collectionKey = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();
        $databaseKey = 'database_' . $database->getSequence();

        $attributesValidator = new AttributesValidator(
            APP_LIMIT_ARRAY_PARAMS_SIZE,
            $dbForProject->getAdapter()->getSupportForSpatialAttributes()
        );

        if (!$attributesValidator->isValid($attributes)) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $attributesValidator->getDescription());
        }

        foreach ($attributes as $attribute) {
            if (($attribute['type'] ?? '') === Database::VAR_RELATIONSHIP) {
                $dbForProject->deleteDocument($databaseKey, $collection->getId());
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Relationship attributes cannot be created inline. Use the create relationship endpoint instead.');
            }
        }

        $collectionAttributes = [];
        $attributeDocuments = [];
        try {
            foreach ($attributes as $attributeDef) {
                $attrDoc = $this->buildAttributeDocument($database, $collection, $attributeDef);
                $collectionAttributes[] = $attrDoc['collection'];
                $attributeDocuments[] = $attrDoc['document'];
            }
        } catch (\Throwable $e) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw $e;
        }

        // Validate indexes
        $indexesValidator = new IndexesValidator($dbForProject->getLimitForIndexes());
        if (!$indexesValidator->isValid($indexes)) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $indexesValidator->getDescription());
        }

        $collectionIndexes = [];
        $indexDocuments = [];
        try {
            foreach ($indexes as $indexDef) {
                $idxDoc = $this->buildIndexDocument($database, $collection, $indexDef, $collectionAttributes);
                $collectionIndexes[] = $idxDoc['collection'];
                $indexDocuments[] = $idxDoc['document'];
            }
        } catch (\Throwable $e) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw $e;
        }

        // Validate indexes with DB adapter capabilities
        $indexValidator = new IndexValidator(
            $collectionAttributes,
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

        foreach ($collectionIndexes as $indexDoc) {
            if (!$indexValidator->isValid($indexDoc)) {
                $dbForProject->deleteDocument($databaseKey, $collection->getId());
                throw new Exception($this->getInvalidIndexException(), $indexValidator->getDescription());
            }
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
            throw new Exception($this->getDuplicateException(), params: [$collectionId]);
        } catch (IndexException $e) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception($this->getInvalidIndexException(), $e->getMessage());
        } catch (LimitException) {
            $dbForProject->deleteDocument($databaseKey, $collection->getId());
            throw new Exception($this->getLimitException(), params: [$collectionId]);
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
            throw new Exception($this->getDuplicateException(), params: [$collectionId]);
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
    protected function buildAttributeDocument(
        Document $database,
        Document $collection,
        array $attribute,
    ): array {
        $key = $attribute['key'];
        $type = $attribute['type'];
        $size = $attribute['size'] ?? 0;
        $required = $attribute['required'] ?? false;
        $signed = $attribute['signed'] ?? true;
        $array = $attribute['array'] ?? false;
        $format = $attribute['format'] ?? '';
        $formatOptions = [];
        $filters = $attribute['filters'] ?? [];
        $default = $attribute['default'] ?? null;

        if ($format === APP_DATABASE_ATTRIBUTE_ENUM && isset($attribute['elements'])) {
            $formatOptions = ['elements' => $attribute['elements']];
        }

        if (isset($attribute['min']) || isset($attribute['max'])) {
            $format = $type === Database::VAR_INTEGER
                ? APP_DATABASE_ATTRIBUTE_INT_RANGE
                : APP_DATABASE_ATTRIBUTE_FLOAT_RANGE;

            $formatOptions = [
                'min' => $attribute['min'] ?? ($type === Database::VAR_INTEGER ? \PHP_INT_MIN : -\PHP_FLOAT_MAX),
                'max' => $attribute['max'] ?? ($type === Database::VAR_INTEGER ? \PHP_INT_MAX : \PHP_FLOAT_MAX),
            ];
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
    protected function buildIndexDocument(Document $database, Document $collection, array $indexDef, array $attributeDocuments): array
    {
        $key = $indexDef['key'];
        $type = $indexDef['type'];
        $indexAttributes = $indexDef['attributes'];
        $orders = $indexDef['orders'] ?? [];
        $lengths = $indexDef['lengths'] ?? [];

        $attrKeys = array_map(fn ($a) => $a->getAttribute('key'), $attributeDocuments);

        // Build lengths and orders based on attribute properties
        foreach ($indexAttributes as $i => $attr) {
            $attrIndex = array_search($attr, $attrKeys);
            if ($attrIndex !== false) {
                $attrDoc = $attributeDocuments[$attrIndex];
                $attrArray = $attrDoc->getAttribute('array', false);

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

        return [
            'collection' => $collectionDoc,
            'document' => $document,
        ];
    }

    /**
     * Cleanup on failure: delete the collection document and the underlying DB collection
     */
    protected function cleanup(
        Database $dbForProject,
        string $databaseId,
        string $collectionId,
        string $collectionDocumentId
    ): void {
        try {
            $dbForProject->deleteCollection($collectionId);
        } catch (\Throwable) {
            // Ignore cleanup errors for collection deletion
        }

        try {
            $dbForProject->deleteDocument($databaseId, $collectionDocumentId);
        } catch (\Throwable) {
            // Ignore cleanup errors for document deletion
        }
    }
}
