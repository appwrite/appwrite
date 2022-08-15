<?php

namespace Tests\Unit\Migration;

use ReflectionClass;
use Appwrite\Migration\Version\V14;
use Utopia\Database\Document;
use Utopia\Database\ID;

class MigrationV14Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->migration = new V14();
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V14');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigrateProjects(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('projects'),
            'version' => '0.14.0'
        ]));

        $this->assertEquals($document->getAttribute('version'), '0.15.0');
        $this->assertEquals($document->getAttribute('version'), '0.15.0');
    }

    public function testMigrateKeys(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => 'keys'
        ]));

        $this->assertArrayHasKey('expire', $document->getArrayCopy());
        $this->assertEquals($document->getAttribute('expire'), 0);
    }

    public function testMigrateWebhooks(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => 'webhooks'
        ]));

        $this->assertArrayHasKey('signatureKey', $document->getArrayCopy());
        $this->assertEquals(strlen($document->getAttribute('signatureKey')), 128);
    }

    public function testMigrateUsers(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('users'),
            'phoneVerification' => null
        ]));

        $this->assertArrayHasKey('phoneVerification', $document->getArrayCopy());
        $this->assertFalse($document->getAttribute('phoneVerification'));
    }

    public function testMigratePlatforms(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('platforms'),
            '$createdAt' => null,
            '$updatedAt' => null,
            'dateCreated' => 123456789,
            'dateUpdated' => 987654321
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
        $this->assertEquals($document->getUpdatedAt(), 987654321);
    }

    public function testMigrateFunctions(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('functions'),
            '$createdAt' => null,
            '$updatedAt' => null,
            'dateCreated' => 123456789,
            'dateUpdated' => 987654321
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
        $this->assertEquals($document->getUpdatedAt(), 987654321);
    }

    public function testMigrateDeployments(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('deployments'),
            '$createdAt' => null,
            'dateCreated' => 123456789,
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
    }

    public function testMigrateExecutions(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('executions'),
            '$createdAt' => null,
            'dateCreated' => 123456789,
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
    }

    public function testMigrateTeams(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('teams'),
            '$createdAt' => null,
            'dateCreated' => 123456789,
        ]));

        $this->assertEquals($document->getCreatedAt(), 123456789);
    }

    public function testMigrateAudits(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('audit'),
            'resource' => 'collection/movies',
            'event' => 'collections.movies.create'
        ]));

        $this->assertEquals($document->getAttribute('resource'), 'database/default/collection/movies');
        $this->assertEquals($document->getAttribute('event'), 'databases.default.collections.movies.create');

        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('audit'),
            'resource' => 'document/avatar',
            'event' => 'collections.movies.documents.avatar.create'
        ]));

        $this->assertEquals($document->getAttribute('resource'), 'database/default/collection/movies/document/avatar');
        $this->assertEquals($document->getAttribute('event'), 'databases.default.collections.movies.documents.avatar.create');
    }

    public function testMigrateStats(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('stats'),
            'metric' => 'database.collections.62b2039844d4277495d0.documents.create'
        ]));

        $this->assertEquals($document->getAttribute('metric'), 'databases.default.collections.62b2039844d4277495d0.documents.create');

        $document = $this->fixDocument(new Document([
            '$id' => ID::custom('appwrite'),
            '$collection' => ID::custom('stats'),
            'metric' => 'users.create'
        ]));

        $this->assertEquals($document->getAttribute('metric'), 'users.create');
    }
}
