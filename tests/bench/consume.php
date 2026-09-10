<?php

/**
 * Consume-side benchmark: what the broker costs a worker, across workload shapes.
 *
 * Drives Broker\Redis and Broker\Nats through Adapter\Swoole::consume() -- the loop a
 * worker actually runs -- rather than a local imitation of it, so a refactor of the
 * adapter changes these numbers instead of silently diverging from them. Timing that
 * the adapter gives no hook for comes from a decorator around the consumer (see
 * Timed), which is a wrapper rather than a copy.
 *
 * Two concurrency axes, because they are not interchangeable:
 *
 *   --processes    separate consumer processes, each with its own connections. This is
 *                  what replicas buy. Spawned rather than forked: forking a process
 *                  with an initialised Swoole runtime is not something to rely on for
 *                  a measurement.
 *   --coroutines   handler coroutines inside one process, sharing its connections. This
 *                  is what _APP_WORKER_MAX_COROUTINES sets on a deployed worker.
 *
 * And two workload knobs, because which axis helps depends entirely on them:
 *
 *   --sleep-ms     a handler that waits. Coroutine::sleep yields, so sibling handlers
 *                  and the receive loop keep running: coroutines absorb this.
 *   --cpu-iters    a handler that computes (sha256 rounds). PHP runs one coroutine at a
 *                  time, so this yields nothing and coroutines cannot absorb it --
 *                  only processes can.
 *
 * The children are started staggered but measured together. Every consumer provisions
 * on its first receive(), and starting several at once turns that into a storm --
 * measured at four processes against an already-warm single-replica stream, a child
 * intermittently spent ~124s inside its first receive() while its siblings drained in
 * 1.3s. So each child provisions on its own, reports ready, and blocks; the parent
 * publishes the backlog and releases them together.
 *
 * Elapsed is one clock for the fleet: from that release to the last message any child
 * finished acknowledging. Both ends matter. A per-child clock omits the stagger between their starts
 * and credits the difference to the process axis, inflating it. Running to child *exit*
 * instead over-corrects, because a child stops on a quiet queue and its wait for that
 * verdict is not drain -- it deflated a single-process cell by 1.7x, where no stagger
 * exists at all. The window between the release and the last handled message is the
 * only one that is neither.
 *
 * Needs live Redis and NATS, so it is driven by run.sh rather than run directly:
 *
 *   REDIS_HOST=127.0.0.1 REDIS_PORT=16379 NATS_URL=nats://127.0.0.1:14225 \
 *     php tests/bench/consume.php --backend=nats --processes=4 --coroutines=8
 *
 * Absolute rates are host-bound and mean nothing across machines; compare cells within
 * one run. Drain moves double digits between identical runs, so use --repeat and read
 * the median. BENCH_DEBUG=1 prints what each child reported.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Utopia\Queue\Adapter\Swoole as SwooleAdapter;
use Utopia\Queue\Broker\Nats as NatsBroker;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

const DEFAULTS = [
    'backend' => 'both',
    'processes' => '1',
    'coroutines' => '1',
    'messages' => '600',
    'payload' => '512',
    'repeat' => '3',
    'sleep-ms' => '0',
    'cpu-iters' => '0',
    'label' => '',
    // Milliseconds between child starts, so provisioning does not storm. It costs the
    // measurement nothing: the clocks start together regardless. See the header.
    'stagger' => '300',
    // Internal, set on the children this script spawns.
    'role' => '',
    'share' => '0',
    'gate' => '',
];

$args = DEFAULTS;

// $_SERVER['argv'], not $argv: the latter needs register_argc_argv, which a CLI ini is
// not obliged to set -- and PHPStan is right to refuse to assume it.
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', (string) $arg, $m) === 1 && array_key_exists($m[1], DEFAULTS)) {
        $args[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "unknown option {$arg}\n");
    exit(2);
}

/**
 * A consumer that times its own acknowledgments, and nothing else.
 *
 * The adapter owns when a commit happens and offers no hook for how long it took, so
 * the number is taken here instead of by reimplementing the loop. Everything else is
 * pass-through, extend() included, so the adapter's ack extension still reaches the
 * broker rather than being silently disabled by the wrapper.
 */
final class Timed implements Consumer
{
    /** @var list<float> microseconds per acknowledgment */
    public array $commits = [];

    /**
     * When the last acknowledgment landed, as an absolute timestamp.
     *
     * The fleet clock ends here rather than when the last handler returned. The
     * adapter commits after the callback, so a handler-side timestamp leaves the final
     * acknowledgment outside the measured window -- which quietly favours whichever
     * broker acknowledges more slowly.
     */
    public float $lastCommittedAt = 0.0;

