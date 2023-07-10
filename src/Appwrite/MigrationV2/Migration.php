<?php

namespace Appwrite\MigrationV2;

use Swoole\Runtime;
use Utopia\Database\Document;
use Utopia\Database\Database;
use Utopia\CLI\Console;
use Exception;
use Utopia\Database\ID;
use Utopia\Database\Query;


Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// TODO: Create seed project for testing
// TODO: database filters for managing attributes between migration
/// TODO: If update attribute only contains change in required, no need to create temporary table

class Migration
{

    protected const TYPE_ATTRIBUTE_CREATED = 'attribute_created';
    protected const TYPE_ATTRIBUTE_UPDATED = 'attribute_updated';
    protected const TYPE_ATTRIBUTE_DELETED = 'attribute_deleted';

    protected const TYPE_COLLECTION_CREATED = 'collection_created';
    protected const TYPE_COLLECTION_DELETED = 'collection_deleted';

    protected const MODE_BEFORE = 'before';
    protected const MODE_AFTER = 'after';

    protected array $differences = [];

    protected array $executedActions = [];

    protected string $mode = self::MODE_BEFORE;

    protected int $limit = 100;

    protected Document $project;
    protected Database $projectDB;
    protected Database $consoleDB;

    public function __construct(string $from, string $to)
    {
        $schema1 = $this->loadSchema(__DIR__ . "/../../../app/config/collections/{$from}.php");
        $schema2 = $this->loadSchema(__DIR__ . "/../../../app/config/collections/{$to}.php");

        $this->differences = $this->compareSchemas($schema1, $schema2);
    }


    protected function loadSchema(string $path): array
    {
        $path = realpath($path);
        if (!file_exists($path)) {
            throw new Exception("Schema file not found: " . $path);
        }
        return include $path;
    }

    protected function compareSchemas(array $schema1, array $schema2): array
    {
        $differences = [];

        // Compare collections
        foreach ($schema1 as $key => $collection) {
            if (!isset($schema2[$key])) {
                $differences[] = [
                    'type' => self::TYPE_COLLECTION_DELETED,
                    'collection' => $key
                ];
                continue;
            }

            $collection2 = $schema2[$key];

            // Compare attributes
            foreach ($collection['attributes'] as $attribute) {
                $id = $attribute['$id'];
                $found = false;
                foreach ($collection2['attributes'] as $attribute2) {
                    if ($attribute2['$id'] === $id) {
                        $found = true;
                        if ($attribute != $attribute2) {
                            $differences[] = [
                                'type' => self::TYPE_ATTRIBUTE_UPDATED,
                                'collection' => $key,
                                'attribute' => $id,
                                'old' => $attribute,
                                'new' => $attribute2
                            ];
                        }
                        break;
                    }
                }

                if (!$found) {
                    $differences[] = [
                        'type' => self::TYPE_ATTRIBUTE_DELETED,
                        'collection' => $key,
                        'attribute' => $id,
                        'old' => $attribute
                    ];
                }
            }

            foreach ($collection2['attributes'] as $attribute2) {
                $id = $attribute2['$id'];
                $found = false;
                foreach ($collection['attributes'] as $attribute) {
                    if ($attribute['$id'] === $id) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $differences[] = [
                        'type' => self::TYPE_ATTRIBUTE_CREATED,
                        'collection' => $key,
                        'attribute' => $id,
                        'new' => $attribute2
                    ];
                }
            }
        }

        // Check for added collections
        foreach ($schema2 as $key => $collection) {
            if (!isset($schema1[$key])) {
                $differences[] = [
                    'type' => self::TYPE_COLLECTION_CREATED,
                    'collection' => $collection,
                ];
            }
        }

        usort($differences, function ($a, $b) {
            return $a['type'] < $b['type'] ? -1 : 1;
        });

        return $differences;
    }

    public function setProject(Document $project, Database $projectDB, Database $consoleDB): self
    {
        $this->project = $project;
        $this->projectDB = $projectDB;
        $this->consoleDB = $consoleDB;

        return $this;
    }

