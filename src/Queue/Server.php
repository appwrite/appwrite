<?php

namespace Utopia\Queue;

use Exception;
use Throwable;
use Utopia\DI\Container;
use Utopia\Queue\Consumer\Bounded;
use Utopia\Queue\Consumer\Exclusive;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Servers\Hook;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;
use Utopia\Validator;

class Server
{
    /**
     * Bucket boundaries for the duration histograms, in seconds.
     *
     * A queue spans a wider range than a web request: a job can be picked up
     * in milliseconds or sit behind an hour of backlog. The OpenTelemetry
     * defaults stop at 10 seconds, which puts every observation of a slow
     * queue in the overflow bucket and makes every quantile read exactly 10.
     *
     * @var list<float|int>
     */
    private const array DURATION_BUCKETS = [
        0.005,
        0.01,
        0.025,
        0.05,
        0.075,
        0.1,
        0.25,
        0.5,
        0.75,
        1,
        2.5,
        5,
        7.5,
        10,
        20,
        30,
        60,
        120,
        300,
        600,
        1200,
        1800,
        3600,
        7200,
    ];

    /**
     * Job
     */
    protected Job $job;

    /**
     * Named jobs keyed by queue name.
     *
     * @var array<string, Job>
     */
    protected array $jobs = [];

    /**
     * Per-queue coroutine caps. Defaults to 1 (safe).
     *
     * @var array<string, int>
     */
    protected array $coroutines = [];

    /**
     * @var (callable(string): Consumer)|null
     */
    protected $consumer;

    /**
     * Hooks that will run when error occur
     *
     * @var array<Hook>
     */
    protected array $errorHooks = [];

    /**
     * Hooks that will run before running job
     *
     * @var array<Hook>
     */
    protected array $initHooks = [];

    /**
     * Hooks that will run after running job
     *
     * @var array<Hook>
     */
    protected array $shutdownHooks = [];

    /**
     * Hooks that will run when worker starts
     *
     * @var array<Hook>
     */
    protected array $workerStartHooks = [];

    /**
     * Hooks that will run when worker stops
     *
     * @var array<Hook>
     */
    protected array $workerStopHooks = [];

    /**
     * Messages one receive may claim at once, per queue.
     *
     * @var array<string, int>
     */
    protected array $batches = [];

    private Histogram $jobWaitTime;
    private Histogram $processDuration;

    /**
     * Creates an instance of a Queue server.
     */
    public function __construct(protected Adapter $adapter)
    {
        $this->job = new Job();
        $this->setTelemetry(new NoTelemetry());
    }

    /**
     * Register a job for a queue. Queue name and concurrency live only here —
     * the adapter is transport (processes, namespace, consumer).
     *
     * $batch is how many messages one receive may claim at once, where the
     * consumer supports it. It is bounded by $maxCoroutines and refused above
     * it at start(): a claimed message must have a handler slot waiting for it,
     * or it sits in this process doing nothing while an idle replica could have
     * taken it. Leave it at 1 for a queue whose handlers are expensive -- the
     * round trip it saves is noise next to the work -- and raise it for one
     * whose handlers are not, which is where the broker is the bottleneck.
     */
    public function job(string $queue, int $maxCoroutines = 1, int $batch = 1): Job
    {
        if ($queue === '') {
            throw new Exception('Queue name is required');
        }

        $job = new Job();
        $this->job = $job;
        $this->jobs[$queue] = $job;
        $this->coroutines[$queue] = max(1, $maxCoroutines);
        $this->batches[$queue] = max(1, $batch);

        return $job;
    }

    /**
     * Optional override of the adapter's consumer factory. Prefer passing the
     * factory to the Adapter constructor; use this to replace it at runtime.
     *
     * @param callable(string): Consumer $factory
     */
    public function consumer(callable $factory): self
    {
        $this->consumer = $factory(...);

        return $this;
    }

    /**
     * @return array<string, Job>
     */
    public function jobs(): array
    {
        return $this->jobs;
    }

    public function coroutines(string $queue): int
    {
        return $this->coroutines[$queue] ?? 1;
    }

    public function batch(string $queue): int
    {
        return $this->batches[$queue] ?? 1;
    }

    protected function jobFor(Message $message): Job
    {
        return $this->jobs[$message->getQueue()] ?? $this->job;
    }

    /**
     * Static resources container.
     *
     * Shortcut for the underlying adapter's {@see Adapter::resources()}. Use
     * `$server->resources()->set(...)` to register app-wide services that are
     * shared across every message for the lifetime of the server.
     */
    public function resources(): Container
    {
        return $this->adapter->resources();
    }

