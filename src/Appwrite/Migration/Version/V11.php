<?php

namespace Appwrite\Migration\Version;

use Appwrite\Database\Database as OldDatabase;
use Appwrite\Database\Document as OldDocument;
use Appwrite\Migration\Migration;
use Exception;
use PDO;
use Redis;
use Swoole\Runtime;
use Throwable;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Exception\Authorization as ExceptionAuthorization;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

global $register;

class V11 extends Migration
{
    protected Database $dbProject;
    protected Database $dbConsole;

    protected array $oldCollections;
    protected array $newCollections;

    public function __construct(PDO $db, Redis $cache = null, array $options = [])
    {
        parent::__construct($db, $cache, $options);
        $this->options = array_map(fn ($option) => $option === 'yes' ? true : false, $this->options);

        if (!is_null($cache)) {
            $this->cache->flushAll();
            $cacheAdapter = new Cache(new RedisCache($this->cache));
            $this->dbProject = new Database(new MariaDB($this->db), $cacheAdapter); // namespace is set on execution
            $this->dbConsole = new Database(new MariaDB($this->db), $cacheAdapter);

            $this->dbProject->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $this->dbConsole->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $this->dbConsole->setNamespace('_project_console');
        }

        $this->newCollections = Config::getParam('collections', []);
        $this->oldCollections = Config::getParam('collectionsold', []);
    }

    public function execute(): void
    {
        Authorization::disable();
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $oldProject = $this->project;

        $this->dbProject->setNamespace('_project_' . $oldProject->getId());
        $this->dbConsole->setNamespace('_project_console');

        Console::info('');
        Console::info('------------------------------------');
        Console::info('Migrating project ' . $oldProject->getAttribute('name'));
        Console::info('------------------------------------');

        /**
         * Create internal/external structure for projects and skip the console project.
         */
        if ($oldProject->getId() !== 'console') {
            try {
                $project = $this->dbConsole->getDocument(collection: 'projects', id: $oldProject->getId());
            } catch (\Throwable $th) {
                Console::error($th->getTraceAsString());
            }

            /**
             * Migrate Project Document.
             */
            if ($project->isEmpty()) {
                $newProject = $this->fixDocument($oldProject);
                $newProject->setAttribute('version', '0.12.0');
                $project = $this->dbConsole->createDocument('projects', $newProject);
                Console::log('Created project document: ' . $oldProject->getAttribute('name') . ' (' . $oldProject->getId() . ')');
            }

            /**
             * Create internal tables
             */
            try {
                Console::log('Created internal tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
                $this->dbProject->createMetadata();
            } catch (\Throwable $th) {
            }

            /**
             * Create Audit tables
             */
            if ($this->dbProject->getCollection(Audit::COLLECTION)->isEmpty()) {
                $audit = new Audit($this->dbProject);
                $audit->setup();
                Console::log('Created audit tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
            }

            /**
             * Create Abuse tables
             */
            if ($this->dbProject->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
                $adapter = new TimeLimit("", 0, 1, $this->dbProject);
                $adapter->setup();
                Console::log('Created abuse tables for : ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');
            }

            /**
             * Create internal collections for Project
             */
            foreach ($this->newCollections as $key => $collection) {
                if (!$this->dbProject->getCollection($key)->isEmpty()) continue; // Skip if project collection already exists

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

                $this->dbProject->createCollection($key, $attributes, $indexes);
            }
            if ($this->options['migrateCollections']) {
                $this->migrateExternalCollections();
            }
        } else {
            Console::log('Skipped console project migration.');
        }

        $sum = $this->limit;
        $offset = 0;
        $total = 0;

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
                    '$collection!=' . OldDatabase::SYSTEM_COLLECTION_CONNECTIONS,
                    '$collection!=' . OldDatabase::SYSTEM_COLLECTION_RESERVED,
                    '$collection!=' . OldDatabase::SYSTEM_COLLECTION_TOKENS,
                ]
            ]);

            $sum = \count($all);

            Console::log('Migrating Internal Documents: ' . $offset . ' / ' . $this->oldProjectDB->getSum());

