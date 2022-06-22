<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Migration\Version\V14;
use Utopia\Database\Document;

class MigrationV14Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->migration = new V14();
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V14');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigrateProject()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'projects',
            'version' => '0.14.0'
        ]));

        $this->assertEquals($document->getAttribute('version'), '0.15.0');
        $this->assertEquals($document->getAttribute('version'), '0.15.0');
    }

    public function testMigrateKey()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'keys'
        ]));

        $this->assertArrayHasKey('expire', $document->getArrayCopy());
        $this->assertEquals($document->getAttribute('expire'), 0);
    }

    public function testMigrateWebhooks()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'webhooks'
        ]));

        $this->assertArrayHasKey('signatureKey', $document->getArrayCopy());
        $this->assertEquals(strlen($document->getAttribute('signatureKey')), 128);
    }

    public function testMigrateUsers()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'users',
            'phoneVerification' => null
        ]));

        $this->assertArrayHasKey('phoneVerification', $document->getArrayCopy());
        $this->assertFalse($document->getAttribute('phoneVerification'));
    }

    public function testMigratePlatforms()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'platforms',
            '$createdAt' => null,
            '$updatedAt' => null,
            'dateCreated' => 123456789,
            'dateUpdated' => 987654321
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
        $this->assertEquals($document->getUpdatedAt(), 987654321);
    }

    public function testMigrateFunctions()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'functions',
            '$createdAt' => null,
            '$updatedAt' => null,
            'dateCreated' => 123456789,
            'dateUpdated' => 987654321
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
        $this->assertEquals($document->getUpdatedAt(), 987654321);
    }

    public function testMigrateDeployments()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'deployments',
            '$createdAt' => null,
            'dateCreated' => 123456789,
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
    }

    public function testMigrateExecutions()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'executions',
            '$createdAt' => null,
            'dateCreated' => 123456789,
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
    }

    public function testMigrateTeams()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'appwrite',
            '$collection' => 'teams',
            '$createdAt' => null,
            'dateCreated' => 123456789,
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
    }
}
