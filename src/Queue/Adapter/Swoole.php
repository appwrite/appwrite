<?php

namespace Utopia\Queue\Adapter;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;
use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

class Swoole extends Adapter
{
    protected const string CONTEXT_KEY = '__utopia__';

    /**
     * Step size the maintenance coroutine sleeps in, so a stop is noticed
     * within this rather than at the end of a whole MAINTENANCE_INTERVAL.
     */
    private const float MAINTENANCE_POLL = 0.5;

    /** Whether the maintenance coroutine should keep looping. */
    protected bool $maintaining = false;
    private ?float $stopping = null;

    /** @var Process[] */
    protected array $workers = [];

    /** @var array<int, int> Process ID to worker ID. */
    protected array $workerIds = [];

    /** @var callable[] */
    protected array $onWorkerStart = [];

    /** @var callable[] */
    protected array $onWorkerStop = [];

    /** @var Consumer[] */
    protected array $consumers = [];

    public function __construct(
        Consumer|callable $consumer,
        int $workerNum,
        string $namespace = 'utopia-queue',
        Container $resources = new Container(),
        private readonly float $shutdownTimeout = 30.0,
    ) {
        if ($shutdownTimeout <= 0) {
            throw new \InvalidArgumentException('Shutdown timeout must be positive');
        }
        parent::__construct($consumer, $workerNum, $namespace, $resources);
    }

