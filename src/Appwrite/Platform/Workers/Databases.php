<?php

namespace Appwrite\Platform\Workers;

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
            ->callback(fn (Message $message, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime, Log $log) => $this->action($message, $project, $dbForPlatform, $dbForProject, $queueForRealtime, $log));
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
        $collection = new Document($payload['collection'] ?? []);
        $document = new Document($payload['document'] ?? []);
        $database = new Document($payload['database'] ?? []);

        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        if ($database->isEmpty()) {
            throw new Exception('Missing database');
        }

        $log->addTag('databaseId', $database->getId());

        match (\strval($type)) {
            DATABASE_TYPE_DELETE_DATABASE => $this->deleteDatabase($database, $project, $dbForProject),
            DATABASE_TYPE_DELETE_COLLECTION => $this->deleteCollection($database, $collection, $project, $dbForProject),
            DATABASE_TYPE_CREATE_ATTRIBUTE => $this->createAttribute($database, $collection, $document, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            DATABASE_TYPE_DELETE_ATTRIBUTE => $this->deleteAttribute($database, $collection, $document, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            DATABASE_TYPE_CREATE_INDEX => $this->createIndex($database, $collection, $document, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            DATABASE_TYPE_DELETE_INDEX => $this->deleteIndex($database, $collection, $document, $project, $dbForPlatform, $dbForProject, $queueForRealtime),
            default => throw new Exception('No database operation for type: ' . \strval($type)),
        };
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
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
    private function createAttribute(Document $database, Document $collection, Document $attribute, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($attribute->isEmpty()) {
            throw new Exception('Missing attribute');
        }

        $projectId = $project->getId();
        $event = "databases.[databaseId].collections.[collectionId].attributes.[attributeId].update";
        /**
         * TODO @christyjacob4 verify if this is still the case
         * Fetch attribute from the database, since with Resque float values are loosing informations.
         */
        $attribute = $dbForProject->getDocument('attributes', $attribute->getId());

        if ($attribute->isEmpty()) {
            // Attribute was deleted before job was processed
            return;
        }

        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $type = $attribute->getAttribute('type', '');
        $size = $attribute->getAttribute('size', 0);
        $required = $attribute->getAttribute('required', false);
        $default = $attribute->getAttribute('default', null);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $format = $attribute->getAttribute('format', '');
        $formatOptions = $attribute->getAttribute('formatOptions', []);
        $filters = $attribute->getAttribute('filters', []);
        $options = $attribute->getAttribute('options', []);
        $project = $dbForPlatform->getDocument('projects', $projectId);

        $relatedAttribute = new Document();
        $relatedCollection = new Document();

        try {
            switch ($type) {
                case Database::VAR_RELATIONSHIP:
                    $relatedCollection = $dbForProject->getDocument('database_' . $database->getInternalId(), $options['relatedCollection']);
                    if ($relatedCollection->isEmpty()) {
                        throw new DatabaseException('Collection not found');
                    }

                    if (
                        !$dbForProject->createRelationship(
                            collection: 'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(),
                            relatedCollection: 'database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId(),
                            type: $options['relationType'],
                            twoWay: $options['twoWay'],
                            id: $key,
                            twoWayKey: $options['twoWayKey'],
                            onDelete: $options['onDelete'],
                        )
                    ) {
                        throw new DatabaseException('Failed to create Attribute');
                    }

                    if ($options['twoWay']) {
                        $relatedAttribute = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $options['twoWayKey']);
                        $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'available'));
                    }
                    break;
                default:
                    if (!$dbForProject->createAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key, $type, $size, $required, $default, $signed, $array, $format, $formatOptions, $filters)) {
                        throw new Exception('Failed to create Attribute');
                    }
            }

            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'available'));
        } catch (\Throwable $e) {
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $attribute->setAttribute('error', $e->getMessage());
                if (! $relatedAttribute->isEmpty()) {
                    $relatedAttribute->setAttribute('error', $e->getMessage());
                }
            }

            $dbForProject->updateDocument(
                'attributes',
                $attribute->getId(),
                $attribute->setAttribute('status', 'failed')
            );

            if (! $relatedAttribute->isEmpty()) {
                $dbForProject->updateDocument(
                    'attributes',
                    $relatedAttribute->getId(),
                    $relatedAttribute->setAttribute('status', 'failed')
                );
            }

            throw $e;
        } finally {
            $this->trigger($database, $collection, $project, $event, $queueForRealtime, $attribute);

            if (! $relatedCollection->isEmpty()) {
                $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $relatedCollection->getId());
            }

            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collectionId);
        }
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
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
    private function deleteAttribute(Document $database, Document $collection, Document $attribute, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($attribute->isEmpty()) {
            throw new Exception('Missing attribute');
        }

        $projectId = $project->getId();
        $event = 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].delete';
        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $type = $attribute->getAttribute('type', '');
        $project = $dbForPlatform->getDocument('projects', $projectId);
        $options = $attribute->getAttribute('options', []);
        $relatedAttribute = new Document();
        $relatedCollection = new Document();
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
                        $relatedCollection = $dbForProject->getDocument('database_' . $database->getInternalId(), $options['relatedCollection']);
                        if ($relatedCollection->isEmpty()) {
                            throw new DatabaseException('Collection not found');
                        }
                        $relatedAttribute = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $options['twoWayKey']);
                    }

                    if (!$dbForProject->deleteRelationship('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                        $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'stuck'));
                        throw new DatabaseException('Failed to delete Relationship');
                    }
                } elseif (!$dbForProject->deleteAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                    throw new DatabaseException('Failed to delete Attribute');
                }

                $dbForProject->deleteDocument('attributes', $attribute->getId());

                if (!$relatedAttribute->isEmpty()) {
                    $dbForProject->deleteDocument('attributes', $relatedAttribute->getId());
                }

            } catch (NotFound $e) {
                Console::error($e->getMessage());

                $dbForProject->deleteDocument('attributes', $attribute->getId());

                if (!$relatedAttribute->isEmpty()) {
                    $dbForProject->deleteDocument('attributes', $relatedAttribute->getId());
                }

            } catch (\Throwable $e) {
                Console::error($e->getMessage());

                if ($e instanceof DatabaseException) {
                    $attribute->setAttribute('error', $e->getMessage());
                    if (!$relatedAttribute->isEmpty()) {
                        $relatedAttribute->setAttribute('error', $e->getMessage());
                    }
                }
                $dbForProject->updateDocument(
                    'attributes',
                    $attribute->getId(),
                    $attribute->setAttribute('status', 'stuck')
                );
                if (!$relatedAttribute->isEmpty()) {
                    $dbForProject->updateDocument(
                        'attributes',
                        $relatedAttribute->getId(),
                        $relatedAttribute->setAttribute('status', 'stuck')
                    );
                }

                throw $e;
            } finally {
                $this->trigger($database, $collection, $project, $event, $queueForRealtime, $attribute);
            }

            // The underlying database removes/rebuilds indexes when attribute is removed
            // Update indexes table with changes
            /** @var Document[] $indexes */
            $indexes = $collection->getAttribute('indexes', []);

            foreach ($indexes as $index) {
                /** @var string[] $attributes */
                $attributes = $index->getAttribute('attributes');
                $lengths = $index->getAttribute('lengths');
                $orders = $index->getAttribute('orders');

                $found = \array_search($key, $attributes);

                if ($found !== false) {
                    // If found, remove entry from attributes, lengths, and orders
                    // array_values wraps array_diff to reindex array keys
                    // when found attribute is removed from array
                    $attributes = \array_values(\array_diff($attributes, [$attributes[$found]]));
                    $lengths = \array_values(\array_diff($lengths, isset($lengths[$found]) ? [$lengths[$found]] : []));
                    $orders = \array_values(\array_diff($orders, isset($orders[$found]) ? [$orders[$found]] : []));

                    if (empty($attributes)) {
                        $dbForProject->deleteDocument('indexes', $index->getId());
                    } else {
                        $index
                            ->setAttribute('attributes', $attributes, Document::SET_TYPE_ASSIGN)
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
                            $this->deleteIndex($database, $collection, $index, $project, $dbForPlatform, $dbForProject, $queueForRealtime);
                        } else {
                            $dbForProject->updateDocument('indexes', $index->getId(), $index);
                        }
                    }
                }
            }
        } finally {
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collectionId);

            if (! $relatedCollection->isEmpty()) {
                $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $relatedCollection->getId());
            }
        }
    }

    /**
     * @param Document $database
     * @param Document $collection
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
    private function createIndex(Document $database, Document $collection, Document $index, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($index->isEmpty()) {
            throw new Exception('Missing index');
        }

        $projectId = $project->getId();
        $event = 'databases.[databaseId].collections.[collectionId].indexes.[indexId].update';
        $collectionId = $collection->getId();
        $key = $index->getAttribute('key', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);
        $project = $dbForPlatform->getDocument('projects', $projectId);

        try {
            if (!$dbForProject->createIndex('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key, $type, $attributes, $lengths, $orders)) {
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
            $this->trigger($database, $collection, $project, $event, $queueForRealtime, null, $index);
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collectionId);
        }
    }

    /**
     * @param Document $database
     * @param Document $collection
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
    private function deleteIndex(Document $database, Document $collection, Document $index, Document $project, Database $dbForPlatform, Database $dbForProject, Realtime $queueForRealtime): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($index->isEmpty()) {
            throw new Exception('Missing index');
        }

        $projectId = $project->getId();
        $event = 'databases.[databaseId].collections.[collectionId].indexes.[indexId].delete';
        $key = $index->getAttribute('key');
        $status = $index->getAttribute('status', '');
        $project = $dbForPlatform->getDocument('projects', $projectId);

        try {
            if ($status !== 'failed' && !$dbForProject->deleteIndex('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
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
            $this->trigger($database, $collection, $project, $event, $queueForRealtime, null, $index);
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collection->getId());
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
            $this->deleteCollection($database, $collection, $project, $dbForProject);
        });

        $dbForProject->deleteCollection('database_' . $database->getInternalId());
    }

    /**
     * @param Document $database
     * @param Document $collection
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
    protected function deleteCollection(Document $database, Document $collection, Document $project, Database $dbForProject): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }

        $collectionId = $collection->getId();
        $collectionInternalId = $collection->getInternalId();
        $databaseInternalId = $database->getInternalId();

        $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $collection->getInternalId());

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
     * @param string $collectionId
     * @param array $queries
     * @param Database $database
     * @param callable|null $callback
     * @return void
     * @throws Exception
     */
    protected function deleteByGroup(string $collectionId, array $queries, Database $database, callable $callback = null): void
    {
        $start = \microtime(true);

        try {
            $documents = $database->deleteDocuments($collectionId, $queries);
        } catch (\Throwable $th) {
            Console::error('Failed to delete documents for collection ' . $collectionId . ': ' . $th->getMessage());
            return;
        }

        if (\is_callable($callback)) {
            foreach ($documents as $document) {
                $callback($document);
            }
        }

        $end = \microtime(true);
        $count = \count($documents);

        Console::info("Deleted {$count} documents by group in " . ($end - $start) . " seconds");
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $project
     * @param Realtime $queueForRealtime
     * @param Document|null $attribute
     * @param Document|null $index
     * @return void
     */
    protected function trigger(
        Document $database,
        Document $collection,
        Document $project,
        string $event,
        Realtime $queueForRealtime,
        Document|null $attribute = null,
        Document|null $index = null,
    ): void {
        $queueForRealtime
            ->setProject($project)
            ->setSubscribers(['console'])
            ->setEvent($event)
            ->setParam('databaseId', $database->getId())
            ->setParam('collectionId', $collection->getId());

        if ($attribute !== null && !empty($attribute)) {
            $queueForRealtime
                ->setParam('attributeId', $attribute->getId())
                ->setPayload($attribute->getArrayCopy());
        }
        if ($index !== null && !empty($index)) {
            $queueForRealtime
                ->setParam('indexId', $index->getId())
                ->setPayload($index->getArrayCopy());
        }

        $queueForRealtime->trigger();
    }
}
