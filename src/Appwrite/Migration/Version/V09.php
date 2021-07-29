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

        $oldProject = $this->project;
        if ($oldProject->getId() === 'console') {
            return;
        }
        // Get team on now Database
        $team = $this->dbConsole->getDocument('teams', $oldProject->getAttribute('teamId'));

        if ($team->isEmpty()) { // Migrate Team if it not exists
            $oldTeam = $this->oldConsoleDB->getDocument($oldProject->getAttribute('teamId'));
            $newTeam = new Document($oldTeam->getArrayCopy());
            $newTeam = $this->migratePermissions($newTeam);
            //$newTeam->setAttribute('$write', []);
            $team = $this->dbConsole->createDocument('teams', $newTeam);

            if ($team->isEmpty()) {
                throw new Exception('Couldn\'t migrate team ' . $oldTeam->getAttribute('name') . ' (' . $oldTeam->getId() . ')');
            }

            Console::log('Migrated internal team for ' . $oldProject->getAttribute('name') . ' (' . $oldProject->getId() . ')');
        }
        // Migrate project document
        $project = $this->dbConsole->getDocument('projects', $oldProject->getId());

        if ($project->isEmpty()) {
            $newProject = new Document($oldProject->getArrayCopy());
            $newProject = $this->migratePermissions($newProject);
            $project = $this->dbConsole->createDocument('projects', $newProject);

            Console::log('Migrating project: ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
        }

        $this->dbInternal->setNamespace('project_' . $project->getId() . '_internal');
        if (!$this->dbInternal->exists()) {
            $this->dbInternal->create(); // Create internal DB structure if not exists
            Console::log('Created internal tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
        }

        $this->dbExternal->setNamespace('project_' . $project->getId() . '_external');
        if (!$this->dbExternal->exists()) {
            $this->dbExternal->create(); // Create external DB structure if not exists
            Console::log('Created external tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
        }

        if($this->dbInternal->getCollection(Audit::COLLECTION)->isEmpty()) {
            $audit = new Audit($this->dbInternal);
            $audit->setup();
        }

        if($this->dbInternal->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
            $adapter = new TimeLimit("", 0, 1, $this->dbInternal);
            $adapter->setup();
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
            ], [
                '$collection=' . OldDatabase::SYSTEM_COLLECTION_COLLECTIONS
            ]);
            $sum = \count($databaseCollections);


            Console::log('Migrating Collections: ' . $offset . ' / ' . $this->oldProjectDB->getSum());
            \Co\run(function () use ($databaseCollections) {
                foreach ($databaseCollections as $oldCollection) {
                    go(function () use ($oldCollection) {
                        $id = $oldCollection->getId();
                        $permissions = $oldCollection->getPermissions();
                        $name = $oldCollection->getAttribute('name');

                        $newCollection = $this->dbExternal->getCollection($id);

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
                        foreach ($attributes as $key => $attribute) {
                            if ($key === $newCollection->getAttribute($key)) return; // Skip if attribute already exists

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
                                throw new Exception("Couldn't create create attribute '{$key}' for collection '{$name}'");
                            }
                        }
                    });
                }
            });
        }

        $sum = $this->limit;
        $offset = 0;

        // Migrate remaining documents
        while ($sum >= $this->limit) {
            $all = $this->oldProjectDB->getCollection([
                'limit' => $this->limit,
                'offset' => $offset,
                'orderType' => 'DESC',
            ], [
                '$collection!=' . OldDatabase::SYSTEM_COLLECTION_COLLECTIONS
            ]);

            $sum = \count($all);

            Console::log('Migrating Documents: ' . $offset . ' / ' . $this->projectDB->getSum());
            \Co\run(function () use ($all) {

                foreach ($all as $document) {
                    go(function () use ($document) {

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
                            $new = $this->dbInternal->createDocument($new->getCollection(), $new);
                        } catch (\Throwable $th) {
                            Console::error('Failed to update document: ' . $th->getMessage());
                            return;

                            if ($document && $new->getId() !== $document->getId()) {
                                throw new Exception('Duplication Error');
                            }
                        }
                    });
                }
            });

            $offset += $this->limit;
        }
    }

    protected function fixDocument(OldDocument $document): Document
    {
        $document = new Document($document->getArrayCopy());
        $document = $this->migratePermissions($document);

        switch ($document->getAttribute('$collection')) {
            default:

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
            $document->setAttribute('$read', $permissions['$read'] ?? []);
            $document->setAttribute('$write', $permissions['$write'] ?? []);
            $document->removeAttribute('$permissions');
        }

        return $document;
    }

    protected function migrateCollectionAttributes(OldDocument $collection): array
    {
        $attributes = [];

        foreach ($collection->getAttributes() as $key => $value) {
            $collectionId = $collection->getId();
            $id = $value['$id'];
            $size = 65_535; // Max size of text in MariaDB
            $array = $value['array'] ?? false;
            $required = $value['required'] ?? false;
            $default = $value['default'] ?? null;
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
