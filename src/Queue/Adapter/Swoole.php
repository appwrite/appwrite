<?php

namespace Utopia\Queue\Adapter;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Process;
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

    /** @var Process[] */
    protected array $workers = [];

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
    ) {
        parent::__construct($consumer, $workerNum, $namespace, $resources);
    }

    public function start(): self
    {
        for ($i = 0; $i < $this->workerNum; $i++) {
            $this->spawnWorker($i);
        }

        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

        Coroutine\run(function (): void {
            Process::signal(SIGTERM, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
            Process::signal(SIGINT, fn(): \Utopia\Queue\Adapter\Swoole => $this->stop());
            Process::signal(SIGCHLD, fn() => $this->reap());

            while (\count($this->workers) > 0) {
                Coroutine::sleep(1);
            }
        });

        return $this;
    }

    protected function spawnWorker(int $workerId): void
    {
        $process = new Process(function () use ($workerId): void {
            Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

            Coroutine\run(function () use ($workerId): void {
                Process::signal(SIGTERM, function (): void {
                    // Flip the flag and let the loop drain. Closing the consumer
                    // here landed mid-job: at maxCoroutines=1 the loop is parked
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
        $this->workers[$pid] = $process;
    }

    /**
     * @param array<int, array{queue: Queue, maxCoroutines: int, consumer?: Consumer}> $queues
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
            // Single queue: same hot path as pre-multi-queue main — bind
            // $this->queue/$this->consumer and keep the Coroutine::create capture
            // list identical (no per-message queue/consumer args).
            if (\count($queues) === 1) {
                $spec = $queues[0];
                $previousConsumer = $this->consumer;
                $this->queue = $spec['queue'];
                $this->consumer = $spec['consumer'] ?? $this->consumer;
                if ($this->consumer !== $previousConsumer) {
                    $this->consumers[] = $this->consumer;
                }

                try {
                    $this->consumeBound($spec['maxCoroutines'], $messageCallback, $successCallback, $errorCallback);
                } finally {
                    $this->consumer = $previousConsumer;
                }

                return;
            }

            // Independent loop per queue so each cap is isolated (a databases loop
            // at maxCoroutines=1 cannot share a pool with functions=8).
            $waitGroup = new WaitGroup();

            foreach ($queues as $spec) {
                $waitGroup->add();
                Coroutine::create(function () use ($spec, $messageCallback, $successCallback, $errorCallback, $waitGroup): void {
                    try {
                        $this->run(
                            $spec['queue'],
                            $spec['maxCoroutines'],
                            $messageCallback,
                            $successCallback,
                            $errorCallback,
                            $spec['consumer'] ?? $this->consumer,
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

    /**
     * Receive on one loop with `$this->queue` / `$this->consumer` already bound.
     * Structure matches origin/main's Swoole::consume body.
     *
     * @param callable(Message): void $messageCallback
     * @param callable(Message): void $successCallback
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    protected function consumeBound(
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
    ): void {
        $slots = new Channel($maxCoroutines);
        $waitGroup = new WaitGroup();

        while (!$this->isStopped()) {
            $slots->push(true);

            $message = $this->nextMessage($errorCallback);

            if (!$message instanceof Message) {
                $slots->pop();
                continue;
            }

            $waitGroup->add();

            Coroutine::create(function () use ($message, $messageCallback, $successCallback, $errorCallback, $slots, $waitGroup): void {
                try {
                    $this->process($message, $messageCallback, $successCallback, $errorCallback);
                } catch (\Throwable $error) {
                    // process() is total; net for a stray throw so it isn't lost
                    error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                } finally {
                    $this->releaseSlot($waitGroup, $slots);
                }
            });
        }

        $waitGroup->wait();
    }

    /**
     * Concurrent multi-queue loop: queue/consumer stay on the stack so sibling
     * loops do not race `$this->queue`.
     *
     * A slot is reserved before the receive, never after: a message popped with
     * no capacity to run it would sit captive in this loop — out of the broker,
     * unprocessed, invisible to every idle sibling consumer — for as long as the
     * in-flight handlers hold the pool. Blocking without a message leaves it in
     * the broker for whichever consumer frees up first.
     */
    #[\Override]
    protected function run(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        Consumer $consumer,
    ): void {
        if ($consumer !== $this->consumer) {
            $this->consumers[] = $consumer;
        }

        $slots = new Channel($maxCoroutines);
        $waitGroup = new WaitGroup();

        while (!$this->isStopped()) {
            $slots->push(true);

            $message = $this->nextMessageFrom($errorCallback, $queue, $consumer);

            if (!$message instanceof Message) {
                $slots->pop();
                continue;
            }

            $waitGroup->add();

            Coroutine::create(function () use ($message, $messageCallback, $successCallback, $errorCallback, $slots, $waitGroup, $queue, $consumer): void {
                try {
                    $this->processFrom($message, $messageCallback, $successCallback, $errorCallback, $queue, $consumer);
                } catch (\Throwable $error) {
                    // processFrom() is total; net for a stray throw so it isn't lost
                    error_log('Uncaught error while processing queue message: ' . $error->getMessage());
                } finally {
                    $this->releaseSlot($waitGroup, $slots);
                }
            });
        }

        $waitGroup->wait();
    }

    /**
     * Hold the broker's delivery deadline open for as long as the handler runs.
     *
     * ackWait is how long the server waits for an ack before deciding the
     * worker died. Nothing extended it, so it was a hard ceiling on job
     * duration — and being redelivered while the first attempt is still running
     * is worse than being retried: two workers do the same side effect at the
     * same time. Screenshots ran 76.9% of production renders past their old 10s
     * ackWait with four processes pulling the same durable.
     *
     * A coroutine alongside the handler reports progress on the broker's own
     * cadence, and stops the moment the handler returns, however it returns.
     * Only brokers that expose both halves are extended; there is no default
     * interval to fall back on, because guessing one that is longer than the
     * real ackWait would extend nothing while looking like it did.
     *
     * @param \Closure(): void $work
     */
    #[\Override]
    protected function withAckExtension(Consumer $consumer, Queue $queue, Message $message, \Closure $work): void
    {
        $extend = [$consumer, 'extend'];
        $interval = [$consumer, 'extendInterval'];

        if (!\is_callable($extend) || !\is_callable($interval)) {
            $work();

            return;
        }

        // Asked once, not once per beat. A pooled consumer answers this by
        // leasing a broker, so calling it from inside the heartbeat loop would
        // take a lease on every beat -- contending with the handler for the
        // pool it is running on behalf of. ackWait does not change under us,
        // so the cadence is a constant for the life of this message.
        //
        // Null is how a consumer that cannot extend says so after being asked:
        // a pool only knows once it has a broker in hand. Anything non-positive
        // is treated the same way, because a heartbeat that fires continuously
        // would be worse than none.
        $beat = $interval();

        if (!\is_int($beat) && !\is_float($beat)) {
            $work();

            return;
        }

        $beat = (float) $beat;

        if ($beat <= 0.0) {
            $work();

            return;
        }

        // The handler signals completion by pushing, so waiting for the next
        // beat and noticing the handler has finished are the same operation.
        // A sleep followed by a flag check leaves a window between the two
        // where the handler can finish and the consume loop can go back to
        // reading the socket — and extending then would write to a connection
        // another coroutine is reading. Waiting on the channel closes it, and
        // ends the heartbeat the moment the handler returns rather than at the
        // end of an interval it no longer needs.
        $finished = new Channel(1);

        Coroutine::create(function () use ($finished, $extend, $beat, $queue, $message): void {
            // pop() yields false when the wait times out, and the pushed value
            // when the handler is done.
            while ($finished->pop($beat) === false) {
                try {
                    $extend($queue, $message);
                } catch (\Throwable) {
                    // The connection is gone, or the message no longer in
                    // flight. Either way there is nothing left to extend, and
                    // the handler's own failure is the one worth reporting.
                    return;
                }
            }
        });

        try {
            $work();
        } finally {
            $finished->push(true);
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

    /**
     * Pop the concurrency slot even if wait-group accounting throws, so a
     * failed teardown cannot permanently cap the worker below maxCoroutines.
     */
    private function releaseSlot(WaitGroup $waitGroup, Channel $slots): void
    {
        try {
            $waitGroup->done();
        } finally {
            $slots->pop();
        }
    }

    protected function reap(): void
    {
        while (($ret = Process::wait(false)) !== false) {
            unset($this->workers[$ret['pid']]);
        }
    }

    public function stop(): self
    {
        // Flip the flag only — same as main. Closing consumers here races with
        // in-flight commit/reject after the handler that called stop(), and is
        // unnecessary to end the loop (the next receive returns and isStopped
        // is checked). SIGTERM still closes every consumer so a blocking
        // receive unblocks on worker shutdown.
        $this->stopped = true;

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
