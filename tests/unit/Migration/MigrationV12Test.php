<?php

namespace Tests\Unit\Migration;

use ReflectionClass;
use Appwrite\Migration\Version\V12;
use Utopia\Database\Document;
use Utopia\Database\ID;

class MigrationV12Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->migration = new V12();
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V12');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigrationProjects(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('project'),
            '$collection' => ID::custom('projects'),
            'name' => 'Appwrite',
            'version' => '0.12.0',
            'search' => ''
        ]));

        $this->assertEquals($document->getAttribute('version'), '0.13.0');
        $this->assertEquals($document->getAttribute('search'), 'project Appwrite');
    }

    public function testMigrationUsers(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('user'),
            '$collection' => ID::custom('users'),
            'email' => 'test@appwrite.io',
            'name' => 'Torsten Dittmann'
        ]));

        $this->assertEquals($document->getAttribute('search'), 'user test@appwrite.io Torsten Dittmann');
    }

    public function testMigrationTeams(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('team'),
            '$collection' => ID::custom('teams'),
            'name' => 'Appwrite'
        ]));

        $this->assertEquals($document->getAttribute('search'), 'team Appwrite');
    }

    public function testMigrationFunctions(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('function'),
            '$collection' => ID::custom('functions'),
            'name' => 'My Function',
            'runtime' => 'php-8.0'
        ]));

        $this->assertEquals($document->getAttribute('search'), 'function My Function php-8.0');
    }

    public function testMigrationExecutions(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('execution'),
            '$collection' => ID::custom('executions'),
            'functionId' => ID::custom('function')
        ]));

        $this->assertEquals($document->getAttribute('search'), 'execution function');
    }
}
