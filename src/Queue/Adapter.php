<?php

namespace Utopia\Queue;

use Utopia\DI\Container;

abstract class Adapter
{
    protected const int RECEIVE_TIMEOUT = 2;

    /**
     * Pause before asking again after the broker failed to answer, so an
     * unreachable broker is retried at a steady rate rather than in a tight loop.
     */
    protected const int RECEIVE_BACKOFF = 1;

    /**
     * Active queue for the sequential / single-loop hot path. Concurrent
     * multi-queue loops pass Queue explicitly via {@see nextMessageFrom()} /
     * {@see processFrom()} so they do not race this property. Bound by
     * consume() / run() before the first receive.
     */
    public Queue $queue;

    protected ?Container $context = null;
    protected bool $stopped = false;

    public Consumer $consumer;

    /**
     * @var callable(string): Consumer
     */
    protected $consumerFactory;

    protected bool $sharedConsumer = false;

    /**
     * Prefer a callable factory so each consume loop gets its own receive
     * connection. A bare Consumer is OK for single-queue only.
     *
     * @param Consumer|callable $consumer Consumer instance, `(string $queue): Consumer`,
     *        or a zero-arg factory that returns a Consumer
     * @param int $workerNum Process/worker count for pool adapters (Swoole/Workerman)
     * @param string $namespace Broker key prefix shared by every job on this adapter
     */
    public function __construct(
        Consumer|callable $consumer,
        public int $workerNum,
        public string $namespace = 'utopia-queue',
        protected Container $resources = new Container(),
    ) {
        if ($consumer instanceof Consumer) {
            $this->consumer = $consumer;
            $this->consumerFactory = static fn(string $queue): Consumer => $consumer;
            $this->sharedConsumer = true;
        } else {
            $this->consumerFactory = self::normalizeFactory($consumer);
            $this->consumer = ($this->consumerFactory)('');
            $this->sharedConsumer = false;
        }
    }

    /**
     * Invoke the adapter's consumer factory for a queue.
     */
    public function createConsumer(string $queue = ''): Consumer
    {
        return ($this->consumerFactory)($queue);
    }

    /**
     * True when the adapter was constructed with a bare shared Consumer.
     */
    public function sharesConsumer(): bool
    {
        return $this->sharedConsumer;
    }

    /**
     * @return callable(string): Consumer
     */
    protected static function normalizeFactory(callable $factory): callable
    {
        $closure = $factory instanceof \Closure ? $factory : \Closure::fromCallable($factory);
        $reflection = new \ReflectionFunction($closure);

        if ($reflection->getNumberOfRequiredParameters() === 0) {
            return static fn(string $queue): Consumer => $factory();
        }

        return $closure;
    }

    /**
     * Starts the Server.
     */
    abstract public function start(): self;

    /**
     * Stops the Server.
     */
    abstract public function stop(): self;

    /** @phpstan-impure stop() flips this from a signal handler mid-consume(). */
    protected function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * @param callable(Message): void $messageCallback
     * @param callable(Message): void $successCallback
     * @param callable(?Message, \Throwable): void $errorCallback Receives null when
     *        the failure was in obtaining a message rather than handling one.
     * @param array<int, array{queue: Queue, maxCoroutines: int, consumer?: Consumer}> $queues
     *        Queue identity and concurrency come from Server::job(); sequential
     *        adapters run specs one after another, Swoole runs independent loops.
     */
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

