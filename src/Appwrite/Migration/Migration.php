<?php

namespace Appwrite\Migration;

use Exception;
use Swoole\Runtime;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

abstract class Migration
{
    /**
     * @var int
     */
    protected int $limit = 100;

    /**
     * @var Document
     */
    protected Document $project;

    /**
     * @var Database
     */
    protected Database $projectDB;

    /**
     * @var Database
     */
    protected Database $consoleDB;

    /**
     * @var \PDO
     */
    protected \PDO $pdo;

    /**
     * @var array
     */
    public static array $versions = [
        '1.0.0-RC1' => 'V15',
        '1.0.0' => 'V15',
        '1.0.1' => 'V15',
        '1.0.3' => 'V15',
        '1.1.0' => 'V16',
        '1.1.1' => 'V16',
        '1.1.2' => 'V16',
        '1.2.0' => 'V17',
        '1.2.1' => 'V17',
        '1.3.0' => 'V18',
        '1.3.1' => 'V18',
        '1.3.2' => 'V18',
        '1.3.3' => 'V18',
        '1.3.4' => 'V18',
        '1.3.5' => 'V18',
        '1.3.6' => 'V18',
        '1.3.7' => 'V18',
        '1.3.8' => 'V18',
        '1.4.0' => 'V19',
        '1.4.1' => 'V19',
        '1.4.2' => 'V19',
        '1.4.3' => 'V19',
        '1.4.4' => 'V19',
        '1.4.5' => 'V19',
        '1.4.6' => 'V19',
        '1.4.7' => 'V19',
        '1.4.8' => 'V19',
        '1.4.9' => 'V19',
        '1.4.10' => 'V19',
        '1.4.11' => 'V19',
        '1.4.12' => 'V19',
        '1.4.13' => 'V19',
        '1.5.0'  => 'V20',
        '1.5.1'  => 'V20',
        '1.5.2'  => 'V20',
        '1.5.3'  => 'V20',
        '1.5.4'  => 'V20',
        '1.5.5'  => 'V20',
        '1.5.6'  => 'V20',
        '1.5.7'  => 'V20',
        '1.5.8'  => 'V20',
        '1.5.9'  => 'V20',
        '1.5.10' => 'V20',
        '1.5.11' => 'V20',
        '1.6.0' => 'V21',
        '1.6.1' => 'V21',
        '1.6.2' => 'V22',
    ];

    /**
     * @var array
     */
    protected array $collections;

    public function __construct()
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        $this->collections = Config::getParam('collections', []);

        $projectCollections = $this->collections['projects'];

