<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response as UtopiaResponse;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Structure;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Range;

abstract class Action extends UtopiaAction
{
    /**
     * @var string|null The current context (either 'column' or 'attribute')
     */
    private ?string $context = ATTRIBUTES;

    /**
     * Get the correct response model.
     */
    abstract protected function getResponseModel(): string|array;

    public function setHttpPath(string $path): UtopiaAction
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = COLUMNS;
        }
        return parent::setHttpPath($path);
    }

    /**
     * Get the current context.
     */
    protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Returns true if current context is Collections API.
     */
    protected function isCollectionsAPI(): bool
    {
        // columns in tables context
        // attributes in collections context
        return $this->getContext() === ATTRIBUTES;
    }

    /**
     * Get the SDK group name for the current action.
     *
     * Can be used for XList operations as well!
     */
    protected function getSDKGroup(): string
    {
        return $this->isCollectionsAPI() ? 'attributes' : 'columns';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    protected function getSDKNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'databases' : 'tablesDB';
    }

    /**
     * Get the appropriate parent level not found exception.
     */
    protected function getParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_FOUND
            : Exception::COLUMN_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    protected function getIndexDependencyException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_DEPENDENCY
            : Exception::COLUMN_INDEX_DEPENDENCY;
    }

    /**
     * Get the appropriate already exists exception.
     */
    protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_ALREADY_EXISTS
            : Exception::COLUMN_ALREADY_EXISTS;
    }

    /**
     * Get the correct invalid structure message.
     */
    protected function getStructureException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_INVALID_STRUCTURE
            : Exception::ROW_INVALID_STRUCTURE;
    }

    /**
     * Get the appropriate limit exceeded exception.
     */
    protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_LIMIT_EXCEEDED
            : Exception::COLUMN_LIMIT_EXCEEDED;
    }

    /**
     * Get the appropriate index invalid exception.
     */
    protected function getInvalidIndexException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_INVALID
            : Exception::COLUMN_INDEX_INVALID;
    }

    /**
     * Get the correct default unsupported message.
     */
    protected function getDefaultUnsupportedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED
            : Exception::COLUMN_DEFAULT_UNSUPPORTED;
    }

    /**
     * Get the correct format unsupported message.
     */
    protected function getFormatUnsupportedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_FORMAT_UNSUPPORTED
            : Exception::COLUMN_FORMAT_UNSUPPORTED;
    }

    /**
     * Get the exception for invalid type or format mismatch.
     */
    protected function getTypeInvalidException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_TYPE_INVALID
            : Exception::COLUMN_TYPE_INVALID;
    }

    /**
     * Get the exception for resizing invalid attributes/columns.
     */
    protected function getInvalidResizeException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_INVALID_RESIZE
            : Exception::COLUMN_INVALID_RESIZE;
    }

    /**
     * Get the exception for invalid attributes/columns value.
     */
    protected function getInvalidValueException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_VALUE_INVALID
            : Exception::COLUMN_VALUE_INVALID;
    }

    /**
     * Get the exception for non-available column/attribute.
     */
    protected function getNotAvailableException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_AVAILABLE
            : Exception::COLUMN_NOT_AVAILABLE;
    }

    /**
     * Get the exception for spatial type attribute not supported by the database adapter
    */
    protected function getSpatialTypeNotSupportedException(): string
    {
        return $this->isCollectionsAPI() ? Exception::ATTRIBUTE_TYPE_NOT_SUPPORTED : Exception::COLUMN_TYPE_NOT_SUPPORTED;
    }

    /**
     * Get the correct collections context for Events queue.
     */
    protected function getCollectionsEventsContext(): string
    {
        return $this->isCollectionsAPI() ? 'collection' : 'table';
    }

    /**
     *  Get the proper column/attribute type based on set context.
     */
    protected function getModel(string $type, string $format): string
    {
        $isCollections = $this->isCollectionsAPI();

        return match ($type) {
            Database::VAR_BOOLEAN => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_BOOLEAN
                : UtopiaResponse::MODEL_COLUMN_BOOLEAN,

            Database::VAR_INTEGER => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_INTEGER
                : UtopiaResponse::MODEL_COLUMN_INTEGER,

            Database::VAR_FLOAT => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_FLOAT
                : UtopiaResponse::MODEL_COLUMN_FLOAT,

            Database::VAR_DATETIME => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_DATETIME
                : UtopiaResponse::MODEL_COLUMN_DATETIME,

            Database::VAR_RELATIONSHIP => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_RELATIONSHIP
                : UtopiaResponse::MODEL_COLUMN_RELATIONSHIP,

            Database::VAR_POINT => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_POINT
                : UtopiaResponse::MODEL_COLUMN_POINT,

            Database::VAR_LINESTRING => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_LINE
                : UtopiaResponse::MODEL_COLUMN_LINE,

            Database::VAR_POLYGON => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE_POLYGON
                : UtopiaResponse::MODEL_COLUMN_POLYGON,

            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => $isCollections
                    ? UtopiaResponse::MODEL_ATTRIBUTE_EMAIL
                    : UtopiaResponse::MODEL_COLUMN_EMAIL,

                APP_DATABASE_ATTRIBUTE_ENUM => $isCollections
                    ? UtopiaResponse::MODEL_ATTRIBUTE_ENUM
                    : UtopiaResponse::MODEL_COLUMN_ENUM,

                APP_DATABASE_ATTRIBUTE_IP => $isCollections
                    ? UtopiaResponse::MODEL_ATTRIBUTE_IP
                    : UtopiaResponse::MODEL_COLUMN_IP,

                APP_DATABASE_ATTRIBUTE_URL => $isCollections
                    ? UtopiaResponse::MODEL_ATTRIBUTE_URL
                    : UtopiaResponse::MODEL_COLUMN_URL,

                default => $isCollections
                    ? UtopiaResponse::MODEL_ATTRIBUTE_STRING
                    : UtopiaResponse::MODEL_COLUMN_STRING,
            },
            default => $isCollections
                ? UtopiaResponse::MODEL_ATTRIBUTE
                : UtopiaResponse::MODEL_COLUMN,
        };
    }

    protected function createAttribute(string $databaseId, string $collectionId, Document $attribute, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): Document
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

        if (in_array($type, Database::SPATIAL_TYPES) && !$dbForProject->getAdapter()->getSupportForSpatialAttributes()) {
            throw new Exception($this->getSpatialTypeNotSupportedException(), params: [$type]);
        }

        $db = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        if (!empty($format)) {
            if (!Structure::hasFormat($format, $type)) {
                throw new Exception($this->getFormatUnsupportedException(), "Format $format not available for $type columns.");
            }
        }

        // Must throw here since dbForProject->createAttribute is performed by db worker
        if ($required && isset($default)) {
            throw new Exception($this->getDefaultUnsupportedException(), 'Cannot set default value for required ' . $this->getContext());
        }

        if ($array && isset($default)) {
            throw new Exception($this->getDefaultUnsupportedException(), 'Cannot set default value for array ' . $this->getContext() . 's');
        }

        if ($type === Database::VAR_RELATIONSHIP) {
            $options['side'] = Database::RELATION_SIDE_PARENT;
            $relatedCollection = $dbForProject->getDocument('database_' . $db->getSequence(), $options['relatedCollection'] ?? '');
            if ($relatedCollection->isEmpty()) {
                $parent = $this->isCollectionsAPI() ? 'collection' : 'table';
                throw new Exception($this->getParentNotFoundException(), "The related $parent was not found.");
            }
        }

        try {
            $attribute = new Document([
                '$id' => ID::custom($db->getSequence() . '_' . $collection->getSequence() . '_' . $key),
                'key' => $key,
                'databaseInternalId' => $db->getSequence(),
                'databaseId' => $db->getId(),
                'collectionInternalId' => $collection->getSequence(),
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
            if (
                !$dbForProject->getAdapter()->getSupportForSpatialIndexNull() &&
                \in_array($attribute->getAttribute('type'), Database::SPATIAL_TYPES) &&
                $attribute->getAttribute('required')
            ) {
                $hasData = !$authorization->skip(fn () => $dbForProject
                    ->findOne('database_' . $db->getSequence() . '_collection_' . $collection->getSequence()))
                    ->isEmpty();

                if ($hasData) {
                    throw new StructureException('Failed to add required spatial column: existing rows present. Make the column optional.');
                }
            }
            $dbForProject->checkAttribute($collection, $attribute);
            $attribute = $dbForProject->createDocument('attributes', $attribute);
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException(), params: [$key]);
        } catch (LimitException) {
            throw new Exception($this->getLimitException(), params: [$collectionId]);
        } catch (StructureException $e) {
            throw new Exception($this->getStructureException(), $e->getMessage());
        } catch (Throwable $e) {
            $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);
            $dbForProject->purgeCachedCollection('database_' . $db->getSequence() . '_collection_' . $collection->getSequence());
            throw $e;
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $db->getSequence() . '_collection_' . $collection->getSequence());

        if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
            $twoWayKey = $options['twoWayKey'];
            $options['relatedCollection'] = $collection->getId();
            $options['twoWayKey'] = $key;
            $options['side'] = Database::RELATION_SIDE_CHILD;

            try {
                $twoWayAttribute = new Document([
                    '$id' => ID::custom($db->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $twoWayKey),
                    'key' => $twoWayKey,
                    'databaseInternalId' => $db->getSequence(),
                    'databaseId' => $db->getId(),
                    'collectionInternalId' => $relatedCollection->getSequence(),
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
                throw new Exception($this->getDuplicateException(), params: [$twoWayKey]);
            } catch (LimitException) {
                throw new Exception($this->getLimitException(), params: [$relatedCollection->getId()]);
            } catch (StructureException) {
                throw new Exception($this->getStructureException());
            } catch (Throwable $e) {
                $dbForProject->deleteDocument('attributes', $attribute->getId());
                throw $e;
            } finally {
                $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);
                $dbForProject->purgeCachedCollection('database_' . $db->getSequence() . '_collection_' . $collection->getSequence());
            }

            // If operation succeeded, purge the cache for the related collection too
            $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $relatedCollection->getId());
            $dbForProject->purgeCachedCollection('database_' . $db->getSequence() . '_collection_' . $relatedCollection->getSequence());
        }

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_ATTRIBUTE)
            ->setDatabase($db);

        if ($this->isCollectionsAPI()) {
            $queueForDatabase
                ->setDocument($attribute)
                ->setCollection($collection);
        } else {
            $queueForDatabase
                ->setRow($attribute)
                ->setTable($collection);
        }

        $queueForEvents
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setParam('attributeId', $attribute->getId())
            ->setParam('columnId', $attribute->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection);

        $response->setStatusCode(SwooleResponse::STATUS_CODE_CREATED);

        return $attribute;
    }

    protected function updateAttribute(string $databaseId, string $collectionId, string $key, Database $dbForProject, Event $queueForEvents, Authorization $authorization, string $type, int $size = null, string $filter = null, string|bool|int|float|array $default = null, bool $required = null, int|float|null $min = null, int|float|null $max = null, array $elements = null, array $options = [], string $newKey = null): Document
    {
        $db = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        $attribute = $dbForProject->getDocument('attributes', $db->getSequence() . '_' . $collection->getSequence() . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$key]);
        }

        if ($attribute->getAttribute('status') !== 'available') {
            throw new Exception($this->getNotAvailableException());
        }

        if ($attribute->getAttribute(('type') !== $type)) {
            throw new Exception($this->getTypeInvalidException());
        }

        if ($attribute->getAttribute('type') === Database::VAR_STRING && $attribute->getAttribute(('filter') !== $filter)) {
            throw new Exception($this->getTypeInvalidException());
        }

        if ($required && isset($default)) {
            throw new Exception($this->getDefaultUnsupportedException(), 'Cannot set default value for required ' . $this->getContext());
        }

        if ($attribute->getAttribute('array', false) && isset($default)) {
            throw new Exception($this->getDefaultUnsupportedException(), 'Cannot set default value for array ' . $this->getContext() . 's');
        }

        $collectionId = 'database_' . $db->getSequence() . '_collection_' . $collection->getSequence();

        $attribute
            ->setAttribute('default', $default)
            ->setAttribute('required', $required);

        if (!empty($size)) {
            $attribute->setAttribute('size', $size);
        }

        switch ($attribute->getAttribute('format')) {
            case APP_DATABASE_ATTRIBUTE_INT_RANGE:
            case APP_DATABASE_ATTRIBUTE_FLOAT_RANGE:
                $min ??= $attribute->getAttribute('formatOptions')['min'];
                $max ??= $attribute->getAttribute('formatOptions')['max'];

                if ($min > $max) {
                    throw new Exception($this->getInvalidValueException(), 'Minimum value must be lesser than maximum value');
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
                    throw new Exception($this->getInvalidValueException(), $validator->getDescription());
                }

                $options = [
                    'min' => $min,
                    'max' => $max
                ];
                $attribute->setAttribute('formatOptions', $options);

                break;
            case APP_DATABASE_ATTRIBUTE_ENUM:
                if (empty($elements)) {
                    throw new Exception($this->getInvalidValueException(), 'Enum elements must not be empty');
                }

                foreach ($elements as $element) {
                    if (\strlen($element) === 0) {
                        throw new Exception($this->getInvalidValueException(), 'Each enum element must not be empty');
                    }
                }

                if (!is_null($default) && !in_array($default, $elements)) {
                    throw new Exception($this->getInvalidValueException(), 'Default value not found in elements');
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
            try {
                $dbForProject->updateRelationship(
                    collection: $collectionId,
                    id: $key,
                    newKey: $newKey,
                    onDelete: $primaryDocumentOptions['onDelete'],
                );
            } catch (IndexException) {
                throw new Exception(Exception::INDEX_INVALID);
            } catch (LimitException) {
                throw new Exception($this->getLimitException(), params: [$collectionId]);
            } catch (RelationshipException $e) {
                throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $e->getMessage());
            } catch (StructureException $e) {
                throw new Exception($this->getStructureException(), $e->getMessage());
            }

            if ($primaryDocumentOptions['twoWay']) {
                $relatedCollection = $dbForProject->getDocument('database_' . $db->getSequence(), $primaryDocumentOptions['relatedCollection']);

                $relatedAttribute = $dbForProject->getDocument('attributes', $db->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $primaryDocumentOptions['twoWayKey']);

                if (!empty($newKey) && $newKey !== $key) {
                    $options['twoWayKey'] = $newKey;
                }

                $relatedOptions = \array_merge($relatedAttribute->getAttribute('options'), $options);
                $relatedAttribute->setAttribute('options', $relatedOptions);
                $dbForProject->updateDocument('attributes', $db->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $primaryDocumentOptions['twoWayKey'], $relatedAttribute);

                $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $relatedCollection->getId());
            }
        } else {
            try {
                $dbForProject->updateAttribute(
                    collection: $collectionId,
                    id: $key,
                    size: $size,
                    required: $required,
                    default: $default,
                    formatOptions: $options,
                    newKey: $newKey ?? null
                );
            } catch (DuplicateException) {
                throw new Exception($this->getDuplicateException(), params: [$key]);
            } catch (IndexException $e) {
                throw new Exception($this->getInvalidIndexException(), $e->getMessage());
            } catch (LimitException) {
                throw new Exception($this->getLimitException(), params: [$collectionId]);
            } catch (TruncateException) {
                throw new Exception($this->getInvalidResizeException());
            }
        }

        if (!empty($newKey) && $key !== $newKey) {
            $originalUid = $attribute->getId();

            $attribute
                ->setAttribute('$id', ID::custom($db->getSequence() . '_' . $collection->getSequence() . '_' . $newKey))
                ->setAttribute('key', $newKey);

            try {
                $dbForProject->updateDocument('attributes', $originalUid, $attribute);
            } catch (DuplicateException) {
                throw new Exception($this->getDuplicateException(), params: [$newKey]);
            }

            /**
             * @var Document $index
             */
            foreach ($collection->getAttribute('indexes') as $index) {
                /**
                 * @var array<string> $attributes
                 */
                $attributes = $index->getAttribute('attributes', []);
                $found = \array_search($key, $attributes);

                if ($found !== false) {
                    $attributes[$found] = $newKey;
                    $index->setAttribute('attributes', $attributes);
                    $dbForProject->updateDocument('indexes', $index->getId(), $index);
                }
            }
        } else {
            $attribute = $dbForProject->updateDocument('attributes', $db->getSequence() . '_' . $collection->getSequence() . '_' . $key, $attribute);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collection->getId());

        $queueForEvents
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setParam('attributeId', $attribute->getId())
            ->setParam('columnId', $attribute->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection);

        return $attribute;
    }
}
