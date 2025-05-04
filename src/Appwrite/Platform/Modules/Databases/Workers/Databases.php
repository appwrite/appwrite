<?php

namespace Appwrite\Platform\Modules\Databases\Workers;

use Appwrite\Event\Realtime;
use Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\NotFound;
use Utopia\Database\Exception\Restricted;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Databases extends Action
{
    public static function getName(): string
    {
        return 'databases';
    }

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this
            ->desc('Databases worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('queueForRealtime')
            ->inject('log')
            ->callback([$this, 'action']);
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param Database $dbForPlatform
     * @param Database $dbForProject
     * @param Realtime $queueForRealtime
     * @param Log $log
     * @return void
     * @throws \Exception
     */
    public function action(Message $message, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type = $payload['type'];
        $row = new Document($payload['row'] ?? []);
        $table = new Document($payload['table'] ?? []);
        $database = new Document($payload['database'] ?? []);

        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        if ($database->isEmpty()) {
            throw new Exception('Missing database');
        }

        $log->addTag('databaseId', $database->getId());

        match (\strval($type)) {
            DATABASE_TYPE_DELETE_DATABASE => $this->deleteDatabase($database, $project, $dbForProject),
            DATABASE_TYPE_DELETE_COLLECTION => $this->deleteTable($database, $table, $project, $dbForProject),
            DATABASE_TYPE_CREATE_ATTRIBUTE => $this->createColumn($database, $table, $row, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            DATABASE_TYPE_DELETE_ATTRIBUTE => $this->deleteColumn($database, $table, $row, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            DATABASE_TYPE_CREATE_INDEX => $this->createIndex($database, $table, $row, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            DATABASE_TYPE_DELETE_INDEX => $this->deleteIndex($database, $table, $row, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            default => throw new Exception('No database operation for type: ' . \strval($type)),
        };
    }

    /**
     * @param Document $database
     * @param Document $table
     * @param Document $column
     * @param Document $project
     * @param Database $dbForPlatform
     * @param Database $dbForProject
     * @param Realtime $queueForRealtime
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws \Exception
     * @throws \Throwable
     */
    private function createColumn(
        Document $database,
        Document $table,
        Document $column,
        Document $project,
        Database $dbForPlatform,
        Database $dbForProject,
        Realtime $queueForRealtime
    ): void {
        if ($table->isEmpty()) {
            throw new Exception('Missing table');
        }
        if ($column->isEmpty()) {
            throw new Exception('Missing column');
        }

        $projectId = $project->getId();
        $event = "databases.[databaseId].tables.[tableId].columns.[columnId].update";
        /**
         * TODO @christyjacob4 verify if this is still the case
         * Fetch attribute from the database, since with Resque float values are loosing informations.
         */
        $column = $dbForProject->getDocument('attributes', $column->getId());

        if ($column->isEmpty()) {
            // Attribute was deleted before job was processed
            return;
        }

        $tableId = $table->getId();
        $key = $column->getAttribute('key', '');
        $type = $column->getAttribute('type', '');
        $size = $column->getAttribute('size', 0);
        $required = $column->getAttribute('required', false);
        $default = $column->getAttribute('default', null);
        $signed = $column->getAttribute('signed', true);
        $array = $column->getAttribute('array', false);
        $format = $column->getAttribute('format', '');
        $formatOptions = $column->getAttribute('formatOptions', []);
        $filters = $column->getAttribute('filters', []);
        $options = $column->getAttribute('options', []);
        $project = $dbForPlatform->getDocument('projects', $projectId);

        $relatedColumn = new Document();
        $relatedTable = new Document();

        try {
            switch ($type) {
                case Database::VAR_RELATIONSHIP:
                    $relatedTable = $dbForProject->getDocument('database_' . $database->getInternalId(), $options['relatedCollection']);
                    if ($relatedTable->isEmpty()) {
                        throw new DatabaseException('Table not found');
                    }

                    if (
                        !$dbForProject->createRelationship(
                            collection: 'database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(),
                            relatedCollection: 'database_' . $database->getInternalId() . '_collection_' . $relatedTable->getInternalId(),
                            type: $options['relationType'],
                            twoWay: $options['twoWay'],
                            id: $key,
                            twoWayKey: $options['twoWayKey'],
                            onDelete: $options['onDelete'],
                        )
                    ) {
                        throw new DatabaseException('Failed to create Column');
                    }

                    if ($options['twoWay']) {
                        $relatedColumn = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $options['twoWayKey']);
                        $dbForProject->updateDocument('attributes', $relatedColumn->getId(), $relatedColumn->setAttribute('status', 'available'));
                    }
                    break;
                default:
                    if (!$dbForProject->createAttribute('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $key, $type, $size, $required, $default, $signed, $array, $format, $formatOptions, $filters)) {
                        throw new Exception('Failed to create Column');
                    }
            }

            $dbForProject->updateDocument('attributes', $column->getId(), $column->setAttribute('status', 'available'));
        } catch (\Throwable $e) {
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $column->setAttribute('error', $e->getMessage());
                if (! $relatedColumn->isEmpty()) {
                    $relatedColumn->setAttribute('error', $e->getMessage());
                }
            }

            $dbForProject->updateDocument(
                'attributes',
                $column->getId(),
                $column->setAttribute('status', 'failed')
            );

            if (! $relatedColumn->isEmpty()) {
                $dbForProject->updateDocument(
                    'attributes',
                    $relatedColumn->getId(),
                    $relatedColumn->setAttribute('status', 'failed')
                );
            }

            throw $e;
        } finally {
            $this->trigger($database, $table, $project, $event, $queueForRealtime, $column);

            if (! $relatedTable->isEmpty()) {
                $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $relatedTable->getId());
            }

            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $tableId);
        }
    }

    /**
     * @param Document $database
     * @param Document $table
     * @param Document $column
     * @param Document $project
     * @param Database $dbForPlatform
     * @param Database $dbForProject
     * @param Realtime $queueForRealtime
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws \Exception
     * @throws \Throwable
     **/
    private function deleteColumn(Document $database, Document $table, Document $column, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime): void
    {
        if ($table->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($column->isEmpty()) {
            throw new Exception('Missing attribute');
        }

        $projectId = $project->getId();
        $event = 'databases.[databaseId].tables.[tableId].columns.[columnId].delete';
        $tableId = $table->getId();
        $key = $column->getAttribute('key', '');
        $type = $column->getAttribute('type', '');
        $project = $dbForPlatform->getDocument('projects', $projectId);
        $options = $column->getAttribute('options', []);
        $relatedColumn = new Document();
        $relatedTable = new Document();
        // possible states at this point:
        // - available: should not land in queue; controller flips these to 'deleting'
        // - processing: hasn't finished creating
        // - deleting: was available, in deletion queue for first time
        // - failed: attribute was never created
        // - stuck: attribute was available but cannot be removed

        try {
            try {
                if ($type === Database::VAR_RELATIONSHIP) {
                    if ($options['twoWay']) {
                        $relatedTable = $dbForProject->getDocument('database_' . $database->getInternalId(), $options['relatedCollection']);
                        if ($relatedTable->isEmpty()) {
                            throw new DatabaseException('Table not found');
                        }
                        $relatedColumn = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $options['twoWayKey']);
                    }

                    if (!$dbForProject->deleteRelationship('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $key)) {
                        $dbForProject->updateDocument('attributes', $relatedColumn->getId(), $relatedColumn->setAttribute('status', 'stuck'));
                        throw new DatabaseException('Failed to delete Relationship');
                    }
                } elseif (!$dbForProject->deleteAttribute('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $key)) {
                    throw new DatabaseException('Failed to delete Column');
                }

                $dbForProject->deleteDocument('attributes', $column->getId());

                if (!$relatedColumn->isEmpty()) {
                    $dbForProject->deleteDocument('attributes', $relatedColumn->getId());
                }

            } catch (NotFound $e) {
                Console::error($e->getMessage());

                $dbForProject->deleteDocument('attributes', $column->getId());

                if (!$relatedColumn->isEmpty()) {
                    $dbForProject->deleteDocument('attributes', $relatedColumn->getId());
                }

            } catch (\Throwable $e) {
                Console::error($e->getMessage());

                if ($e instanceof DatabaseException) {
                    $column->setAttribute('error', $e->getMessage());
                    if (!$relatedColumn->isEmpty()) {
                        $relatedColumn->setAttribute('error', $e->getMessage());
                    }
                }
                $dbForProject->updateDocument(
                    'attributes',
                    $column->getId(),
                    $column->setAttribute('status', 'stuck')
                );
                if (!$relatedColumn->isEmpty()) {
                    $dbForProject->updateDocument(
                        'attributes',
                        $relatedColumn->getId(),
                        $relatedColumn->setAttribute('status', 'stuck')
                    );
                }

                throw $e;
            } finally {
                $this->trigger($database, $table, $project, $event, $queueForRealtime, $column);
            }

            // The underlying database removes/rebuilds indexes when attribute is removed
            // Update indexes table with changes
            /** @var Document[] $indexes */
            $indexes = $table->getAttribute('indexes', []);

            foreach ($indexes as $index) {
                /** @var string[] $columns */
                $columns = $index->getAttribute('attributes');
                $lengths = $index->getAttribute('lengths');
                $orders = $index->getAttribute('orders');

                $found = \array_search($key, $columns);

                if ($found !== false) {
                    // If found, remove entry from attributes, lengths, and orders
                    // array_values wraps array_diff to reindex array keys
                    // when found attribute is removed from array
                    $columns = \array_values(\array_diff($columns, [$columns[$found]]));
                    $lengths = \array_values(\array_diff($lengths, isset($lengths[$found]) ? [$lengths[$found]] : []));
                    $orders = \array_values(\array_diff($orders, isset($orders[$found]) ? [$orders[$found]] : []));

                    if (empty($columns)) {
                        $dbForProject->deleteDocument('indexes', $index->getId());
                    } else {
                        $index
                            ->setAttribute('attributes', $columns, Document::SET_TYPE_ASSIGN)
                            ->setAttribute('lengths', $lengths, Document::SET_TYPE_ASSIGN)
                            ->setAttribute('orders', $orders, Document::SET_TYPE_ASSIGN);

                        // Check if an index exists with the same attributes and orders
                        $exists = false;
                        foreach ($indexes as $existing) {
                            if (
                                $existing->getAttribute('key') !== $index->getAttribute('key') // Ignore itself
                                && $existing->getAttribute('attributes') === $index->getAttribute('attributes')
                                && $existing->getAttribute('orders') === $index->getAttribute('orders')
                            ) {
                                $exists = true;
                                break;
                            }
                        }

                        if ($exists) { // Delete the duplicate if created, else update in db
                            $this->deleteIndex($database, $table, $index, $project, $dbForPlatform, $dbForProject, $queueForRealtime);
                        } else {
                            $dbForProject->updateDocument('indexes', $index->getId(), $index);
                        }
                    }
                }
            }
        } finally {
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $tableId);

            if (! $relatedTable->isEmpty()) {
                $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $relatedTable->getId());
            }
        }
    }

    /**
     * @param Document $database
     * @param Document $table
     * @param Document $index
     * @param Document $project
     * @param Database $dbForPlatform
     * @param Database $dbForProject
     * @param Realtime $queueForRealtime
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws Structure
     * @throws DatabaseException
     * @throws \Throwable
     */
    private function createIndex(Document $database, Document $table, Document $index, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime): void
    {
        if ($table->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($index->isEmpty()) {
            throw new Exception('Missing index');
        }

        $projectId = $project->getId();
        $event = 'databases.[databaseId].tables.[tableId].indexes.[indexId].update';
        $collectionId = $table->getId();
        $key = $index->getAttribute('key', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);
        $project = $dbForPlatform->getDocument('projects', $projectId);

        try {
            if (!$dbForProject->createIndex('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $key, $type, $attributes, $lengths, $orders)) {
                throw new DatabaseException('Failed to create Index');
            }
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'available'));
        } catch (\Throwable $e) {
            Console::error($e->getMessage());
            if ($e instanceof DatabaseException) {
                $index->setAttribute('error', $e->getMessage());
            }
            $dbForProject->updateDocument(
                'indexes',
                $index->getId(),
                $index->setAttribute('status', 'failed')
            );

            throw $e;
        } finally {
            $this->trigger($database, $table, $project, $event, $queueForRealtime, null, $index);
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collectionId);
        }
    }

    /**
     * @param Document $database
     * @param Document $table
     * @param Document $index
     * @param Document $project
     * @param Database $dbForPlatform
     * @param Database $dbForProject
     * @param Realtime $queueForRealtime
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws Structure
     * @throws DatabaseException
     * @throws \Throwable
     */
    private function deleteIndex(Document $database, Document $table, Document $index, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime): void
    {
        if ($table->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($index->isEmpty()) {
            throw new Exception('Missing index');
        }

        $projectId = $project->getId();
        $event = 'databases.[databaseId].tables.[tableId].indexes.[indexId].delete';
        $key = $index->getAttribute('key');
        $status = $index->getAttribute('status', '');
        $project = $dbForPlatform->getDocument('projects', $projectId);

        try {
            if ($status !== 'failed' && !$dbForProject->deleteIndex('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId(), $key)) {
                throw new DatabaseException('Failed to delete index');
            }
            $dbForProject->deleteDocument('indexes', $index->getId());
            $index->setAttribute('status', 'deleted');
        } catch (\Throwable $e) {
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $index->setAttribute('error', $e->getMessage());
            }
            $dbForProject->updateDocument(
                'indexes',
                $index->getId(),
                $index->setAttribute('status', 'stuck')
            );

            throw $e;

        } finally {
            $this->trigger($database, $table, $project, $event, $queueForRealtime, null, $index);
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $table->getId());
        }
    }

    /**
     * @param Document $database
     * @param Document $project
     * @param $dbForProject
     * @return void
     * @throws Exception
     */
    protected function deleteDatabase(Document $database, Document $project, $dbForProject): void
    {
        $this->deleteByGroup('database_' . $database->getInternalId(), [], $dbForProject, function ($collection) use ($database, $project, $dbForProject) {
            $this->deleteTable($database, $collection, $project, $dbForProject);
        });

        $dbForProject->deleteCollection('database_' . $database->getInternalId());
    }

    /**
     * @param Document $database
     * @param Document $table
     * @param Document $project
     * @param Database $dbForProject
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws DatabaseException
     * @throws Restricted
     * @throws Structure
     * @throws Exception
     */
    protected function deleteTable(Document $database, Document $table, Document $project, Database $dbForProject): void
    {
        if ($table->isEmpty()) {
            throw new Exception('Missing table');
        }

        $collectionId = $table->getId();
        $collectionInternalId = $table->getInternalId();
        $databaseInternalId = $database->getInternalId();

        $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $table->getInternalId());

        /**
         * Related collections relating to current collection
         */
        $this->deleteByGroup(
            'attributes',
            [
                Query::equal('databaseInternalId', [$databaseInternalId]),
                Query::equal('type', [Database::VAR_RELATIONSHIP]),
                Query::notEqual('collectionInternalId', $collectionInternalId),
                Query::contains('options', ['"relatedCollection":"'. $collectionId .'"']),
            ],
            $dbForProject,
            function ($attribute) use ($dbForProject, $databaseInternalId) {
                $dbForProject->purgeCachedDocument('database_' . $databaseInternalId, $attribute->getAttribute('collectionId'));
                $dbForProject->purgeCachedCollection('database_' . $databaseInternalId . '_collection_' . $attribute->getAttribute('collectionInternalId'));
            }
        );

        $this->deleteByGroup('attributes', [
            Query::equal('databaseInternalId', [$databaseInternalId]),
            Query::equal('collectionInternalId', [$collectionInternalId])
        ], $dbForProject);

        $this->deleteByGroup('indexes', [
            Query::equal('databaseInternalId', [$databaseInternalId]),
            Query::equal('collectionInternalId', [$collectionInternalId])
        ], $dbForProject);
    }


    /**
     * @param string $tableId
     * @param array $queries
     * @param Database $database
     * @param callable|null $callback
     * @return void
     * @throws Exception
     */
    protected function deleteByGroup(string $tableId, array $queries, Database $database, callable $callback = null): void
    {
        $start = \microtime(true);

        try {
            $count = $database->deleteDocuments(
                $tableId,
                $queries,
                Database::DELETE_BATCH_SIZE,
                $callback
            );
        } catch (\Throwable $th) {
            $tenant = $database->getSharedTables() ? 'Tenant:'.$database->getTenant() : '';
            Console::error("Failed to delete rows for table:{$database->getNamespace()}_{$tableId} {$tenant} :{$th->getMessage()}");
            return;
        }

        $end = \microtime(true);
        Console::info("Deleted {$count} rows by group in " . ($end - $start) . " seconds");
    }

    /**
     * @param Document $database
     * @param Document $table
     * @param Document $project
     * @param Realtime $queueForRealtime
     * @param Document|null $column
     * @param Document|null $index
     * @return void
     */
    protected function trigger(
        Document      $database,
        Document      $table,
        Document      $project,
        string        $event,
        Realtime      $queueForRealtime,
        Document|null $column = null,
        Document|null $index = null,
    ): void {
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console'])
            ->setEvent($event)
            ->setParam('databaseId', $database->getId())
            ->setParam('tableId', $table->getId());

        if (! empty($column)) {
            $queueForRealtime
                ->setParam('columnId', $column->getId())
                ->setPayload($column->getArrayCopy());
        }
        if (! empty($index)) {
            $queueForRealtime
                ->setParam('indexId', $index->getId())
                ->setPayload($index->getArrayCopy());
        }

        $queueForRealtime->trigger();
    }
}
