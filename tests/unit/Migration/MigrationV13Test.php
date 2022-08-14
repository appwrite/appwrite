<?php

namespace Tests\Unit\Migration;

use ReflectionClass;
use Appwrite\Migration\Version\V13;
use Utopia\Database\Document;

class MigrationV13Test extends MigrationTest
{
    public function setUp(): void
    {
        $this->migration = new V13();
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V13');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigrateFunctions(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'func',
            '$collection' => 'functions',
            'events' => ['account.create', 'users.create']
        ]));

        $this->assertEquals($document->getAttribute('events'), ['users.*.create']);
    }

    public function testMigrationWebhooks(): void
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'webh',
            '$collection' => 'webhooks',
            'events' => ['account.create', 'users.create']
        ]));

        $this->assertEquals($document->getAttribute('events'), ['users.*.create']);
    }
}