    public function __construct(private readonly Consumer $inner) {}

    public function receive(Queue $queue, int $timeout): ?Message
    {
        return $this->inner->receive($queue, $timeout);
    }

    public function commit(Queue $queue, Message $message): void
    {
        $started = hrtime(true);
        $this->inner->commit($queue, $message);
        $this->commits[] = (hrtime(true) - $started) / 1000;
        $this->lastCommittedAt = microtime(true);
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->inner->reject($queue, $message);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function extend(Queue $queue, Message $message): void
    {
        if (is_callable([$this->inner, 'extend'])) {
            $this->inner->extend($queue, $message);
        }
    }

    public function extendInterval(): float
    {
        if (is_callable([$this->inner, 'extendInterval'])) {
            return (float) $this->inner->extendInterval();
        }

        return 0.0;
    }
}

function broker(string $name): RedisBroker|NatsBroker
{
    if ($name === 'redis') {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 16379);

        // The shape cloud wires: a blocking receive connection plus a Locking commands
        // connection. Broker\Nats resolves the same split internally from the factory.
        return new RedisBroker(
            receive: new RedisConnection($host, $port),
            commands: new Locking(new RedisConnection($host, $port)),
        );
    }

    $url = getenv('NATS_URL') ?: 'nats://127.0.0.1:14225';

    return new NatsBroker(fn(): \Utopia\NATS\Connection => \Utopia\NATS\Connection::connect($url));
}

function queueFor(string $name): Queue
{
    return new Queue('bench_' . $name, 'bench');
}

/**
 * The handler. Sleep yields and computation does not, which is the whole point of
 * having both: the same drain rate responds to a different axis depending on which of
 * these dominates.
 */
function work(float $sleepSeconds, int $cpuIters): string
{
    if ($sleepSeconds > 0) {
        Coroutine::sleep($sleepSeconds);
    }

    // Each round hashes the previous digest, so the chain is dependent: nothing can
    // reorder or elide it, and it is the digest that is returned rather than discarded.
    $sink = '';
    for ($i = 0; $i < $cpuIters; $i++) {
        $sink = hash('sha256', $sink . $i);
    }

    return $sink;
}

/** Nearest-rank percentile over a microsecond sample, in milliseconds. */
function pct(array $samples, float $q): float
{
    if ($samples === []) {
        return 0.0;
    }
    sort($samples);
    $rank = (int) ceil($q * count($samples)) - 1;

    return $samples[max(0, min($rank, count($samples) - 1))] / 1000;
}

/**
 * One consumer process: provision, wait at the gate, then drain up to its share.
 *
 * @return array{received: int, lastSeen: float, commits: list<float>, error: ?string}
 */
function consume(array $args): array
{
    $inner = broker($args['backend']);
    $client = new Timed($inner);
    $queue = queueFor($args['backend']);

    $slots = max(1, (int) $args['coroutines']);
    $share = (int) $args['share'];
    $sleep = ((float) $args['sleep-ms']) / 1000;
    $iters = (int) $args['cpu-iters'];
    $gate = (string) $args['gate'];

    $handled = 0;
    $error = null;

    Coroutine\run(function () use ($client, $queue, $slots, $share, $sleep, $iters, $gate, &$handled, &$error): void {
        // Provision on this process's own connections, before the gate opens, so the
        // measured window contains draining and nothing else.
        try {
            $client->receive($queue, 1);
        } catch (Throwable $e) {
            $error = 'provision: ' . $e->getMessage();

            return;
        }

        // Ready, and then wait to be released with everyone else.
        touch($gate . '.ready.' . getmypid());
        $deadline = microtime(true) + 120.0;
        while (!file_exists($gate . '.go')) {
            if (microtime(true) > $deadline) {
                $error = 'gate never opened';

                return;
            }
            usleep(2000);
        }

        $adapter = new SwooleAdapter($client, 1, $queue->namespace);

        // No per-child quota: children race for one pre-filled backlog, exactly as
        // replicas of a worker do, and the parent knows the total. $share is only a
        // ceiling so a runaway cannot spin forever.
        //
        // The queue is full when the gate opens, so it goes quiet only once it is
        // empty. Waiting on that is how a child knows it is done -- and it is why the
        // clock ends at the last handled message rather than here.
        $idle = microtime(true);
        Coroutine::create(function () use ($adapter, $share, &$handled, &$idle): void {
            while ($handled < $share && microtime(true) - $idle < 2.0) {
                Coroutine::sleep(0.1);
            }

            $adapter->stop();
        });

        $adapter->consume(
            function () use ($adapter, $share, $sleep, $iters, &$handled, &$idle): void {
                work($sleep, $iters);

                $idle = microtime(true);
                if (++$handled >= $share) {
                    $adapter->stop();
                }
            },
            static fn(): null => null,
            function (?Message $message, Throwable $failure) use ($adapter, &$error): void {
                $error ??= 'handler: ' . $failure->getMessage();
                $adapter->stop();
            },
            [
                ['queue' => $queue, 'maxCoroutines' => $slots],
            ],
        );

        $client->close();
    });

    return [
        'received' => $handled,
        'lastSeen' => $client->lastCommittedAt,
        'commits' => $client->commits,
        'error' => $error,
    ];
}

