<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Migration\Version\V12;
use Utopia\Database\Document;

class MigrationV12Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->migration = new V12();
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V12');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigration()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'project',
            '$collection' => 'projects',
            'version' => '0.12.0'
        ]));

        $this->assertEquals($document->getAttribute('version'), '0.13.0');
    }
}
