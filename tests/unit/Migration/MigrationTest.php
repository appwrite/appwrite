<?php

namespace Appwrite\Tests;

use Appwrite\Migration\Migration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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
    public function testMigrationVersions()
    {
        require_once __DIR__ . '/../../../app/init.php';

        foreach (Migration::$versions as $class) {
            $this->assertTrue(class_exists('Appwrite\\Migration\\Version\\' . $class));
        }
        // Test if current version exists
        $this->assertArrayHasKey(APP_VERSION_STABLE, Migration::$versions);
    }

    public function testHasDifference()
    {
        $this->assertFalse(Migration::hasDifference([], []));
        $this->assertFalse(Migration::hasDifference([
            'a' => true,
            'b' => 'abc',
            'c' => 123,
            'd' => ['a', 'b', 'c'],
            'nested' => [
                'a' => true,
                'b' => 'abc',
                'c' => 123,
                'd' => ['a', 'b', 'c']
            ]
        ], [
            'a' => true,
            'b' => 'abc',
            'c' => 123,
            'd' => ['a', 'b', 'c'],
            'nested' => [
                'a' => true,
                'b' => 'abc',
                'c' => 123,
                'd' => ['a', 'b', 'c']
            ]
        ]));
        $this->assertTrue(Migration::hasDifference([
            'a' => true
        ], [
            'b' => true
        ]));
        $this->assertTrue(Migration::hasDifference([
            'a' => 'true'
        ], [
            'a' => true
        ]));
        $this->assertTrue(Migration::hasDifference([
            'a' => true
        ], [
            'a' => false
        ]));
        $this->assertTrue(Migration::hasDifference([
            'nested' => [
                'a' => true
            ]
        ], [
            'nested' => []
        ]));
        $this->assertTrue(Migration::hasDifference([
            'nested' => [
                'a' => true,
                'b' => 'abc',
                'c' => 123,
                'd' => ['a', 'b', 'c']
            ]
        ], [
            'nested' => [
                'a' => true,
                'c' => '123',
                'd' => ['a', 'b', 'c']
            ]
        ]));
    }
}
