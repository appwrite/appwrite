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

    public function testMigrationProjects()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'project',
            '$collection' => 'projects',
            'name' => 'Appwrite',
            'version' => '0.12.0',
            'search' => ''
        ]));

        $this->assertEquals($document->getAttribute('version'), '0.13.0');
        $this->assertEquals($document->getAttribute('search'), 'project Appwrite');
    }

    public function testMigrationUsers()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'user',
            '$collection' => 'users',
            'email' => 'test@appwrite.io',
            'name' => 'Torsten Dittmann'
        ]));

        $this->assertEquals($document->getAttribute('search'), 'user test@appwrite.io Torsten Dittmann');
    }

    public function testMigrationTeams()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'team',
            '$collection' => 'teams',
            'name' => 'Appwrite'
        ]));

        $this->assertEquals($document->getAttribute('search'), 'team Appwrite');
    }

    public function testMigrationFunctions()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'function',
            '$collection' => 'functions',
            'name' => 'My Function',
            'runtime' => 'php-8.0'
        ]));

        $this->assertEquals($document->getAttribute('search'), 'function My Function php-8.0');
    }

    public function testMigrationExecutions()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'execution',
            '$collection' => 'executions',
            'functionId' => 'function'
        ]));

        $this->assertEquals($document->getAttribute('search'), 'execution function');
    }
}
