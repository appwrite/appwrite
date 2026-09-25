<?php

namespace Tests\Unit\Event;

use Appwrite\Event\Message\Database as DatabaseMessage;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

class DatabasePublisherTest extends TestCase
{
    public function testDifferentShardsPublishToTheConfiguredSharedQueue(): void
    {
        $broker = new MockPublisher();
        $publisher = new DatabasePublisher($broker, new Queue('shared-ddl'));
        foreach (['mysql://shard-a', 'shard-b', ''] as $i => $dsn) {
            $publisher->enqueue(new DatabaseMessage(
                project: new Document(['$id' => "project-$i", 'database' => $dsn]),
                type: 'createAttribute',
            ));
        }

        $this->assertSame(['project-0', 'project-1', 'project-2'], array_column(array_column($broker->getEvents('shared-ddl'), 'project'), '$id'));
        $this->assertSame(3, $publisher->getSize());
        $this->assertNull($broker->getEvents('shard-a'));
        $this->assertNull($broker->getEvents('shard-b'));
    }

    public function testExplicitQueueOverrideIsStillSupported(): void
    {
        $broker = new MockPublisher();
        $publisher = new DatabasePublisher($broker, new Queue('shared-ddl'));
        $publisher->enqueue(new DatabaseMessage(type: 'createIndex'), new Queue('override'));

        $this->assertSame('createIndex', $broker->getEvents('override')[0]['type']);
        $this->assertSame(0, $publisher->getSize());
    }
}
