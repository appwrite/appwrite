<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Queue\Adapter;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Where a rejected message is parked, which decides whether it runs again.
 *
 * The failed list is a retry queue in all but name: retry() pops it and
 * re-enqueues, so a payload that fails identically on every attempt circulates
 * until maxAttempts or newerThan finally parks it on the dead list. A handler
 * that already knows the answer should not have to wait for that circuit.
 */
final class RedisRejectTest extends RedisTestCase
{
    public function testAnOrdinaryFailureIsParkedForTheRetrySweep(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a']);
        $message = $broker->receive($queue, 0)[0];
        $broker->reject($queue, $message);

        $this->assertSame(1, $broker->getFailedCount($queue));
        $this->assertSame([], $broker->receive($queue, 0));
        $this->assertSame(0, $connection->listSize($this->namespace . '.dead.audits'));
    }

    /**
     * A terminal verdict is a JetStream economy: there, every further attempt holds
     * an ack slot for the length of its backoff. This broker has no such window, so
     * the verdict must not cost the operator the only recovery they have -- retry()
     * reads the failed list, and nothing anywhere pops the dead one.
     */
    public function testATerminalFailureStaysWhereTheSweepCanReachIt(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a']);
        $message = $broker->receive($queue, 0)[0];
        $broker->reject($queue, $message->terminal());

        $this->assertSame(1, $broker->getFailedCount($queue), 'the failure is counted');
        $this->assertSame([$message->getPid()], $connection->listRange($this->namespace . '.failed.audits', 10, 0), 'and stays on the list the sweep reads');
        $this->assertSame(0, $connection->listSize($this->namespace . '.dead.audits'), 'nothing pops the dead list, so a verdict alone may not park it there');
        $this->assertSame([], $broker->receive($queue, 0), 'it is not re-run on its own');

        // The property this test exists for: once the fault behind the verdict is
        // fixed, the existing sweep brings the work back. Backdated first --
        // retry() reads a job stamped inside its own second as one it has already
        // requeued, and stops rather than looping over its own output.
        $this->backdate($queue, $message->getPid());
        $broker->retry($queue);
        $this->assertSame(1, $broker->getQueueSize($queue), 'the sweep re-drives it once the fault is fixed');
    }

    /**
     * The verdict end to end, against real storage: a handler whose payload cannot
     * satisfy its own signature throws, the message is not re-run on its own, and
     * the operator sweep can still bring it back.
     *
     * Asserted on where the message physically is rather than on the flag the
     * adapter set, because the flag is only a claim about the destination.
     */
    public function testATypeErrorOutOfAHandlerStaysWhereTheSweepCanReachIt(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a', 'project' => 'not-an-array']);
        $message = $broker->receive($queue, 0)[0];

        $adapter = new class ($broker) extends Adapter {
            public function __construct(Consumer $consumer)
            {
                parent::__construct($consumer, 1);
            }

            public function runOne(Queue $queue, Message $message, callable $handler): void
            {
                $this->processFrom($message, $handler, static function (): void {}, static function (): void {}, $queue, $this->consumer);
            }

            public function start(): self
            {
                return $this;
            }

            public function stop(): self
            {
                return $this;
            }

            public function workerStart(callable $callback): self
            {
                return $this;
            }

            public function workerStop(callable $callback): self
            {
                return $this;
            }
        };

        $adapter->runOne($queue, $message, static function (Message $message): void {
            // Thrown by PHP, not by the test: the payload carries a string where
            // the signature takes an array, which is the shape production hits
            // when an envelope holds an object a handler constructs from.
            (static fn(array $project): array => $project)($message->getPayload()['project']);
        });

        $this->assertSame([], $broker->receive($queue, 0), 'it must not be re-run on its own');
        $this->assertSame(0, $connection->listSize($this->namespace . '.dead.audits'), 'and must not be put beyond the sweep');
        $this->assertSame([$message->getPid()], $connection->listRange($this->namespace . '.failed.audits', 10, 0));

        // A codec change is the case this matters for: flip the writer, find the
        // handlers whose payloads no longer fit their signatures, fix them, and the
        // work is still there to re-drive.
        $this->backdate($queue, $message->getPid());
        $broker->retry($queue);
        $this->assertSame(1, $broker->getQueueSize($queue), 'the operator sweep can still recover it');
    }

    /**
     * Age a stored job out of the sweep's own second.
     *
     * retry() stops when it reaches a job stamped at or after the sweep started,
     * which is how it avoids looping over what it just requeued -- and which a
     * test publishing and sweeping in the same second hits every time.
     */
    private function backdate(Queue $queue, string $pid, int $seconds = 60): void
    {
        $key = $this->namespace . '.jobs.' . $queue->name . '.' . $pid;
        $codec = new Json();
        $job = $codec->decode((string) $this->connection->get($key));
        $job['timestamp'] -= $seconds;
        $this->connection->set($key, $codec->encode($job));
    }
}