    /**
     * Per-message context container.
     *
     * Shortcut for the underlying adapter's {@see Adapter::context()}. Use
     * `$server->context()->set(...)` to register message-scoped resources and
     * `$server->context()->get(...)` to read them. Lookups fall through to the
     * static resources container, so app-wide services remain accessible.
     */
    public function context(): Container
    {
        return $this->adapter->context();
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->jobWaitTime = $telemetry->createHistogram(
            'messaging.process.wait.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKETS],
        );

        // https://opentelemetry.io/docs/specs/semconv/messaging/messaging-metrics/#metric-messagingprocessduration
        $this->processDuration = $telemetry->createHistogram(
            'messaging.process.duration',
            's',
            null,
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKETS],
        );

        $this->createDepthGauge(
            $telemetry,
            'messaging.queue.depth',
            'Number of pending messages in the queue.',
            failedJobs: false,
        );

        $this->createDepthGauge(
            $telemetry,
            'messaging.queue.failed.depth',
            'Number of messages in the failed queue.',
            failedJobs: true,
        );
    }

    private function createDepthGauge(
        Telemetry $telemetry,
        string $name,
        string $description,
        bool $failedJobs,
    ): void {
        $gauge = $telemetry->createObservableGauge($name, '{message}', $description);

        $gauge->observe(function (callable $observe) use ($failedJobs): void {
            if (!$this->adapter->consumer instanceof Synchronous) {
                return;
            }

            $queues = array_keys($this->jobs);

            foreach ($queues as $queueName) {
                $queue = new Queue($queueName, $this->adapter->namespace);

                try {
                    $size = $this->adapter->consumer->getQueueSize($queue, $failedJobs);
                } catch (Throwable) {
                    continue;
                }

                $observe($size, [
                    'messaging.destination.name' => $queue->name,
                    'messaging.destination.namespace' => $queue->namespace,
                ]);
            }
        });
    }

    /**
     * Shutdown Hooks
     */
    public function shutdown(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);
        $this->shutdownHooks[] = $hook;
        return $hook;
    }

    /**
     * Stops the Queue server.
     */
    public function stop(): self
    {
        try {
            $this->adapter->stop();
        } catch (Throwable $error) {
            $this->resources()->set('error', fn(): \Throwable => $error);
            foreach ($this->errorHooks as $hook) {
                $hook->getAction()(...$this->getArguments($this->resources(), $hook));
            }
        }
        return $this;
    }

    /**
     * Init Hooks
     */
    public function init(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);
        $this->initHooks[] = $hook;
        return $hook;
    }

    /**
     * Starts the Queue Server
     */
    public function start(): self
    {
        try {
            $this->adapter->workerStart(function (string $workerId): void {
                $this->resources()->set('workerId', fn(): string => $workerId);

                foreach ($this->workerStartHooks as $hook) {
                    $hook->getAction()(...$this->getArguments($this->resources(), $hook));
                }

                $messageCallback = function (Message $message) {
                    $receivedAtTimestamp = microtime(true);
                    $job = $this->jobFor($message);
                    try {
                        // The enqueue timestamp comes from the publisher's
                        // clock and this from the consumer's, so on an idle
                        // queue a few milliseconds of skew between the two
                        // hosts yields a negative duration. Recording it
                        // decrements a cumulative histogram sum, which every
                        // Prometheus reader takes for a counter reset and
                        // re-attributes the process's whole lifetime sum to
                        // one interval — one -20ms sample paged a two-hour
                        // queue wait on a queue that was empty throughout.
                        $waitDuration = max(
                            0.0,
                            microtime(true) - $message->getTimestamp(),
                        );
                        $this->jobWaitTime->record($waitDuration);

                        $this->context()->set('message', fn(): \Utopia\Queue\Message => $message);

                        if ($job->getHook()) {
                            foreach ($this->initHooks as $hook) {
                                if (\in_array('*', $hook->getGroups())) {
                                    $arguments = $this->getArguments(
                                        $this->context(),
                                        $hook,
                                        $message->getPayload(),
                                    );
                                    $hook->getAction()(...$arguments);
                                }
                            }
                        }

                        foreach ($job->getGroups() as $group) {
                            foreach ($this->initHooks as $hook) {
                                if (\in_array($group, $hook->getGroups())) {
                                    $arguments = $this->getArguments(
                                        $this->context(),
                                        $hook,
                                        $message->getPayload(),
                                    );
                                    $hook->getAction()(...$arguments);
                                }
                            }
                        }

                        return \call_user_func_array(
                            $job->getAction(),
                            $this->getArguments(
                                $this->context(),
                                $job,
                                $message->getPayload(),
                            ),
                        );
                    } finally {
                        $this->processDuration->record(microtime(true) - $receivedAtTimestamp);
                    }
                };

                $successCallback = function (Message $message): void {
                    $job = $this->jobFor($message);
                    $this->context()->set('message', fn(): \Utopia\Queue\Message => $message);

                    if ($job->getHook()) {
                        foreach ($this->shutdownHooks as $hook) {
                            if (\in_array('*', $hook->getGroups())) {
                                $arguments = $this->getArguments(
                                    $this->context(),
                                    $hook,
                                    $message->getPayload(),
                                );
                                $hook->getAction()(...$arguments);
                            }
                        }
                    }

                    foreach ($job->getGroups() as $group) {
                        foreach ($this->shutdownHooks as $hook) {
                            if (\in_array($group, $hook->getGroups())) {
                                $arguments = $this->getArguments(
                                    $this->context(),
                                    $hook,
                                    $message->getPayload(),
                                );
                                $hook->getAction()(...$arguments);
                            }
                        }
                    }
                };

                $errorCallback = function (?Message $message, Throwable $th): void {
                    $this->context()->set('error', fn(): \Throwable => $th);
                    if ($message instanceof \Utopia\Queue\Message) {
                        $this->context()->set('message', fn(): \Utopia\Queue\Message => $message);
                    }

                    foreach ($this->errorHooks as $hook) {
                        $hook->getAction()(...$this->getArguments($this->context(), $hook));
                    }
                };

                // Jobs own queue identity and concurrency. The adapter only
                // runs the consume loops those jobs describe.
                if ($this->jobs === []) {
                    throw new Exception('At least one job() must be registered before start()');
                }

                // Concurrent receive loops must not share a Redis/NATS receive
                // connection — protocol responses and acks would miscorrelate.
                if (
                    \count($this->jobs) > 1
                    && !\is_callable($this->consumer)
                    && $this->adapter->sharesConsumer()
                ) {
                    throw new Exception(
                        'Multi-queue workers must pass a callable factory to the Adapter constructor (or Server::consumer()) — a shared Consumer cannot be used across concurrent receive loops',
                    );
                }

                $queues = [];
                foreach (array_keys($this->jobs) as $queueName) {
                    $maxCoroutines = $this->coroutines[$queueName] ?? 1;
                    $batch = $this->batches[$queueName] ?? 1;
                    $consumer = \is_callable($this->consumer)
                        ? ($this->consumer)($queueName)
                        : $this->adapter->createConsumer($queueName);

                    // An exclusive consumer owns a single socket and does not serialise
                    // access to it. Above one coroutine the receive loop is parked in a
                    // read on it while the handlers that are still running commit, reject
                    // or extend on the same socket, and Swoole aborts the worker on the
                    // first overlap. Refuse here so a concurrency that a serialising
                    // consumer -- Broker\Redis and Broker\Nats both are -- carries safely
                    // cannot reach production as a crash loop on one that is not.
                    if ($maxCoroutines > 1 && $consumer instanceof Exclusive) {
                        throw new Exception(\sprintf(
                            "Queue '%s' is registered with job('%s', %d), but its consumer %s drives a single socket that only one coroutine may read at a time. Register it as job('%s', 1) and add replicas for throughput.",
                            $queueName,
                            $queueName,
                            $maxCoroutines,
                            $consumer::class,
                            $queueName,
                        ));
                    }

                    // A bounded consumer hands out at most N messages before it waits for
                    // an acknowledgment, and a message parked in redelivery backoff is one
                    // of those N -- asleep rather than being worked, holding a slot the
                    // whole time. So a ceiling at or below the coroutine cap is filled by
                    // as many failures as there are handlers, after which the queue is not
                    // delivered into at all: the wedge looks like a backlog with idle
                    // workers in front of it. Refuse it here, the way an exclusive
                    // consumer's cap is refused, rather than let it be found in a graph.
                    $ceiling = $consumer instanceof Bounded ? $consumer->inFlightCeiling() : null;
                    if ($ceiling !== null && $ceiling <= $maxCoroutines) {
                        throw new Exception(\sprintf(
                            "Queue '%s' is registered with job('%s', %d), but its consumer %s holds at most %d message(s) in flight. Messages sleeping in backoff hold a slot each, so the handlers can take every slot the consumer has and the queue stops being delivered into. Raise the in-flight ceiling (Broker\\Nats: maxAckPending) above %d, or lower the coroutine cap.",
                            $queueName,
                            $queueName,
                            $maxCoroutines,
                            $consumer::class,
                            $ceiling,
                            $maxCoroutines,
                        ));
                    }

                    // A batch above the coroutine count would claim messages this
                    // worker has nowhere to run: they would wait here, out of the
                    // broker and invisible to every idle replica, until a handler
                    // ahead of them finished. It is also what keeps the in-flight
                    // ceiling honest -- reserving a slot per message is why a batch
                    // cannot push more messages unacknowledged than maxCoroutines.
                    if ($batch > $maxCoroutines) {
                        throw new Exception(\sprintf(
                            "Queue '%s' is registered with job('%s', %d, %d): a batch cannot exceed the handler slots waiting for it. Raise the coroutine count, or lower the batch to %d.",
                            $queueName,
                            $queueName,
                            $maxCoroutines,
                            $batch,
                            $maxCoroutines,
                        ));
                    }

                    $queues[] = [
                        'queue' => new Queue($queueName, $this->adapter->namespace),
                        'maxCoroutines' => $maxCoroutines,
                        'batch' => $batch,
                        'consumer' => $consumer,
                    ];
                }
                $this->adapter->consume($messageCallback, $successCallback, $errorCallback, $queues);
            });

            $this->adapter->workerStop(function (string $workerId): void {
                $this->resources()->set('workerId', fn(): string => $workerId);

                try {
                    // Call user-defined workerStop hooks
                    foreach ($this->workerStopHooks as $hook) {
                        try {
                            $hook->getAction()(...$this->getArguments($this->resources(), $hook));
                        } catch (Throwable) {
                        }
                    }
                } finally {
                    // Always close consumer connection, even if hooks throw
                    $this->adapter->consumer->close();
                }
            });

            $this->adapter->start();
        } catch (Throwable $error) {
            $this->resources()->set('error', fn(): \Throwable => $error);
            foreach ($this->errorHooks as $hook) {
                $hook->getAction()(...$this->getArguments($this->resources(), $hook));
            }

            throw $error;
        }
        return $this;
    }

    /**
     * Is called when a Worker starts.
     */
    public function workerStart(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);
        $this->workerStartHooks[] = $hook;
        return $hook;
    }

    /**
     * Returns Worker starts hooks.
     */
    public function getWorkerStart(): array
    {
        return $this->workerStartHooks;
    }

    /**
     * Is called when a Worker stops.
     */
    public function workerStop(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);
        $this->workerStopHooks[] = $hook;
        return $hook;
    }

    /**
     * Returns Worker stops hooks.
     */
    public function getWorkerStop(): array
    {
        return $this->workerStopHooks;
    }

    /**
     * Get Arguments
     */
    protected function getArguments(Container $context, Hook $hook, array $payload = []): array
    {
        $arguments = [];
        foreach ($hook->getParams() as $key => $param) {
            $payloadKey = $key;
            if (!\array_key_exists($key, $payload) && !empty($param['aliases'])) {
                foreach ($param['aliases'] as $alias) {
                    if (\array_key_exists($alias, $payload)) {
                        $payloadKey = $alias;
                        break;
                    }
                }
            }

            // Get value from route or request object
            $value = $payload[$payloadKey] ?? $param['default'];
            $value
                = $value === '' || $value === null ? $param['default'] : $value;

            $this->validate($key, $param, $value, $context);
            $hook->setParamValue($key, $value);
            $arguments[$param['order']] = $value;
        }

        foreach ($hook->getInjections() as $injection) {
            $arguments[$injection['order']] = $context->get(
                $injection['name'],
            );
        }

        // call_user_func_array passes integer keys in iteration order, not key
        // order, so sort the two-pass (params, then injections) array by key.
        ksort($arguments);

        return $arguments;
    }

    /**
     * Validate Param
     *
     * Creates an validator instance and validate given value with given rules.
     *
     *
     * @throws Exception
     *
     */
    protected function validate(string $key, array $param, mixed $value, Container $context): void
    {
        if ('' !== $value && $value !== null) {
            $validator = $param['validator']; // checking whether the class exists

            if (\is_callable($validator)) {
                $validatorKey = '_validator:' . $key;
                $context->set($validatorKey, $validator, $param['injections']);
                $validator = $context->get($validatorKey);
            }

            if (!$validator instanceof Validator) {
                // is the validator object an instance of the Validator class
                throw new Exception(
                    'Validator object is not an instance of the Validator class',
                    500,
                );
            }

            if (!$validator->isValid($value)) {
                throw new Exception(
                    'Invalid ' . $key . ': ' . $validator->getDescription(),
                    400,
                );
            }
        } elseif (!$param['optional']) {
            throw new Exception("Param $key is not optional.", 400);
        }
    }

    /**
     * Register hook. Will be executed when error occurs.
     */
    public function error(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);
        $this->errorHooks[] = $hook;
        return $hook;
    }
}
