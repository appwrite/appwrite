<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\StorageType;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * E2E tests for the NATS JetStream broker. Requires a JetStream-enabled server
 * (NATS_URL, default nats://127.0.0.1:14225); skips when unreachable.
 */
final class NatsBrokerTest extends TestCase
{
    private Nats $broker;
    private Queue $queue;

    protected function setUp(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';

        $host = parse_url($url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($url, PHP_URL_PORT) ?: 4222;
        $probe = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);
        if ($probe === false) {
            $this->markTestSkipped("NATS server not reachable at {$url}");
        }
        fclose($probe);

        $connection = Connection::connect($url);
        // Short ackWait + low maxDeliver so the redelivery/dead-letter paths are fast.
        $this->broker = new Nats($connection, ackWait: 2.0, maxDeliver: 3);
        $this->queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));
    }

    protected function tearDown(): void
    {
        $this->broker->close();
    }

    public function testEnqueueReceiveCommit(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'a']);
        $this->broker->enqueue($this->queue, ['task' => 'b']);
        $this->assertSame(2, $this->broker->getQueueSize($this->queue));

        $message = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('a', $message->getPayload()['task']);

        $this->broker->commit($this->queue, $message);
        $this->assertSame(1, $this->broker->getQueueSize($this->queue));
    }

    public function testPriorityMessageJumpsAhead(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'normal']);
        $this->broker->enqueue($this->queue, ['task' => 'urgent'], priority: true);

        $message = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('urgent', $message->getPayload()['task']);
        $this->broker->commit($this->queue, $message);
    }

    public function testRejectRedeliversAndCountsAttempts(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'retryable']);

        $first = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $first);
        $this->assertSame(0, $first->getAttempts());

        $this->broker->reject($this->queue, $first);

        $second = $this->broker->receive($this->queue, 3);
        $this->assertInstanceOf(Message::class, $second);
        $this->assertSame('retryable', $second->getPayload()['task']);
        $this->assertSame(1, $second->getAttempts());
        $this->broker->commit($this->queue, $second);
    }

    public function testExhaustedMessageIsDeadLetteredThenRetried(): void
    {
        $this->broker->enqueue($this->queue, ['task' => 'doomed']);

        // maxDeliver = 3: reject three deliveries; the third exhausts and dead-letters.
        for ($i = 0; $i < 3; $i++) {
            $message = $this->broker->receive($this->queue, 3);
            $this->assertInstanceOf(Message::class, $message);
            $this->broker->reject($this->queue, $message);
        }

        $this->assertSame(1, $this->broker->getQueueSize($this->queue, true), 'message should be on the dead stream');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue), 'work queue should be empty');

        // retry() re-drives the dead stream back onto the work queue.
        $this->broker->retry($this->queue, 10);
        $this->assertSame(1, $this->broker->getQueueSize($this->queue));
        $this->assertSame(0, $this->broker->getQueueSize($this->queue, true));

        $recovered = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $recovered);
        $this->assertSame('doomed', $recovered->getPayload()['task']);
        $this->broker->commit($this->queue, $recovered);
    }

    public function testUncommittedMessageIsRedeliveredAfterAckWait(): void
    {
        // A worker that receives but never commits (crash/OOM) must not lose the
        // message: JetStream redelivers it after AckWait — the reap() replacement.
        $this->broker->enqueue($this->queue, ['task' => 'survivor']);

        $first = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $first);
        $this->assertSame(0, $first->getAttempts());

        // Never commit; wait past ackWait (2s).
        sleep(3);

        $redelivered = $this->broker->receive($this->queue, 3);
        $this->assertInstanceOf(Message::class, $redelivered);
        $this->assertSame('survivor', $redelivered->getPayload()['task']);
        $this->assertSame(1, $redelivered->getAttempts());
        $this->broker->commit($this->queue, $redelivered);
    }

    public function testReceiveReturnsNullOnEmptyQueue(): void
    {
        $this->assertNotInstanceOf(Message::class, $this->broker->receive($this->queue, 1));
    }

    public function testSeparateQueuesAreIsolated(): void
    {
        $other = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));

        $this->broker->enqueue($this->queue, ['q' => 'mine']);
        $this->assertNotInstanceOf(Message::class, $this->broker->receive($other, 1), 'a message in one queue is invisible to another');
        $this->assertSame(1, $this->broker->getQueueSize($this->queue));

        $mine = $this->broker->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $mine);
        $this->broker->commit($this->queue, $mine);
    }

    public function testCompetingConsumersEachGetAMessageOnce(): void
    {
        // WorkQueue retention: every message is delivered to exactly one consumer.
        $other = new Nats(Connection::connect(getenv('NATS_URL') ?: 'nats://127.0.0.1:14225'), ackWait: 2.0, maxDeliver: 5);

        for ($i = 0; $i < 6; $i++) {
            $this->broker->enqueue($this->queue, ['n' => $i]);
        }

        $seen = [];
        for ($i = 0; $i < 6; $i++) {
            $consumer = ($i % 2 === 0) ? $this->broker : $other;
            $message = $consumer->receive($this->queue, 3);
            $this->assertInstanceOf(Message::class, $message);
            $seen[] = $message->getPayload()['n'];
            $consumer->commit($this->queue, $message);
        }
        $other->close();

        sort($seen);
        $this->assertSame([0, 1, 2, 3, 4, 5], $seen, 'each message delivered exactly once across two consumers');
    }

    public function testMessagesSurviveClientReconnect(): void
    {
        // Durability: unlike the ephemeral Dragonfly store, JetStream persists jobs
        // across a client close/reconnect.
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';

        $first = new Nats(Connection::connect($url));
        $first->enqueue($this->queue, ['keep' => true]);
        $first->close();

        $second = new Nats(Connection::connect($url));
        $this->assertSame(1, $second->getQueueSize($this->queue), 'message persisted across reconnect');
        $survivor = $second->receive($this->queue, 2);
        $this->assertInstanceOf(Message::class, $survivor);
        $this->assertTrue($survivor->getPayload()['keep']);
        $second->commit($this->queue, $survivor);
        $second->close();
    }

    public function testDottedQueueNameIsSanitisedToAValidStream(): void
    {
        // Queue names may contain dots (e.g. per-shard names); stream names may not.
        $dotted = new Queue('v1-database.shard.main');
        $this->broker->enqueue($dotted, ['ok' => 1]);

        $message = $this->broker->receive($dotted, 2);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(1, $message->getPayload()['ok']);
        $this->broker->commit($dotted, $message);
    }

    public function testUsesIdiomaticStreamAndSubjectNames(): void
    {
        // Q_<UPPER-NAME> stream (mirrors NATS's own KV_/OBJ_) + q.<lower-name>.<class>
        // subjects; the namespace is not folded in.
        $name = 'audits-' . substr(md5(uniqid('', true)), 0, 6);
        $this->broker->enqueue(new Queue($name), ['ok' => 1]);

        $js = Connection::connect(getenv('NATS_URL') ?: 'nats://127.0.0.1:14225')->jetStream();
        $info = $js->getStreamInfo('Q_' . strtoupper($name));
        $this->assertSame('Q_' . strtoupper($name), $info->config->name);
        $this->assertContains('q.' . strtolower($name) . '.normal', $info->config->subjects);
        $this->assertContains('q.' . strtolower($name) . '.priority', $info->config->subjects);
    }

    public function testCollidingQueueNamesFailLoud(): void
    {
        // Dropping the namespace means two names that sanitise to the same stream would
        // silently share it; the guard turns that into a loud error instead. "c.<s>" and
        // "c_<s>" both map to stream Q_C_<S>.
        $suffix = substr(md5(uniqid('', true)), 0, 6);
        $this->broker->enqueue(new Queue("c.{$suffix}"), ['ok' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->broker->enqueue(new Queue("c_{$suffix}"), ['ok' => 1]);
    }

    public function testCollidingQueueNamesFailAcrossInstances(): void
    {
        // The guard reads the owning identity from the stream's metadata, so it holds
        // even when the colliding queues are provisioned by *separate* broker instances
        // (the real deployment: producer pool + worker are different processes).
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';
        $suffix = substr(md5(uniqid('', true)), 0, 6);
        $a = new Nats(Connection::connect($url));
        $b = new Nats(Connection::connect($url));

        $a->enqueue(new Queue("x.{$suffix}"), ['ok' => 1]); // stamps identity on Q_X_<S>
        try {
            $b->enqueue(new Queue("x_{$suffix}"), ['ok' => 1]); // same stream, different identity, other instance
            $this->fail('expected a cross-instance collision to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already belongs', $e->getMessage());
        } finally {
            $a->close();
            $b->close();
        }
    }

    public function testOverlongQueueNameFailsLoud(): void
    {
        // Readable names are unbounded, so a very long queue name would exceed
        // JetStream's 255-byte stream-name limit; fail clearly rather than let the
        // server reject the create.
        $this->expectException(\RuntimeException::class);
        $this->broker->enqueue(new Queue('q' . str_repeat('a', 300)), ['x' => 1]);
    }

    public function testJobTtlExpiresUnackedMessages(): void
    {
        // jobTtl maps to the stream's MaxAge: an unconsumed message expires.
        $ttlQueue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8), 'utopia-queue', 2);

        $this->broker->enqueue($ttlQueue, ['ephemeral' => true]);
        $this->assertSame(1, $this->broker->getQueueSize($ttlQueue));

        sleep(3);
        $this->assertSame(0, $this->broker->getQueueSize($ttlQueue), 'message expired after MaxAge');
    }

    public function testReapIsANoOp(): void
    {
        // AckWait redelivery reclaims stranded jobs, so reap() has nothing to do.
        $this->assertSame(0, $this->broker->reap($this->queue));
    }

    public function testCrashLoopedMessageIsTerminallyDeadLettered(): void
    {
        // A worker that crashes every delivery (never commit/reject) is redelivered by
        // AckWait until maxDeliver, after which JetStream emits the max-deliveries
        // advisory and the broker moves the stuck message to the dead stream.
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';
        $broker = new Nats(Connection::connect($url), ackWait: 1.0, maxDeliver: 2);
        $queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));

        $broker->enqueue($queue, ['poison' => true]);

        $this->assertInstanceOf(Message::class, $broker->receive($queue, 2)); // delivery 1
        sleep(2);                                                              // > ackWait
        $this->assertInstanceOf(Message::class, $broker->receive($queue, 2)); // delivery 2 == maxDeliver
        sleep(2);                                                              // advisory fires

        // Dead-lettering happens on the consume path (receive() drains the max-deliveries
        // advisory); getQueueSize() is a passive observer and never drains. Advisory
        // delivery is asynchronous, so pump receive() until the message lands on the dead
        // stream rather than assuming a single poll catches it.
        $deadLettered = false;
        for ($i = 0; $i < 10 && !$deadLettered; $i++) {
            $broker->receive($queue, 1);
            $deadLettered = $broker->getQueueSize($queue, true) === 1;
        }
        $this->assertTrue($deadLettered, 'stuck message moved to the dead stream');
        $this->assertSame(0, $broker->getQueueSize($queue), 'work queue empty after terminal dead-letter');

        $broker->close();
    }

    /**
     * getQueueSize() must be safe to call while another coroutine is blocked in
     * receive() on the SAME broker — the shape the Swoole worker runs, where the
     * OpenTelemetry depth gauge fires getQueueSize() on a timer coroutine while the
     * consume loop is mid-fetch. Both once shared one NATS socket, and Swoole aborts
     * the process with "Socket#N has already been bound to another coroutine". Reverting
     * the control-connection split makes this crash. Regression for the fra1-staging
     * worker-stats-usage crash-loop.
     */
    public function testGetQueueSizeIsSafeDuringConcurrentReceive(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';
        // Closure factory: each broker owns its consume connection AND opens a distinct
        // control connection — the isolation getQueueSize relies on under coroutines.
        $broker = new Nats(fn(): Connection => Connection::connect($url), ackWait: 2.0, maxDeliver: 3);
        $queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));

        $error = null;
        $sizes = [];

        \Swoole\Coroutine\run(function () use ($broker, $queue, &$error, &$sizes): void {
            $broker->enqueue($queue, ['n' => 1]); // provisions streams + consumers

            $wg = new \Swoole\Coroutine\WaitGroup();

            // Consume loop: drains the one message, then blocks ~3s reading the socket.
            $wg->add();
            \Swoole\Coroutine::create(function () use ($broker, $queue, $wg, &$error): void {
                try {
                    $broker->receive($queue, 1);
                    $broker->receive($queue, 3);
                } catch (\Throwable $e) {
                    $error ??= $e;
                }
                $wg->done();
            });

            // Telemetry gauge: reads depth while the consume loop is mid-fetch.
            $wg->add();
            \Swoole\Coroutine::create(function () use ($broker, $queue, $wg, &$error, &$sizes): void {
                \Swoole\Coroutine::sleep(0.5); // let the consume loop enter its blocking read
                try {
                    for ($i = 0; $i < 3; $i++) {
                        $sizes[] = $broker->getQueueSize($queue);
                        \Swoole\Coroutine::sleep(0.3);
                    }
                } catch (\Throwable $e) {
                    $error ??= $e;
                }
                $wg->done();
            });

            $wg->wait();
        });

        $broker->close();

        $this->assertNotInstanceOf(\Throwable::class, $error, 'getQueueSize collided with the consume connection: ' . ($error?->getMessage() ?? ''));
        $this->assertCount(3, $sizes, 'depth gauge ran to completion without crashing the worker');
    }

    public function testBackoffStretchesRedeliveries(): void
    {
        // backoff [1s, 3s]: the first uncommitted timeout redelivers after ~1s, the
        // second after ~3s. Without backoff both would come back on the flat 1s ackWait.
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';
        $broker = new Nats(Connection::connect($url), ackWait: 1.0, maxDeliver: 5, backoff: [1.0, 3.0]);
        $queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));

        $broker->enqueue($queue, ['task' => 'slowpoke']);

        $first = $broker->receive($queue, 2);
        $this->assertInstanceOf(Message::class, $first);

        // First redelivery: due after backoff[0] = 1s.
        $second = $broker->receive($queue, 3);
        $this->assertInstanceOf(Message::class, $second);
        $this->assertSame(1, $second->getAttempts());

        // Second redelivery: due after backoff[1] = 3s, so a 1s window is too early…
        $tooEarly = $broker->receive($queue, 1);
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $tooEarly, 'redelivery arrived before the 3s backoff elapsed');

        // …and a window past the full delay sees it.
        $third = $broker->receive($queue, 4);
        $this->assertInstanceOf(Message::class, $third);
        $this->assertSame(2, $third->getAttempts());

        $broker->commit($queue, $third);
        $broker->close();
    }

    public function testDeadMaxAgeExpiresDeadLetters(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';
        $broker = new Nats(Connection::connect($url), ackWait: 2.0, maxDeliver: 2, deadMaxAge: 2.0);
        $queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));

        $broker->enqueue($queue, ['task' => 'doomed']);
        for ($i = 0; $i < 2; $i++) {
            $message = $broker->receive($queue, 3);
            $this->assertInstanceOf(Message::class, $message);
            $broker->reject($queue, $message);
        }
        $this->assertSame(1, $broker->getQueueSize($queue, true), 'message should be dead-lettered');

        sleep(4); // deadMaxAge is 2s; JetStream expiry runs on its own timer, allow slack

        $this->assertSame(0, $broker->getQueueSize($queue, true), 'dead letter should have expired via deadMaxAge');
        $broker->close();
    }

    public function testMemoryStorageRoundTrip(): void
    {
        $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';
        $broker = new Nats(Connection::connect($url), ackWait: 2.0, maxDeliver: 3, storage: StorageType::Memory);
        $queue = new Queue('t_' . substr(md5(uniqid('', true)), 0, 8));

        $broker->enqueue($queue, ['task' => 'ephemeral']);
        $this->assertSame(1, $broker->getQueueSize($queue));

        $message = $broker->receive($queue, 2);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('ephemeral', $message->getPayload()['task']);

        $broker->commit($queue, $message);
        $this->assertSame(0, $broker->getQueueSize($queue));
        $broker->close();
    }
}
