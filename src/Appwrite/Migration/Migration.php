<?php

namespace Appwrite\Migration;

use Exception;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Helpers\ID;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

abstract class Migration
{
    protected int $limit = 100;

    protected Document $project;

    protected Database $dbForProject;

    protected Database $dbForPlatform;

    protected PDO $pdo;

    /**
     * @var array<string, string>
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
        '1.7.0' => 'V23',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $collections;

    public function __construct()
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        $this->collections = Config::getParam('collections', []);

        $this->collections['projects'][] = [
            '_metadata' => [
                '$id' => ID::custom('_metadata'),
                '$collection' => Database::METADATA
            ]
        ];

        $this->collections['projects'][] = [
            'audit' => [
                '$id' => ID::custom('audit'),
                '$collection' => Database::METADATA
            ],
        ];
    }

    /**
     * Set project for migration.
     *
     * @param Document $project
     * @param Database $dbForProject
     * @param Database $dbForPlatform
     * @return self
     */
    public function setProject(Document $project, Database $dbForProject, Database $dbForPlatform): self
    {
        $this->project = $project;
        $this->dbForProject = $dbForProject;
        $this->dbForPlatform = $dbForPlatform;

        return $this;
    }

    /**
     * Set PDO for Migration.
     *
     * @param PDO $pdo
     * @return Migration
     */
    public function setPDO(PDO $pdo): self
    {
        $this->pdo = $pdo;

        return $this;
    }

    /**
     * Iterates through every document.
     *
     * @param callable $callback
     * @throws Exception
     */
    public function forEachDocument(callable $callback): void
    {
        $projectInternalId = $this->project->getInternalId();

        $collections = match ($projectInternalId) {
            'console' => $this->collections['console'],
            default => $this->collections['projects'],
        };

        foreach ($collections as $collection) {
            // Only migrate top-level collections
            if ($collection['$collection'] !== Database::METADATA) {
                continue;
            }

            Console::log('Migrating collection ' . $collection['$id'] . '...');

            $this->dbForProject->foreach($collection['$id'], function(Document $document) use ($collection, $callback) {
                if (empty($document->getId()) || empty($document->getCollection())) {
                    return;
                }

                $old = $document->getArrayCopy();
                $new = $callback($document);

                if ($new === null || $new->getArrayCopy() == $old) {
                    return;
                }

                try {
                    $this->dbForProject->updateDocument(
                        $document->getCollection(),
                        $document->getId(),
                        $document
                    );
                } catch (\Throwable $th) {
                    Console::error("Failed to update document \"{$document->getId()}\" in collection \"{$collection['$id']}\":" . $th->getMessage());
                    return;
                }
            });
        }
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

        if (!$this->dbForProject->getCollection($id)->isEmpty()) {
            $attributes = [];
            $indexes = [];
            $collection = $this->collections[$collectionType][$id];
            foreach ($collection['attributes'] as $attribute) {
                $attributes[] = new Document($attribute);
            }
            foreach ($collection['indexes'] as $index) {
                $indexes[] = new Document($index);
            }

            $this->dbForProject->createCollection($name, $attributes, $indexes);
        }
    }

    /**
     * Creates attributes from collections.php
     *
     * @param Database $database
     * @param string $collectionId
     * @param array $attributeIds
     * @param string|null $from
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Authorization
     * @throws Conflict
     * @throws Duplicate
     * @throws Limit
     * @throws Structure
     */
    public function createAttributesFromCollection(
        Database $database,
        string $collectionId,
        array $attributeIds,
        string $from = null
    ): void
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

        if ($collection === null) {
            throw new Exception("Collection {$from} not found");
        }

        $attributes = [];
        foreach ($attributeIds as $attributeId) {
            $attribute = $collection['attributes'][$attributeId] ?? null;

            if ($attribute === null) {
                throw new Exception("Attribute {$attributeId} not found");
            }

            $attribute['filters'] ??= [];
            $attribute['default'] ??= null;
            $attribute['default'] = \in_array('json', $attribute['filters'])
                ? \json_encode($attribute['default'])
                : $attribute['default'];

            $attributes[] = $attribute;
        }

        $database->createAttributes(
            collection: $collectionId,
            attributes: $attributes,
        );
    }

    /**
     * Creates attribute from collections.php
     *
     * @param Database $database
     * @param string $collectionId
     * @param string $attributeId
     * @param string|null $from
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws \Utopia\Database\Exception\Authorization
     * @throws Conflict
     * @throws Duplicate
     * @throws Limit
     * @throws Structure
     */
    public function createAttributeFromCollection(
        Database $database,
        string $collectionId,
        string $attributeId,
        string $from = null
    ): void
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

        if ($collection === null) {
            throw new Exception("Collection {$from} not found");
        }

        $attributes = $collection['attributes'];

        $attributeKey = \array_search($attributeId, \array_column($attributes, '$id'));

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
            required: $attribute['required'],
            default: \in_array('json', $filters) ? \json_encode($default) : $default,
            signed: $attribute['signed'] ?? true,
            array: $attribute['array'] ?? false,
            format: $attribute['format'] ?? '',
            formatOptions: $attribute['formatOptions'] ?? [],
            filters: $filters,
        );
    }

    /**
     * Creates index from collections.php
     *
     * @param Database $database
     * @param string $collectionId
     * @param string $indexId
     * @param string|null $from
     * @return void
     * @throws \Exception
     * @throws Duplicate
     * @throws Limit
     */
    public function createIndexFromCollection(Database $database, string $collectionId, string $indexId, string $from = null): void
    {
        $from ??= $collectionId;

        $collectionType = match ($this->project->getInternalId()) {
            'console' => 'console',
            default => 'projects',
        };

        $collection = $this->collections[$collectionType][$from] ?? null;

        if ($collection === null) {
            throw new Exception("Collection {$collectionId} not found");
        }

        $indexes = $collection['indexes'];

        $indexKey = \array_search($indexId, \array_column($indexes, '$id'));

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
     * @throws \Utopia\Database\Exception
     */
    protected function changeAttributeInternalType(string $collection, string $attribute, string $type): void
    {
        $stmt = $this->pdo->prepare("ALTER TABLE `{$this->dbForProject->getDatabase()}`.`_{$this->project->getInternalId()}_{$collection}` MODIFY `$attribute` $type;");

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
