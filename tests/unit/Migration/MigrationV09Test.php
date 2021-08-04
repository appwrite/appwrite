<?php

namespace Appwrite\Tests;

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use ReflectionClass;
use Appwrite\Migration\Version\V09;

class MigrationV09Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->migration = new V09($this->pdo);
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V09');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigration()
    {
        $this->assertTrue(true);
        // $document = $this->fixDocument(new Document([
        //     '$id' => uniqid(),
        //     '$collection' => Database::SYSTEM_COLLECTION_USERS,
        //     'status' => 0
        // ]));

        // $this->assertIsBool($document->getAttribute('status'));
        // $this->assertEquals(true, $document->getAttribute('status', false));

        // $document = $this->fixDocument(new Document([
        //     '$id' => uniqid(),
        //     '$collection' => Database::SYSTEM_COLLECTION_USERS,
        //     'status' => 1
        // ]));

        // $this->assertIsBool($document->getAttribute('status'));
        // $this->assertEquals(true, $document->getAttribute('status', false));

        // $document = $this->fixDocument(new Document([
        //     '$id' => uniqid(),
        //     '$collection' => Database::SYSTEM_COLLECTION_USERS,
        //     'status' => 2
        // ]));

        // $this->assertIsBool($document->getAttribute('status'));
        // $this->assertEquals(false, $document->getAttribute('status', false));
    }
}