        $this->collections['projects'] = array_merge([
            '_metadata' => [
                '$id' => ID::custom('_metadata'),
                '$collection' => Database::METADATA
            ],
            'audit' => [
                '$id' => ID::custom('audit'),
                '$collection' => Database::METADATA
            ],
            'abuse' => [
                '$id' => ID::custom('abuse'),
                '$collection' => Database::METADATA
            ]
        ], $projectCollections);
    }

    /**
     * Set project for migration.
     *
     * @param Document $project
     * @param Database $projectDB
     * @param Database $oldConsoleDB
     *
     * @return self
     */
    public function setProject(Document $project, Database $projectDB, Database $consoleDB): self
    {
        $this->project = $project;
        $this->projectDB = $projectDB;
        $this->consoleDB = $consoleDB;

        return $this;
    }

    /**
     * Set PDO for Migration.
     *
     * @param \PDO $pdo
     * @return \Appwrite\Migration\Migration
     */
    public function setPDO(\PDO $pdo): self
    {
        $this->pdo = $pdo;

        return $this;
    }

    /**
     * Iterates through every document.
     *
     * @param callable $callback
     */
    public function forEachDocument(callable $callback): void
    {
        $internalProjectId = $this->project->getInternalId();

        $collections = match ($internalProjectId) {
            'console' => $this->collections['console'],
            default => $this->collections['projects'],
        };

        foreach ($collections as $collection) {
            if ($collection['$collection'] !== Database::METADATA) {
                continue;
            }

            Console::log('Migrating Collection ' . $collection['$id'] . ':');

            foreach ($this->documentsIterator($collection['$id']) as $document) {
                go(function (Document $document, callable $callback) {
                    if (empty($document->getId()) || empty($document->getCollection())) {
                        return;
                    }

                    $old = $document->getArrayCopy();
                    $new = call_user_func($callback, $document);

                    if (is_null($new) || $new->getArrayCopy() == $old) {
                        return;
                    }

                    try {
                        $this->projectDB->updateDocument($document->getCollection(), $document->getId(), $document);
                    } catch (\Throwable $th) {
                        Console::error('Failed to update document: ' . $th->getMessage());
                        return;
                    }
                }, $document, $callback);
            }
        }
    }

    /**
     * Provides an iterator for all documents on a collection.
     *
     * @param string $collectionId
     * @return iterable<Document>
     * @throws \Exception
     */
    public function documentsIterator(string $collectionId, $queries = []): iterable
    {
        $sum = 0;
        $nextDocument = null;
        $collectionCount = $this->projectDB->count($collectionId);
        $queries[] = Query::limit($this->limit);

        do {
            if ($nextDocument !== null) {
                $cursorQueryIndex = \array_search('cursorAfter', \array_map(fn (Query $query) => $query->getMethod(), $queries));

                if ($cursorQueryIndex !== false) {
                    $queries[$cursorQueryIndex] = Query::cursorAfter($nextDocument);
                } else {
                    $queries[] = Query::cursorAfter($nextDocument);
                }
            }

            $documents = $this->projectDB->find($collectionId, $queries);
            $count = count($documents);
            $sum += $count;

            Console::log($sum . ' / ' . $collectionCount);
            foreach ($documents as $document) {
                yield $document;
            }

            if ($count !== $this->limit) {
                $nextDocument = null;
            } else {
                $nextDocument = end($documents);
            }
        } while (!is_null($nextDocument));
    }

    /**
     * Creates collection from the config collection.
     *
     * @param string $id
     * @param string|null $name
     * @return void
     * @throws \Throwable
     */
    protected function createCollection(string $id, string $name = null): void
    {
        $name ??= $id;

        $collectionType = match ($this->project->getInternalId()) {
            'console' => 'console',
            default => 'projects',
        };

        if (!$this->projectDB->exists(System::getEnv('_APP_DB_SCHEMA', 'appwrite'), $name)) {
            $attributes = [];
            $indexes = [];
            $collection = $this->collections[$collectionType][$id];

            foreach ($collection['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => $attribute['$id'],
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'default' => $attribute['default'] ?? null,
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                ]);
            }

            foreach ($collection['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => $index['$id'],
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }

            try {
                $this->projectDB->createCollection($name, $attributes, $indexes);
            } catch (\Throwable $th) {
                throw $th;
            }
        }
    }

    /**
     * Creates attribute from collections.php
     *
     * @param \Utopia\Database\Database $database
     * @param string $collectionId
     * @param string $attributeId
     * @return void
     * @throws \Exception
     * @throws \Utopia\Database\Exception\Duplicate
     * @throws \Utopia\Database\Exception\Limit
     */
    public function createAttributeFromCollection(Database $database, string $collectionId, string $attributeId, string $from = null): void
    {
        $from ??= $collectionId;

        $collectionType = match ($this->project->getInternalId()) {
            'console' => 'console',
            default => 'projects',
        };

        if ($from === 'files') {
            $collectionType = 'buckets';
        }

        $collection = $this->collections[$collectionType][$from] ?? null;

        if (is_null($collection)) {
            throw new Exception("Collection {$from} not found");
        }

        $attributes = $collection['attributes'];

        $attributeKey = array_search($attributeId, array_column($attributes, '$id'));

        if ($attributeKey === false) {
            throw new Exception("Attribute {$attributeId} not found");
        }

        $attribute = $attributes[$attributeKey];
        $filters = $attribute['filters'] ?? [];
        $default = $attribute['default'] ?? null;

        $database->createAttribute(
            collection: $collectionId,
            id: $attributeId,
            type: $attribute['type'],
            size: $attribute['size'],
            required: $attribute['required'] ?? false,
            default: in_array('json', $filters) ? json_encode($default) : $default,
            signed: $attribute['signed'] ?? false,
            array: $attribute['array'] ?? false,
            format: $attribute['format'] ?? '',
            formatOptions: $attribute['formatOptions'] ?? [],
            filters: $filters,
        );
    }

    /**
     * Creates index from collections.php
     *
     * @param \Utopia\Database\Database $database
     * @param string $collectionId
     * @param string $indexId
     * @param string|null $from
     * @return void
     * @throws \Exception
     * @throws \Utopia\Database\Exception\Duplicate
     * @throws \Utopia\Database\Exception\Limit
     */
    public function createIndexFromCollection(Database $database, string $collectionId, string $indexId, string $from = null): void
    {
        $from ??= $collectionId;

        $collectionType = match ($this->project->getInternalId()) {
            'console' => 'console',
            default => 'projects',
        };

        $collection = $this->collections[$collectionType][$from] ?? null;

        if (is_null($collection)) {
            throw new Exception("Collection {$collectionId} not found");
        }

        $indexes = $collection['indexes'];

        $indexKey = array_search($indexId, array_column($indexes, '$id'));

        if ($indexKey === false) {
            throw new Exception("Index {$indexId} not found");
        }

        $index = $indexes[$indexKey];

        $database->createIndex(
            collection: $collectionId,
            id: $indexId,
            type: $index['type'],
            attributes: $index['attributes'],
            lengths: $index['lengths'] ?? [],
            orders: $index['orders'] ?? []
        );
    }

    /**
     * Change a collection attribute's internal type
     *
     * @param string $collection
     * @param string $attribute
     * @param string $type
     * @return void
     */
    protected function changeAttributeInternalType(string $collection, string $attribute, string $type): void
    {
        $stmt = $this->pdo->prepare("ALTER TABLE `{$this->projectDB->getDatabase()}`.`_{$this->project->getInternalId()}_{$collection}` MODIFY `$attribute` $type;");

        try {
            $stmt->execute();
        } catch (\Throwable $e) {
            Console::warning($e->getMessage());
        }
    }

    /**
     * Executes migration for set project.
     */
    abstract public function execute(): void;
}
