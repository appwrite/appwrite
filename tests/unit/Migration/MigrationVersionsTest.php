<?php

declare(strict_types=1);

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use Appwrite\Migration\Version\V24;
use Appwrite\Migration\Version\V25;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
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

    public function testCreateAttributesFromCollectionSkipsExistingAttributes(): void
    {
        require_once __DIR__ . '/../../../app/init.php';

        $authorization = new Authorization();
        $database = new Database(new Memory(), new Cache(new None()));
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
        $database = new Database(new Memory(), new Cache(new None()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationV25ProviderAttributes')
            ->setNamespace('migration_provider_attributes_' . \uniqid());
        $database->create();
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
}
