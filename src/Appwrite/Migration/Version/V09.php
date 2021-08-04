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
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

global $register;

class V09 extends Migration
{
    protected Database $dbInternal;
    protected Database $dbExternal;
    protected Database $dbConsole;

    protected array $oldCollections;
    protected array $newCollections;

    public function __construct(PDO $db, Redis $cache = null)
    {
        parent::__construct($db, $cache);

        if(!is_null($cache)) {
            $cacheAdapter = new Cache(new RedisCache($this->cache));
            $this->dbInternal = new Database(new MariaDB($this->db), $cacheAdapter);
            $this->dbExternal = new Database(new MariaDB($this->db), $cacheAdapter);
            $this->dbConsole = new Database(new MariaDB($this->db), $cacheAdapter);
            $this->dbConsole->setNamespace('project_console_internal');
        }

        $this->newCollections = Config::getParam('collections2', []);
        $this->oldCollections = Config::getParam('collections', []);
    }

    public function execute(): void
    {
        Authorization::disable();
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        /**
         * Get a project to check if the version works with this migration.
         */
        if (!str_starts_with($this->oldConsoleDB->getCollectionFirst([
            'filters' => [
                '$collection=' . OldDatabase::SYSTEM_COLLECTION_PROJECTS
            ]
        ])->getAttribute('version'), '0.9.')) {
            throw new Exception("Can only migrate from version 0.9.x to 0.10.x");
        }

        \Co\run(function () {
            $oldProject = $this->project;

            $this->dbInternal->setNamespace('project_' . $oldProject->getId() . '_internal');
            $this->dbExternal->setNamespace('project_' . $oldProject->getId() . '_external');

            /**
             * Create internal/external structure for projects and skip the console project.
             */
            if ($oldProject->getId() !== 'console') {
                $project = $this->dbConsole->getDocument('projects', $oldProject->getId());

                /**
                 * Migrate Project Document.
                 */
                if ($project->isEmpty()) {
                    $newProject = $this->fixDocument($oldProject);
                    $newProject->setAttribute('version', '0.10.0');
                    $project = $this->dbConsole->createDocument('projects', $newProject);
                    Console::log('Migrating project: ' . $oldProject->getAttribute('name') . ' (' . $oldProject->getId() . ')');
                }

                /**
                 * Create internal DB tables
                 */
                if (!$this->dbInternal->exists()) {
                    $this->dbInternal->create();
                    Console::log('Created internal tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                /**
                 * Create external DB tables
                 */
                if (!$this->dbExternal->exists()) {
                    $this->dbExternal->create();
                    Console::log('Created external tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                /**
                 * Create Audit tables
                 */
                if ($this->dbInternal->getCollection(Audit::COLLECTION)->isEmpty()) {
                    $audit = new Audit($this->dbInternal);
                    $audit->setup();
                    Console::log('Created audit tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                /**
                 * Create Abuse tables
                 */
                if ($this->dbInternal->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
                    $adapter = new TimeLimit("", 0, 1, $this->dbInternal);
                    $adapter->setup();
                    Console::log('Created abuse tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                }

                /**
                 * Create internal collections for Project
                 */
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

                /**
                 * Migrate external collections for Project
                 */
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

                            if ($newCollection->isEmpty()) {
                                $collection = $this->dbExternal->createCollection($id);
                                /**
                                 * Migrate permissions
                                 */
                                $read = $this->migrateWildcardPermissions($permissions['read'] ?? []);
                                $write = $this->migrateWildcardPermissions($permissions['write'] ?? []);

                                /**
                                 * Suffix collection name with a subsequent number to make it unique if possible.
                                 */
                                $suffix = 1;
                                while ($this->dbExternal->findFirst(Database::COLLECTIONS, [
                                    new Query('name', Query::TYPE_EQUAL, [$name])
                                ])) {
                                    $name .= ' - ' . $suffix++;
                                }

                                $collection->setAttribute('name', $name);
                                $collection->setAttribute('$read', $read);
                                $collection->setAttribute('$write', $write);


                                $this->dbExternal->updateDocument(Database::COLLECTIONS, $id, $collection);
                            } else {
                                Console::warning('Skipped Collection ' . $newCollection->getId() . ' from ' . $newCollection->getCollection());
                            }

                            /**
                             * Migrate collection rules to attributes
                             */
                            $attributes = $this->getCollectionAttributes($oldCollection);

                            foreach ($attributes as $attribute) {
                                if (array_key_exists($attribute['$id'], $newCollection->getAttributes())) {
                                    return; // Skip if attribute already exists
                                }

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
                                    Console::log('Created ' . $attribute['$id'] . ' attribute in collection: ' . $name);
                                }
                            }

                            /**
                             * Migrate all external documents
                             */
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
                                            /**
                                             * Convert nested Document to JSON strings.
                                             */
                                            if ($document->getAttribute($key) instanceof OldDocument) {
                                                $document[$key] = json_encode($this->fixDocument($attr)->getArrayCopy());
                                            }
                                            /**
                                             * Convert numeric Attributes to float.
                                             */
                                            if (is_numeric($attr)) {
                                                $document[$key] = floatval($attr);
                                            }
                                            if (\is_array($attr)) {
                                                foreach ($attr as $index => $child) {
                                                    /**
                                                     * Convert array of nested Document to array JSON strings.
                                                     */
                                                    if ($document->getAttribute($key)[$index] instanceof OldDocument) {
                                                        $document[$key][$index] = json_encode($this->fixDocument($child)->getArrayCopy());
                                                    }
                                                    /**
                                                     * Convert array of numeric Attributes to array float.
                                                     */
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

            /**
             * Migrate internal documents
             */
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

        /**
         * Check attributes and set their default values.
         */
        if (array_key_exists($document->getCollection(), $this->oldCollections)) {
            foreach ($this->newCollections[$document->getCollection()]['attributes'] as $attr) {
                if (
                    (!$attr['array'] ||
                        ($attr['array'] && array_key_exists('filter', $attr)
                            && in_array('json', $attr['filter'])))
                    && empty($document->getAttribute($attr['$id'], null))
                ) {
                    $document->setAttribute($attr['$id'], $attr['default'] ?? null);
                }
            }
        }

        switch ($document->getAttribute('$collection')) {
            case OldDatabase::SYSTEM_COLLECTION_USERS:
                /**
                 * Remove deprecated user status 0 and replace with boolean.
                 */
                if ($document->getAttribute('status') === 2) {
                    $document->setAttribute('status', false);
                } else {
                    $document->setAttribute('status', true);
                }

                /**
                 * Set default values for arrays if not set.
                 */
                if (empty($document->getAttribute('prefs', []))) {
                    $document->setAttribute('prefs', []);
                }
                if (empty($document->getAttribute('sessions', []))) {
                    $document->setAttribute('sessions', []);
                }
                if (empty($document->getAttribute('tokens', []))) {
                    $document->setAttribute('tokens', []);
                }
                if (empty($document->getAttribute('memberships', []))) {
                    $document->setAttribute('memberships', []);
                }

                /**
                 * Replace user:{self} with user:USER_ID
                 */
                $write = $document->getWrite();
                $document->setAttribute('$write', str_replace('user:{self}', "user:{$document->getId()}", $write));

                break;
            case OldDatabase::SYSTEM_COLLECTION_FILES:
                /**
                 * Migrating breakind changes on Files.
                 */
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

                /**
                 * Remove deprecated attributes.
                 */
                $document->removeAttribute('folderId');
                $document->removeAttribute('token');
                break;
        }

        return $document;
    }

    /**
     * Migrates $permissions to independent $read and $write.
     * @param Document $document 
     * @return Document 
     */
    protected function migratePermissions(Document $document): Document
    {
        if ($document->isSet('$permissions')) {
            $permissions = $document->getAttribute('$permissions', []);
            $read = $this->migrateWildcardPermissions($permissions['read'] ?? []);
            $write = $this->migrateWildcardPermissions($permissions['write'] ?? []);
            $document->setAttribute('$read', $read);
            $document->setAttribute('$write', $write);
            $document->removeAttribute('$permissions');
        }

        return $document;
    }

    /**
     * Takes a permissions array and replaces wildcard * with role:all.
     * @param array $permissions 
     * @return array 
     */
    protected function migrateWildcardPermissions(array $permissions): array
    {
        return array_map(function ($permission) {
            if ($permission === '*') return 'role:all';
            return $permission;
        }, $permissions);
    }

    /**
     * Get new collection attributes from old collection rules.
     * @param OldDocument $collection 
     * @return array 
     */
    protected function getCollectionAttributes(OldDocument $collection): array
    {
        $attributes = [];
        foreach ($collection->getAttribute('rules', []) as $key => $value) {
            $collectionId = $collection->getId();
            $id = $value['key'];
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

            $size = $type === Database::VAR_STRING ? 65_535 : 0; // Max size of text in MariaDB

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