    public function start(): self
    {
        $this->stopped = false;
        $this->stopping = null;
        // Dispatch signals without a persistent coroutine: Swoole cannot fork
        // a replacement while any coroutine is running in the supervisor.
        $timer = Timer::tick(1000, static fn(): null => null);
        Process::signal(SIGTERM, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
        Process::signal(SIGINT, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
        Process::signal(SIGCHLD, static fn(): null => null);

        try {
            for ($i = 0; $i < $this->workerNum; $i++) {
                $this->spawnWorker($i);
            }

            while ($this->workers !== []) {
                Event::dispatch();
                $this->reap();
            }
        } finally {
            $this->stop();
            while ($this->workers !== []) {
                Event::dispatch();
                $this->reap();
            }
            Timer::clear($timer);
            Process::signal(SIGTERM, null);
            Process::signal(SIGINT, null);
            Process::signal(SIGCHLD, null);
            Event::wait();
        }

        return $this;
    }

    protected function spawnWorker(int $workerId): void
    {
        $process = new Process(function () use ($workerId): void {
            // Only the supervisor owns sibling processes.
            $this->workers = [];
            $this->workerIds = [];
            Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

            Coroutine\run(function () use ($workerId): void {
                Process::signal(SIGTERM, function (): void {
                    // Flip the flag and let the loop drain. Closing the consumer
                    // here landed mid-job: at coroutines=1 the loop is parked
                    // while a handler runs, so the socket went away underneath
                    // it. The handler then finished, commit() threw on a closed
                    // connection, execution fell through to reject() which threw
                    // too, and both were swallowed — so a job that had just
                    // succeeded was NAK'd and re-run after the restart. Every
                    // rolling restart re-ran one.
                    //
                    // The loop notices within RECEIVE_TIMEOUT, and workerStop
                    // closes the consumers once consume() has returned.
                    $this->stopped = true;
                });

                foreach ($this->onWorkerStart as $callback) {
                    $callback((string) $workerId);
                }

                foreach ($this->onWorkerStop as $callback) {
                    $callback((string) $workerId);
                }
            });
        }, false, 0, false);

        $pid = $process->start();
        if ($pid === false) {
            throw new \RuntimeException('Failed to start queue worker ' . $workerId);
        }
        $this->workers[$pid] = $process;
        $this->workerIds[$pid] = $workerId;
    }

    /**
     * @param array<int, array{queue: Queue, coroutines: int, prefetch?: int, consumer?: Consumer}> $queues
     */
    #[\Override]
    public function consume(
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        array $queues,
    ): void {
        $this->stopped = false;

        if ($queues === []) {
            throw new \LogicException('At least one queue is required');
        }

        $this->startMaintenance($errorCallback);

        try {
            // Independent loop per queue so each cap is isolated (a databases loop
            // at coroutines=1 cannot share a pool with functions=8).
            $waitGroup = new WaitGroup();

            foreach ($queues as $spec) {
                $waitGroup->add();
                Coroutine::create(function () use ($spec, $messageCallback, $successCallback, $errorCallback, $waitGroup): void {
                    try {
                        $this->run(
                            $spec['queue'],
                            $spec['coroutines'],
                            $messageCallback,
                            $successCallback,
                            $errorCallback,
                            $spec['consumer'] ?? $this->consumer,
                            $spec['prefetch'] ?? $spec['coroutines'],
                        );
                    } finally {
                        $waitGroup->done();
                    }
                });
            }

            $waitGroup->wait();
        } finally {
            // The consume loops are done, however they ended. Without this a
            // throw out of consume() would leave the maintenance coroutine
            // looping on a flag only stop() ever clears, and Coroutine\run()
            // would never return — a worker that cannot exit.
            $this->maintaining = false;

            // Now that the loops have drained, the per-queue consumers this
            // call created can go. SIGTERM used to close them, which is what
            // made the close land mid-job; closing them here keeps them open
            // for exactly as long as a handler might still need to ack.
            // Server's workerStop hook owns the adapter's own consumer.
            foreach ($this->consumers as $consumer) {
                try {
                    $consumer->close();
                } catch (\Throwable) {
                }
            }

            $this->consumers = [];
        }
    }

    /** One bounded buffer and renewal loop per queue, independent of processing concurrency. */
    #[\Override]
    protected function run(
        Queue $queue,
        int $coroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        Consumer $consumer,
        ?int $prefetch = null,
    ): void {
        if ($consumer !== $this->consumer) {
            $this->consumers[] = $consumer;
        }
        $prefetch ??= $coroutines;
        if ($coroutines < 1 || $prefetch < $coroutines) {
            throw new \InvalidArgumentException('Prefetch must be at least the positive number of coroutines');
        }
        $running = new Channel($coroutines);
        $available = new Channel($prefetch);
        $waitGroup = new WaitGroup();
        $finished = new Channel(1);
        /** @var \ArrayObject<int, Message> $deliveries */
        $deliveries = new \ArrayObject();
        $beat = \is_callable([$consumer, 'extendInterval']) ? $consumer->extendInterval() : null;
        $renewing = is_numeric($beat) && $beat > 0 && \is_callable([$consumer, 'extend']);
        if ($renewing) {
            $waitGroup->add();
            Coroutine::create(function () use ($consumer, $queue, $beat, &$deliveries, $finished, $waitGroup, $errorCallback): void {
                try {
                    while ($finished->pop((float) $beat) === false) {
                        if ($deliveries->count() === 0) {
                            continue;
                        }
                        try {
                            // Older third-party consumers may expose only single-message renewal.
                            if (new \ReflectionMethod($consumer, 'extend')->isVariadic()) {
                                $consumer->extend($queue, ...array_values($deliveries->getArrayCopy()));
                            } else {
                                foreach ($deliveries as $message) {
                                    $consumer->extend($queue, $message);
                                }
                            }
                        } catch (\Throwable $error) {
                            try {
                                $errorCallback(null, $error);
                            } catch (\Throwable $reportFailure) {
                                $this->reportUnreported($error, $reportFailure);
                            }
                        }
                    }
                } finally {
                    $waitGroup->done();
                }
            });
        }
        try {
            while (!$this->isStopped()) {
                while ($prefetch - \count($deliveries) < max(1, intdiv($prefetch, 2)) && !$this->isStopped()) {
                    $available->pop(0.1);
                }
                if ($this->isStopped()) {
                    break;
                }
                $messages = $this->nextBatchFrom($errorCallback, $queue, $consumer, $prefetch - \count($deliveries));
                foreach ($messages as $message) {
                    $deliveries[spl_object_id($message)] = $message;
                }
                foreach ($messages as $index => $message) {
                    $running->push(true);
                    if ($this->isStopped()) {
                        $running->pop();
                        $waiting = \array_slice($messages, $index);
                        try {
                            if (\is_callable([$consumer, 'release'])) {
                                $consumer->release($queue, ...$waiting);
                            }
                        } catch (\Throwable $error) {
                            try {
                                $errorCallback(null, $error);
                            } catch (\Throwable $reportFailure) {
                                $this->reportUnreported($error, $reportFailure);
                            }
                        } finally {
                            foreach ($waiting as $pending) {
                                unset($deliveries[spl_object_id($pending)]);
                            }
                        }
                        break;
                    }
                    $waitGroup->add();
                    Coroutine::create(function () use ($message, $queue, $consumer, $messageCallback, $successCallback, $errorCallback, $running, $available, $waitGroup, &$deliveries): void {
                        try {
                            $this->processFrom($message, function (Message $message) use ($messageCallback, $running): void {
                                try {
                                    $messageCallback($message);
                                } finally {
                                    // Confirmation may remain in flight while the next handler starts.
                                    $running->pop();
                                }
                            }, $successCallback, $errorCallback, $queue, $consumer);
                        } catch (\Throwable $error) {
                            error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                        } finally {
                            unset($deliveries[spl_object_id($message)]);
                            if (!$available->isFull()) {
                                $available->push(true);
                            }
                            $waitGroup->done();
                        }
                    });
                }
            }
        } finally {
            // Keep renewing until every handler and confirmation has finished.
            while ($deliveries->count() > 0) {
                $available->pop(0.1);
            }
            if ($renewing) {
                $finished->push(true);
            }
            $waitGroup->wait();
        }
    }

    /**
     * The clock behind {@see Adapter::maintain()}.
     *
     * A broker knows how to keep its idle connections alive; what it has no way
     * to obtain is a periodic call. This is the one place in the stack that
     * owns a scheduler, so it is where the interval lives — the packages below
     * stay free of any dependency on a runtime.
     *
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    protected function startMaintenance(callable $errorCallback): void
    {
        $this->maintaining = true;

        Coroutine::create(function () use ($errorCallback): void {
            while ($this->maintaining && !$this->isStopped()) {
                // Slept in short steps rather than one long sleep so shutdown
                // is not held up for the rest of an interval that has already
                // begun. A worker must not take 30s longer to stop for this.
                $waited = 0.0;
                while ($waited < static::MAINTENANCE_INTERVAL && $this->maintaining && !$this->isStopped()) {
                    Coroutine::sleep(self::MAINTENANCE_POLL);
                    $waited += self::MAINTENANCE_POLL;
                }

                if (!$this->maintaining || $this->isStopped()) {
                    return;
                }

                $this->maintain($errorCallback);
            }
        });
    }

    #[\Override]
    public function context(): Container
    {
        // Each message runs in its own coroutine, so the container is created
        // lazily per coroutine and stays isolated across concurrent handlers.
        if (Coroutine::getCid() !== -1) {
            return Coroutine::getContext()[self::CONTEXT_KEY] ??= new Container($this->resources());
        }

        return $this->resources();
    }

    /**
     * Every consumer this adapter has bound, not only the one on $consumer:
     * a multi-queue worker runs an independent loop and its own consumer per
     * queue, and each of those holds connections that idle between messages.
     *
     * @return list<Consumer>
     */
    #[\Override]
    protected function maintenanceTargets(): array
    {
        $targets = parent::maintenanceTargets();

        foreach ($this->consumers as $consumer) {
            if (!\in_array($consumer, $targets, true)) {
                $targets[] = $consumer;
            }
        }

        return $targets;
    }

    #[\Override]
    protected function releaseContext(): void
    {
        if (Coroutine::getCid() !== -1) {
            $context = Coroutine::getContext();
            unset($context[self::CONTEXT_KEY]);
        }

        parent::releaseContext();
    }

    protected function reap(): void
    {
        if ($this->stopping !== null && microtime(true) - $this->stopping >= $this->shutdownTimeout) {
            foreach (array_keys($this->workers) as $pid) {
                Process::kill($pid, SIGKILL);
            }
        }
        $exited = [];
        while (($ret = Process::wait(false)) !== false) {
            $pid = $ret['pid'];
            if (isset($this->workerIds[$pid])) {
                $exited[] = $this->workerIds[$pid];
                unset($this->workers[$pid], $this->workerIds[$pid]);
            }
        }

        if (! $this->stopped) {
            foreach ($exited as $workerId) {
                $this->spawnWorker($workerId);
            }
        }
    }

    public function stop(): self
    {
        // Drain until the supervisor deadline; unfinished deliveries stay recoverable.
        $this->stopped = true;
        $this->stopping ??= microtime(true);

        foreach (array_keys($this->workers) as $pid) {
            Process::kill($pid, SIGTERM);
        }

        return $this;
    }

    public function workerStart(callable $callback): self
    {
        $this->onWorkerStart[] = $callback;
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        $this->onWorkerStop[] = $callback;
        return $this;
    }
}
