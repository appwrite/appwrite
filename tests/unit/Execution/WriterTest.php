<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Appwrite\Execution\Writer;
use Closure;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Throwable;

use function Swoole\Coroutine\run;

/**
 * run() leaves every runtime hook enabled and a reactor behind, so each test gets a process of its own.
 */
#[RunTestsInSeparateProcesses]
final class WriterTest extends TestCase
{
    private const float DEADLINE_SECONDS = 5.0;

    private const string OK = 'ok';

    private const string INSERT_FAILED = 'insert failed';

    private const string BATCH_INSERT_FAILED = 'Execution batch insert failed: insert failed';

    private const string FLUSH_INTERRUPTED = 'flush interrupted';

    private const string WRITE_INTERRUPTED = 'Execution write interrupted while waiting for its batch; its rows may have been written';

    /** @var list<list<string>> */
    private array $flushes = [];

    /** @var array<string, string> */
    private array $outcomes = [];

    /** @var array<string, Throwable> */
    private array $errors = [];

    /** @var list<string> */
    private array $events = [];

    private Channel $gate;

    private WaitGroup $running;

    protected function setUp(): void
    {
        $this->gate = new Channel(1);
    }

    public function testFlushesEachWriteDirectlyOutsideCoroutines(): void
    {
        $writer = new Writer($this->recorder());

        $writer->write([['id' => 'first']]);
        $writer->write([['id' => 'second'], ['id' => 'third']]);

        $this->assertSame([['first'], ['second', 'third']], $this->flushes);
    }

