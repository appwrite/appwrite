<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Exception;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
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
            ->inject('dbForConsole')
            ->inject('dbForProject')
            ->inject('log')
            ->callback(fn (Message $message, Database $dbForConsole, Database $dbForProject, Log $log) => $this->action($message, $dbForConsole, $dbForProject, $log));
    }

    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @param Database $dbForProject
     * @param Log $log
     * @return void
     * @throws \Exception
     */
    public function action(Message $message, Database $dbForConsole, Database $dbForProject, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $type = $payload['type'];
        $project = new Document($payload['project']);
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
            DATABASE_TYPE_CREATE_ATTRIBUTE => $this->createAttribute($database, $collection, $document, $project, $dbForConsole, $dbForProject),
            DATABASE_TYPE_DELETE_ATTRIBUTE => $this->deleteAttribute($database, $collection, $document, $project, $dbForConsole, $dbForProject),
            DATABASE_TYPE_CREATE_INDEX => $this->createIndex($database, $collection, $document, $project, $dbForConsole, $dbForProject),
            DATABASE_TYPE_DELETE_INDEX => $this->deleteIndex($database, $collection, $document, $project, $dbForConsole, $dbForProject),
            default => throw new \Exception('No database operation for type: ' . \strval($type)),
        };
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
     * @param Document $project
     * @param Database $dbForConsole
     * @param Database $dbForProject
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws \Exception
     */
    private function createAttribute(Document $database, Document $collection, Document $attribute, Document $project, Database $dbForConsole, Database $dbForProject): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($attribute->isEmpty()) {
            throw new Exception('Missing attribute');
        }

        $projectId = $project->getId();

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].attributes.[attributeId].update', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);
        /**
         * TODO @christyjacob4 verify if this is still the case
         * Fetch attribute from the database, since with Resque float values are loosing informations.
         */
        $attribute = $dbForProject->getDocument('attributes', $attribute->getId());

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
        $project = $dbForConsole->getDocument('projects', $projectId);


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
                        throw new \Exception('Failed to create Attribute');
                    }
            }

            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'available'));
        } catch (\Throwable $e) {
            // TODO: Send non DatabaseExceptions to Sentry
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $attribute->setAttribute('error', $e->getMessage());
                if (isset($relatedAttribute)) {
                    $relatedAttribute->setAttribute('error', $e->getMessage());
                }
            }


            $dbForProject->updateDocument(
                'attributes',
                $attribute->getId(),
                $attribute->setAttribute('status', 'failed')
            );

            if (isset($relatedAttribute)) {
                $dbForProject->updateDocument(
                    'attributes',
                    $relatedAttribute->getId(),
                    $relatedAttribute->setAttribute('status', 'failed')
                );
            }
        } finally {
            $this->trigger($database, $collection, $attribute, $project, $projectId, $events);
        }

        if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $relatedCollection->getId());
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collectionId);
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
     * @param Document $project
     * @param Database $dbForConsole
     * @param Database $dbForProject
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws \Exception
     **/
    private function deleteAttribute(Document $database, Document $collection, Document $attribute, Document $project, Database $dbForConsole, Database $dbForProject): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($attribute->isEmpty()) {
            throw new Exception('Missing attribute');
        }

        $projectId = $project->getId();

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].attributes.[attributeId].delete', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);
        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $status = $attribute->getAttribute('status', '');
        $type = $attribute->getAttribute('type', '');
        $project = $dbForConsole->getDocument('projects', $projectId);
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
            if ($status !== 'failed') {
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
            }

            $dbForProject->deleteDocument('attributes', $attribute->getId());

            if (!$relatedAttribute->isEmpty()) {
                $dbForProject->deleteDocument('attributes', $relatedAttribute->getId());
            }
        } catch (\Throwable $e) {
            // TODO: Send non DatabaseExceptions to Sentry
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
        } finally {
            $this->trigger($database, $collection, $attribute, $project, $projectId, $events);
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
                        $this->deleteIndex($database, $collection, $index, $project, $dbForConsole, $dbForProject);
                    } else {
                        $dbForProject->updateDocument('indexes', $index->getId(), $index);
                    }
                }
            }
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());

        if (!$relatedCollection->isEmpty() && !$relatedAttribute->isEmpty()) {
            $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $relatedCollection->getId());
            $dbForProject->purgeCachedCollection('database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
        }
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $index
     * @param Document $project
     * @param Database $dbForConsole
     * @param Database $dbForProject
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws Structure
     * @throws DatabaseException
     */
    private function createIndex(Document $database, Document $collection, Document $index, Document $project, Database $dbForConsole, Database $dbForProject): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($index->isEmpty()) {
            throw new Exception('Missing index');
        }

        $projectId = $project->getId();

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].indexes.[indexId].update', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'indexId' => $index->getId()
        ]);
        $collectionId = $collection->getId();
        $key = $index->getAttribute('key', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            if (!$dbForProject->createIndex('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key, $type, $attributes, $lengths, $orders)) {
                throw new DatabaseException('Failed to create Index');
            }
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'available'));
        } catch (\Throwable $e) {
            // TODO: Send non DatabaseExceptions to Sentry
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $index->setAttribute('error', $e->getMessage());
            }
            $dbForProject->updateDocument(
                'indexes',
                $index->getId(),
                $index->setAttribute('status', 'failed')
            );
        } finally {
            $this->trigger($database, $collection, $index, $project, $projectId, $events);
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collectionId);
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $index
     * @param Document $project
     * @param Database $dbForConsole
     * @param Database $dbForProject
     * @return void
     * @throws Authorization
     * @throws Conflict
     * @throws Structure
     * @throws DatabaseException
     */
    private function deleteIndex(Document $database, Document $collection, Document $index, Document $project, Database $dbForConsole, Database $dbForProject): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }
        if ($index->isEmpty()) {
            throw new Exception('Missing index');
        }

        $projectId = $project->getId();

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].indexes.[indexId].delete', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'indexId' => $index->getId()
        ]);
        $key = $index->getAttribute('key');
        $status = $index->getAttribute('status', '');
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            if ($status !== 'failed' && !$dbForProject->deleteIndex('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                throw new DatabaseException('Failed to delete index');
            }
            $dbForProject->deleteDocument('indexes', $index->getId());
            $index->setAttribute('status', 'deleted');
        } catch (\Throwable $e) {
            // TODO: Send non DatabaseExceptions to Sentry
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $index->setAttribute('error', $e->getMessage());
            }
            $dbForProject->updateDocument(
                'indexes',
                $index->getId(),
                $index->setAttribute('status', 'stuck')
            );
        } finally {
            $this->trigger($database, $collection, $index, $project, $projectId, $events);
        }

        $dbForProject->purgeCachedDocument('database_' . $database->getInternalId(), $collection->getId());
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

        $this->deleteAuditLogsByResource('database/' . $database->getId(), $project, $dbForProject);
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
     */
    protected function deleteCollection(Document $database, Document $collection, Document $project, Database $dbForProject): void
    {
        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }

        $collectionId = $collection->getId();
        $collectionInternalId = $collection->getInternalId();
        $databaseId = $database->getId();
        $databaseInternalId = $database->getInternalId();

        $relationships = \array_filter(
            $collection->getAttribute('attributes'),
            fn ($attribute) => $attribute['type'] === Database::VAR_RELATIONSHIP
        );

        foreach ($relationships as $relationship) {
            if (!$relationship['twoWay']) {
                continue;
            }
            $relatedCollection = $dbForProject->getDocument('database_' . $databaseInternalId, $relationship['relatedCollection']);
            $dbForProject->deleteDocument('attributes', $databaseInternalId . '_' . $relatedCollection->getInternalId() . '_' . $relationship['twoWayKey']);
            $dbForProject->purgeCachedDocument('database_' . $databaseInternalId, $relatedCollection->getId());
            $dbForProject->purgeCachedCollection('database_' . $databaseInternalId . '_collection_' . $relatedCollection->getInternalId());
        }

        $dbForProject->deleteCollection('database_' . $databaseInternalId . '_collection_' . $collection->getInternalId());

        $this->deleteByGroup('attributes', [
            Query::equal('databaseInternalId', [$databaseInternalId]),
            Query::equal('collectionInternalId', [$collectionInternalId])
        ], $dbForProject);

        $this->deleteByGroup('indexes', [
            Query::equal('databaseInternalId', [$databaseInternalId]),
            Query::equal('collectionInternalId', [$collectionInternalId])
        ], $dbForProject);

        $this->deleteAuditLogsByResource('database/' . $databaseId . '/collection/' . $collectionId, $project, $dbForProject);
    }

    /**
     * @param string $resource
     * @param Document $project
     * @param Database $dbForProject
     * @return void
     * @throws Exception
     */
    protected function deleteAuditLogsByResource(string $resource, Document $project, Database $dbForProject): void
    {
        $this->deleteByGroup(Audit::COLLECTION, [
            Query::equal('resource', [$resource])
        ], $dbForProject);
    }

    /**
     * @param string $collection collectionID
     * @param array $queries
     * @param Database $database
     * @param callable|null $callback
     * @return void
     * @throws Exception
     */
    protected function deleteByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;

            $results = $database->find($collection, \array_merge([Query::limit($limit)], $queries));

            $sum = count($results);

            Console::info('Deleting chunk #' . $chunk . '. Found ' . $sum . ' documents');

            foreach ($results as $document) {
                if ($database->deleteDocument($document->getCollection(), $document->getId())) {
                    Console::success('Deleted document "' . $document->getId() . '" successfully');

                    if (\is_callable($callback)) {
                        $callback($document);
                    }
                } else {
                    Console::warning('Failed to delete document: ' . $document->getId());
                }
                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::info("Deleted {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    protected function trigger(
        Document $database,
        Document $collection,
        Document $attribute,
        Document $project,
        string $projectId,
        array $events
    ): void {
        $target = Realtime::fromPayload(
            // Pass first, most verbose event pattern
            event: $events[0],
            payload: $attribute,
            project: $project,
        );
        Realtime::send(
            projectId: 'console',
            payload: $attribute->getArrayCopy(),
            events: $events,
            channels: $target['channels'],
            roles: $target['roles'],
            options: [
                'projectId' => $projectId,
                'databaseId' => $database->getId(),
                'collectionId' => $collection->getId()
            ]
        );
    }
}