            foreach ($all as $document) {
                if (
                    !array_key_exists($document->getCollection(), $this->oldCollections)
                ) {
                    continue;
                }

                $new = $this->fixDocument($document);

                if (is_null($new) || empty($new->getId())) {
                    Console::warning('Skipped Document due to missing ID.');
                    continue;
                }

                try {
                    if ($this->dbProject->getDocument($new->getCollection(), $new->getId())->isEmpty()) {
                        $this->dbProject->createDocument($new->getCollection(), $new);
                    }
                } catch (\Throwable $th) {
                    Console::error("Failed to migrate document ({$new->getId()}) from collection ({$new->getCollection()}): " . $th->getMessage());
                    continue;
                }
            }

            $offset += $this->limit;
            $total += $sum;
        }
        Console::log('Migrated ' . $total . ' Internal Documents.');
    }

    /**
     * Migrate external collections for Project
     *
     * @return void 
     * @throws Exception 
     * @throws Throwable 
     * @throws Limit 
     * @throws ExceptionAuthorization 
     * @throws Structure 
     */
    protected function migrateExternalCollections(): void
    {
        $sum = $this->limit;
        $offset = 0;

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
                $id = $oldCollection->getId();
                $permissions = $oldCollection->getPermissions();
                $name = $oldCollection->getAttribute('name');
                $newCollection = $this->dbProject->getCollection('collection_' . $id);

                if ($newCollection->isEmpty()) {
                    $this->dbProject->createCollection('collection_' . $id);
                    /**
                     * Migrate permissions
                     */
                    $read = $this->migrateWildcardPermissions($permissions['read'] ?? []);
                    $write = $this->migrateWildcardPermissions($permissions['write'] ?? []);

                    /**
                     * Suffix collection name with a subsequent number to make it unique if possible.
                     */
                    $suffix = 1;
                    while ($this->dbProject->findOne('collections', [
                        new Query('name', Query::TYPE_EQUAL, [$name])
                    ])) {
                        $name .= ' - ' . $suffix++;
                    }

                    $this->dbProject->createDocument('collections', new Document([
                        '$id' => $id,
                        '$read' => [],
                        '$write' => [],
                        'permission' => 'document',
                        'dateCreated' => time(),
                        'dateUpdated' => time(),
                        'name' => substr($name, 0, 256),
                        'enabled' => true,
                        'search' => implode(' ', [$id, $name]),
                    ]));
                } else {
                    Console::warning('Skipped Collection ' . $newCollection->getId() . ' from ' . $newCollection->getCollection());
                }

                /**
                 * Migrate collection rules to attributes
                 */
                $attributes = $this->getCollectionAttributes($oldCollection);

                foreach ($attributes as $attribute) {
                    try {
                        $this->dbProject->createAttribute(
                            collection: 'collection_' . $attribute['$collection'],
                            id: $attribute['$id'],
                            type: $attribute['type'],
                            size: $attribute['size'],
                            required: $attribute['required'],
                            default: $attribute['default'],
                            signed: $attribute['signed'],
                            array: $attribute['array'],
                            format: $attribute['format'] ?? null,
                            formatOptions: $attribute['formatOptions'] ?? [],
                            filters: $attribute['filters']
                        );
                        $this->dbProject->createDocument('attributes', new Document([
                            '$id' => $attribute['$collection'] . '_' . $attribute['$id'],
                            'key' => $attribute['$id'],
                            'collectionId' => $attribute['$collection'],
                            'type' => $attribute['type'],
                            'status' => 'available',
                            'size' => $attribute['size'],
                            'required' => $attribute['required'],
                            'signed' => $attribute['signed'],
                            'default' => $attribute['default'],
                            'array' => $attribute['array'],
                            'format' => $attribute['format'] ?? null,
                            'formatOptions' => $attribute['formatOptions'] ?? null,
                            'filters' => $attribute['filters']
                        ]));

                        Console::log('Created "' . $attribute['$id'] . '" attribute in collection: ' . $name);
                    } catch (\Throwable $th) {
                        Console::log($th->getMessage() . ' - ("' . $attribute['$id'] . '" attribute in collection ' . $name . ')');
                    }
                }
                if ($this->options['migrateDocuments']) {
                    $this->migrateExternalDocuments(collection: $id);
                }
            }
            $offset += $this->limit;
        }
    }
    /**
     * Migrate all external documents
     *
     * @return void 
     * @throws Exception 
     * @throws Throwable 
     * @throws ExceptionAuthorization 
     * @throws Structure 
     */
    protected function migrateExternalDocuments(string $collection): void
    {
        $sum = $this->limit;
        $offset = 0;

        while ($sum >= $this->limit) {
            $allDocs = $this->oldProjectDB->getCollection([
                'limit' => $this->limit,
                'offset' => $offset,
                'orderType' => 'DESC',
                'filters' => [
                    '$collection=' . $collection
                ]
            ]);

            $sum = \count($allDocs);

            Console::log('Migrating External Documents for Collection ' . $collection . ': ' . $offset . ' / ' . $this->oldProjectDB->getSum());

            foreach ($allDocs as $document) {
                if (!$this->dbProject->getDocument('collection_' . $collection, $document->getId())->isEmpty()) {
                    continue;
                }
                go(function ($document) {
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
                        if (!is_string($attr) && is_numeric($attr)) {
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
                                if (!is_string($child) && is_numeric($child)) {
                                    $document[$key][$index] = floatval($child); // Convert any numeric to float
                                }
                            }
                        }
                    }
                }, $document);
                $document = new Document($document->getArrayCopy());
                $document = $this->migratePermissions($document);

                try {
                    $this->dbProject->createDocument('collection_' . $collection, $document);
                } catch (\Throwable $th) {
                    Console::error("Failed to migrate document ({$document->getId()}): " . $th->getMessage());
                    continue;
                }
            }
            $offset += $this->limit;
        }
    }

    /**
     * Migrates single docuemnt.
     *
     * @param OldDocument $oldDocument 
     * @return Document|null
     * @throws Exception 
     */
    protected function fixDocument(OldDocument $oldDocument): Document|null
    {
        $document = new Document($oldDocument->getArrayCopy());
        $document = $this->migratePermissions($document);

        /**
         * Check attributes and set their default values.
         */
        if (array_key_exists($document->getCollection(), $this->oldCollections)) {
            foreach ($this->newCollections[$document->getCollection()]['attributes'] ?? [] as $attr) {
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
            case OldDatabase::SYSTEM_COLLECTION_PROJECTS:
                $newProviders = [];
                $newAuths = [];
                $providers = Config::getParam('providers', []);
                $auths = Config::getParam('auth', []);

                /**
                 * Remove Tasks
                 */
                $document->removeAttribute('tasks');

                /*
                    * Add enabled OAuth2 providers to default data rules
                    */
                foreach ($providers as $index => $provider) {
                    $appId = $document->getAttribute('usersOauth2' . \ucfirst($index) . 'Appid');
                    $appSecret = $document->getAttribute('usersOauth2' . \ucfirst($index) . 'Secret');

                    if (!is_null($appId) || !is_null($appId)) {
                        $newProviders[$appId] = $appSecret;
                    }

                    $document
                        ->removeAttribute('usersOauth2' . \ucfirst($index) . 'Appid')
                        ->removeAttribute('usersOauth2' . \ucfirst($index) . 'Secret');
                }

                $document->setAttribute('providers', $newProviders);

                /*
                    * Migrate User providers settings
                    */
                $oldAuths = [
                    'email-password' => 'usersAuthEmailPassword',
                    'magic-url' => 'usersAuthMagicURL',
                    'anonymous' => 'usersAuthAnonymous',
                    'invites' => 'usersAuthInvites',
                    'jwt' => 'usersAuthJWT',
                    'phone' => 'usersAuthPhone'
                ];

                foreach ($oldAuths as $index => $auth) {
                    $enabled = $document->getAttribute($auth, true);
                    $newAuths['auth' . \ucfirst($auths[$index]['key'])] = $enabled;
                    $document->removeAttribute($auth);
                }

                if (!empty($document->getAttribute('usersAuthLimit'))) {
                    $newAuths['limit'] = $document->getAttribute('usersAuthLimit');
                }

                $document->removeAttribute('usersAuthLimit');

                $document->setAttribute('auths', $newProviders);

                break;
            case OldDatabase::SYSTEM_COLLECTION_PLATFORMS:
                $projectId = $this->getProjectIdFromReadPermissions($document);

                if (is_null($projectId)) {
                    return null;
                }

                /**
                 * Set Project ID
                 */
                if ($document->getAttribute('projectId') === null) {
                    $document->setAttribute('projectId', $projectId);
                }

                /**
                 * Set empty key and store if null
                 */
                if ($document->getAttribute('key') === null) {
                    $document->setAttribute('key', '');
                }
                if ($document->getAttribute('store') === null) {
                    $document->setAttribute('store', '');
                }

                /**
                 * Reset Permissions
                 */
                $document->setAttribute('$read', ['role:all']);
                $document->setAttribute('$write', ['role:all']);

                break;
            case OldDatabase::SYSTEM_COLLECTION_CERTIFICATES:
                /**
                 * Replace certificateId attribute.
                 */
                if ($document->getAttribute('certificateId') !== null) {
                    $document->setAttribute('$id', $document->getAttribute('certificateId'));
                    $document->removeAttribute('certificateId');
                }

                break;
            case OldDatabase::SYSTEM_COLLECTION_DOMAINS:
                $projectId = $this->getProjectIdFromReadPermissions($document);

                if (is_null($projectId)) {
                    return null;
                }

                /**
                 * Set Project ID
                 */
                if ($document->getAttribute('projectId') === null) {
                    $document->setAttribute('projectId', $projectId);
                }

                /**
                 * Set empty verification if null
                 */
                if ($document->getAttribute('verification') === null) {
                    $document->setAttribute('verification', false);
                }

                /**
                 * Reset Permissions
                 */
                $document->setAttribute('$read', ['role:all']);
                $document->setAttribute('$write', ['role:all']);

                break;
            case OldDatabase::SYSTEM_COLLECTION_KEYS:
                $projectId = $this->getProjectIdFromReadPermissions($document);

                if (is_null($projectId)) {
                    return null;
                }

                /**
                 * Set Project ID
                 */
                if ($document->getAttribute('projectId') === null) {
                    $document->setAttribute('projectId', $projectId);
                }

                /**
                 * Set scopes if empty
                 */
                if (empty($document->getAttribute('scopes', []))) {
                    $document->setAttribute('scopes', []);
                }

                /**
                 * Reset Permissions
                 */
                $document->setAttribute('$read', ['role:all']);
                $document->setAttribute('$write', ['role:all']);

                break;
            case OldDatabase::SYSTEM_COLLECTION_FUNCTIONS:
                $document->setAttribute('events', $document->getAttribute('events', []));

                break;
            case OldDatabase::SYSTEM_COLLECTION_WEBHOOKS:
                $projectId = $this->getProjectIdFromReadPermissions($document);

                if (is_null($projectId)) {
                    return null;
                }

                /**
                 * Set Project ID
                 */
                if ($document->getAttribute('projectId') === null) {
                    $document->setAttribute('projectId', $projectId);
                }

                $document->setAttribute('events', $document->getAttribute('events', []));

                /**
                 * Reset Permissions
                 */
                $document->setAttribute('$read', ['role:all']);
                $document->setAttribute('$write', ['role:all']);

                break;
            case OldDatabase::SYSTEM_COLLECTION_USERS:
                /**
                 * Set deleted attribute to false
                 */
                if ($document->getAttribute('deleted') === null) {
                    $document->setAttribute('deleted', false);
                }
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
                    $document->setAttribute('prefs', new \stdClass());
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
            case OldDatabase::SYSTEM_COLLECTION_TEAMS:

                /**
                 * Replace team:{self} with team:TEAM_ID
                 */
                $read = $document->getWrite();
                $write = $document->getWrite();

                $document->setAttribute('$read', str_replace('team:{self}', "team:{$document->getId()}", $read));
                $document->setAttribute('$write', str_replace('team:{self}', "team:{$document->getId()}", $write));

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

            if ($required) {
                $default = null;
            }

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

            if ($type === Database::VAR_FLOAT) {
                $attributes[$key]['format'] = APP_DATABASE_ATTRIBUTE_FLOAT_RANGE;
                $attributes[$key]['formatOptions'] = [];
                $attributes[$key]['formatOptions']['min'] = -PHP_FLOAT_MAX;
                $attributes[$key]['formatOptions']['max'] = PHP_FLOAT_MAX;
            }
        }

        return $attributes;
    }

    /**
     * @param Document $document
     * @return string|null 
     * @throws Exception 
     */
    protected function getProjectIdFromReadPermissions(Document $document): string|null
    {
        $readPermissions = $document->getRead();
        $teamId = str_replace('team:', '', reset($readPermissions));
        $project = $this->oldConsoleDB->getCollectionFirst([
            'filters' => [
                '$collection=' . OldDatabase::SYSTEM_COLLECTION_PROJECTS,
                'teamId=' . $teamId
            ]
        ]);

        if (!$project) {
            return null;
        }

        return $project->getId();
    }
}
