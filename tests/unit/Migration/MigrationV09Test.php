<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
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
        $document = $this->fixDocument(new Document([
            '$id' => uniqid(),
            '$collection' => Database::SYSTEM_COLLECTION_USERS,
            'status' => 0
        ]));

        $this->assertIsBool($document->getAttribute('status'));
        $this->assertEquals(true, $document->getAttribute('env', false));

        $document = $this->fixDocument(new Document([
            '$id' => uniqid(),
            '$collection' => Database::SYSTEM_COLLECTION_USERS,
            'status' => 1
        ]));

        $this->assertIsBool($document->getAttribute('status'));
        $this->assertEquals(true, $document->getAttribute('env', false));

        $document = $this->fixDocument(new Document([
            '$id' => uniqid(),
            '$collection' => Database::SYSTEM_COLLECTION_USERS,
            'status' => 2
        ]));

        $this->assertIsBool($document->getAttribute('status'));
        $this->assertEquals(false, $document->getAttribute('env', false));
    }
}
