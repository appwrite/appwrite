<?php

namespace Appwrite\Migration\Version;

use Appwrite\Database\Database as OldDatabase;
use Appwrite\Database\Document as OldDocument;
use Appwrite\Migration\Migration;
use Exception;
use PDO;
use Redis;
use Swoole\Runtime;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

global $register;

class V09 extends Migration
{
    protected Database $dbInternal;
    protected Database $dbExternal;
    protected Database $dbConsole;

    protected array $oldCollections;
    protected array $newCollections;

    public function __construct(PDO $db, Redis $cache)
    {
        parent::__construct($db, $cache);

        $cacheAdapter = new Cache(new RedisCache($this->cache));
        $this->dbInternal = new Database(new MariaDB($this->db), $cacheAdapter);
        $this->dbExternal = new Database(new MariaDB($this->db), $cacheAdapter);
        $this->dbConsole = new Database(new MariaDB($this->db), $cacheAdapter);
        $this->dbConsole->setNamespace('project_console_internal');

        $this->newCollections = Config::getParam('collections2', []);
        $this->oldCollections = Config::getParam('collections', []);
    }

    public function execute(): void
    {
        Authorization::disable();
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        \Co\run(function () {
            $oldProject = $this->project;

            $this->dbInternal->setNamespace('project_' . $oldProject->getId() . '_internal');
            $this->dbExternal->setNamespace('project_' . $oldProject->getId() . '_external');

            if ($oldProject->getId() !== 'console') {
                // Migrate project document
                $project = $this->dbConsole->getDocument('projects', $oldProject->getId());

                if ($project->isEmpty()) {
                    Console::log('Migrating project: ' . $oldProject->getAttribute('name') . ' (' . $oldProject->getId() . ')');

                    $newProject = new Document($oldProject->getArrayCopy());
                    $newProject = $this->migratePermissions($newProject);
                    $project = $this->dbConsole->createDocument('projects', $newProject);
                }

                if (!$this->dbInternal->exists()) {
                    $this->dbInternal->create(); // Create internal DB structure if not exists
                    Console::log('Created internal tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                if (!$this->dbExternal->exists()) {
                    $this->dbExternal->create(); // Create external DB structure if not exists
                    Console::log('Created external tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                if ($this->dbInternal->getCollection(Audit::COLLECTION)->isEmpty()) {
                    $audit = new Audit($this->dbInternal);
                    $audit->setup(); // Setup Audit tables
                    Console::log('Created audit tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                if ($this->dbInternal->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
                    $adapter = new TimeLimit("", 0, 1, $this->dbInternal);
                    $adapter->setup(); // Setup Abuse tables
                    Console::log('Created abuse tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                // Create collections for Project
                foreach ($this->newCollections as $key => $collection) {
                    if (!$this->dbInternal->getCollection($key)->isEmpty()) return; // Skip if project collection already exists

                    $attributes = [];
                    $indexes = [];

                    foreach ($collection['attributes'] as $attribute) {
                        $attributes[] = new Document([
                            '$id' => $attribute['$id'],
                            'type' => $attribute['type'],
                            'size' => $attribute['size'],
                            'required' => $attribute['required'],
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

                    $this->dbInternal->createCollection($key, $attributes, $indexes);
                }

                $sum = $this->limit;
                $offset = 0;

                // Migrate collections for Database
                while ($sum >= $this->limit) {
                    $databaseCollections = $this->oldProjectDB->getCollection([
                        'limit' => $this->limit,
                        'offset' => $offset,
                        'orderType' => 'DESC',
                        'filters' => [
                            '$collection=' . OldDatabase::SYSTEM_COLLECTION_COLLECTIONS,
                        ]
                    ]);

                    $sum = \count($databaseCollections);
                    Console::log('Migrating Collections: ' . $offset . ' / ' . $this->oldProjectDB->getSum());
                    foreach ($databaseCollections as $oldCollection) {
                        go(function () use ($oldCollection) {
                            $id = $oldCollection->getId();
                            $permissions = $oldCollection->getPermissions();
                            $name = $oldCollection->getAttribute('name');
                            $newCollection = $this->dbExternal->getCollection($id);

                            $name .= '.'.$id; // TODO: make name unique only when necessary

                            // Create collection if not exists
                            if ($newCollection->isEmpty()) {
                                $collection = $this->dbExternal->createCollection($id);
                                $collection->setAttribute('name', $name);
                                $collection->setAttribute('$read', $permissions['read'] ?? []);
                                $collection->setAttribute('$write', $permissions['write'] ?? []);
                                $this->dbExternal->updateDocument(Database::COLLECTIONS, $id, $collection);
                            }
                            // Migrate collection rules to attributes
                            $attributes = $this->migrateCollectionAttributes($oldCollection);

                            foreach ($attributes as $attribute) {
                                if (array_key_exists($attribute['$id'], $newCollection->getAttributes())){
                                    var_dump($attribute['$id'].' exists');
                                    return;
                                } // Skip if attribute already exists

                                $success = $this->dbExternal->createAttribute(
                                    $attribute['$collection'],
                                    $attribute['$id'],
                                    $attribute['type'],
                                    $attribute['size'],
                                    $attribute['required'],
                                    $attribute['default'],
                                    $attribute['signed'],
                                    $attribute['array'],
                                    $attribute['filters']
                                );

                                if (!$success) {
                                    throw new Exception("Couldn't create create attribute '{$attribute['$id']}' for collection '{$name}'");
                                } else {
                                    Console::log('Created '.$attribute['$id'].' attribute in collection: ' . $name);
                                }
                            }

                            $sumDocs = $this->limit;
                            $offsetDocs = 0;
                            while ($sumDocs >= $this->limit) {
                                $allDocs = $this->oldProjectDB->getCollection([
                                    'limit' => $this->limit,
                                    'offset' => $offsetDocs,
                                    'orderType' => 'DESC',
                                    'filters' => [
                                        '$collection=' . $id
                                    ]
                                ]);

                                $sumDocs = \count($allDocs);
                                foreach ($allDocs as $document) {
                                    go(function () use ($document, $id) {
                                        if (!$this->dbExternal->getDocument($id, $document->getId())->isEmpty()) {
                                            return;
                                        }
                                        foreach ($document as $key => $attr) {
                                            if ($document->getAttribute($key) instanceof OldDocument) {
                                                $document[$key] = json_encode($attr->getArrayCopy());
                                            }
                                            if (is_numeric($attr)) {
                                                $document[$key] = floatval($attr); // Convert any numeric to float
                                            }
                                            if (\is_array($attr)) {
                                                foreach ($attr as $index => $child) {
                                                    if ($document->getAttribute($key)[$index] instanceof OldDocument) {
                                                        $document[$key][$index] = json_encode($child->getArrayCopy());
                                                    }
                                                    if (is_numeric($attr)) {
                                                        $document[$key][$index] = floatval($child); // Convert any numeric to float
                                                    }
                                                }
                                            }
                                        }
                                        $document = new Document($document->getArrayCopy());
                                        $document = $this->migratePermissions($document);
                                        $this->dbExternal->createDocument($id, $document);
                                    });
                                }
                                $offsetDocs += $this->limit;
                            }
                        });
                    }
                    $offset += $this->limit;
                }
            } else {
                Console::log('Skipped console project migration.');
            }

            $sum = $this->limit;
            $offset = 0;

            // Migrate remaining documents
            while ($sum >= $this->limit) {
                $all = $this->oldProjectDB->getCollection([
                    'limit' => $this->limit,
                    'offset' => $offset,
                    'orderType' => 'DESC',
                    'filters' => [
                        '$collection!=' . OldDatabase::SYSTEM_COLLECTION_COLLECTIONS,
                        '$collection!=' . OldDatabase::SYSTEM_COLLECTION_RULES,
                        '$collection!=' . OldDatabase::SYSTEM_COLLECTION_TASKS,
                        '$collection!=' . OldDatabase::SYSTEM_COLLECTION_PROJECTS,
                    ]
                ]);

                $sum = \count($all);

                Console::log('Migrating Documents: ' . $offset . ' / ' . $this->oldProjectDB->getSum());

                foreach ($all as $document) {
                    go(function () use ($document) {
                        if (!array_key_exists($document->getCollection(), $this->oldCollections)) {
                            return;
                        }
                        $old = $document->getArrayCopy();
                        $new = $this->fixDocument($document);

                        if (empty($new->getId())) {
                            Console::warning('Skipped Document due to missing ID.');
                            return;
                        }

                        if (!$this->check_diff_multi($new->getArrayCopy(), $old)) {
                            return;
                        }

                        try {
                            if ($this->dbInternal->getDocument($new->getCollection(), $new->getId())->isEmpty()) {
                                $this->dbInternal->createDocument($new->getCollection(), $new);
                            } else {
                                Console::warning('Skipped Document ' . $new->getId() . ' from ' . $new->getCollection());
                            }
                        } catch (\Throwable $th) {
                            Console::error('Failed to update document: ' . $th->getMessage());
                            return;

                            if ($document && $new->getId() !== $document->getId()) {
                                throw new Exception('Duplication Error');
                            }
                        }
                    });
                }

                $offset += $this->limit;
            }
            Console::log('Migrated ' . $sum . ' Documents.');

        });
    }

    protected function fixDocument(OldDocument $oldDocument): Document
    {
        $document = new Document($oldDocument->getArrayCopy());
        $document = $this->migratePermissions($document);

        switch ($document->getAttribute('$collection')) {
            case OldDatabase::SYSTEM_COLLECTION_USERS:
                /**
                 * Remove deprecated user status 0 and replace with boolean.
                 */
                if ($document->getAttribute('status') === 0 || $document->getAttribute('status') === 1) {
                    $document->setAttribute('status', true);
                }
                if ($document->getAttribute('status') === 2) {
                    $document->setAttribute('status', false);
                }
                var_dump($document->getAttribute('password'));
                break;
            case OldDatabase::SYSTEM_COLLECTION_FILES:
                if (!empty($document->getAttribute('fileOpenSSLVersion', null))) {
                    $document
                        ->setAttribute('openSSLVersion', $document->getAttribute('fileOpenSSLVersion'))
                        ->removeAttribute('fileOpenSSLVersion');
                }
                if (!empty($document->getAttribute('fileOpenSSLCipher', null))) {
                    $document
                        ->setAttribute('openSSLCipher', $document->getAttribute('fileOpenSSLCipher'))
                        ->removeAttribute('fileOpenSSLCipher');
                }
                if (!empty($document->getAttribute('fileOpenSSLTag', null))) {
                    $document
                        ->setAttribute('openSSLTag', $document->getAttribute('fileOpenSSLTag'))
                        ->removeAttribute('fileOpenSSLTag');
                }
                if (!empty($document->getAttribute('fileOpenSSLIV', null))) {
                    $document
                        ->setAttribute('openSSLIV', $document->getAttribute('fileOpenSSLIV'))
                        ->removeAttribute('fileOpenSSLIV');
                }
                $document->removeAttribute('folderId');
                $document->removeAttribute('token');
                break;
        }

        // Convert nested Documents to JSON strings
        foreach ($document as &$attr) {
            if ($attr instanceof OldDocument) {
                $attr = json_encode($attr->getArrayCopy());
            }

            if (\is_array($attr)) {
                foreach ($attr as &$child) {
                    if ($child instanceof OldDocument) {
                        $child = json_encode($child->getArrayCopy());
                    }
                }
            }
        }

        return $document;
    }

    protected function migratePermissions(Document $document): Document
    {
        // Migrate $permissions to independent $read,$write
        if ($document->isSet('$permissions')) {
            $permissions = $document->getAttribute('$permissions', []);
            $document->setAttribute('$read', $permissions['read'] ?? []);
            $document->setAttribute('$write', $permissions['write'] ?? []);
            $document->removeAttribute('$permissions');
        }

        return $document;
    }

    protected function migrateCollectionAttributes(OldDocument $collection): array
    {
        $attributes = [];
        foreach ($collection->getAttribute('rules', []) as $key => $value) {
            $collectionId = $collection->getId();
            $id = $value['key'];
            $size = 65_535; // Max size of text in MariaDB
            $array = $value['array'] ?? false;
            $required = $value['required'] ?? false;
            $default = $value['default'] ?? null;
            $default = match ($value['type']) {
                OldDatabase::SYSTEM_VAR_TYPE_NUMERIC => floatval($default),
                default => $default
            };
            $type = match ($value['type']) {
                OldDatabase::SYSTEM_VAR_TYPE_TEXT => Database::VAR_STRING,
                OldDatabase::SYSTEM_VAR_TYPE_EMAIL => Database::VAR_STRING,
                OldDatabase::SYSTEM_VAR_TYPE_DOCUMENT => Database::VAR_STRING,
                OldDatabase::SYSTEM_VAR_TYPE_IP => Database::VAR_STRING,
                OldDatabase::SYSTEM_VAR_TYPE_URL => Database::VAR_STRING,
                OldDatabase::SYSTEM_VAR_TYPE_WILDCARD => Database::VAR_STRING,
                OldDatabase::SYSTEM_VAR_TYPE_NUMERIC => Database::VAR_FLOAT,
                OldDatabase::SYSTEM_VAR_TYPE_BOOLEAN => Database::VAR_BOOLEAN,
                default => Database::VAR_STRING
            };

            $attributes[$key] = [
                '$collection' => $collectionId,
                '$id' => $id,
                'type' => $type,
                'size' => $size,
                'required' => $required,
                'default' => $default,
                'array' => $array,
                'signed' => true,
                'filters' => []
            ];
        }

        return $attributes;
    }
}
