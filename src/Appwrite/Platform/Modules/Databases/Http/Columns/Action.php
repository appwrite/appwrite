<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Structure;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Range;

class Action extends UtopiaAction
{
    /**
     * * Create column of varying type
     *
     * @param string $databaseId
     * @param string $tableId
     * @param Document $column
     * @param Response $response
     * @param Database $dbForProject
     * @param EventDatabase $queueForDatabase
     * @param Event $queueForEvents
     *
     * @return Document Newly created attribute document
     *
     * @throws AuthorizationException
     * @throws Exception
     * @throws LimitException
     * @throws RestrictedException
     * @throws StructureException
     * @throws \Utopia\Database\Exception
     * @throws ConflictException
     */
    protected function createColumn(
        string        $databaseId,
        string        $tableId,
        Document      $column,
        Response      $response,
        Database      $dbForProject,
        EventDatabase $queueForDatabase,
        Event         $queueForEvents
    ): Document {
        $key = $column->getAttribute('key');
        $type = $column->getAttribute('type', '');
        $size = $column->getAttribute('size', 0);
        $required = $column->getAttribute('required', true);
        $signed = $column->getAttribute('signed', true); // integers are signed by default
        $array = $column->getAttribute('array', false);
        $format = $column->getAttribute('format', '');
        $formatOptions = $column->getAttribute('formatOptions', []);
        $filters = $column->getAttribute('filters', []); // filters are hidden from the endpoint
        $default = $column->getAttribute('default');
        $options = $column->getAttribute('options', []);

        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        if (!empty($format)) {
            if (!Structure::hasFormat($format, $type)) {
                throw new Exception(Exception::ATTRIBUTE_FORMAT_UNSUPPORTED, "Format {$format} not available for {$type} columns.");
            }
        }

        // Must throw here since dbForProject->createAttribute is performed by db worker
        if ($required && isset($default)) {
            throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required column');
        }

        if ($array && isset($default)) {
            throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array columns');
        }

        if ($type === Database::VAR_RELATIONSHIP) {
            $options['side'] = Database::RELATION_SIDE_PARENT;
            $relatedTable = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection'] ?? '');
            if ($relatedTable->isEmpty()) {
                throw new Exception(Exception::COLLECTION_NOT_FOUND, 'The related table was not found.');
            }
        }

        try {
            $column = new Document([
                '$id' => ID::custom($db->getInternalId() . '_' . $table->getInternalId() . '_' . $key),
                'key' => $key,
                'databaseInternalId' => $db->getInternalId(),
                'databaseId' => $db->getId(),
                'collectionInternalId' => $table->getInternalId(),
                'collectionId' => $tableId,
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

            $dbForProject->checkAttribute($table, $column);
            $column = $dbForProject->createDocument('attributes', $column);
        } catch (DuplicateException) {
            throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
        } catch (LimitException) {
            throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
        } catch (Throwable $e) {
            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);
            $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $table->getInternalId());
            throw $e;
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $table->getInternalId());

        if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
            $twoWayKey = $options['twoWayKey'];
            $options['relatedCollection'] = $table->getId();
            $options['twoWayKey'] = $key;
            $options['side'] = Database::RELATION_SIDE_CHILD;

            try {
                $twoWayAttribute = new Document([
                    '$id' => ID::custom($db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $twoWayKey),
                    'key' => $twoWayKey,
                    'databaseInternalId' => $db->getInternalId(),
                    'databaseId' => $db->getId(),
                    'collectionInternalId' => $relatedTable->getInternalId(),
                    'collectionId' => $relatedTable->getId(),
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

                $dbForProject->checkAttribute($relatedTable, $twoWayAttribute);
                $dbForProject->createDocument('attributes', $twoWayAttribute);
            } catch (DuplicateException) {
                $dbForProject->deleteDocument('attributes', $column->getId());
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
            } catch (LimitException) {
                $dbForProject->deleteDocument('attributes', $column->getId());
                throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
            } catch (Throwable $e) {
                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedTable->getId());
                $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedTable->getInternalId());
                throw $e;
            }

            $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedTable->getId());
            $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedTable->getInternalId());
        }

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_ATTRIBUTE)
            ->setDatabase($db)
            ->setTable($table)
            ->setRow($column);

        $queueForEvents
            ->setContext('table', $table)
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('columnId', $column->getId());

        $response->setStatusCode(SwooleResponse::STATUS_CODE_CREATED);

        return $column;
    }

    protected function updateColumn(
        string                $databaseId,
        string                $tableId,
        string                $key,
        Database              $dbForProject,
        Event                 $queueForEvents,
        string                $type,
        int                   $size = null,
        string                $filter = null,
        string|bool|int|float $default = null,
        bool                  $required = null,
        int|float|null        $min = null,
        int|float|null        $max = null,
        array                 $elements = null,
        array                 $options = [],
        string                $newKey = null,
    ): Document {
        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $column = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key);

        if ($column->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        if ($column->getAttribute('status') !== 'available') {
            throw new Exception(Exception::ATTRIBUTE_NOT_AVAILABLE);
        }

        if ($column->getAttribute(('type') !== $type)) {
            throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
        }

        if ($column->getAttribute('type') === Database::VAR_STRING && $column->getAttribute(('filter') !== $filter)) {
            throw new Exception(Exception::ATTRIBUTE_TYPE_INVALID);
        }

        if ($required && isset($default)) {
            throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for required column');
        }

        if ($column->getAttribute('array', false) && isset($default)) {
            throw new Exception(Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED, 'Cannot set default value for array columns');
        }

        $tableId = 'database_' . $db->getInternalId() . '_collection_' . $table->getInternalId();

        $column
            ->setAttribute('default', $default)
            ->setAttribute('required', $required);

        if (!empty($size)) {
            $column->setAttribute('size', $size);
        }

        switch ($column->getAttribute('format')) {
            case APP_DATABASE_ATTRIBUTE_INT_RANGE:
            case APP_DATABASE_ATTRIBUTE_FLOAT_RANGE:
                $min ??= $column->getAttribute('formatOptions')['min'];
                $max ??= $column->getAttribute('formatOptions')['max'];

                if ($min > $max) {
                    throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
                }

                if ($column->getAttribute('format') === APP_DATABASE_ATTRIBUTE_INT_RANGE) {
                    $validator = new Range($min, $max, Database::VAR_INTEGER);
                } else {
                    $validator = new Range($min, $max, Database::VAR_FLOAT);

                    if (!is_null($default)) {
                        $default = \floatval($default);
                    }
                }

                if (!is_null($default) && !$validator->isValid($default)) {
                    throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
                }

                $options = [
                    'min' => $min,
                    'max' => $max
                ];
                $column->setAttribute('formatOptions', $options);

                break;
            case APP_DATABASE_ATTRIBUTE_ENUM:
                if (empty($elements)) {
                    throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Enum elements must not be empty');
                }

                foreach ($elements as $element) {
                    if (\strlen($element) === 0) {
                        throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Each enum element must not be empty');
                    }
                }

                if (!is_null($default) && !in_array($default, $elements)) {
                    throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
                }

                $options = [
                    'elements' => $elements
                ];

                $column->setAttribute('formatOptions', $options);

                break;
        }

        if ($type === Database::VAR_RELATIONSHIP) {
            $primaryRowOptions = \array_merge($column->getAttribute('options', []), $options);
            $column->setAttribute('options', $primaryRowOptions);
            try {
                $dbForProject->updateRelationship(
                    collection: $tableId,
                    id: $key,
                    newKey: $newKey,
                    onDelete: $primaryRowOptions['onDelete'],
                );
            } catch (NotFoundException) {
                throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
            }

            if ($primaryRowOptions['twoWay']) {
                $relatedTable = $dbForProject->getDocument('database_' . $db->getInternalId(), $primaryRowOptions['relatedCollection']);

                $relatedColumn = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $primaryRowOptions['twoWayKey']);

                if (!empty($newKey) && $newKey !== $key) {
                    $options['twoWayKey'] = $newKey;
                }

                $relatedOptions = \array_merge($relatedColumn->getAttribute('options'), $options);
                $relatedColumn->setAttribute('options', $relatedOptions);
                $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $primaryRowOptions['twoWayKey'], $relatedColumn);

                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $relatedTable->getId());
            }
        } else {
            try {
                $dbForProject->updateAttribute(
                    collection: $tableId,
                    id: $key,
                    size: $size,
                    required: $required,
                    default: $default,
                    formatOptions: $options,
                    newKey: $newKey ?? null
                );
            } catch (TruncateException) {
                throw new Exception(Exception::ATTRIBUTE_INVALID_RESIZE);
            } catch (NotFoundException) {
                throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
            } catch (LimitException) {
                throw new Exception(Exception::ATTRIBUTE_LIMIT_EXCEEDED);
            } catch (IndexException $e) {
                throw new Exception(Exception::INDEX_INVALID, $e->getMessage());
            }
        }

        if (!empty($newKey) && $key !== $newKey) {
            $originalUid = $column->getId();

            $column
                ->setAttribute('$id', ID::custom($db->getInternalId() . '_' . $table->getInternalId() . '_' . $newKey))
                ->setAttribute('key', $newKey);

            $dbForProject->updateDocument('attributes', $originalUid, $column);

            /**
             * @var Document $index
             */
            foreach ($table->getAttribute('indexes') as $index) {
                /**
                 * @var string[] $columns
                 */
                $columns = $index->getAttribute('attributes', []);
                $found = \array_search($key, $columns);

                if ($found !== false) {
                    $columns[$found] = $newKey;
                    $index->setAttribute('attributes', $columns);
                    $dbForProject->updateDocument('indexes', $index->getId(), $index);
                }
            }
        } else {
            $column = $dbForProject->updateDocument('attributes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key, $column);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $table->getId());

        $queueForEvents
            ->setContext('table', $table)
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('columnId', $column->getId());

        return $column;
    }
}
