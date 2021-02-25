<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Migration\Version\V06;
use Appwrite\Database\Database;
use Appwrite\Database\Document;

class MigrationV06Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');

        $this->migration = new V06($this->pdo);
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V06');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigration()
    {
        $document = $this->fixDocument(new Document([
            '$id' => uniqid(),
            '$collection' => Database::SYSTEM_COLLECTION_USERS,
            'password-update' => 123
        ]));

        $this->assertEquals($document->getAttribute('password-update', null), null);
        $this->assertEquals($document->getAttribute('passwordUpdate', null), 123);

        $document = $this->fixDocument(
            new Document([
                '$id' => uniqid(),
                '$collection' => Database::SYSTEM_COLLECTION_KEYS,
                'secret' => 123
            ])
        );

        $encrypted = json_decode($document->getAttribute('secret', null));
        $this->assertObjectHasAttribute('data', $encrypted);
        $this->assertObjectHasAttribute('method', $encrypted);
        $this->assertObjectHasAttribute('iv', $encrypted);
        $this->assertObjectHasAttribute('tag', $encrypted);
        $this->assertObjectHasAttribute('version', $encrypted);
    }
}
