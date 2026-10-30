<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\Event\Handler;
use Appwrite\Realtime\EventTailRegistry;
use Appwrite\Realtime\Usage;
use PHPUnit\Framework\TestCase;
use Swoole\Table;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;
use Utopia\WebSocket\Server;

final class HandlerTest extends TestCase
{
    private TestTelemetry $telemetry;

    private Handler $handler;

    protected function setUp(): void
    {
        $this->telemetry = new TestTelemetry();

        $stats = new Table(16);
        $stats->column('messages', Table::TYPE_INT);
        $stats->create();

        $this->handler = new Handler(
            server: new Server(new RecordingAdapter()),
            realtime: new Realtime(),
            stats: $stats,
            eventTailRegistry: new EventTailRegistry(),
            usage: new Usage(function (): void {
            }),
            arrivalDelay: $this->telemetry->createHistogram('realtime.server.arrival_delay'),
            deliveryDelay: $this->telemetry->createHistogram('realtime.server.delivery_delay'),
            messagesSent: $this->telemetry->createCounter('realtime.server.message.sent'),
            outboundBytes: $this->telemetry->createCounter('realtime.server.outbound_bytes'),
            eventsReceived: $this->telemetry->createCounter('realtime.server.events.received'),
            eventsDropped: $this->telemetry->createCounter('realtime.server.events.dropped'),
            eventsFailed: $this->telemetry->createCounter('realtime.server.events.failed'),
            eventStage: $this->telemetry->createObservableGauge('realtime.server.event.stage'),
            subscriptions: $this->telemetry->createUpDownCounter('realtime.server.worker_subscriptions'),
            workerAttributes: ['workerId' => '0'],
            unauthorized: new AppwriteException(AppwriteException::USER_UNAUTHORIZED),
            unauthorizedPayload: '{}',
            timeout: 1.0,
            workerId: 0,
        );
    }

    public function testCountsEveryEventAndDropsOnesWithoutSubscribers(): void
    {
        $this->handler->handle(null, 'realtime', $this->payload(\date(DATE_ATOM)));

        $this->assertSame([1], $this->values('realtime.server.events.received'));
        $this->assertSame([1], $this->values('realtime.server.events.dropped'));
        $this->assertSame([], $this->values('realtime.server.events.failed'));
        $this->assertCount(1, $this->telemetry->histograms['realtime.server.arrival_delay']->values);
    }

    public function testCountsArrivalFailureInsteadOfSwallowingIt(): void
    {
        $this->handler->handle(null, 'realtime', $this->payload('not a date'));

        $this->assertSame([1], $this->values('realtime.server.events.received'));
        $this->assertSame([1], $this->values('realtime.server.events.failed'));
        $this->assertArrayNotHasKey('realtime.server.arrival_delay', $this->telemetry->histograms);
    }

    public function testDropsPayloadThatIsNotAnEvent(): void
    {
        $this->handler->handle(null, 'realtime', 'not json');

        $this->assertSame([1], $this->values('realtime.server.events.received'));
        $this->assertSame([1], $this->values('realtime.server.events.dropped'));
        $this->assertSame([], $this->values('realtime.server.events.failed'));
    }

    public function testStageGaugeReadsZeroWhenIdle(): void
    {
        $this->handler->handle(null, 'realtime', $this->payload(\date(DATE_ATOM)));

        $observed = [];
        foreach ($this->telemetry->observableGauges['realtime.server.event.stage']->callbacks as $callback) {
            $callback(function (float $value, array $attributes) use (&$observed): void {
                $observed[] = [$value, $attributes];
            });
        }

        $this->assertSame([[0.0, ['workerId' => '0', 'stage' => 'idle']]], $observed);
    }

    private function payload(string $timestamp): string
    {
        return \json_encode([
            'project' => 'project1',
            'roles' => ['any'],
            'permissionsChanged' => false,
            'userId' => null,
            'data' => [
                'events' => ['databases.db.collections.col.documents.doc.create'],
                'channels' => ['documents'],
                'timestamp' => $timestamp,
                'payload' => ['$id' => 'doc'],
            ],
        ]);
    }

    /** @return list<int|float> */
    private function values(string $counter): array
    {
        return isset($this->telemetry->counters[$counter]) ? $this->telemetry->counters[$counter]->values : [];
    }
}