    public function confirm(): bool
    {
        if ($this->mode === self::MODE_AFTER) {
            $attributesRemoved = array_filter($this->differences, fn ($difference) => $difference['type'] === self::TYPE_ATTRIBUTE_DELETED);
            if (count($attributesRemoved)) Console::success("The following attributes will be deleted");
            foreach ($attributesRemoved as $attribute) {
                Console::log("  {$attribute['attribute']} in collection {$attribute['collection']}");
            }

            $collectionsRemoved = array_filter($this->differences, fn ($difference) => $difference['type'] === self::TYPE_COLLECTION_DELETED);
            if (count($collectionsRemoved)) Console::success("The following collections will be removed");
            foreach ($collectionsRemoved as $collection) {
                Console::log("  {$collection['collection']}");
            }
        } else if ($this->mode == self::MODE_BEFORE) {
            $attributesAdded = array_filter($this->differences, fn ($difference) => $difference['type'] === self::TYPE_ATTRIBUTE_CREATED);
            if (count($attributesAdded)) Console::success("The following attributes will be added");
            foreach ($attributesAdded as $attribute) {
                Console::log("  {$attribute['attribute']} in collection {$attribute['collection']}");
            }

            $attributesUpdated = array_filter($this->differences, fn ($difference) => $difference['type'] === self::TYPE_ATTRIBUTE_UPDATED);
            if (count($attributesUpdated)) Console::success("The following attributes will be updated");
            foreach ($attributesUpdated as $attribute) {
                Console::log("  {$attribute['attribute']} in collection {$attribute['collection']}");
            }

            $collectionsAdded = array_filter($this->differences, fn ($difference) => $difference['type'] === self::TYPE_COLLECTION_CREATED);
            if (count($collectionsAdded)) Console::success("The following collections will be added");
            foreach ($collectionsAdded as $collection) {
                Console::log("  {$collection['collection']['name']}");
            }
        }

        $response = Console::confirm("Are you sure you want to continue? (YES/NO)");
        if ($response !== 'YES') {
            Console::warning("Migration aborted");
            return false;
        }
        return true;
    }

