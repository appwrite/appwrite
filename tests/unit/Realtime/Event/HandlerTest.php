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
use Utopia\Database\Role;
use Utopia\Telemetry\Counter;
use Utopia\WebSocket\Server;

final class HandlerTest extends TestCase
{
    private RecordingHistogram $arrivalDelay;

    private RecordingCounter $received;

    private RecordingCounter $dropped;

    private RecordingCounter $failed;

    private RecordingGauge $stage;

    private Realtime $realtime;

    private RecordingAdapter $adapter;

    private Handler $handler;

    protected function setUp(): void
    {
        $this->arrivalDelay = new RecordingHistogram();
        $this->received = new RecordingCounter();
        $this->dropped = new RecordingCounter();
        $this->failed = new RecordingCounter();
        $this->stage = new RecordingGauge();
        $this->realtime = new Realtime();
        $this->adapter = new RecordingAdapter();
        $this->handler = $this->makeHandler($this->received);
    }

    private function makeHandler(Counter $eventsReceived): Handler
    {
        $stats = new Table(16);
        $stats->column('messages', Table::TYPE_INT);
        $stats->create();

        return new Handler(
            server: new Server($this->adapter),
            realtime: $this->realtime,
            stats: $stats,
            eventTailRegistry: new EventTailRegistry(),
            usage: new Usage(function (): void {
            }),
            arrivalDelay: $this->arrivalDelay,
            deliveryDelay: new RecordingHistogram(),
            messagesSent: new RecordingCounter(),
            outboundBytes: new RecordingCounter(),
            eventsReceived: $eventsReceived,
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

    public function testNeverThrowsWhenTelemetryFails(): void
    {
        // An exception escaping handle() reaches phpredis, which keeps reading but never calls back again.
        $handler = $this->makeHandler(new ThrowingCounter());

        $outcome = $handler->handle(null, 'realtime', $this->payload(\date(DATE_ATOM)));

        $this->assertSame('failed', $outcome['result']);
        $this->assertInstanceOf(\RuntimeException::class, $outcome['error']);
        $this->assertSame([[1, ['stage' => 'decode']]], $this->failed->added);
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
     * @return \Iterator<string, array{string, (\Throwable | null), float, bool}>
     */
    public static function spanCases(): \Iterator
    {
        yield 'fast success' => ['project1', null, 0.01, false];
        yield 'failed' => ['project1', new \RuntimeException('boom'), 0.01, true];
        yield 'slow' => ['project1', null, 1.0, true];
        yield 'traced project' => ['traced', null, 0.01, true];
        yield 'event without a project' => ['', null, 0.01, false];
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

    public function testSkipsTransferOfProjectWithoutConnectionsOnThisPod(): void
    {
        // Console events reach the console region only; a project hosted elsewhere has no connections or database here.
        $event = \json_decode($this->payload(\date(DATE_ATOM)), true);
        $event['project'] = 'console';
        $event['permissionsChanged'] = true;
        $event['data']['events'] = ['projects.remote.update.team'];
        $event['data']['channels'] = ['console', 'projects.remote'];

        $outcome = $this->handler->handle(null, 'realtime', \json_encode($event));

        $this->assertSame('no_subscriber', $outcome['result']);
        $this->assertSame([], $this->failed->added);
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

    public function testDeletingAProjectTeamEndsOnlyItsMemberSockets(): void
    {
        // A team id may contain periods, so the dotted id must still be recognised.
        $this->realtime->subscribe('project1', 101, 'member', [Role::team('my.team')->toString()], ['account']);
        $this->realtime->subscribe('project1', 102, 'bystander', [Role::user('user2')->toString()], ['account']);

        $this->handleTeamDelete('project1', 'my.team', ['teams.my.team.delete', 'teams.*.delete']);

        // The stale team role is evicted by closing the socket, so a reconnect rebuilds roles
        // without the gone team; a connection that never held the role is left alone.
        $closed = \array_column($this->adapter->closed, 0);
        $this->assertContains(101, $closed);
        $this->assertNotContains(102, $closed);
    }

    public function testMembershipDeleteDoesNotEndTeamMemberSockets(): void
    {
        $this->realtime->subscribe('project1', 201, 'member', [Role::team('team1')->toString()], ['account']);

        // A membership delete carries the membership id, so its event name is not
        // teams.team1.delete and must not be mistaken for the team's own deletion.
        $this->handleTeamDelete('project1', 'membership1', ['teams.team1.memberships.membership1.delete']);

        $this->assertSame([], $this->adapter->closed);
    }

    /**
     * @param list<string> $events
     */
    private function handleTeamDelete(string $projectId, string $payloadId, array $events): void
    {
        $event = \json_decode($this->payload(\date(DATE_ATOM)), true);
        $event['project'] = $projectId;
        $event['data']['events'] = $events;
        $event['data']['channels'] = ['account'];
        $event['data']['payload'] = ['$id' => $payloadId];

        $this->handler->handle(null, 'realtime', \json_encode($event));
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