/**
 * Provision, drain anything left over, publish the backlog, release the children
 * together, and aggregate what they report.
 *
 * @return array{drain: float, p50: float, p95: float, received: int, error: ?string}
 */
function measure(string $name, array $args): array
{
    $client = broker($name);
    $queue = queueFor($name);
    $total = (int) $args['messages'];
    $processes = max(1, (int) $args['processes']);
    $stagger = max(0, (int) $args['stagger']);
    $filler = str_repeat('x', (int) $args['payload']);
    $fail = static fn(string $why): array => ['drain' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'received' => 0, 'error' => $why];

    // Provision, then drain whatever a previous or interrupted run left behind. These
    // queues are durable and reused, so without this a sample can consume the last
    // run's messages -- a different payload, at a different count -- stop early on its
    // own tally, and leave its own behind for the next one.
    $client->publish($queue, ['warmup' => true, 'filler' => $filler]);
    $leftover = 0;
    while (($stale = $client->receive($queue, 1)) instanceof \Utopia\Queue\Message) {
        $client->commit($queue, $stale);
        if (++$leftover > $total * 10) {
            return $fail('queue would not drain before the run');
        }
    }

    // Unique per call, not per process: every repeat in this parent would otherwise
    // share a gate, so a stale .ready file from a failed repeat can open the next
    // repeat's gate before its children are ready.
    $gate = sys_get_temp_dir() . '/utopia-queue-bench-' . $name . '-' . bin2hex(random_bytes(6));
    $handles = [];

    // Every exit path goes through here. A child left running would drain the next
    // repeat's backlog, and a gate file left behind would release it early. Takes the
    // handles as an argument rather than capturing them by reference, which keeps it
    // honest about what it closes -- and analysable, since a by-ref capture reads as
    // the empty array it was defined next to.
    $teardown = static function (array $open) use ($gate): void {
        foreach ($open as $handle) {
            // The pipe goes first: a child blocked writing a result nobody read gets
            // EPIPE and exits on its own, which is most of them.
            fclose($handle['stdout']);

            // Then SIGTERM, then a bounded wait, then SIGKILL. proc_close() reaps, and
            // reaping blocks until the child is actually gone -- so a child wedged in a
            // socket read against an unhealthy broker would hang the very path that
            // exists to report "children never reported ready".
            // Re-read into a variable each time rather than calling in the condition:
            // the call has a different answer every time and static analysis is right
            // to treat two identical calls as one value unless told otherwise.
            $status = proc_get_status($handle['process']);
            if ($status['running']) {
                proc_terminate($handle['process']);

                $deadline = microtime(true) + 2.0;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($handle['process']);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(20000);
                }

                // 9 rather than SIGKILL: the constant comes from ext-pcntl, which this
                // benchmark does not otherwise need.
                if ($status['running']) {
                    proc_terminate($handle['process'], 9);
                }
            }

            proc_close($handle['process']);
        }

        foreach (glob($gate . '*') ?: [] as $file) {
            @unlink($file);
        }
    };

    for ($p = 0; $p < $processes; $p++) {
        $command = [PHP_BINARY, __FILE__, '--role=consume', '--backend=' . $name, '--share=' . $total, '--gate=' . $gate];
        foreach (['coroutines', 'messages', 'payload', 'sleep-ms', 'cpu-iters'] as $key) {
            $command[] = '--' . $key . '=' . $args[$key];
        }

        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            $teardown($handles);

            return $fail('spawn failed');
        }

        $handles[] = ['process' => $process, 'stdout' => $pipes[1]];

        // Staggered so their provisioning does not collide. Costs the measurement
        // nothing: the clock starts at the gate, below.
        if ($stagger > 0 && $p < $processes - 1) {
            usleep($stagger * 1000);
        }
    }

    // Every child provisioned and waiting is the point at which publishing is safe and
    // the clock is meaningful.
    $deadline = microtime(true) + 150.0;
    while (count(glob($gate . '.ready.*') ?: []) < $processes) {
        if (microtime(true) > $deadline) {
            $teardown($handles);

            return $fail('children never reported ready');
        }
        usleep(2000);
    }

    for ($i = 0; $i < $total; $i++) {
        $client->publish($queue, ['n' => $i, 'filler' => $filler]);
    }
    $client->close();

    $started = microtime(true);
    touch($gate . '.go');

    $received = 0;
    $lastSeen = 0.0;
    $commits = [];
    $error = null;

    foreach ($handles as $handle) {
        $raw = stream_get_contents($handle['stdout']);

        $child = json_decode((string) $raw, true);
        if (!is_array($child)) {
            $error ??= 'child returned no result';
            continue;
        }

        if (getenv('BENCH_DEBUG')) {
            fwrite(STDERR, sprintf(
                "    child: received=%d drained=%.3fs error=%s\n",
                (int) $child['received'],
                ((float) $child['lastSeen']) - $started,
                $child['error'] ?? '-',
            ));
        }

        $received += (int) $child['received'];
        $lastSeen = max($lastSeen, (float) $child['lastSeen']);
        $commits = array_merge($commits, array_map(floatval(...), $child['commits']));
        $error ??= $child['error'];
    }

    $teardown($handles);

    // One clock for the fleet: the release to the last message anyone handled.
    $elapsed = $lastSeen - $started;

    return [
        'drain' => $elapsed > 0 ? $received / $elapsed : 0.0,
        'p50' => pct($commits, 0.50),
        'p95' => pct($commits, 0.95),
        'received' => $received,
        'error' => $error,
    ];
}

