<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use Appwrite\Migration\Version\V24;
use Appwrite\Migration\Version\V25;
use Appwrite\Migration\Version\V26;
use Appwrite\Platform\Tasks\Migrate;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Registry\Registry;

final class MigrationVersionsTest extends TestCase
{
    protected function tearDown(): void
    {
        require __DIR__ . '/../../../app/init/database/filters.php';
    }

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

        $migration = $this->v24WithoutWalks();
        $migration->setProject(
            new Document(['$id' => 'console', '$sequence' => 'console']),
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

        $collection = $database->findCollection('notifications');
        $this->assertNotNull($collection);

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
            '_key_team',
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
        $database->createCollection(Collection::create(id: 'notifications'));

        $migration = $this->v24WithoutWalks();
        $migration->setProject(
            new Document(['$id' => 'console', '$sequence' => 'console']),
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

        $collection = $database->getCollection('notifications');
        $attributes = [];
        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            $id = $attribute instanceof Document ? $attribute->getAttribute('$id') : ($attribute['$id'] ?? '');
            $attributes[$id] = $attribute;
        }

        $this->assertArrayHasKey('firstSeen', $attributes);
        $this->assertArrayHasKey('lastSeen', $attributes);
    }

    /**
     * A legacy install has notifications without the team columns. The fixture
     * below is a frozen snapshot of that shape, written out rather than derived
     * from the current config, so it keeps describing the old install even as
     * the config moves on.
     *
     * Runs execute() without its document walk, as the other migration tests
     * here do: the walk reads every document of every console collection, which
     * needs a full install rather than a fixture. Then does the thing the
     * columns exist for: store a notification against a team, read it back by
     * team, and check another team does not see it.
     */
    public function testV25LetsALegacyInstallStoreAndQueryTeamScopedNotifications(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV25TeamNotifications')
            ->setNamespace('migration_team_notifications_' . \uniqid());
        $database->create();

        $database->createCollection(Collection::create(
            id: 'notifications',
            attributes: [
                Attribute::string(key: 'messageId'),
                Attribute::string(key: 'recipientHash', size: 64),
                Attribute::string(key: 'type', size: 100),
                Attribute::string(key: 'channel', size: 64),
                Attribute::string(key: 'projectId'),
                Attribute::string(key: 'projectInternalId'),
                Attribute::string(key: 'resourceType', size: 64),
                Attribute::string(key: 'resourceId'),
                Attribute::string(key: 'resourceInternalId'),
                Attribute::string(key: 'title', size: 256),
                Attribute::boolean(key: 'read'),
            ],
        ));

        $migration = new class () extends V25 {
            #[\Override]
            public function forEachDocument(callable $callback): void
            {
            }
        };
        $migration->setProject(
            new Document(['$id' => 'console', '$sequence' => 'console']),
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

        $authorization->skip(fn () => $database->createDocument('notifications', new Document([
            '$id' => 'domain-expiry',
            'messageId' => 'domain-expiry',
            'recipientHash' => \md5('owner@example.com'),
            'type' => 'warning',
            'channel' => 'email',
            'projectId' => 'console',
            'projectInternalId' => 'console',
            'teamId' => 'team-a',
            'teamInternalId' => '1',
            'resourceType' => 'domains',
            'resourceId' => 'domain-a',
            'resourceInternalId' => '1',
            'title' => 'example.com expires in 30 days',
            'read' => false,
        ])));

        $mine = $authorization->skip(fn () => $database->find('notifications', [
            Query::equal('teamId', ['team-a']),
        ]));

        $this->assertCount(1, $mine);
        $this->assertSame('domain-a', $mine[0]->getAttribute('resourceId'));

        $theirs = $authorization->skip(fn () => $database->find('notifications', [
            Query::equal('teamId', ['team-b']),
        ]));

        $this->assertCount(0, $theirs);
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
        $database->createCollection(Collection::create(id: 'functions'));

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
        $database->createCollection(Collection::create(id: 'databases'));
        $database->createCollection(Collection::create(id: 'functions'));
        $database->createCollection(Collection::create(id: 'sites'));
        $database->createCollection(Collection::create(id: 'migrations'));

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

    public function testMigrateToTheV25ReleaseStopsShortOfV26Ownership(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization(defaultStatus: false);
        $platform = $this->createConfiguredDatabase($authorization, 'migrationV25ReleasePlatform', 'console');
        $platform->createAttribute('projects', Attribute::string('version', size: 16));
        $project = $platform->createDocument('projects', new Document([
            '$id' => 'pre-v25-project',
            'version' => '1.9.5',
        ]));

        $database = $this->createConfiguredDatabase($authorization, 'migrationV25ReleaseProject', 'projects');
        foreach (['status', 'stage'] as $attribute) {
            $database->createAttribute('migrations', Attribute::string($attribute, size: 0));
        }
        $database->createDocument('migrations', new Document([
            '$id' => 'migration',
            'status' => 'failed',
            'stage' => 'processing',
        ]));

        \ob_start();
        try {
            $this->runMigration('1.9.6', $platform, $database, $project, $authorization);
        } finally {
            \ob_end_clean();
        }

        foreach (['providerBranches', 'providerPaths'] as $attribute) {
            $this->assertContains($attribute, $this->attributeIds($database, 'functions'));
            $this->assertContains($attribute, $this->attributeIds($database, 'sites'));
        }
        $this->assertContains('scopes', $this->attributeIds($database, 'sites'));
        $this->assertContains('status', $this->attributeIds($database, 'databases'));
        $this->assertContains('resourceInternalId', $this->attributeIds($database, 'migrations'));

        $this->assertNotContains('migrationId', $this->attributeIds($database, 'databases'));
        $this->assertNotContains('migrationAttemptId', $this->attributeIds($database, 'databases'));
        $this->assertNotContains('attemptId', $this->attributeIds($database, 'migrations'));
        $this->assertSame('processing', $database->getDocument('migrations', 'migration')->getAttribute('stage'));
    }

    public function testMigrateRunsCumulativeV25AndV26FromPreV25Project(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization(defaultStatus: false);
        $platform = $this->createConfiguredDatabase($authorization, 'migrationV26PreV25Platform', 'console');
        $platform->createAttribute('projects', Attribute::string('version', size: 16));
        $project = $platform->createDocument('projects', new Document([
            '$id' => 'pre-v25-project',
            'version' => '1.9.5',
        ]));

        $database = $this->createConfiguredDatabase($authorization, 'migrationV26PreV25Project', 'projects');
        $database->createAttribute('databases', Attribute::string('legacy', size: 0));
        foreach (['legacy', 'status', 'stage'] as $attribute) {
            $database->createAttribute('migrations', Attribute::string($attribute, size: 0));
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
            $this->runMigration('2.0.0', $platform, $database, $project, $authorization);
            $this->assertCumulativeMigration($database);
            $this->runMigration('2.0.0', $platform, $database, $project, $authorization);
        } finally {
            \ob_end_clean();
        }

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

        $authorization = new Authorization(defaultStatus: false);
        $platform = $this->createConfiguredDatabase($authorization, 'migrationV26RcPlatform', 'console');
        $platform->createAttribute('projects', Attribute::string('version', size: 16));
        $project = $platform->createDocument('projects', new Document([
            '$id' => 'rc-project',
            'version' => '2.0.0-rc.2',
        ]));
        $database = $this->createConfiguredDatabase($authorization, 'migrationV26RcProject', 'projects');
        foreach (['status', 'legacy'] as $attribute) {
            $database->createAttribute('databases', Attribute::string($attribute, size: 0));
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
            $database->createAttribute('migrations', Attribute::string($attribute, size: 0));
        }
        foreach (['providerBranches', 'providerPaths'] as $attribute) {
            $database->createAttribute('functions', Attribute::string($attribute, array: true, size: 0));
            $database->createAttribute('sites', Attribute::string($attribute, array: true, size: 0));
        }
        $database->createAttribute('sites', Attribute::string('scopes', array: true, size: 0));
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
            $this->runMigration('2.0.0', $platform, $database, $project, $authorization);
            $this->assertCumulativeMigration($database);
            $this->runMigration('2.0.0', $platform, $database, $project, $authorization);
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

        $authorization = new Authorization(defaultStatus: false);
        $database = new class (new Memory(), new Cache(new NoCache())) extends Database {
            private bool $interleave = true;

            private bool $timestamped = false;

            #[\Override]
            public function withRequestTimestamp(?\DateTime $requestTimestamp, callable $callback): mixed
            {
                $this->timestamped = $requestTimestamp !== null;
                try {
                    return parent::withRequestTimestamp($requestTimestamp, $callback);
                } finally {
                    $this->timestamped = false;
                }
            }

            #[\Override]
            public function updateDocument(string $collection, string $id, Document $document, ?int $expectedVersion = null): Document
            {
                if ($this->interleave && $collection === 'migrations' && $this->timestamped) {
                    $this->interleave = false;
                    parent::updateDocument($collection, $id, new Document([
                        'attemptId' => 'attempt-retry',
                        'status' => 'processing',
                        'stage' => 'processing',
                    ]));
                }

                return parent::updateDocument($collection, $id, $document);
            }
        };
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV26Race')
            ->setNamespace('migration_v26_race_' . \uniqid());
        $database->create();

        foreach (Config::getParam('collections', [])['projects'] as $collection) {
            $database->createCollection(Collection::create(id: (string) $collection['$id']));
        }
        foreach ([Database::METADATA, 'audit'] as $id) {
            if ($database->findCollection($id) === null) {
                $database->createCollection(Collection::create(id: $id));
            }
        }
        foreach (['status', 'stage'] as $attribute) {
            $database->createAttribute('migrations', Attribute::string($attribute, size: 0));
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

    /**
     * V24 without its bucket and document walks, which need a full install rather than a fixture.
     */
    private function v24WithoutWalks(): V24
    {
        return new class () extends V24 {
            #[\Override]
            protected function migrateBuckets(): void
            {
            }

            #[\Override]
            public function forEachDocument(callable $callback): void
            {
            }
        };
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
            if ($database->findCollection($id) !== null) {
                continue;
            }

            $database->createCollection(Collection::create(id: $id));
        }

        if ($type === 'projects') {
            foreach ([Database::METADATA, 'audit'] as $id) {
                if ($database->findCollection($id) !== null) {
                    continue;
                }

                $database->createCollection(Collection::create(id: $id));
            }
        }

        return $database;
    }

    private function runMigration(
        string $version,
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
            $version,
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

    /**
     * A legacy install has users without the email metadata columns. That one
     * collection is a frozen snapshot of the old shape, written out rather than
     * derived from the current config, so it keeps describing the old install
     * even as the config moves on. Everything around it is built the way project
     * provisioning builds it, because execute() walks the whole project.
     *
     * Then does the thing the columns exist for: write a user carrying them.
     * Before the repair this fails with Unknown attribute: "emailCanonical".
     */
    public function testV25LetsALegacyInstallWriteAUserCarryingEmailMetadata(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV25EmailMetadata')
            ->setNamespace('migration_email_metadata_' . \uniqid());
        $database->create();

        (new Audit(new AdapterDatabase($database)))->setup();

        foreach (Config::getParam('collections', [])['projects'] as $key => $collection) {
            if ($key === 'users' || ($collection['$collection'] ?? '') !== Database::METADATA) {
                continue;
            }

            $database->createCollection(Collection::create(
                id: $key,
                attributes: \array_values($collection['attributes']),
                indexes: \array_values($collection['indexes']),
            ));
        }

        $database->createCollection(Collection::create(id: 'users', attributes: [
            Attribute::string('name', size: 256),
            Attribute::string('email', size: 320),
            Attribute::string('phone', size: 16),
            Attribute::boolean('status'),
            Attribute::boolean('emailVerification'),
            Attribute::boolean('phoneVerification'),
            Attribute::boolean('reset'),
            Attribute::boolean('mfa'),
        ]));

        $migration = new V25();
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

        $authorization->skip(fn () => $database->createDocument('users', new Document([
            '$id' => 'legacy-user',
            'name' => 'Legacy User',
            'email' => 'legacy.user@example.com',
            'status' => true,
            'emailVerification' => false,
            'emailCanonical' => 'legacyuser@example.com',
            'emailIsFree' => true,
            'emailIsDisposable' => false,
            'emailIsCorporate' => false,
            'emailIsCanonical' => false,
        ])));

        $user = $authorization->skip(fn () => $database->getDocument('users', 'legacy-user'));

        $this->assertSame('legacyuser@example.com', $user->getAttribute('emailCanonical'));
    }
}
