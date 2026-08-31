<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use Appwrite\Migration\Version\V24;
use Appwrite\Migration\Version\V25;
use Appwrite\Migration\Version\V26;
use Appwrite\Platform\Tasks\Migrate;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;
use Utopia\Registry\Registry;

final class MigrationVersionsTest extends TestCase
{
    /**
     * Check versions array integrity.
     */
    public function testMigrationVersions(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        foreach (Migration::$versions as $class) {
            $this->assertTrue(class_exists('Appwrite\\Migration\\Version\\' . $class));
        }

        // Test if current version exists
        // Only test official releases - skip if latest is release candidate
        if (!(\str_contains(APP_VERSION_STABLE, 'RC'))) {
            $this->assertArrayHasKey(APP_VERSION_STABLE, Migration::$versions);
        }
    }

    public function testV24CreatesAlertsCollectionForConsoleProject(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV24')
            ->setNamespace('migration_' . \uniqid());
        $database->create();

        $migration = new V24();
        $migration->setProject(
            new Document(['$id' => 'console', '$sequence' => 'console']),
            $database,
            $database,
            $authorization,
        );

        $migrateCollections = new \ReflectionMethod($migration, 'migrateCollections');
        \ob_start();
        try {
            $migrateCollections->invoke($migration);
        } finally {
            \ob_end_clean();
        }

        $collection = $database->getCollection('notifications');
        $this->assertFalse($collection->isEmpty());

        $attributes = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            $id = $attribute instanceof Document ? $attribute->getAttribute('$id') : ($attribute['$id'] ?? '');
            $attributes[$id] = $attribute;
        }
        $this->assertArrayHasKey('resourceInternalId', $attributes);
        $this->assertArrayHasKey('parentResourceInternalId', $attributes);
        $this->assertArrayHasKey('firstSeen', $attributes);
        $this->assertArrayHasKey('lastSeen', $attributes);

        $indexes = [];
        foreach ($collection->getAttribute('indexes', []) as $index) {
            $id = $index instanceof Document ? $index->getAttribute('$id') : ($index['$id'] ?? '');
            $indexes[$id] = $index instanceof Document ? $index->getAttribute('attributes') : ($index['attributes'] ?? []);
        }