        foreach ($queues as $spec) {
            $this->run(
                $spec['queue'],
                $spec['maxCoroutines'],
                $messageCallback,
                $successCallback,
                $errorCallback,
                $spec['consumer'] ?? $this->consumer,
            );
        }
    }

    /**
     * One-queue loop. `$maxCoroutines` is accepted for adapter parity; the
     * sequential fallback processes one message at a time (effective cap 1).
     *
     * Binds `$this->queue` / `$this->consumer` for the duration so the hot
     * path matches pre-multi-queue (no per-message queue/consumer args).
     *
     * @param callable(Message): void $messageCallback
     * @param callable(Message): void $successCallback
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    protected function run(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        Consumer $consumer,
    ): void {
        unset($maxCoroutines);

        $previousConsumer = $this->consumer;
        $this->queue = $queue;
        $this->consumer = $consumer;

        try {
            while (!$this->isStopped()) {
                $message = $this->nextMessage($errorCallback);

                if (!$message instanceof Message) {
                    continue;
                }

                $this->context = new Container($this->resources());
                $this->process($message, $messageCallback, $successCallback, $errorCallback);
            }
        } finally {
            $this->consumer = $previousConsumer;
        }
    }

    /**
     * Never throws: a broker that cannot be reached is reported to
     * $errorCallback and retried after RECEIVE_BACKOFF. Losing the worker to a
     * transient outage is worse than waiting for the broker to come back.
     *
     * $errorCallback takes a nullable message for exactly this case — the
     * failure is in obtaining one, so there is none to report alongside it.
     *
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    protected function nextMessage(callable $errorCallback): ?Message
    {
        try {
            return $this->consumer->receive($this->queue, static::RECEIVE_TIMEOUT);
        } catch (\Throwable $error) {
            // A reporting hook that throws must not cost the worker either.
            try {
                $errorCallback(null, $error);
            } catch (\Throwable $reportFailure) {
                $this->reportUnreported($error, $reportFailure);
            }

            sleep(static::RECEIVE_BACKOFF);

            return null;
        }
    }

    /**
     * Concurrent multi-queue variant: queue/consumer are explicit so loops do
     * not race {@see $queue} / {@see $consumer}.
     *
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    protected function nextMessageFrom(callable $errorCallback, Queue $queue, Consumer $consumer): ?Message
    {
        try {
            return $consumer->receive($queue, static::RECEIVE_TIMEOUT);
        } catch (\Throwable $error) {
            try {
                $errorCallback(null, $error);
            } catch (\Throwable $reportFailure) {
                $this->reportUnreported($error, $reportFailure);
            }

            sleep(static::RECEIVE_BACKOFF);

            return null;
        }
    }

    /**
     * Never throws: a failed handler is rejected and reported to $errorCallback;
     * a failing reject or callback is swallowed rather than left to escape (and
     * be lost on a coroutine).
     */
    protected function process(
        Message $message,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
    ): void {
        try {
            $messageCallback($message);
            $this->consumer->commit($this->queue, $message);
            $successCallback($message);
        } catch (\Throwable $error) {
            try {
                $this->consumer->reject($this->queue, $message);
            } catch (\Throwable) {
            }
            try {
                $errorCallback($message, $error);
            } catch (\Throwable $reportFailure) {
                $this->reportUnreported($error, $reportFailure, $message);
            }
        } finally {
            $this->releaseContext();
        }
    }

    /**
     * Concurrent multi-queue variant of {@see process()}.
     */
    protected function processFrom(
        Message $message,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        Queue $queue,
        Consumer $consumer,
    ): void {
        try {
            $messageCallback($message);
            $consumer->commit($queue, $message);
            $successCallback($message);
        } catch (\Throwable $error) {
            try {
                $consumer->reject($queue, $message);
            } catch (\Throwable) {
            }
            try {
                $errorCallback($message, $error);
            } catch (\Throwable $reportFailure) {
                $this->reportUnreported($error, $reportFailure, $message);
            }
        } finally {
            $this->releaseContext();
        }
    }

    /**
     * Drop the per-message container after commit/reject and outcome callbacks.
     * The next message (or process shutdown) must not inherit the previous
     * message's resolved graph.
     */
    protected function releaseContext(): void
    {
        $this->context = null;
    }

    /**
     * Last-resort trace for a failure whose reporting hook also failed.
     *
     * A hook typically needs resources of its own — a database handle to
     * resolve the message's project, say — so the very outages that fail a
     * message also fail the report of it, and the message is then rejected
     * with nothing written anywhere. Production lost whole batches this way,
     * visible only as messages appearing on the failed list. Stderr is the one
     * sink that needs nothing to be working.
     */
    protected function reportUnreported(\Throwable $error, \Throwable $reportFailure, ?Message $message = null): void
    {
        try {
            fwrite($this->trace(), \sprintf(
                "[queue] %s failed and its error report failed too: %s (%s:%d) | report: %s\n",
                $message instanceof Message ? "message {$message->getPid()}" : 'receive',
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                $reportFailure->getMessage(),
            ));
        } catch (\Throwable) {
        }
    }

    /**
     * Where {@see self::reportUnreported()} writes. Overridable so a caller can
     * route the trace somewhere it will be retained, and so it can be asserted.
     *
     * @return resource
     */
    protected function trace(): mixed
    {
        return \defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
    }

    public function resources(): Container
    {
        return $this->resources;
    }

    public function context(): Container
    {
        return $this->context ??= new Container($this->resources());
    }

    /**
     * Is called when a Worker starts.
     */
    abstract public function workerStart(callable $callback): self;

    /**
     * Is called when a Worker stops.
     */
    abstract public function workerStop(callable $callback): self;
}
