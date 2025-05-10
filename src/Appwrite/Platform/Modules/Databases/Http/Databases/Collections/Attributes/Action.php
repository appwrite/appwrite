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
use Utopia\Database\Exception\NotFound as NotFoundException;
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
    private ?string $context = DATABASE_ATTRIBUTES_CONTEXT;

    /**
     * Get the correct response model.
     */
    abstract protected function getResponseModel(): string|array;

    /**
     * Set the context to either `column` or `attribute`.
     *
     * @throws \InvalidArgumentException If the context is invalid.
     */
    final protected function setContext(string $context): void
    {
        if (!\in_array($context, [DATABASE_COLUMNS_CONTEXT, DATABASE_ATTRIBUTES_CONTEXT], true)) {
            throw new \InvalidArgumentException("Invalid context '$context'. Use `DATABASE_COLUMNS_CONTEXT` or `DATABASE_ATTRIBUTES_CONTEXT`");
        }

        $this->context = $context;
    }

    /**
     * Get the current context.
     */
    final protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Returns true if current context is Collections API.
     */
    final protected function isCollectionsAPI(): bool
    {
        // columns in tables context
        // attributes in collections context
        return $this->getContext() === DATABASE_ATTRIBUTES_CONTEXT;
    }

    /**
     * Get the SDK group name for the current action.
     *
     * Can be used for XList operations as well!
     */
    final protected function getSdkGroup(): string
    {
        return $this->isCollectionsAPI() ? 'attributes' : 'columns';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    final protected function getSdkNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'collections' : 'tables';
    }

    /**
     * Get the appropriate parent level not found exception.
     */
    final protected function getParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    final protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_FOUND
            : Exception::COLUMN_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    final protected function getIndexDependencyException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_DEPENDENCY
            : Exception::COLUMN_INDEX_DEPENDENCY;
    }

    /**
     * Get the appropriate already exists exception.
     */
    final protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_ALREADY_EXISTS
            : Exception::COLUMN_ALREADY_EXISTS;
    }

    /**
     * Get the appropriate limit exceeded exception.
     */
    final protected function getLimitException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_LIMIT_EXCEEDED
            : Exception::COLUMN_LIMIT_EXCEEDED;
    }

    /**
     * Get the appropriate index invalid exception.
     */
    final protected function getInvalidIndexException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::INDEX_INVALID
            : Exception::COLUMN_INDEX_INVALID;
    }

    /**
     * Get the correct default unsupported message.
     */
    final protected function getDefaultUnsupportedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED
            : Exception::COLUMN_DEFAULT_UNSUPPORTED;
    }

    /**
     * Get the correct format unsupported message.
     */
    final protected function getFormatUnsupportedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_FORMAT_UNSUPPORTED
            : Exception::COLUMN_FORMAT_UNSUPPORTED;
    }

    /**
     * Get the exception for invalid type or format mismatch.
     */
    final protected function getTypeInvalidException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_TYPE_INVALID
            : Exception::COLUMN_TYPE_INVALID;
    }

    /**
     * Get the exception for resizing invalid attributes/columns.
     */
    final protected function getInvalidResizeException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_INVALID_RESIZE
            : Exception::COLUMN_INVALID_RESIZE;
    }

    /**
     * Get the exception for invalid attributes/columns value.
     */
    final protected function getInvalidValueException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_VALUE_INVALID
            : Exception::COLUMN_VALUE_INVALID;
    }

    /**
     * Get the exception for non-available column/attribute.
     */
    final protected function getNotAvailableException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::ATTRIBUTE_NOT_AVAILABLE
            : Exception::COLUMN_NOT_AVAILABLE;
    }

    /**
     * Get the correct collections context for Events queue.
     */
    final protected function getCollectionsEventsContext(): string
    {
        return $this->isCollectionsAPI() ? 'collection' : 'table';
    }

    /**
     *  Get the proper column/attribute type based on set context.
     */
    final protected function getCorrectModel(string $type, string $format): string
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

    final protected function createAttribute(string $databaseId, string $collectionId, Document $attribute, Response $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): Document
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

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException());
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
            $relatedCollection = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection'] ?? '');
            if ($relatedCollection->isEmpty()) {
                $parent = $this->isCollectionsAPI() ? 'collection' : 'table';
                throw new Exception($this->getParentNotFoundException(), "The related $parent was not found.");
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
                'options' => $options,
            ]);

            $dbForProject->checkAttribute($collection, $attribute);
            $attribute = $dbForProject->createDocument('attributes', $attribute);
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException());
        } catch (Throwable $e) {
            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);
            $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());
            throw $e;
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());

        if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
            $twoWayKey = $options['twoWayKey'];
            $options['relatedCollection'] = $collection->getId();
            $options['twoWayKey'] = $key;
            $options['side'] = Database::RELATION_SIDE_CHILD;

            try {
                $twoWayAttribute = new Document([
                    '$id' => ID::custom($db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $twoWayKey),
                    'key' => $twoWayKey,
                    'databaseInternalId' => $db->getInternalId(),
                    'databaseId' => $db->getId(),
                    'collectionInternalId' => $relatedCollection->getInternalId(),
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
                $dbForProject->deleteDocument('attributes', $attribute->getId());
                throw new Exception($this->getDuplicateException());
            } catch (LimitException) {
                $dbForProject->deleteDocument('attributes', $attribute->getId());
                throw new Exception($this->getLimitException());
            } catch (Throwable $e) {
                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedCollection->getId());
                $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
                throw $e;
            }

            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedCollection->getId());
            $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
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

    final protected function updateAttribute(string $databaseId, string $collectionId, string $key, Database $dbForProject, Event $queueForEvents, string $type, int $size = null, string $filter = null, string|bool|int|float $default = null, bool $required = null, int|float|null $min = null, int|float|null $max = null, array $elements = null, array $options = [], string $newKey = null): Document
    {
        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException());
        }

        $attribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception($this->getNotFoundException());
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

        $collectionId = 'database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId();

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
            } catch (NotFoundException) {
                throw new Exception($this->getNotFoundException());
            }

            if ($primaryDocumentOptions['twoWay']) {
                $relatedCollection = $dbForProject->getDocument('database_' . $db->getInternalId(), $primaryDocumentOptions['relatedCollection']);

                $relatedAttribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $primaryDocumentOptions['twoWayKey']);

                if (!empty($newKey) && $newKey !== $key) {
                    $options['twoWayKey'] = $newKey;
                }

                $relatedOptions = \array_merge($relatedAttribute->getAttribute('options'), $options);
                $relatedAttribute->setAttribute('options', $relatedOptions);
                $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $primaryDocumentOptions['twoWayKey'], $relatedAttribute);

                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedCollection->getId());
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
            } catch (TruncateException) {
                throw new Exception($this->getInvalidResizeException());
            } catch (NotFoundException) {
                throw new Exception($this->getNotFoundException());
            } catch (LimitException) {
                throw new Exception($this->getLimitException());
            } catch (IndexException $e) {
                throw new Exception($this->getInvalidIndexException(), $e->getMessage());
            }
        }

        if (!empty($newKey) && $key !== $newKey) {
            $originalUid = $attribute->getId();

            $attribute
                ->setAttribute('$id', ID::custom($db->getInternalId() . '_' . $collection->getInternalId() . '_' . $newKey))
                ->setAttribute('key', $newKey);

            $dbForProject->updateDocument('attributes', $originalUid, $attribute);

            /**
             * @var Document $index
             */
            foreach ($collection->getAttribute('indexes') as $index) {
                /**
                 * @var string[] $attribute
                 */
                $attribute = $index->getAttribute('attributes', []);
                $found = \array_search($key, $attribute);

                if ($found !== false) {
                    $attribute[$found] = $newKey;
                    $index->setAttribute('attributes', $attribute);
                    $dbForProject->updateDocument('indexes', $index->getId(), $index);
                }
            }
        } else {
            $attribute = $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key, $attribute);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collection->getId());

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
