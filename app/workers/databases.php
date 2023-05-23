<?php

use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Messaging\Adapter\Realtime;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Queue\Server;

use function Swoole\Coroutine\Http\get;

require_once __DIR__ . '/../worker.php';

Authorization::disable();
Authorization::setDefaultStatus(false);

const DATABASE_PROJECT = 'project';
const DATABASE_CONSOLE = 'console';

/**
 * @param Document $database
 * @param Document $collection
 * @param Document $attribute
 * @param Document $project
 */
Server::setResource('createDBAttribute', function (Database $dbForConsole, Database $dbForProject) {
    return function (
        Document $project,
        Document $database,
        Document $collection,
        Document $attribute,
    ) use (
        $dbForConsole,
        $dbForProject
) {

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].attributes.[attributeId].update', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);

        /**
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

        try {
            if (!$dbForProject->createAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key, $type, $size, $required, $default, $signed, $array, $format, $formatOptions, $filters)) {
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
                    'projectId' => $project->getId(),
                    'databaseId' => $database->getId(),
                    'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
    };
}, ['dbForConsole, dbForProject']);

/**
 * @param Document $database
 * @param Document $collection
 * @param Document $attribute
 * @param Document $project
 * @throws \Utopia\Database\Exception\Authorization
 */
Server::setResource('deleteDBAttribute', function (Database $dbForConsole, Database $dbForProject) {
    return function (
        Document $project,
        Document $database,
        Document $collection,
        Document $attribute,
    ) use (
        $dbForConsole,
        $dbForProject
    ) {
        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].attributes.[attributeId].delete', [
        'databaseId' => $database->getId(),
        'collectionId' => $collection->getId(),
        'attributeId' => $attribute->getId()
        ]);
        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $status = $attribute->getAttribute('status', '');

    // possible states at this point:
    // - available: should not land in queue; controller flips these to 'deleting'
    // - processing: hasn't finished creating
    // - deleting: was available, in deletion queue for first time
    // - failed: attribute was never created
    // - stuck: attribute was available but cannot be removed
        try {
            if ($status !== 'failed' && !$dbForProject->deleteAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                throw new Exception('Failed to delete Attribute');
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
                    'projectId' => $project->getId(),
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
                        deleteIndex($database, $collection, $index, $project, $dbForConsole);
                    } else {
                        $dbForProject->updateDocument('indexes', $index->getId(), $index);
                    }
                }
            }
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
        $dbForProject->deleteCachedCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());
    };
}, ['dbForConsole, dbForProject']);

/**
 * @param Document $database
 * @param Document $collection
 * @param Document $index
 * @param Document $project
 */
Server::setResource('createIndex', function (Database $dbForConsole, Database $dbForProject) {
    return function (
        Document $project,
        Document $database,
        Document $collection,
        Document $index,
    ) use (
        $dbForConsole,
        $dbForProject
    ) {
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
                    'projectId' => $project->getId(),
                    'databaseId' => $database->getId(),
                    'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
    };
}, ['dbForConsole, dbForProject']);

/**
 * @param Document $database
 * @param Document $collection
 * @param Document $index
 * @param Database $dbForConsole
 * @param Document $project
 */
Server::setResource('deleteIndex', function (Database $dbForConsole, Database $dbForProject) {
    return function (
        Document $project,
        Document $database,
        Document $collection,
        Document $index,
    ) use (
        $dbForConsole,
        $dbForProject
    ) {

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].indexes.[indexId].delete', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'indexId' => $index->getId()
        ]);
        $key = $index->getAttribute('key');
        $status = $index->getAttribute('status', '');

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
                'projectId' => $project->getId(),
                'databaseId' => $database->getId(),
                'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collection->getId());
    };
}, ['dbForConsole, dbForProject']);

$server->job()
    ->inject('message')
    ->inject('createDBAttribute')
    ->inject('deleteDBAttribute')
    ->inject('createIndex')
    ->inject('deleteIndex')
    ->action(function (Message $message, callable $createDBAttribute, callable $deleteDBAttribute, callable $createIndex, callable $deleteIndex) {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type = $payload['type'];
        $project = new Document($payload['project']);
        $collection = new Document($payload['collection'] ?? []);
        $document = new Document($payload['document'] ?? []);
        $database = new Document($payload['database'] ?? []);

        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }

        if ($document->isEmpty()) {
            throw new Exception('Missing document');
        }

        match (strval($type)) {
            DATABASE_TYPE_CREATE_ATTRIBUTE => $createDBAttribute($database, $collection, $document, $project),
            DATABASE_TYPE_DELETE_ATTRIBUTE => $deleteDBAttribute($database, $collection, $document, $project),
            DATABASE_TYPE_CREATE_INDEX => $createIndex($database, $collection, $document, $project),
            DATABASE_TYPE_DELETE_INDEX => $deleteIndex($database, $collection, $document, $project),
            default => Console::error('No database operation for type: ' . $type),
        };
    });

$server->workerStart();
$server->start();
