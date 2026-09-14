<?php

namespace Utopia\Http\Adapter\Swoole;

use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleServer;
use Swoole\Timer;
use Utopia\DI\Container;
use Utopia\Http\Adapter;
use Utopia\Http\TrustedHeaders;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None;

class Server extends Adapter
{
    protected SwooleServer $server;
    protected const string CONTEXT_KEY = '__utopia__';

    /**
     * Server::stats() keys scoped to the calling worker, mapped to their
     * telemetry name. Emitted from every worker so a sum across instances is
     * accurate. "coroutine_peek_num" is the upstream Swoole key, typo and all.
     *
     * @var array<string, string>
     */
    private const array WORKER_STATS = [
        'worker_request_count' => 'swoole.worker_request.count',
        'worker_response_count' => 'swoole.worker_response.count',
        'worker_dispatch_count' => 'swoole.worker_dispatch.count',
        'worker_concurrency' => 'swoole.worker.concurrency',
        'coroutine_num' => 'swoole.coroutine.count',
        'coroutine_peek_num' => 'swoole.coroutine.peak',
    ];

    /**
     * Server::stats() keys tracked by the master, mapped to their telemetry
     * name. Emitted from worker 0 only so each server instance reports once.
     *
     * @var array<string, string>
     */
    private const array SERVER_STATS = [
        'connection_num' => 'swoole.connection.count',
        'accept_count' => 'swoole.accept.count',
        'close_count' => 'swoole.close.count',
        'abort_count' => 'swoole.abort.count',
        'dispatch_count' => 'swoole.dispatch.count',
        'request_count' => 'swoole.request.count',
        'response_count' => 'swoole.response.count',
        'total_recv_bytes' => 'swoole.recv.bytes',
        'total_send_bytes' => 'swoole.send.bytes',
        'concurrency' => 'swoole.concurrency',
        'worker_num' => 'swoole.worker.count',
        'idle_worker_num' => 'swoole.idle_worker.count',
        'task_worker_num' => 'swoole.task_worker.count',
    ];

    /**
     * Co::stats() keys (per worker), mapped to their telemetry name.
     * coroutine_last_cid is the cumulative count of coroutines created.
     *
     * @var array<string, string>
     */
    private const array COROUTINE_STATS = [
        'coroutine_last_cid' => 'swoole.coroutine.created',
        'aio_task_num' => 'swoole.aio.tasks_pending',
        'aio_worker_num' => 'swoole.aio.workers',
        'event_num' => 'swoole.reactor.events',
        'signal_listener_num' => 'swoole.signal_listeners',
    ];

    // Standalone metrics derived from server settings, timers and the process.
    private const string METRIC_REACTOR_THREADS = 'swoole.reactor.threads';
    private const string METRIC_COROUTINE_MAX = 'swoole.coroutine.max';
    private const string METRIC_TIMERS_ACTIVE = 'swoole.timers.active';
    private const string METRIC_MEMORY_USAGE = 'swoole.memory.usage_bytes';
    private const string METRIC_MEMORY_PEAK = 'swoole.memory.peak_bytes';
    private const string METRIC_SCHEDULER_LAG = 'swoole.scheduler.lag_ms';

    /**
     * Request context for non-coroutine modes, where a worker handles
     * one request at a time and there is no coroutine context to hang it on.
     */
    protected ?Container $context = null;

    /** The telemetry adapter whose gauges have been registered, if any. */
    private ?Telemetry $telemetry = null;

    /**
     * @param  Mode|array<string, mixed>  $settings
     */
    public function __construct(
        string $host,
        ?string $port = null,
        Mode|array $settings = [],
        int $mode = SWOOLE_PROCESS,
        protected Container $resources = new Container(),
        protected TrustedHeaders $trusted = new TrustedHeaders(),
    ) {
        $this->server = new SwooleServer($host, (int) $port, $mode);
        $this->server->set($settings instanceof Mode ? $settings->settings() : $settings);
    }

    public function onRequest(callable $callback): void
    {
        $this->server->on('request', function (SwooleRequest $request, SwooleResponse $response) use ($callback) {
            $context = new Container($this->resources);
            $context->set('swooleRequest', fn() => $request);
            $context->set('swooleResponse', fn() => $response);

            $cid = Coroutine::getCid();
            if ($cid !== -1) {
                Coroutine::getContext()[self::CONTEXT_KEY] = $context;
            } else {
                $this->context = $context;
            }

            try {
                \call_user_func($callback, new Request($request, $this->trusted), new Response($response));
            } finally {
                // Coroutine mode discards its context slot when the coroutine
                // ends; the non-coroutine slot is shared across requests, so
                // clear it to keep the "no context between requests" invariant.
                if ($cid === -1) {
                    $this->context = null;
                }
            }
        });
    }