// Child: drain and hand the samples back as JSON on stdout.
if ($args['role'] === 'consume') {
    echo json_encode(consume($args));
    exit(0);
}

$backends = $args['backend'] === 'both' ? ['redis', 'nats'] : [$args['backend']];
$repeat = max(1, (int) $args['repeat']);
$total = (int) $args['messages'];
$exit = 0;

printf(
    "%s%d messages, %dB payload, %s x %s (processes x coroutines), sleep=%sms cpu=%s iters, median of %d\n\n",
    $args['label'] === '' ? '' : $args['label'] . ': ',
    $total,
    (int) $args['payload'],
    $args['processes'],
    $args['coroutines'],
    $args['sleep-ms'],
    $args['cpu-iters'],
    $repeat,
);
printf("%-7s %12s %11s %11s\n", 'backend', 'drain msg/s', 'ack p50', 'ack p95');
printf("%-7s %12s %11s %11s\n", '-------', '------------', '-----------', '-----------');

foreach ($backends as $name) {
    // A sample counts only if it drained the whole backlog with nothing going wrong. A
    // partial run reports a rate over a fraction of the workload, and a run with a
    // failed acknowledgment has left work stranded or pending redelivery -- either way
    // its rate and its latencies describe something other than the broker working.
    $complete = [];
    $rejected = [];

    for ($r = 0; $r < $repeat; $r++) {
        $sample = measure($name, $args);

        if ($sample['error'] !== null) {
            $rejected[] = $sample['error'];
            continue;
        }

        if ($sample['received'] < $total) {
            $rejected[] = sprintf('drained %d of %d', $sample['received'], $total);
            continue;
        }

        $complete[] = $sample;
    }

    $note = $rejected === [] ? '' : sprintf('   (!! %d/%d rejected: %s)', count($rejected), $repeat, $rejected[0]);

    if ($complete === []) {
        printf("%-7s %12s %11s %11s%s\n", $name, 'n/a', 'n/a', 'n/a', $note);
        $exit = 1;

        continue;
    }

    $pick = static function (string $key) use ($complete): float {
        $series = array_map(static fn(array $s): float => $s[$key], $complete);
        sort($series);

        return $series[intdiv(count($series), 2)];
    };

    printf(
        "%-7s %12.0f %9.2fms %9.2fms%s\n",
        $name,
        $pick('drain'),
        $pick('p50'),
        $pick('p95'),
        $note,
    );
}

// A cell that produced no usable sample is a failed benchmark, not a blank row.
exit($exit);
