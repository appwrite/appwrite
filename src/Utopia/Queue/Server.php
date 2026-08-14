<?php

namespace Utopia\Queue;

/**
 * In-tree override of utopia-php/queue until this API lands in
 * utopia-php/monorepo `packages/queue`. Composer PSR-4 maps
 * `Utopia\Queue\` here so these classes win over vendor.
 *
 * Adds named jobs, an independent consume loop per queue, and a required
 * per-job maxCoroutines (default 1 — never a shared cap).
 */

use Exception;
use Throwable;
use Utopia\DI\Container;
use Utopia\Servers\Hook;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Validator;

class Server
{
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
    protected array $jobCoroutines = [];

    /**
     * @var (callable(string): Consumer)|null
     */
    protected $consumerFactory = null;

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

    private Histogram $jobWaitTime;
    private Histogram $processDuration;
    private ObservableGauge $queueDepth;

    /**
     * Creates an instance of a Queue server.
     */
    public function __construct(protected Adapter $adapter)
    {
        $this->job = new Job();
        $this->setTelemetry(new NoTelemetry());
    }

    public function job(?string $queue = null, int $maxCoroutines = 1): Job
    {
        $job = new Job();
        $queue ??= $this->adapter->queue->name;
        $this->job = $job;
        $this->jobs[$queue] = $job;
        $this->jobCoroutines[$queue] = max(1, $maxCoroutines);

        return $job;
    }

    /**
     * Factory for a dedicated receive consumer per queue. Required when more
     * than one queue is consumed so blocking BRPOP calls do not share a Redis
     * connection across coroutines.
     *
     * @param callable(string): Consumer $factory
     */
    public function setConsumerFactory(callable $factory): self
    {
        $this->consumerFactory = $factory(...);

        return $this;
    }

    /**
     * @return array<string, Job>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function getJobCoroutines(string $queue): int
    {
        return $this->jobCoroutines[$queue] ?? 1;
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
            [
                'ExplicitBucketBoundaries' => [
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
                ],
            ],
        );

        // https://opentelemetry.io/docs/specs/semconv/messaging/messaging-metrics/#metric-messagingprocessduration
        $this->processDuration = $telemetry->createHistogram(
            'messaging.process.duration',
            's',
            null,
            [
                'ExplicitBucketBoundaries' => [
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
                ],
            ],
        );

        $this->queueDepth = $telemetry->createObservableGauge(
            'messaging.queue.depth',
            '{message}',
            'Number of pending messages in the queue.',
        );

        $this->queueDepth->observe(function (callable $observe): void {
            if (!$this->adapter->consumer instanceof Publisher) {
                return;
            }

            $queues = $this->jobs !== []
                ? array_keys($this->jobs)
                : [$this->adapter->queue->name];

            foreach ($queues as $queueName) {
                $queue = new Queue($queueName, $this->adapter->queue->namespace);

                try {
                    $size = $this->adapter->consumer->getQueueSize($queue);
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

                if (\count($this->jobs) > 1) {
                    $queues = [];
                    foreach ($this->jobs as $queueName => $job) {
                        $queues[] = [
                            'queue' => new Queue($queueName, $this->adapter->queue->namespace),
                            'maxCoroutines' => $this->jobCoroutines[$queueName] ?? 1,
                            'consumer' => \is_callable($this->consumerFactory)
                                ? ($this->consumerFactory)($queueName)
                                : $this->adapter->consumer,
                        ];
                    }
                    $this->adapter->consumeMany($queues, $messageCallback, $successCallback, $errorCallback);
                } else {
                    $this->adapter->consume($messageCallback, $successCallback, $errorCallback);
                }
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
