<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use Appwrite\Migration\Version\V24;
use Appwrite\Migration\Version\V25;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
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

    /**
     * A mapping newer than APP_VERSION_STABLE means a version was given a
     * migration but never marked stable, which is how 1.9.1 through 1.9.4 came
     * to exist in this map with no release behind them.
     */
    public function testNoVersionIsMappedAheadOfStable(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        if (\str_contains(APP_VERSION_STABLE, 'RC') || \str_contains(APP_VERSION_STABLE, '-rc.')) {
            $this->markTestSkipped('Release candidates are mapped when the final version is cut.');
        }

        foreach (\array_keys(Migration::$versions) as $mapped) {
            $this->assertLessThanOrEqual(
                0,
                \version_compare((string) $mapped, APP_VERSION_STABLE),
                "Migration::\$versions maps {$mapped}, which is newer than APP_VERSION_STABLE " . APP_VERSION_STABLE . '.'
            );
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
}
