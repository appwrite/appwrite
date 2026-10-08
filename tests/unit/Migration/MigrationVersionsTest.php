<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use Appwrite\Migration\Version\V24;
use Appwrite\Migration\Version\V25;
use Appwrite\Migration\Version\V26;
use PHPUnit\Framework\TestCase;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Audit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

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
        $database->createCollection('notifications');

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

    /**
     * A legacy install has notifications without the team columns. The fixture
     * below is a frozen snapshot of that shape, written out rather than derived
     * from the current config, so it keeps describing the old install even as
     * the config moves on.
     *
     * Drives migrateCollections, as the other migration tests here do:
     * execute() also walks every document in every console collection, which
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

        $string = fn (string $id, int $size = Database::LENGTH_KEY): Document => new Document([
            '$id' => $id,
            'type' => Database::VAR_STRING,
            'format' => '',
            'size' => $size,
            'signed' => true,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => [],
        ]);

        $database->createCollection('notifications', [
            $string('messageId'),
            $string('recipientHash', 64),
            $string('type', 100),
            $string('channel', 64),
            $string('projectId'),
            $string('projectInternalId'),
            $string('resourceType', 64),
            $string('resourceId'),
            $string('resourceInternalId'),
            $string('title', 256),
            new Document([
                '$id' => 'read',
                'type' => Database::VAR_BOOLEAN,
                'format' => '',
                'size' => 0,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ]);

        $migration = new V25();
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
        $database->createCollection('functions');

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
        $database->createCollection('databases');
        $database->createCollection('functions');
        $database->createCollection('sites');

        $migration = new V25();
        $migration->setProject(
            new Document(['$id' => 'project', '$sequence' => '1']),
            $database,
            $database,
            $authorization,
        );

        $migration->createAttributesFromCollection($database, 'functions', ['providerBranches']);

        $migrateCollections = new \ReflectionMethod($migration, 'migrateCollections');
        \ob_start();
        try {
            $migrateCollections->invoke($migration);
            $migrateCollections->invoke($migration);
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

            $database->createCollection(
                $key,
                \array_map(fn (array $attribute) => new Document($attribute), $collection['attributes']),
                \array_map(fn (array $index) => new Document($index), $collection['indexes']),
            );
        }

        $string = fn (string $id, int $size): Document => new Document([
            '$id' => $id,
            'type' => Database::VAR_STRING,
            'format' => '',
            'size' => $size,
            'signed' => true,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => [],
        ]);

        $boolean = fn (string $id): Document => new Document([
            '$id' => $id,
            'type' => Database::VAR_BOOLEAN,
            'format' => '',
            'size' => 0,
            'signed' => true,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => [],
        ]);

        $database->createCollection('users', [
            $string('name', 256),
            $string('email', 320),
            $string('phone', 16),
            $boolean('status'),
            $boolean('emailVerification'),
            $boolean('phoneVerification'),
            $boolean('reset'),
            $boolean('mfa'),
        ]);

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

    /**
     * An install upgraded past Videos has none of the seven collections. V26
     * creates them and seeds the default encode ladder. A second run must not
     * insert another ladder.
     */
    public function testV26CreatesVideoCollectionsAndSeedsProfilesIdempotently(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV26Videos')
            ->setNamespace('migration_videos_' . \uniqid());
        $database->create();

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
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        $collections = [
            'videos',
            'videos_previews',
            'videos_renditions',
            'videos_renditions_segments',
            'videos_profiles',
            'videos_captions',
            'videos_captions_segments',
        ];
        foreach ($collections as $collectionId) {
            $this->assertFalse(
                $database->getCollection($collectionId)->isEmpty(),
                "Expected collection \"{$collectionId}\" to exist"
            );
        }

        $profiles = $authorization->skip(fn () => $database->find('videos_profiles', [
            Query::limit(100),
        ]));
        $this->assertCount(6, $profiles);

        $names = \array_map(fn (Document $profile) => $profile->getAttribute('name'), $profiles);
        \sort($names);
        $this->assertSame(['1080p', '2160p', '360p', '480p', '576p', '720p'], $names);

        foreach (['videos_renditions', 'videos_captions', 'videos_previews'] as $collectionId) {
            $attributeIds = [];
            foreach ($database->getCollection($collectionId)->getAttribute('attributes', []) as $attribute) {
                $attributeIds[] = $attribute instanceof Document ? $attribute->getId() : ($attribute['$id'] ?? '');
            }
            $this->assertContains('size', $attributeIds, "Expected \"{$collectionId}\" to store retained bytes");
        }

        $previewAttributeIds = [];
        foreach ($database->getCollection('videos_previews')->getAttribute('attributes', []) as $attribute) {
            $previewAttributeIds[] = $attribute instanceof Document ? $attribute->getId() : ($attribute['$id'] ?? '');
        }
        $this->assertNotContains('second', $previewAttributeIds);
    }

    public function testV26SkipsConsoleProject(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV26Console')
            ->setNamespace('migration_videos_console_' . \uniqid());
        $database->create();

        $migration = new V26();
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

        $this->assertTrue($database->getCollection('videos')->isEmpty());
        $this->assertTrue($database->getCollection('videos_profiles')->isEmpty());
    }

    /**
     * On a shared-tables host the physical table and its _metadata row belong
     * to no tenant. Each project still gets its own encode ladder because
     * unique indexes lead with _tenant.
     */
    public function testV26SharedTablesCreateGlobalCollectionsAndPerTenantProfiles(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV26Shared')
            ->setNamespace('migration_videos_shared_' . \uniqid())
            ->setSharedTables(true)
            ->setTenant(null);
        $database->create();

        $migrationA = new V26();
        $database->setTenant('tenant-a');
        $migrationA->setProject(
            new Document(['$id' => 'project-a', '$sequence' => 'tenant-a']),
            $database,
            $database,
            $authorization,
        );

        \ob_start();
        try {
            $migrationA->execute();
        } finally {
            \ob_end_clean();
        }

        $metadata = $database->withTenant(null, fn () => $database->getDocument(Database::METADATA, 'videos'));
        $this->assertFalse($metadata->isEmpty());
        $this->assertNull($metadata->getTenant());

        $profilesA = $authorization->skip(fn () => $database->find('videos_profiles', [
            Query::limit(100),
        ]));
        $this->assertCount(6, $profilesA);

        $migrationB = new V26();
        $database->setTenant('tenant-b');
        $migrationB->setProject(
            new Document(['$id' => 'project-b', '$sequence' => 'tenant-b']),
            $database,
            $database,
            $authorization,
        );

        \ob_start();
        try {
            $migrationB->execute();
        } finally {
            \ob_end_clean();
        }

        $profilesB = $authorization->skip(fn () => $database->find('videos_profiles', [
            Query::limit(100),
        ]));
        $this->assertCount(6, $profilesB);

        $database->setTenant('tenant-a');
        $profilesAAgain = $authorization->skip(fn () => $database->find('videos_profiles', [
            Query::limit(100),
        ]));
        $this->assertCount(6, $profilesAAgain);

        $idsA = \array_map(fn (Document $profile) => $profile->getId(), $profilesAAgain);
        $idsB = \array_map(fn (Document $profile) => $profile->getId(), $profilesB);
        $this->assertSame([], \array_intersect($idsA, $idsB));
    }
}
