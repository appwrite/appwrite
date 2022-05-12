<?php

namespace Appwrite\Tests;

use Appwrite\Event\Validator\Event;
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

    public function testMigrateFunctions()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'func',
            '$collection' => 'functions',
            'events' => ['account.create', 'users.create']
        ]));

        $this->assertEquals($document->getAttribute('events'), ['users.*.create']);
    }

    public function testMigrationWebhooks()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'webh',
            '$collection' => 'webhooks',
            'events' => ['account.create', 'users.create']
        ]));

        $this->assertEquals($document->getAttribute('events'), ['users.*.create']);
    }

    public function testEventsConversion()
    {
        $migration = new V13();
        $events = $migration->migrateEvents($migration->events);
        foreach ($events as $event) {
            $this->assertTrue((new Event())->isValid($event), $event);
        }
        $this->assertCount(44, $events);
    }
}
