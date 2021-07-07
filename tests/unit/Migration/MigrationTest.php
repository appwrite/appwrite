<?php

namespace Appwrite\Tests;

use Appwrite\Database\Document;
use Appwrite\Migration\Migration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

abstract class MigrationTest extends TestCase
{
    /**
     * @var PDO
     */
    protected \PDO $pdo;

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
    public function testMigrationVersions()
    {
        require_once __DIR__.'/../../../app/init.php';

        foreach (Migration::$versions as $version => $class) {
            $this->assertTrue(class_exists('Appwrite\\Migration\\Version\\'.$class));
        }
        // Test if current version exists
        $this->assertArrayHasKey(APP_VERSION_STABLE, Migration::$versions);
    }
}
