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
        $this->assertArrayHasKey(APP_VERSION_STABLE, Migration::$versions);
    }

    public function testHasDifference(): void
    {
        $this->assertFalse(Migration::hasDifference([], []));
        $this->assertFalse(Migration::hasDifference([
            'bool' => true,
            'string' => 'abc',
            'int' => 123,
            'array' => ['a', 'b', 'c'],
            'assoc' => [
                'a' => true,
                'b' => 'abc',
                'c' => 123,
                'd' => ['a', 'b', 'c']
            ]
        ], [
            'bool' => true,
            'string' => 'abc',
            'int' => 123,
            'array' => ['a', 'b', 'c'],
            'assoc' => [
                'a' => true,
                'b' => 'abc',
                'c' => 123,
                'd' => ['a', 'b', 'c']
            ]
        ]));
        $this->assertFalse(Migration::hasDifference([
            'bool' => true,
            'string' => 'abc',
            'int' => 123,
            'array' => ['a', 'b', 'c'],
            'assoc' => [
                'a' => true,
                'b' => 'abc',
                'c' => 123,
                'd' => ['a', 'b', 'c']
            ]
        ], [
            'string' => 'abc',
            'assoc' => [
                'a' => true,
                'b' => 'abc',
                'c' => 123,
                'd' => ['a', 'b', 'c']
            ],
            'int' => 123,
            'array' => ['a', 'b', 'c'],
            'bool' => true,

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
            'assoc' => [
                'bool' => true,
                'string' => 'abc',
                'int' => 123,
                'array' => ['a', 'b', 'c']
            ]
        ], [
            'nested' => [
                'a' => true,
                'int' => '123',
                'array' => ['a', 'b', 'c']
            ]
        ]));
    }
}
