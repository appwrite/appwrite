<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Base as BaseMessage;
use Appwrite\Event\Publisher\Base as TypedPublisher;
use Appwrite\Event\Publisher\Plain;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Queue\Queue;

/**
 * What a handler will actually be handed.
 *
 * Every consumer of these queues reconstructs from arrays, and JSON used to make that true by
 * accident: it flattens an object on the way out and returns an array on the way in. A codec that
 * preserves types does not, and the first attempt at igbinary took worker-webhooks down for hours
 * on a TypeError thrown by every delivery of 701 messages.
 *
 * So these assert the published payload rather than the message: what reached the broker, at every
 * depth, is arrays and scalars.
 */
final class PublisherPayloadTest extends TestCase
{
    public function testADocumentInAPayloadReachesTheBrokerAsAnArray(): void
    {
        $publisher = new MockPublisher();
        $queue = new Queue('v1-tests');

        (new TypedPublisher(new Plain($publisher)))->publish($queue, $this->message([
            'project' => new Document(['$id' => 'p1', 'name' => 'test']),
            'userId' => 'u1',
        ]));

        $published = $publisher->getEvents('v1-tests')[0];

        $this->assertSame([], $this->objectsIn($published), 'nothing an igbinary consumer would receive as an object');
        $this->assertSame('p1', $published['project']['$id']);
        $this->assertSame('test', $published['project']['name']);
        $this->assertSame('u1', $published['userId']);
    }

    public function testADocumentNestedInsideADocumentIsFlattenedToo(): void
    {
        $publisher = new MockPublisher();
        $queue = new Queue('v1-tests');

        // getArrayCopy() flattens one level, so the inner Document survives it. This is the
        // case a per-message toArray() reduction misses.
        (new TypedPublisher(new Plain($publisher)))->publish($queue, $this->message([
            'project' => new Document([
                '$id' => 'p1',
                'team' => new Document(['$id' => 't1']),
            ]),
        ]));

        $published = $publisher->getEvents('v1-tests')[0];

        $this->assertSame([], $this->objectsIn($published));
        $this->assertSame('t1', $published['project']['team']['$id']);
    }

    public function testAnEmptyMapRenderedForJsonArrivesAsAnEmptyArray(): void
    {
        $publisher = new MockPublisher();
        $queue = new Queue('v1-tests');

        // A rendered API response carries new stdClass() wherever a map is empty, because that is
        // what {} has to look like in JSON. Consumers already receive [] for these — json_decode
        // with assoc gives back an array — so the flattening keeps what they see.
        (new TypedPublisher(new Plain($publisher)))->publish($queue, $this->message([
            'payload' => ['prefs' => new \stdClass(), 'name' => 'test'],
        ]));

        $published = $publisher->getEvents('v1-tests')[0];

        $this->assertSame([], $this->objectsIn($published));
        $this->assertSame([], $published['payload']['prefs']);
        $this->assertSame('test', $published['payload']['name']);
    }

    /**
     * The route the outage came down: Event::trigger() publishes to the broker directly, not
     * through Event\\Publisher\\Base, and preparePayload() hands over the project and user
     * Documents whole. A guard on the typed-message publisher would not have covered this one.
     */
    public function testTheEventPathIsCoveredToo(): void
    {
        $publisher = new MockPublisher();
        $queue = 'v1-tests-event';

        $event = new Event(new Plain($publisher));
        $event->setClass('TestsV1')
            ->setQueue($queue)
            ->setProject(new Document(['$id' => 'p1', 'database' => 'db1']))
            ->setUser(new Document(['$id' => 'u1', 'prefs' => new \stdClass()]))
            ->trigger();

        $published = $publisher->getEvents($queue)[0];

        $this->assertSame([], $this->objectsIn($published), 'the Documents preparePayload() hands over must not reach the broker');
        $this->assertSame('p1', $published['project']['$id']);
        $this->assertSame('u1', $published['user']['$id']);
        $this->assertSame([], $published['user']['prefs']);
    }

    /** @param array<string, mixed> $payload */
    private function message(array $payload): BaseMessage
    {
        return new class ($payload) extends BaseMessage {
            /** @param array<string, mixed> $payload */
            public function __construct(private readonly array $payload)
            {
            }

            public function toArray(): array
            {
                return $this->payload;
            }

            public static function fromArray(array $data): static
            {
                throw new \LogicException('not needed for this test');
            }
        };
    }

    /**
     * Every path holding something a handler could not construct from.
     *
     * @return list<string> paths, so a failure names the field rather than the count
     */
    private function objectsIn(mixed $value, string $path = ''): array
    {
        if (\is_array($value)) {
            $found = [];
            foreach ($value as $key => $child) {
                $found = [...$found, ...$this->objectsIn($child, $path === '' ? (string) $key : "{$path}.{$key}")];
            }

            return $found;
        }

        return \is_object($value) ? [$path . ' (' . \get_debug_type($value) . ')'] : [];
    }
}
