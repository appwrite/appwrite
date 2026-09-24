<?php

namespace Tests\Integration;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;

class MongoDBClient
{
    private Database $database;

    public function __construct(
        string $uri = 'mongodb://localhost:27017',
        string $database = 'query_test',
    ) {
        $client = new Client($uri);
        $this->database = $client->selectDatabase($database);
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function execute(string $queryJson, array $bindings = []): array
    {
        $decoded = \json_decode($queryJson, false, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $op */
        $op = $this->objectToArray($decoded);

        $op = $this->replaceBindings($op, $bindings);

        /** @var string $collectionName */
        $collectionName = $op['collection'];
        $collection = $this->database->selectCollection($collectionName);

        /** @var string $operation */
        $operation = $op['operation'] ?? 'null';

        return match ($operation) {
            'find' => $this->executeFind($collection, $op),
            'aggregate' => $this->executeAggregate($collection, $op),
            'insertMany' => $this->executeInsertMany($collection, $op),
            'updateMany' => $this->executeUpdateMany($collection, $op),
            'updateOne' => $this->executeUpdateOne($collection, $op),
            'deleteMany' => $this->executeDeleteMany($collection, $op),
            default => throw new \RuntimeException('Unknown MongoDB operation: ' . $operation),
        };
    }

    public function command(string $commandJson): void
    {
        /** @var array<string, mixed> $op */
        $op = \json_decode($commandJson, true, 512, JSON_THROW_ON_ERROR);
        /** @var string $command */
        $command = $op['command'] ?? '';

        /** @var string $collectionName */
        $collectionName = $op['collection'] ?? '';
        /** @var string $indexName */
        $indexName = $op['index'] ?? '';
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'] ?? [];
        /** @var array<string, mixed> $options */
        $options = $op['options'] ?? [];

        if (isset($op['validator']) && ! isset($options['validator'])) {
            $options['validator'] = $op['validator'];
        }

        match ($command) {
            'createCollection' => $this->database->createCollection($collectionName, $options),
            'drop' => $this->dropCollection($collectionName),
            'createIndex' => $this->createIndex($op),
            'dropIndex' => $this->database->selectCollection($collectionName)->dropIndex($indexName),
            'deleteMany' => $this->database->selectCollection($collectionName)->deleteMany($filter),
            'collMod' => $this->collMod($collectionName, $op),
            'renameCollection' => $this->renameCollection($op),
            default => throw new \RuntimeException('Unknown MongoDB command: ' . $command),
        };
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function collMod(string $collection, array $op): void
    {
        $command = ['collMod' => $collection];
        if (isset($op['validator'])) {
            $command['validator'] = $op['validator'];
        }
        $this->database->command($command);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    private function renameCollection(array $op): void
    {
        /** @var string $from */
        $from = $op['from'] ?? '';
        /** @var string $to */
        $to = $op['to'] ?? '';

        $databaseName = $this->database->getDatabaseName();

        $this->database->getManager()->executeCommand('admin', new \MongoDB\Driver\Command([
            'renameCollection' => $databaseName . '.' . $from,
            'to' => $databaseName . '.' . $to,
        ]));
    }

    public function dropCollection(string $name): void
    {
        $this->database->dropCollection($name);
    }

    /**
     * @return list<string>
     */
    public function listCollectionNames(): array
    {
        $names = [];
        foreach ($this->database->listCollectionNames() as $name) {
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function listIndexNames(string $collection): array
    {
        $names = [];
        foreach ($this->database->selectCollection($collection)->listIndexes() as $index) {
            $names[] = $index->getName();
        }

        return $names;
    }

    /**
     * @return array<string, int>
     */
    public function getIndexKey(string $collection, string $name): array
    {
        foreach ($this->database->selectCollection($collection)->listIndexes() as $index) {
            if ($index->getName() === $name) {
                /** @var array<string, int> $key */
                $key = $index->getKey();

                return $key;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $document
     */
    public function insertOne(string $collection, array $document): void
    {
        $this->database->selectCollection($collection)->insertOne($document);
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function insertMany(string $collection, array $documents): void
    {
        $this->database->selectCollection($collection)->insertMany($documents);
    }

    /**
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function executeFind(Collection $collection, array $op): array
    {
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'] ?? [];
        $options = [];

        if (isset($op['projection'])) {
            $options['projection'] = $op['projection'];
        }
        if (isset($op['sort'])) {
            $options['sort'] = $op['sort'];
        }
        if (isset($op['skip'])) {
            $options['skip'] = $op['skip'];
        }
        if (isset($op['limit'])) {
            $options['limit'] = $op['limit'];
        }

        $cursor = $collection->find($filter, $options);
        $rows = [];
        foreach ($cursor as $doc) {
            /** @var array<string, mixed> $arr */
            $arr = (array) $doc;
            unset($arr['_id']);
            $rows[] = $arr;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function executeAggregate(Collection $collection, array $op): array
    {
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'] ?? [];
        $cursor = $collection->aggregate($pipeline);
        $rows = [];
        foreach ($cursor as $doc) {
            /** @var array<string, mixed> $arr */
            $arr = (array) $doc;
            unset($arr['_id']);
            $rows[] = $arr;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function executeInsertMany(Collection $collection, array $op): array
    {
        /** @var list<array<string, mixed>> $documents */
        $documents = $op['documents'] ?? [];
        /** @var array<string, mixed> $options */
        $options = $op['options'] ?? [];
        $collection->insertMany($documents, $options);

        return [];
    }

    /**
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function executeUpdateMany(Collection $collection, array $op): array
    {
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'] ?? [];
        /** @var array<string, mixed> $update */
        $update = $op['update'] ?? [];
        /** @var array<string, mixed> $options */
        $options = $op['options'] ?? [];
        $collection->updateMany($filter, $update, $options);

        return [];
    }

    /**
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function executeUpdateOne(Collection $collection, array $op): array
    {
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'] ?? [];
        /** @var array<string, mixed> $update */
        $update = $op['update'] ?? [];
        /** @var array<string, mixed> $options */
        $options = $op['options'] ?? [];
        $collection->updateOne($filter, $update, $options);

        return [];
    }

    /**
     * @param array<string, mixed> $op
     * @return list<array<string, mixed>>
     */
    private function executeDeleteMany(Collection $collection, array $op): array
    {
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'] ?? [];
        $collection->deleteMany($filter);

        return [];
    }

    /**
     * @param array<string, mixed> $op
     */
    private function createIndex(array $op): void
    {
        /** @var array<string, mixed> $index */
        $index = $op['index'];
        /** @var array<string, int> $keys */
        $keys = $index['key'];
        /** @var string $name */
        $name = $index['name'];
        $options = ['name' => $name];
        if (! empty($index['unique'])) {
            $options['unique'] = true;
        }

        /** @var string $collectionName */
        $collectionName = $op['collection'];
        $this->database->selectCollection($collectionName)->createIndex($keys, $options);
    }

    /**
     * Recursively replace "?" string values with binding values.
     *
     * @param array<string, mixed> $data
     * @param list<mixed> $bindings
     * @return array<string, mixed>
     */
    private function replaceBindings(array $data, array $bindings): array
    {
        $index = 0;

        /** @var array<string, mixed> */
        return $this->walkAndReplace($data, $bindings, $index);
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<mixed> $bindings
     * @return array<int|string, mixed>
     */
    private function walkAndReplace(array $data, array $bindings, int &$index): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($value) && $value === '?') {
                $data[$key] = $bindings[$index] ?? null;
                $index++;
            } elseif (\is_array($value)) {
                $data[$key] = $this->walkAndReplace($value, $bindings, $index);
            }
        }

        return $data;
    }

    /**
     * Recursively convert stdClass objects to associative arrays while
     * preserving empty objects as stdClass so MongoDB encodes them as BSON
     * documents (not BSON arrays).
     */
    private function objectToArray(mixed $data): mixed
    {
        if ($data instanceof \stdClass) {
            $vars = \get_object_vars($data);
            if ($vars === []) {
                return $data;
            }
            $out = [];
            foreach ($vars as $key => $value) {
                $out[$key] = $this->objectToArray($value);
            }

            return $out;
        }

        if (\is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                $out[$key] = $this->objectToArray($value);
            }

            return $out;
        }

        return $data;
    }
}
