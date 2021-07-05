<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Auth\Auth;
use Appwrite\Migration\Version\V08;

class MigrationV08Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->migration = new V08($this->pdo);
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V08');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigration()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'unique',
            '$collection' => Database::SYSTEM_COLLECTION_FUNCTIONS,
            'env' => 'node-16'
        ]));
        
        $this->assertEquals($document->getAttribute('env', null), null);
        $this->assertEquals($document->getAttribute('runtime', null), 'node-16');
    }
}