    public function execute()
    {
        try {
            foreach ($this->differences as $difference) {
                switch ($difference['type']) {
                    case self::TYPE_ATTRIBUTE_CREATED:
                        $this->createAttribute($difference['collection'], $difference['new']);
                        break;
                    case self::TYPE_ATTRIBUTE_UPDATED:
                        $this->updateAttribute($difference['collection'], $difference['old'], $difference['new']);
                        break;
                    case self::TYPE_ATTRIBUTE_DELETED:
                        $this->deleteAttribute($difference['collection'], $difference['old']);
                        break;
                    case self::TYPE_COLLECTION_CREATED:
                        $this->createCollection($difference['collection']);
                        break;
                    case self::TYPE_COLLECTION_DELETED:
                        $this->deleteCollection($difference['collection']);
                        break;
                }
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
        }
    }

    public function createFilters()
    {
        $attributesUpdated = array_filter($this->differences, fn ($difference) => $difference['type'] === self::TYPE_ATTRIBUTE_UPDATED);
        foreach ($attributesUpdated as $attribute) {
            $oldAttribute = $attribute['old'];
            $tempAttributeId = $oldAttribute['$id'] . '_temp';

            $filter = "sync-{$oldAttribute['$id']}-{$tempAttributeId}";
            var_dump("Creating filter $filter");

            Database::addFilter($filter, function (mixed $value, Document $document) use ($oldAttribute) {
                return $document->getAttribute($oldAttribute['$id']);
            }, function (mixed $value) {
                return null;
            });
        }
    }

    protected function createCollection(array $collection): void
    {
        try {
            if ($this->mode == self::MODE_BEFORE) {
                $id = $collection['$id'];

                foreach ($collection['attributes'] as $attribute) {
                    $attributes[] = new Document([
                        '$id' => ID::custom($attribute['$id']),
                        'type' => $attribute['type'],
                        'size' => $attribute['size'],
                        'required' => $attribute['required'],
                        'signed' => $attribute['signed'],
                        'array' => $attribute['array'],
                        'filters' => $attribute['filters'],
                        'default' => $attribute['default'] ?? null,
                        'format' => $attribute['format'] ?? ''
                    ]);
                }

                foreach ($collection['indexes'] as $index) {
                    $indexes[] = new Document([
                        '$id' => ID::custom($index['$id']),
                        'type' => $index['type'],
                        'attributes' => $index['attributes'],
                        'lengths' => $index['lengths'],
                        'orders' => $index['orders'],
                    ]);
                }

                $this->projectDB->createCollection($id, $attributes, $indexes);

                $this->executedActions[] = [
                    'type' => 'collection_added',
                    'collection' => $collection
                ];

                Console::success("Added collection: " . $id);
            } else {
                Console::warning("Skipping addition of collection: '{$collection}' in migrate mode: '{$this->mode}'");
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
        }
    }

    protected function deleteCollection(array $collection): void
    {
        Console::log("Deleting collection: " . $collection);
        try {
            if ($this->mode == self::MODE_AFTER) {
                $this->projectDB->deleteCollection($collection['$id']);

                $this->executedActions[] = [
                    'type' => 'collection_deleted',
                    'collection' => $collection
                ];

                Console::success("Deleted collection: " . $collection);
            } else {
                Console::warning("Skipping deletion of collection: '{$collection}' in migrate mode: '{$this->mode}'");
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
        }
    }

    protected function createAttribute(string $collection, array $attribute): void
    {
        Console::log("Adding attribute: " . $attribute['$id'] . " in collection: " . $collection);

        try {
            if ($this->mode == self::MODE_BEFORE) {
                $this->projectDB->createAttribute(
                    $collection,
                    $attribute['$id'],
                    $attribute['type'],
                    $attribute['size'],
                    false, // Required attributes need to be marked false since the old application is unaware of them. After application upgrade this will be updated if required
                    $attribute['default'],
                    $attribute['signed'],
                    $attribute['array'],
                    $attribute['format'],
                    $attribute['format_options'] ?? []
                );

                $this->executedActions[] = [
                    'type' => 'attribute_added',
                    'collection' => $collection,
                    'attribute' => $attribute
                ];

                Console::success("Added attribute: " . $attribute['$id']);
            } else {
                Console::warning("Skipping addition of attribute: '{$attribute['$id']}' in migrate mode: '{$this->mode}'");

                // Update the required property of the attribute to true if it was originally supposed to be required
                if ($attribute['required']) {
                    $this->projectDB->updateAttributeRequired(
                        $collection,
                        $attribute['$id'],
                        true
                    );
                }
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
        }
    }

    protected function updateAttribute(string $collection, array $oldAttribute, array $newAttribute): void
    {
        Console::log("Updating attribute: " . $oldAttribute['$id'] . " in collection: " . $collection);
        // TODO consider case when name of the attribute is changed.
        try {
            if ($this->mode == self::MODE_BEFORE) {
                $tempAttributeId = $oldAttribute['$id'] . '_temp';

                $this->projectDB->createAttribute(
                    $collection,
                    $tempAttributeId,
                    $newAttribute['type'],
                    $newAttribute['size'],
                    false, // Required attributes need to be marked false. After migration this will be updated to true
                    $newAttribute['default'],
                    $newAttribute['signed'],
                    $newAttribute['array'],
                    $newAttribute['format'],
                    $newAttribute['formatOptions'] ?? [],
                    $newAttribute['filters'] ?? [],
                );

                /** Update existing documents */
                foreach ($this->documentsIterator($collection) as $document) {
                    $document->setAttribute($tempAttributeId, $document->getAttribute($oldAttribute['$id']));
                    $this->projectDB->updateDocument($collection, $document['$id'], $document);
                }

                /** Apply a filter for all future documents */
                $filter = "sync-{$oldAttribute['$id']}-{$tempAttributeId}";

                $this->projectDB->updateAttributeFilters(
                    $collection,
                    $tempAttributeId,
                    array_merge($newAttribute['filters'] ?? [], [$filter])
                );

                $this->executedActions[] = [
                    'type' => 'temp_attribute_added',
                    'collection' => $collection,
                    'attribute' => $tempAttributeId
                ];

                Console::success("Added temp attribute: $tempAttributeId");
            } else {
                $tempAttributeId = $oldAttribute['$id'] . '_temp';

                $this->projectDB->deleteAttribute(
                    $collection,
                    $oldAttribute['$id']
                );

                $this->projectDB->renameAttribute(
                    $collection,
                    $tempAttributeId,
                    $oldAttribute['$id']
                );

                $this->executedActions[] = [
                    'type' => 'attribute_updated',
                    'collection' => $collection,
                    'oldAttribute' => $oldAttribute,
                    'newAttribute' => $newAttribute
                ];
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
        }
    }

    protected function deleteAttribute(string $collection, array $attribute): void
    {
        Console::log("Deleting attribute: " . $attribute['$id'] . " in collection: " . $collection);

        try {
            if ($this->mode === self::MODE_AFTER) {
                /** Perform  deletion of the column only after the application upgrade */
                $this->projectDB->deleteAttribute($collection, $attribute['$id']);

                $this->executedActions[] = [
                    'type' => 'attribute_removed',
                    'collectionId' => $collection,
                    'attribute' => $attribute
                ];
                Console::success("Deleted attribute: {$attribute['$id']} from collection: $collection");
            } else {
                Console::warning("Skipping deletion of attribute: '{$attribute['$id']}' in migrate mode: '{$this->mode}'");
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
        }
    }

    /**
     * Provides an iterator for all documents on a collection.
     *
     * @param string $collectionId
     * @return iterable<Document>
     * @throws \Exception
     */
    public function documentsIterator(string $collectionId): iterable
    {
        $sum = 0;
        $nextDocument = null;
        $collectionCount = $this->projectDB->count($collectionId);

        do {
            $queries = [Query::limit($this->limit)];
            if ($nextDocument !== null) {
                $queries[] = Query::cursorAfter($nextDocument);
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
}