    public function resources(): Container
    {
        return $this->resources;
    }

    public function context(): Container
    {
        if (Coroutine::getCid() !== -1) {
            return Coroutine::getContext()[self::CONTEXT_KEY] ?? $this->resources;
        }

        return $this->context ?? $this->resources;
    }

    public function getServer(): SwooleServer
    {
        return $this->server;
    }

    /**
     * Publish Swoole's own server/coroutine/runtime metrics through the given
     * telemetry adapter. Observable gauges read live state lazily, so the
     * application's normal `$telemetry->collect()` drives them — no extra
     * timers. Metrics are emitted under the `swoole.*` namespace:
     *
     *  - per-worker stats (every worker) and the per-worker coroutine ceiling
     *  - server-wide stats + reactor-thread ceiling (worker 0)
     *  - coroutine creations, AIO backlog, reactor events, signal listeners,
     *    active timers, memory, and event-loop scheduler lag
     *
     * Safe to call repeatedly (e.g. from a per-request Http): the no-op adapter
     * is ignored and registration runs once per distinct real adapter, so
     * observe callbacks don't stack.
     */
    public function setTelemetry(Telemetry $telemetry): void
    {
        if ($telemetry instanceof None || $this->telemetry === $telemetry) {
            return;
        }
        $this->telemetry = $telemetry;

        $server = $this->server;
        // Server::setting only holds values passed to the constructor; absent
        // keys fall back to Swoole's built-in defaults.
        $settings = $server->setting ?? [];

        // Register an observable gauge whose value is read on each collect().
        // A null reading skips the observation, so only register a gauge this
        // process can actually fill — see the worker-id note below.
        $observe = function (string $name, callable $value) use ($telemetry): void {
            $telemetry->createObservableGauge($name)->observe(function (callable $observer) use ($value): void {
                $reading = $value();
                if ($reading !== null) {
                    $observer($reading, []);
                }
            });
        };

        // Per-worker stats: emitted from every worker so a sum across
        // service.instance.id is accurate. max_coroutine is a per-worker ceiling
        // (PHPCoroutine::config is thread-local), so it pairs with coroutine_num.
        foreach (self::WORKER_STATS as $key => $name) {
            $observe($name, fn() => $server->stats()[$key] ?? null);
        }
        $observe(self::METRIC_COROUTINE_MAX, fn() => $settings['max_coroutine'] ?? 100_000);

        // Co::stats() reflects this worker's coroutine scheduler.
        foreach (self::COROUTINE_STATS as $key => $name) {
            $observe($name, fn() => Coroutine::stats()[$key] ?? 0);
        }
        $observe(self::METRIC_TIMERS_ACTIVE, fn() => Timer::stats()['num'] ?? 0);
        // real_usage=false reports the in-use script heap, not the OS pool (which
        // grows in slabs and rarely shrinks), revealing per-request churn.
        $observe(self::METRIC_MEMORY_USAGE, fn() => memory_get_usage(false));
        $observe(self::METRIC_MEMORY_PEAK, fn() => memory_get_peak_usage(false));

        // Co::sleep(10ms) should take ~10ms; any extra is how long the event loop
        // was blocked. Needs a coroutine, so it's skipped in non-coroutine mode.
        $telemetry->createObservableGauge(self::METRIC_SCHEDULER_LAG)->observe(function (callable $observer): void {
            if (Coroutine::getCid() === -1) {
                return;
            }
            $startNs = hrtime(true);
            Coroutine::sleep(0.01);
            $observer(max(0.0, (hrtime(true) - $startNs) / 1_000_000 - 10), []);
        });

        // Server-wide stats are master-tracked, so only worker 0 emits them to
        // avoid every worker reporting the same numbers. The check belongs here,
        // at registration, NOT inside the callback: an instrument whose callback
        // observes nothing is still exported, as a metric with zero data points,
        // and Prometheus 3.13 and later rejects the whole OTLP request over one
        // of those. This runs on worker start, so the worker id is already
        // assigned. reactor threads run in the master (SWOOLE_PROCESS mode) too.
        if ($server->getWorkerId() !== 0) {
            return;
        }

        foreach (self::SERVER_STATS as $key => $name) {
            $observe($name, fn() => $server->stats()[$key] ?? null);
        }
        $observe(self::METRIC_REACTOR_THREADS, fn() => $settings['reactor_num'] ?? swoole_cpu_num());
    }

    public function onStart(callable $callback): void
    {
        $this->server->on('start', function () use ($callback) {
            go(function () use ($callback) {
                \call_user_func($callback, $this);
            });
        });
    }

    public function start(): void
    {
        $this->server->start();
    }
}
