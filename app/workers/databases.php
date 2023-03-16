<?php

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;

require_once __DIR__ . '/../init.php';

Console::title('Database V1 Worker');
Console::success(APP_NAME . ' database worker v1 has started' . "\n");

class DatabaseV1 extends Worker
{
    public function init(): void
    {
    }

    public function run(): void
    {
        $type = $this->args['type'];
        $project = new Document($this->args['project']);
        $collection = new Document($this->args['collection'] ?? []);
        $document = new Document($this->args['document'] ?? []);
        $database = new Document($this->args['database'] ?? []);

        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }

        if ($document->isEmpty()) {
            throw new Exception('Missing document');
        }

        switch (strval($type)) {
            case DATABASE_TYPE_CREATE_ATTRIBUTE:
                $this->createAttribute($database, $collection, $document, $project->getId());
                break;
            case DATABASE_TYPE_DELETE_ATTRIBUTE:
                $this->deleteAttribute($database, $collection, $document, $project->getId());
                break;
            case DATABASE_TYPE_CREATE_INDEX:
                $this->createIndex($database, $collection, $document, $project->getId());
                break;
            case DATABASE_TYPE_DELETE_INDEX:
                $this->deleteIndex($database, $collection, $document, $project->getId());
                break;

            default:
                Console::error('No database operation for type: ' . $type);
                break;
        }
    }

    public function shutdown(): void
    {
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
     * @param string $projectId
     */
    protected function createAttribute(Document $database, Document $collection, Document $attribute, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].attributes.[attributeId].update', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);
        /**x
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
            if ($type === Database::VAR_RELATIONSHIP) {
                $relatedCollection = $dbForProject->getDocument('database_' . $database->getInternalId(), $options['relatedCollection']);
                if (
                    !$dbForProject->createRelationship(
                        collection: 'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(),
                        relatedCollection: 'database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId(),
                        type: $options['relationType'] || null,
                        twoWay: $options['twoWay'] || null,
                        id: $options['id'] || null,
                        twoWayKey: $options['twoWayKey'] || null,
                        onUpdate: $options['onUpdate'] || null,
                        onDelete: $options['onDelete'] || null,
                    )
                ) {
                    throw new Exception('Failed to create Attribute');
                }
            } elseif (!$dbForProject->createAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key, $type, $size, $required, $default, $signed, $array, $format, $formatOptions, $filters)) {
                throw new Exception('Failed to create Attribute');
            }

            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'available'));
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'failed'));
        } finally {
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

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
     * @param string $projectId
     */
    protected function deleteAttribute(Document $database, Document $collection, Document $attribute, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

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

        // possible states at this point:
        // - available: should not land in queue; controller flips these to 'deleting'
        // - processing: hasn't finished creating
        // - deleting: was available, in deletion queue for first time
        // - failed: attribute was never created
        // - stuck: attribute was available but cannot be removed

        try {
            if ($status !== 'failed') {
                if ($type === Database::VAR_RELATIONSHIP) {
                    if (!$dbForProject->deleteRelationship('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                        throw new Exception('Failed to delete Attribute');
                    }
                } elseif (!$dbForProject->deleteAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                    throw new Exception('Failed to delete Attribute');
                }
            }
            $dbForProject->deleteDocument('attributes', $attribute->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'stuck'));
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $attribute,
                project: $project
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
                $lengths = \array_values(\array_diff($lengths, [$lengths[$found]]));
                $orders = \array_values(\array_diff($orders, [$orders[$found]]));

                if (empty($attributes)) {
                    $dbForProject->deleteDocument('indexes', $index->getId());
                } else {
                    $index
                        ->setAttribute('attributes', $attributes, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('lengths', $lengths, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('orders', $orders, Document::SET_TYPE_ASSIGN)
                    ;

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
                        $this->deleteIndex($database, $collection, $index, $projectId);
                    } else {
                        $dbForProject->updateDocument('indexes', $index->getId(), $index);
                    }
                }
            }
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
        $dbForProject->deleteCachedCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function createIndex(Document $database, Document $collection, Document $index, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

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
                throw new Exception('Failed to create Index');
            }
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'available'));
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'failed'));
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $index,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $index->getArrayCopy(),
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

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function deleteIndex(Document $database, Document $collection, Document $index, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

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
                throw new Exception('Failed to delete index');
            }
            $dbForProject->deleteDocument('indexes', $index->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'stuck'));
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $index,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $index->getArrayCopy(),
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

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collection->getId());
    }
}
