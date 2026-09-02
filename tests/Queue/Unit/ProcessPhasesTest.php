<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Which failures mean "the work did not happen".
 *
 * Handling, acking and the success hook used to share one try, so a transient
 * ack timeout was treated exactly like a handler that threw: the message went
 * back to the broker. That redelivers a job that already ran — or, at the
 * delivery ceiling, dead-letters a job that succeeded as though it never had.
 */
final class ProcessPhasesTest extends TestCase
{
    private function message(): Message
    {
        return new Message([
            'pid' => 'p1',
            'queue' => 'q',
            'timestamp' => time(),
            'payload' => ['task' => 'a'],
        ]);
    }

    public function testAFailedHandlerIsRejected(): void
    {
        $consumer = new PhaseConsumer();
        $adapter = new PhaseAdapter($consumer);
        $errors = [];

        $adapter->runOne(
            $this->message(),
            static function (): never {
                throw new \RuntimeException('handler blew up');
            },
            static function (): void {},
            static function (?Message $m, \Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        );

        // The work did not happen, so the message must go back.
        $this->assertSame(['reject'], $consumer->calls);
        $this->assertSame(['handler blew up'], $errors);
    }

    public function testAFailedAckIsNeverRejected(): void
    {
        $consumer = new PhaseConsumer(commitThrows: true);
        $adapter = new PhaseAdapter($consumer);
        $errors = [];

        $adapter->runOne(
            $this->message(),
            static function (): void {},
            static function (): void {},
            static function (?Message $m, \Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        );

        // The handler succeeded. Rejecting now NAKs completed work; the broker
        // will redeliver on its own deadline if the ack really never landed,
        // which a handler can guard against — a NAK here is a rerun for certain.
        $this->assertSame(['commit'], $consumer->calls);
        $this->assertNotContains('reject', $consumer->calls);
        $this->assertSame(['ack timed out'], $errors);
    }

    public function testAFailedSuccessHookIsNeverRejected(): void
    {
        $consumer = new PhaseConsumer();
        $adapter = new PhaseAdapter($consumer);
        $errors = [];

        $adapter->runOne(
            $this->message(),
            static function (): void {},
            static function (): never {
                throw new \RuntimeException('shutdown hook failed');
            },
            static function (?Message $m, \Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        );

        // Bookkeeping after the message is acked and gone: there is nothing
        // left to reject, and rerunning the job would not fix a shutdown hook.
        $this->assertSame(['commit'], $consumer->calls);
        $this->assertSame(['shutdown hook failed'], $errors);
    }

    public function testTheSuccessHookRunsOnlyAfterTheAckLands(): void
    {
        $consumer = new PhaseConsumer(commitThrows: true);
        $adapter = new PhaseAdapter($consumer);
        $succeeded = false;

        $adapter->runOne(
            $this->message(),
            static function (): void {},
            static function () use (&$succeeded): void {
                $succeeded = true;
            },
            static function (): void {},
        );

        // Reporting success for a message the broker never acknowledged would
        // tell every downstream hook the job is finished and settled.
        $this->assertFalse($succeeded);
    }

    public function testAHandlerFailureWhoseReportAlsoFailsStillRejects(): void
    {
        $consumer = new PhaseConsumer();
        $adapter = new PhaseAdapter($consumer);

        $adapter->runOne(
            $this->message(),
            static function (): never {
                throw new \RuntimeException('handler blew up');
            },
            static function (): void {},
            static function (): never {
                throw new \RuntimeException('the reporter is down too');
            },
        );

        // The outages that fail a message tend to fail the reporting of it, and
        // the message must still be given back when that happens.
        $this->assertSame(['reject'], $consumer->calls);
        $this->assertStringContainsString('handler blew up', $adapter->traced());
    }

    public function testTheHandlerRunsUnderAnAckExtension(): void
    {
        $consumer = new PhaseConsumer();
        $adapter = new PhaseAdapter($consumer);
        $order = [];

        $adapter->onExtension = static function () use (&$order): void {
            $order[] = 'extension:open';
        };

        $adapter->runOne(
            $this->message(),
            static function () use (&$order): void {
                $order[] = 'handler';
            },
            static function (): void {},
            static function (): void {},
        );

        // The handler, and only the handler, is wrapped: extending an ack while
        // committing would be reporting progress on work that is finished.
        $this->assertSame(['extension:open', 'handler'], $order);
    }
}

final class PhaseAdapter extends Adapter
{
    public ?\Closure $onExtension = null;

    /** @var resource */
    private readonly mixed $traceStream;

    public function __construct(Consumer $consumer)
    {
        parent::__construct($consumer, 1);

        $stream = fopen('php://memory', 'r+');
        \assert(\is_resource($stream));
        $this->traceStream = $stream;
    }

    public function traced(): string
    {
        rewind($this->traceStream);

        return (string) stream_get_contents($this->traceStream);
    }

    public function runOne(Message $message, callable $handler, callable $success, callable $error): void
    {
        $this->processFrom($message, $handler, $success, $error, new Queue('q'), $this->consumer);
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

    #[\Override]
    protected function withAckExtension(Consumer $consumer, Queue $queue, Message $message, \Closure $work): void
    {
        if ($this->onExtension instanceof \Closure) {
            ($this->onExtension)();
        }

        $work();
    }

    /**
     * Capture the last-resort trace instead of writing it to stderr.
     *
     * @return resource
     */
    #[\Override]
    protected function trace(): mixed
    {
        return $this->traceStream;
    }
}

final class PhaseConsumer implements Consumer
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly bool $commitThrows = false) {}

    public function receive(Queue $queue, int $timeout): ?Message
    {
        return null;
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->calls[] = 'commit';

        if ($this->commitThrows) {
            throw new \RuntimeException('ack timed out');
        }
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->calls[] = 'reject';
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return 0;
    }

    public function close(): void {}
}
