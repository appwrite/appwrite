<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use Appwrite\Migration\Version\V24;
use Appwrite\Migration\Version\V25;
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
        $this->assertInstanceOf(Collection::class, $collection);

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