    public function testConcurrentWritesShareFlushes(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));
        $ids = \array_map(fn (int $index): string => "execution-{$index}", \range(0, 29));

        $this->scenario(function () use ($writer, $ids): void {
            foreach ($ids as $id) {
                $this->spawn($writer, $id);
            }
        });

        $this->assertSame([['execution-0'], \array_slice($ids, 1)], $this->flushes);
        $this->assertOutcomes(\array_fill_keys($ids, self::OK));
    }

    public function testFlushErrorReachesEveryWriterInTheBatch(): void
    {
        $this->assertBatchFailure(function (): void {
            throw new RuntimeException(self::INSERT_FAILED);
        });
    }

    public function testFlushErrorAfterYieldingReachesEveryWriterInTheBatch(): void
    {
        $this->assertBatchFailure(function (): void {
            $this->gate->pop();
            throw new RuntimeException(self::INSERT_FAILED);
        });
    }

    public function testWriteSucceedsAfterFailedFlush(): void
    {
        $calls = 0;
        $writer = new Writer($this->recorder(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException(self::INSERT_FAILED);
            }
        }));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'failed');
            $this->spawn($writer, 'retried');
        });

        $this->assertSame(['failed' => self::INSERT_FAILED, 'retried' => self::OK], $this->outcomes);
        $this->assertSame([['failed'], ['retried']], $this->flushes);
    }

    public function testFollowerWritingAgainAfterItsBatchIsFlushed(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'leader');
            $this->spawn($writer, 'follower');
            $this->spawn($writer, 'other', 'again');
        });

        $this->assertSame([['leader'], ['follower', 'other'], ['again']], $this->flushes);
        $this->assertOutcomes(['leader' => self::OK, 'follower' => self::OK, 'other' => self::OK, 'again' => self::OK]);
    }

    public function testCancelledFollowerThrowsWhileTheRestFlush(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'leader');
            $this->spawn($writer, 'next');
            $cancelled = $this->spawn($writer, 'cancelled');
            $this->spawn($writer, 'last');
            Coroutine::cancel($cancelled);
        });

        $this->assertSame([['leader'], ['next', 'last']], $this->flushes);
        $this->assertOutcomes(['leader' => self::OK, 'next' => self::OK, 'cancelled' => self::WRITE_INTERRUPTED, 'last' => self::OK]);
    }

    public function testFollowerCancelledWithExceptionLeavesTheBatch(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'leader');
            $this->spawn($writer, 'next');
            $cancelled = $this->spawn($writer, 'cancelled');
            $this->spawn($writer, 'last');
            Coroutine::cancel($cancelled, true);
        });

        $this->assertInstanceOf(CanceledException::class, $this->errors['cancelled']);
        $this->assertSame([['leader'], ['next', 'last']], $this->flushes);
        $this->assertSame(self::OK, $this->outcomes['next']);
        $this->assertSame(self::OK, $this->outcomes['last']);
    }

    public function testCancelledNextLeaderLetsTheRestFlush(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'leader');
            $next = $this->spawn($writer, 'next');
            $this->spawn($writer, 'other');
            $this->spawn($writer, 'last');
            Coroutine::cancel($next);
        });

        $this->assertSame([['leader'], ['other', 'last']], $this->flushes);
        $this->assertOutcomes(['leader' => self::OK, 'next' => self::WRITE_INTERRUPTED, 'other' => self::OK, 'last' => self::OK]);
    }

    public function testNextLeaderCancelledWithExceptionLetsTheRestFlush(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'leader');
            $next = $this->spawn($writer, 'next');
            $this->spawn($writer, 'other');
            $this->spawn($writer, 'last');
            Coroutine::cancel($next, true);
        });

        $this->assertInstanceOf(CanceledException::class, $this->errors['next']);
        $this->assertSame([['leader'], ['other', 'last']], $this->flushes);
        $this->assertSame(self::OK, $this->outcomes['other']);
        $this->assertSame(self::OK, $this->outcomes['last']);
    }

    public function testCancelledLeaderDeliversItsErrorAndHandsOff(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'first');
            $leader = $this->spawn($writer, 'leader');
            $this->spawn($writer, 'follower');
            $this->releaseFlush();
            $this->spawn($writer, 'late');
            Coroutine::cancel($leader);
        });

        $this->assertSame([['first'], ['leader', 'follower'], ['late']], $this->flushes);
        $this->assertOutcomes([
            'first' => self::OK,
            'leader' => self::FLUSH_INTERRUPTED,
            'follower' => 'Execution batch insert failed: ' . self::FLUSH_INTERRUPTED,
            'late' => self::OK,
        ]);
        $this->assertSame($this->errors['leader'], $this->errors['follower']->getPrevious());
    }

    public function testLeaderReturnsWhileAFollowerFlushesLaterWrites(): void
    {
        $writer = new Writer($this->recorder($this->blocked()));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'leader');
            $this->spawn($writer, 'second');
            $this->spawn($writer, 'third');
        });

        $this->assertSame([['leader'], ['second', 'third']], $this->flushes);
        $this->assertSame(['flushed leader', 'returned leader', 'flushed second,third', 'returned third', 'returned second'], $this->events);
    }

    private function assertBatchFailure(Closure $failure): void
    {
        $calls = 0;
        $blocked = $this->blocked();
        $writer = new Writer($this->recorder(function () use (&$calls, $failure, $blocked): void {
            $calls++;
            if ($calls === 1) {
                $blocked();
            }
            if ($calls === 2) {
                $failure();
            }
        }));

        $this->scenario(function () use ($writer): void {
            $this->spawn($writer, 'first');
            $this->spawn($writer, 'leader');
            $this->spawn($writer, 'follower');
            $this->spawn($writer, 'last');
        });

        $this->assertOutcomes([
            'first' => self::OK,
            'leader' => self::INSERT_FAILED,
            'follower' => self::BATCH_INSERT_FAILED,
            'last' => self::BATCH_INSERT_FAILED,
        ]);
        $this->assertNotSame($this->errors['follower'], $this->errors['last'], 'Each writer throws its own exception');
        $this->assertSame($this->errors['leader'], $this->errors['follower']->getPrevious());
        $this->assertSame($this->errors['leader'], $this->errors['last']->getPrevious());

        $this->scenario(fn () => $this->spawn($writer, 'recovered'));

        $this->assertSame(self::OK, $this->outcomes['recovered']);
        $this->assertSame([['first'], ['leader', 'follower', 'last'], ['recovered']], $this->flushes);
    }

    /**
     * @param (Closure(): mixed)|null $behaviour
     * @return Closure(list<array<string, mixed>>): void
     */
    private function recorder(?Closure $behaviour = null): Closure
    {
        return function (array $rows) use ($behaviour): void {
            $ids = \array_map(fn (array $row): string => $row['id'], $rows);
            $this->flushes[] = $ids;
            if ($behaviour !== null) {
                $behaviour();
            }
            $this->events[] = 'flushed ' . \implode(',', $ids);
        };
    }

    /** @return Closure(): void */
    private function blocked(): Closure
    {
        return function (): void {
            if ($this->gate->pop() === false) {
                throw new RuntimeException(self::FLUSH_INTERRUPTED);
            }
        };
    }

    private function releaseFlush(): void
    {
        $this->gate->push(true);
    }

    private function releaseFlushes(): void
    {
        while ($this->gate->stats()['consumer_num'] > 0) {
            $this->releaseFlush();
        }
    }

    private function spawn(Writer $writer, string ...$ids): int
    {
        $this->running->add();

        return (int) Coroutine::create(function () use ($writer, $ids): void {
            try {
                foreach ($ids as $id) {
                    try {
                        $writer->write([['id' => $id]]);
                        $this->outcomes[$id] = self::OK;
                        $this->events[] = 'returned ' . $id;
                    } catch (Throwable $error) {
                        $this->outcomes[$id] = $error->getMessage();
                        $this->errors[$id] = $error;
                        return;
                    }
                }
            } finally {
                $this->running->done();
            }
        });
    }

    private function scenario(Closure $scenario): void
    {
        $completed = false;
        run(function () use ($scenario, &$completed): void {
            $this->running = new WaitGroup();
            $scenario();
            $this->releaseFlushes();
            $completed = $this->running->wait(self::DEADLINE_SECONDS);
        });

        $this->assertTrue($completed, 'Every write finishes without deadlocking');
    }

    /** @param array<string, string> $expected */
    private function assertOutcomes(array $expected): void
    {
        $actual = $this->outcomes;
        \ksort($expected);
        \ksort($actual);
        $this->assertSame($expected, $actual);
    }
}
