<?php

declare(strict_types=1);

namespace Tests\Unit\Realtime\Event;

use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Realtime\Event\Handler;
use Appwrite\Realtime\EventTailRegistry;
use Appwrite\Realtime\Usage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Table;
use Utopia\WebSocket\Server;

final class HandlerTest extends TestCase
{
    private RecordingHistogram $arrivalDelay;

    private RecordingCounter $received;

    private RecordingCounter $dropped;

    private RecordingCounter $failed;

    private RecordingGauge $stage;

    private Handler $handler;

    protected function setUp(): void
    {
        $this->arrivalDelay = new RecordingHistogram();
        $this->received = new RecordingCounter();
        $this->dropped = new RecordingCounter();
        $this->failed = new RecordingCounter();
        $this->stage = new RecordingGauge();

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
            arrivalDelay: $this->arrivalDelay,
            deliveryDelay: new RecordingHistogram(),
            messagesSent: new RecordingCounter(),
            outboundBytes: new RecordingCounter(),
            eventsReceived: $this->received,
            eventsDropped: $this->dropped,
            eventsFailed: $this->failed,
            eventStage: $this->stage,
            subscriptions: new SilentUpDownCounter(),
            workerAttributes: ['workerId' => '0'],
            unauthorized: new AppwriteException(AppwriteException::USER_UNAUTHORIZED),
            unauthorizedPayload: '{}',
            timeout: 1.0,
            workerId: 0,
            traceProjectId: 'traced',
        );
    }

    public function testReturnsTheOutcomeForTheEntrypointSpan(): void
    {
        $outcome = $this->handler->handle(null, 'realtime', $this->payload(\date(DATE_ATOM)));

        $this->assertSame([
            'project' => 'project1',
            'event' => 'databases.db.collections.col.documents.doc.create',
            'result' => 'no_subscriber',
            'receivers' => 0,
            'error' => null,
        ], $outcome);
    }

    /**
     * @return array<string, array{string, ?\Throwable, float, bool}>
     */
    public static function spanCases(): array
    {
        return [
            'fast success' => ['project1', null, 0.01, false],
            'failed' => ['project1', new \RuntimeException('boom'), 0.01, true],
            'slow' => ['project1', null, 1.0, true],
            'traced project' => ['traced', null, 0.01, true],
            'event without a project' => ['', null, 0.01, false],
        ];
    }

    #[DataProvider('spanCases')]
    public function testLogsOnlyFailedSlowOrTracedEvents(string $project, ?\Throwable $error, float $seconds, bool $expected): void
    {
        $outcome = ['project' => $project, 'event' => 'e', 'result' => $error === null ? 'delivered' : 'failed', 'receivers' => 1, 'error' => $error];

        $this->assertSame($expected, $this->handler->shouldLog($outcome, $seconds));
    }

    public function testCountsEveryEventAndDropsOnesWithoutSubscribers(): void
    {
        $this->handler->handle(null, 'realtime', $this->payload(\date(DATE_ATOM)));

        $this->assertSame([[1, []]], $this->received->added);
        $this->assertSame([[1, ['reason' => 'no_subscriber']]], $this->dropped->added);
        $this->assertSame([], $this->failed->added);
        $this->assertCount(1, $this->arrivalDelay->recorded);
    }

    public function testCountsArrivalFailureInsteadOfSwallowingIt(): void
    {
        $this->handler->handle(null, 'realtime', $this->payload('not a date'));

        $this->assertSame([[1, ['stage' => 'arrival']]], $this->failed->added);
        $this->assertSame([], $this->arrivalDelay->recorded);
    }

    public function testDropsPayloadThatIsNotAnEvent(): void
    {
        $outcome = $this->handler->handle(null, 'realtime', 'not json');

        $this->assertSame('invalid_payload', $outcome['result']);
        $this->assertSame([[1, ['reason' => 'invalid_payload']]], $this->dropped->added);
        $this->assertSame([], $this->failed->added);
    }

    public function testStageGaugeReadsZeroWhenIdle(): void
    {
        $this->handler->handle(null, 'realtime', $this->payload(\date(DATE_ATOM)));

        $observed = [];
        foreach ($this->stage->callbacks as $callback) {
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
}
