<?php

namespace Tests\Unit\Migration;

use Appwrite\Migration\Migration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Document;

abstract class MigrationTest extends TestCase
{
    /**
     * @var Migration
     */
    protected Migration $migration;

    /**
     * @var ReflectionMethod
     */
    protected ReflectionMethod $method;

    /**
     * Runs every document fix twice, to prevent corrupted data on multiple migrations.
     *
     * @param Document $document
     */
    protected function fixDocument(Document $document)
    {
        return $this->method->invokeArgs($this->migration, [
            $this->method->invokeArgs($this->migration, [$document])
        ]);
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
}