        $this->assertSame([
            '_key_messageId',
            '_key_recipient',
            '_key_project',
            '_key_project_resource',
            '_key_project_parent_resource',
        ], \array_keys($indexes));
        $this->assertSame(['projectId', 'projectInternalId'], $indexes['_key_project']);
        $this->assertSame(['projectId', 'projectInternalId', 'resourceType', 'resourceId', 'resourceInternalId'], $indexes['_key_project_resource']);
        $this->assertSame(['projectId', 'projectInternalId', 'parentResourceType', 'parentResourceId', 'parentResourceInternalId'], $indexes['_key_project_parent_resource']);
    }

    public function testV24AddsSeenAttributesToExistingAlertsCollection(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV24ExistingAlerts')
            ->setNamespace('migration_existing_alerts_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(id: 'notifications'));

        $migration = new V24();
        $migration->setProject(
            new Document(['$id' => 'console', '$sequence' => 'console']),
            $database,
            $database,
            $authorization,
        );

        $migrateCollections = new \ReflectionMethod($migration, 'migrateCollections');
        \ob_start();
        try {
            $migrateCollections->invoke($migration);
        } finally {
            \ob_end_clean();
        }

        $collection = $database->getCollection('notifications');
        $attributes = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            $id = $attribute instanceof Document ? $attribute->getAttribute('$id') : ($attribute['$id'] ?? '');
            $attributes[$id] = $attribute;
        }

        $this->assertArrayHasKey('firstSeen', $attributes);
        $this->assertArrayHasKey('lastSeen', $attributes);
    }

    public function testCreateAttributesFromCollectionSkipsExistingAttributes(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV24Functions')
            ->setNamespace('migration_functions_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(id: 'functions'));

        $migration = new V24();
        $migration->setProject(
            new Document(['$id' => 'project', '$sequence' => '1']),
            $database,
            $database,
            $authorization,
        );

        $existing = [
            'deploymentRetention',
            'startCommand',
            'buildSpecification',
            'runtimeSpecification',
        ];
        $new = [
            'providerBranches',
            'providerPaths',
        ];

        \ob_start();
        try {
            $migration->createAttributesFromCollection($database, 'functions', $existing);
            $migration->createAttributesFromCollection($database, 'functions', [...$existing, ...$new]);
            $migration->createAttributesFromCollection($database, 'functions', [...$existing, ...$new]);
        } finally {
            \ob_end_clean();
        }

        $attributes = [];
        foreach ($database->getCollection('functions')->getAttribute('attributes', []) as $attribute) {
            $attributes[] = $attribute instanceof Document ? $attribute->getAttribute('$id') : ($attribute['$id'] ?? '');
        }

        foreach ([...$existing, ...$new] as $id) {
            $this->assertContains($id, $attributes);
        }
    }

    public function testV25RepairsProviderAttributesIdempotently(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV25ProviderAttributes')
            ->setNamespace('migration_provider_attributes_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(id: 'databases'));
        $database->createCollection(new Collection(id: 'functions'));
        $database->createCollection(new Collection(id: 'sites'));
        $database->createCollection(new Collection(id: 'migrations'));

        $migration = new class () extends V25 {
            #[\Override]
            public function forEachDocument(callable $callback): void
            {
            }
        };
        $migration->setProject(
            new Document(['$id' => 'project', '$sequence' => '1']),
            $database,
            $database,
            $authorization,
        );

        $migration->createAttributesFromCollection($database, 'functions', ['providerBranches']);

        \ob_start();
        try {
            $migration->execute();
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        foreach (['functions', 'sites'] as $collectionId) {
            $attributes = [];
            foreach ($database->getCollection($collectionId)->getAttribute('attributes', []) as $attribute) {
                $attributes[] = $attribute instanceof Document ? $attribute->getAttribute('$id') : ($attribute['$id'] ?? '');
            }

            $this->assertContains('providerBranches', $attributes);
            $this->assertContains('providerPaths', $attributes);
        }

        $databaseAttributes = [];
        foreach ($database->getCollection('databases')->getAttribute('attributes', []) as $attribute) {
            $databaseAttributes[] = $attribute instanceof Document ? $attribute->getAttribute('$id') : ($attribute['$id'] ?? '');
        }

        $this->assertContains('status', $databaseAttributes);
        $this->assertNotContains('migrationId', $databaseAttributes);
        $this->assertNotContains('migrationAttemptId', $databaseAttributes);

        $migrationAttributes = [];
        foreach ($database->getCollection('migrations')->getAttribute('attributes', []) as $attribute) {
            $migrationAttributes[] = $attribute instanceof Document ? $attribute->getAttribute('$id') : ($attribute['$id'] ?? '');
        }

        $this->assertContains('resourceInternalId', $migrationAttributes);
        $this->assertNotContains('attemptId', $migrationAttributes);
    }

    public function testMigrateRunsCumulativeV25AndV26FromPreV25Project(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $authorization->disable();
        $authorization->setDefaultStatus(false);
        $platform = $this->createConfiguredDatabase($authorization, 'migrationV26PreV25Platform', 'console');
        $platform->createAttribute('projects', new Attribute('version', ColumnType::String, size: 16));
        $project = $platform->createDocument('projects', new Document([
            '$id' => 'pre-v25-project',
            'version' => '1.9.5',
        ]));

        $database = $this->createConfiguredDatabase($authorization, 'migrationV26PreV25Project', 'projects');
        $database->createAttribute('databases', new Attribute('legacy', ColumnType::String));
        foreach (['legacy', 'status', 'stage'] as $attribute) {
            $database->createAttribute('migrations', new Attribute($attribute, ColumnType::String));
        }
        $database->createDocument('databases', new Document([
            '$id' => 'database',
            'legacy' => 'database-preserved',
        ]));
        $database->createDocument('migrations', new Document([
            '$id' => 'migration',
            'legacy' => 'migration-preserved',
            'status' => 'failed',
            'stage' => 'processing',
        ]));

        \ob_start();
        try {
            $this->runMigration($platform, $database, $project, $authorization);
            $this->assertCumulativeMigration($database);
            $this->runMigration($platform, $database, $project, $authorization);
        } finally {
            \ob_end_clean();
        }

        $this->assertSame('V25', Migration::$versions['1.9.6']);
        $this->assertSame('V26', Migration::$versions['2.0.0']);
        $this->assertCumulativeMigration($database);
        $this->assertSame('ready', $database->getDocument('databases', 'database')->getAttribute('status'));
        $this->assertSame('database-preserved', $database->getDocument('databases', 'database')->getAttribute('legacy'));
        $this->assertSame('migration-preserved', $database->getDocument('migrations', 'migration')->getAttribute('legacy'));
        $this->assertSame('failed', $database->getDocument('migrations', 'migration')->getAttribute('status'));
        $this->assertSame('finished', $database->getDocument('migrations', 'migration')->getAttribute('stage'));
    }

    public function testMigrateRunsV26FromV25CompleteReleaseCandidateProject(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $authorization->disable();
        $authorization->setDefaultStatus(false);
        $platform = $this->createConfiguredDatabase($authorization, 'migrationV26RcPlatform', 'console');
        $platform->createAttribute('projects', new Attribute('version', ColumnType::String, size: 16));
        $project = $platform->createDocument('projects', new Document([
            '$id' => 'rc-project',
            'version' => '2.0.0-rc.2',
        ]));
        $database = $this->createConfiguredDatabase($authorization, 'migrationV26RcProject', 'projects');
        foreach (['status', 'legacy'] as $attribute) {
            $database->createAttribute('databases', new Attribute($attribute, ColumnType::String));
        }
        foreach ([
            'resourceInternalId',
            'parentResourceId',
            'parentResourceInternalId',
            'parentResourceType',
            'destinationResourceId',
            'destinationResourceInternalId',
            'destinationResourceType',
            'status',
            'stage',
            'legacy',
        ] as $attribute) {
            $database->createAttribute('migrations', new Attribute($attribute, ColumnType::String));
        }
        foreach (['providerBranches', 'providerPaths'] as $attribute) {
            $database->createAttribute('functions', new Attribute($attribute, ColumnType::String, array: true));
            $database->createAttribute('sites', new Attribute($attribute, ColumnType::String, array: true));
        }
        $database->createAttribute('sites', new Attribute('scopes', ColumnType::String, array: true));
        $database->createDocument('databases', new Document([
            '$id' => 'database',
            'status' => 'ready',
            'legacy' => 'database-preserved',
        ]));
        $database->createDocument('migrations', new Document([
            '$id' => 'migration',
            'resourceInternalId' => 'resource-internal',
            'legacy' => 'migration-preserved',
            'status' => 'failed',
            'stage' => 'processing',
        ]));
        $database->createDocument('migrations', new Document([
            '$id' => 'migration-active',
            'status' => 'processing',
            'stage' => 'processing',
        ]));

        \ob_start();
        try {
            $this->runMigration($platform, $database, $project, $authorization);
            $this->assertCumulativeMigration($database);
            $this->runMigration($platform, $database, $project, $authorization);
        } finally {
            \ob_end_clean();
        }

        $this->assertCumulativeMigration($database);
        $this->assertSame('ready', $database->getDocument('databases', 'database')->getAttribute('status'));
        $this->assertSame('database-preserved', $database->getDocument('databases', 'database')->getAttribute('legacy'));
        $this->assertSame('resource-internal', $database->getDocument('migrations', 'migration')->getAttribute('resourceInternalId'));
        $this->assertSame('migration-preserved', $database->getDocument('migrations', 'migration')->getAttribute('legacy'));
        $this->assertSame('failed', $database->getDocument('migrations', 'migration')->getAttribute('status'));
        $this->assertSame('finished', $database->getDocument('migrations', 'migration')->getAttribute('stage'));
        $this->assertSame('processing', $database->getDocument('migrations', 'migration-active')->getAttribute('status'));
        $this->assertSame('processing', $database->getDocument('migrations', 'migration-active')->getAttribute('stage'));
    }

    public function testV26DoesNotNormalizeConcurrentlyRetriedMigration(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $authorization->disable();
        $authorization->setDefaultStatus(false);
        $database = new class (new Memory(), new Cache(new NoCache())) extends Database {
            private bool $interleave = true;

            #[\Override]
            public function updateDocument(
                string $collection,
                string $id,
                Document $document,
                ?int $expectedVersion = null,
            ): Document {
                if ($this->interleave && $collection === 'migrations' && $expectedVersion !== null) {
                    $this->interleave = false;
                    parent::updateDocument($collection, $id, new Document([
                        'attemptId' => 'attempt-retry',
                        'status' => 'processing',
                        'stage' => 'processing',
                    ]));
                }

                return parent::updateDocument($collection, $id, $document, $expectedVersion);
            }
        };
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV26Race')
            ->setNamespace('migration_v26_race_' . \uniqid());
        $database->create();

        foreach (Config::getParam('collections', [])['projects'] as $collection) {
            $database->createCollection(new Collection(id: (string) $collection['$id']));
        }
        foreach ([Database::METADATA, 'audit'] as $id) {
            if ($database->getCollection($id)->isEmpty()) {
                $database->createCollection(new Collection(id: $id));
            }
        }
        foreach (['status', 'stage'] as $attribute) {
            $database->createAttribute('migrations', new Attribute($attribute, ColumnType::String));
        }
        $database->createDocument('migrations', new Document([
            '$id' => 'migration-race',
            'status' => 'failed',
            'stage' => 'processing',
        ]));

        $migration = new V26();
        $migration->setProject(
            new Document(['$id' => 'project', '$sequence' => '1']),
            $database,
            $database,
            $authorization,
        );

        \ob_start();
        try {
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        $stored = $database->getDocument('migrations', 'migration-race');
        $this->assertSame('attempt-retry', $stored->getAttribute('attemptId'));
        $this->assertSame('processing', $stored->getAttribute('status'));
        $this->assertSame('processing', $stored->getAttribute('stage'));
    }

    private function assertCumulativeMigration(Database $database): void
    {
        foreach (['providerBranches', 'providerPaths'] as $attribute) {
            $this->assertContains($attribute, $this->attributeIds($database, 'functions'));
            $this->assertContains($attribute, $this->attributeIds($database, 'sites'));
        }
        $this->assertContains('scopes', $this->attributeIds($database, 'sites'));
        foreach (['status', 'migrationId', 'migrationAttemptId'] as $attribute) {
            $this->assertContains($attribute, $this->attributeIds($database, 'databases'));
        }
        foreach ([
            'resourceInternalId',
            'parentResourceId',
            'parentResourceInternalId',
            'parentResourceType',
            'destinationResourceId',
            'destinationResourceInternalId',
            'destinationResourceType',
            'attemptId',
        ] as $attribute) {
            $this->assertContains($attribute, $this->attributeIds($database, 'migrations'));
        }
    }

    private function createConfiguredDatabase(Authorization $authorization, string $name, string $type): Database
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase($name)
            ->setNamespace($name . '_' . \uniqid());
        $database->create();

        foreach (Config::getParam('collections', [])[$type] as $collection) {
            $id = (string) $collection['$id'];
            if (!$database->getCollection($id)->isEmpty()) {
                continue;
            }

            $database->createCollection(new Collection(id: $id));
        }

        if ($type === 'projects') {
            foreach ([Database::METADATA, 'audit'] as $id) {
                if (!$database->getCollection($id)->isEmpty()) {
                    continue;
                }

                $database->createCollection(new Collection(id: $id));
            }
        }

        return $database;
    }

    private function runMigration(
        Database $platform,
        Database $database,
        Document $project,
        Authorization $authorization,
    ): void {
        $registry = new Registry();
        $registry->set('db', static fn (): null => null);
        $getProjectDatabase = static fn (Document $candidate): Database => $candidate->getId() === $project->getId()
            ? $database
            : $platform;

        (new Migrate())->action(
            '2.0.0',
            $platform,
            $getProjectDatabase,
            $registry,
            $authorization,
            new Document(['$id' => 'console', '$sequence' => 'console']),
        );
    }

    /**
     * @return array<int, string>
     */
    private function attributeIds(Database $database, string $collection): array
    {
        return \array_map(
            static fn (Document $attribute): string => $attribute->getId(),
            $database->getCollection($collection)->getAttribute('attributes', []),
        );
    }
}
