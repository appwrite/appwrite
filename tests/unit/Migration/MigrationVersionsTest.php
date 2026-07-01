<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use Appwrite\Migration\Version\V24;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
}
