<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Appwrite\Event\Redelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Queue\Message;

final class RedeliveryTest extends TestCase
{
    public function testAFirstDeliveryHandlesEveryTargetWithoutReadingTheCache(): void
    {
        $cache = new UnreliableCache();
        $cache->failLoads = true;

        $redelivery = new Redelivery(new Cache($cache), 'webhooks', $this->message(['eventId' => 'event-1']), handledWhenUnknown: true);

        $this->assertFalse($redelivery->wasHandled('webhook-a'));
        $this->assertSame(0, $cache->loads);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function redeliveredPids(): \Iterator
    {
        // JetStream delivers the stored message again, pid and all.
        yield 'same pid' => ['pid-1'];
        // The Redis broker requeues a failed message under a fresh pid.
        yield 'fresh pid' => ['pid-1-requeued'];
    }

    #[DataProvider('redeliveredPids')]
    public function testARedeliverySkipsOnlyTheTargetsTheFailedDeliveryHandled(string $pid): void
    {
        $cache = new Cache(new Memory());
        $payload = ['eventId' => 'event-1', 'payload' => ['$id' => 'row-1']];

        $first = new Redelivery($cache, 'webhooks', $this->message($payload, 'pid-1', attempts: 0), handledWhenUnknown: false);
        $this->assertTrue($first->record(['webhook-a']));

        $again = new Redelivery($cache, 'webhooks', $this->message($payload, $pid, attempts: 1), handledWhenUnknown: false);

        $this->assertTrue($again->wasHandled('webhook-a'));
        $this->assertFalse($again->wasHandled('webhook-b'));
        $this->assertSame($first->id('webhook-a'), $again->id('webhook-a'));
    }

    public function testAMessageWithoutAnEventIdIsNamedByItsPayload(): void
    {
        $cache = new Cache(new Memory());
        $payload = ['payload' => ['$id' => 'row-1'], 'events' => ['databases.*.create']];

        $first = new Redelivery($cache, 'functions', $this->message($payload, 'pid-1', attempts: 0), handledWhenUnknown: true);
        $first->record(['function-a']);

        $requeued = new Redelivery($cache, 'functions', $this->message($payload, 'pid-2', attempts: 1), handledWhenUnknown: true);
        $other = new Redelivery($cache, 'functions', $this->message(['payload' => ['$id' => 'row-2']] + $payload, 'pid-3', attempts: 1), handledWhenUnknown: true);

        $this->assertTrue($requeued->wasHandled('function-a'));
        $this->assertSame($first->id('function-a'), $requeued->id('function-a'));
        $this->assertFalse($other->wasHandled('function-a'), 'A different event is not the same message');
    }

    public function testIdsDifferForEveryOtherEventAndTarget(): void
    {
        $cache = new Cache(new Memory());
        $one = new Redelivery($cache, 'webhooks', $this->message(['eventId' => 'event-1']), handledWhenUnknown: false);
        $two = new Redelivery($cache, 'webhooks', $this->message(['eventId' => 'event-2']), handledWhenUnknown: false);

        $ids = [$one->id('webhook-a'), $one->id('webhook-b'), $two->id('webhook-a'), $two->id('webhook-b')];

        $this->assertCount(4, \array_unique($ids));
    }

    public function testOneConsumerDoesNotSeeWhatAnotherHandled(): void
    {
        $cache = new Cache(new Memory());
        $payload = ['eventId' => 'event-1'];

        (new Redelivery($cache, 'webhooks', $this->message($payload, attempts: 0), handledWhenUnknown: false))->record(['target']);

        $functions = new Redelivery($cache, 'functions', $this->message($payload, attempts: 1), handledWhenUnknown: false);

        $this->assertFalse($functions->wasHandled('target'));
    }

    /**
     * @return \Iterator<string, array{bool}>
     */
    public static function unknownAnswers(): \Iterator
    {
        yield 'skip what may have run' => [true];
        yield 'handle again what may have run' => [false];
    }

    #[DataProvider('unknownAnswers')]
    public function testAnUnreadableRecordGivesTheConsumersChosenAnswer(bool $handledWhenUnknown): void
    {
        $cache = new UnreliableCache();
        $cache->failLoads = true;

        $redelivery = new Redelivery(new Cache($cache), 'functions', $this->message(['eventId' => 'event-1'], attempts: 1), $handledWhenUnknown);

        $this->assertSame($handledWhenUnknown, $redelivery->wasHandled('function-a'));
    }

    public function testARecordTheCacheRefusedIsReportedAndTheRestAreStillWritten(): void
    {
        $adapter = new UnreliableCache();
        $adapter->refuseSaves = ['redelivery:webhooks:' . \md5('event-1:webhook-a')];
        $cache = new Cache($adapter);
        $payload = ['eventId' => 'event-1'];

        $recorded = (new Redelivery($cache, 'webhooks', $this->message($payload, attempts: 0), handledWhenUnknown: false))->record(['webhook-a', 'webhook-b']);

        $again = new Redelivery($cache, 'webhooks', $this->message($payload, attempts: 1), handledWhenUnknown: false);
        $this->assertFalse($recorded);
        $this->assertFalse($again->wasHandled('webhook-a'));
        $this->assertTrue($again->wasHandled('webhook-b'));
    }

    public function testARecordThatThrowsIsReported(): void
    {
        $adapter = new UnreliableCache();
        $adapter->failSaves = true;

        $redelivery = new Redelivery(new Cache($adapter), 'functions', $this->message(['eventId' => 'event-1']), handledWhenUnknown: true);

        $this->assertFalse($redelivery->record(['function-a']));
        $this->assertTrue($redelivery->record([]), 'Nothing to record is not a failure');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function message(array $payload, string $pid = 'pid-1', int $attempts = 0): Message
    {
        return new Message([
            'pid' => $pid,
            'queue' => 'v1-tests',
            'timestamp' => \time(),
            'payload' => $payload,
            'attempts' => $attempts,
        ]);
    }
}

/**
 * A cache that can fail the way a remote one does: a read that throws, a write
 * that throws, and a write the server refuses.
 */
final class UnreliableCache extends Memory
{
    public bool $failLoads = false;

    public bool $failSaves = false;

    /** @var list<string> */
    public array $refuseSaves = [];

    public int $loads = 0;

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;

        if ($this->failLoads) {
            throw new \RedisException('read error on connection');
        }

        return parent::load($key, $ttl, $hash);
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if ($this->failSaves) {
            throw new \RedisException('read error on connection');
        }

        if (\in_array($key, $this->refuseSaves, true)) {
            return false;
        }

        return parent::save($key, $data, $hash, $ttl);
    }
}
