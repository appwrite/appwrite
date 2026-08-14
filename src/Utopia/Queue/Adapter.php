<?php

namespace Utopia\Queue;

/**
 * In-tree override of utopia-php/queue Adapter until consumeQueue /
 * consumeMany land in utopia-php/monorepo `packages/queue`.
 */

use Utopia\DI\Container;

abstract class Adapter
{
    protected const int RECEIVE_TIMEOUT = 2;

    /**
     * Pause before asking again after the broker failed to answer, so an
     * unreachable broker is retried at a steady rate rather than in a tight loop.
     */
    protected const int RECEIVE_BACKOFF = 1;

    public Queue $queue;
    protected ?Container $context = null;
    protected bool $stopped = false;

    public function __construct(
        public Consumer $consumer,
        public int $workerNum,
        string $queue,
        public string $namespace = 'utopia-queue',
        protected Container $resources = new Container(),
    ) {
        $this->queue = new Queue($queue, $namespace);
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
     */
    public function consume(callable $messageCallback, callable $successCallback, callable $errorCallback): void
    {
        $this->consumeQueue(
            $this->queue,
            1,
            $messageCallback,
            $successCallback,
            $errorCallback,
        );
    }

    /**
     * Consume one queue. $maxCoroutines is accepted for adapter parity; the
     * sequential fallback processes one message at a time (effective cap 1).
     *
     * @param callable(Message): void $messageCallback
     * @param callable(Message): void $successCallback
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    public function consumeQueue(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        ?Consumer $consumer = null,
    ): void {
        $this->stopped = false;
        unset($maxCoroutines);

        while (!$this->isStopped()) {
            $message = $this->nextMessage($errorCallback, $queue, $consumer);

            if (!$message instanceof Message) {
                continue;
            }

            $this->context = new Container($this->resources());
            $this->process($message, $messageCallback, $successCallback, $errorCallback, $queue, $consumer);
        }
    }

    /**
     * Consume many queues. Sequential adapters run them one after another
     * (not concurrent); Swoole overrides this with independent loops.
     *
     * @param array<int, array{queue: Queue, maxCoroutines: int, consumer?: Consumer}> $queues
     * @param callable(Message): void $messageCallback
     * @param callable(Message): void $successCallback
     * @param callable(?Message, \Throwable): void $errorCallback
     */
    public function consumeMany(
        array $queues,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
    ): void {
        foreach ($queues as $spec) {
            $this->consumeQueue(
                $spec['queue'],
                $spec['maxCoroutines'],
                $messageCallback,
                $successCallback,
                $errorCallback,
                $spec['consumer'] ?? null,
            );
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
    protected function nextMessage(callable $errorCallback, ?Queue $queue = null, ?Consumer $consumer = null): ?Message
    {
        $queue ??= $this->queue;
        $consumer ??= $this->consumer;

        try {
            return $consumer->receive($queue, static::RECEIVE_TIMEOUT);
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
     * Never throws: a failed handler is rejected and reported to $errorCallback;
     * a failing reject or callback is swallowed rather than left to escape (and
     * be lost on a coroutine).
     */
    protected function process(
        Message $message,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        ?Queue $queue = null,
        ?Consumer $consumer = null,
    ): void {
        $queue ??= $this->queue;
        $consumer ??= $this->consumer;

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
        }
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
