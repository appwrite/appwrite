<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Appwrite\Event\Delivery\Fanout;
use Appwrite\Event\Delivery\Receipt;
use Appwrite\Event\Realtime;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../app/init.php';

final class RealtimeTest extends TestCase
{
    public function testEnvelopeIsPublishedOnceAndReceiptSuppressesRetry(): void
    {
        $adapter = new CapturingAdapter();
        $event = new Realtime($adapter, $this->createFanout());
        $event
            ->setProject(new Document([
                '$id' => 'project-1',
                '$sequence' => 'project-1',
            ]))
            ->setEnvelopeId('envelope-1')
            ->setEvent('users.[userId].create')
            ->setParam('userId', 'user-1')
            ->setPayload([
                '$id' => 'user-1',
                '$permissions' => [],
            ]);

        $this->assertTrue($event->trigger());
        $this->assertTrue($event->trigger());

        $this->assertCount(1, $adapter->messages);
        $this->assertSame('envelope-1', $adapter->messages[0]['options']['envelopeId']);
    }

    public function testFailedDeliveryLeavesReceiptMissingForRetry(): void
    {
        $adapter = new CapturingAdapter();
        $adapter->failures = 1;
        $event = new Realtime($adapter, $this->createFanout());
        $event
            ->setProject(new Document([
                '$id' => 'project-1',
                '$sequence' => 'project-1',
            ]))
            ->setEnvelopeId('envelope-1')
            ->setEvent('users.[userId].create')
            ->setParam('userId', 'user-1')
            ->setPayload([
                '$id' => 'user-1',
                '$permissions' => [],
            ]);

        try {
            $event->trigger();
            $this->fail('Expected the interrupted realtime publication to propagate.');
        } catch (\Exception $error) {
            $this->assertSame('realtime delivery interrupted', $error->getMessage());
        }

        $this->assertTrue($event->trigger());
        $this->assertTrue($event->trigger());

        $this->assertCount(1, $adapter->messages);
        $this->assertSame('envelope-1', $adapter->messages[0]['options']['envelopeId']);
    }

    private function createFanout(): Fanout
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setDatabase('realtimeReceipts')
            ->setNamespace('realtime_receipts_' . \uniqid());
        $database->create();

        $collections = require __DIR__ . '/../../../app/config/collections.php';
        $collection = $collections['console']['eventReceipts'];
        $database->createCollection(
            'eventReceipts',
            \array_map(
                static fn (array $attribute): Document => new Document($attribute),
                $collection['attributes']
            ),
            \array_map(
                static fn (array $index): Document => new Document($index),
                $collection['indexes']
            ),
        );

        return new Fanout(new Receipt($database));
    }
}
