<?php

/**
 * Consume-side comparison of Broker\Redis and Broker\Nats at N handler coroutines.
 *
 * Mirrors Adapter\Swoole::consumeBound -- one receive loop, a slot channel capped at
 * --coroutines, the commit running inside the per-message coroutine. Redis is wired the
 * way cloud wires it (app/worker.php): a blocking receive connection plus a Locking
 * commands connection. The NATS broker gets the Closure factory, so it resolves its own
 * connections exactly as a deployed worker does.
 *
 * Two regimes, because only one of them can tell a shared socket from a split one:
 *
 *   --rate=0 (backlog)  Publish everything, then drain. The queue is never empty, so
 *                       every receive returns at once and the loop is either fetching
 *                       or blocked on its slot channel -- it never parks in a fetch,
 *                       so an acknowledgment never has to wait for one. This regime
 *                       measures the receive loop's ceiling and nothing about locking.
 *   --rate=N (steady)   Publish at N msg/s while draining, below what the handlers can
 *                       absorb. The queue is usually empty, so the loop is usually
 *                       parked in a fetch when a handler acknowledges -- which a broker
 *                       sharing one socket between fetch and ack must serialise. This
 *                       is the regime that shows the difference, and it shows it in
 *                       commit latency rather than in drain rate.
 *
 * --work is what keeps slots occupied. With a zero-cost handler the loop reclaims a slot
 * as fast as it fills one and nothing is ever in flight.
 *
 * Needs live services and so is not wired to `composer bench`: the Benchmark workflow
 * has no Redis or NATS to point it at. Run it against the package's own compose stack,
 * or any reachable pair:
 *
 *   docker compose -f packages/queue/docker-compose.yml up -d redis nats
 *   REDIS_HOST=127.0.0.1 REDIS_PORT=16379 NATS_URL=nats://127.0.0.1:14225 \
 *     php packages/queue/benchmarks/coroutines.php --coroutines=1,2,4,8 --repeat=5
 *
 * Absolute rates are host-bound and not comparable across machines; the medians only
 * mean something against another run on the same host. Five runs minimum for anything
 * written down -- drain moves double digits between identical runs.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Utopia\NATS\Connection as NatsConnection;
use Utopia\Queue\Broker\Nats as NatsBroker;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Queue;

const DEFAULTS = [
    'backend' => 'both',
    'coroutines' => '1,2,4,8',
    'messages' => '400',
    'payload' => '512',
    'repeat' => '3',
    'work' => '0',      // seconds the handler holds its slot
    'rate' => '0',       // msg/s published while draining; 0 = publish everything first
    'label' => '',
];

$args = DEFAULTS;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m) === 1 && array_key_exists($m[1], DEFAULTS)) {
        $args[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "unknown option {$arg}\n");
    exit(2);
}

function broker(string $name): RedisBroker|NatsBroker
{
    if ($name === 'redis') {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        return new RedisBroker(
            receive: new RedisConnection($host, $port),
            commands: new Locking(new RedisConnection($host, $port)),
        );
    }

    $url = getenv('NATS_URL') ?: 'nats://nats:4222';

    return new NatsBroker(fn(): NatsConnection => NatsConnection::connect($url));
}

function queueFor(string $name): Queue
{
    return new Queue('bench_' . $name, 'bench');
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

/** @return array{drain: float, p50: float, p95: float, max: float, received: int, error: ?string} */
function measure(string $name, int $slots, int $total, int $payload, float $work, float $rate): array
{
    $client = broker($name);
    $queue = queueFor($name);

    $out = ['drain' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'max' => 0.0, 'received' => 0, 'error' => null];
    $filler = str_repeat('x', $payload);
    $failure = null;

    Coroutine\run(function () use ($client, $queue, $slots, $total, $filler, $work, $rate, &$out, &$failure): void {
        // Provision the stream / consumers before the clock starts, so first-touch
        // provisioning is not charged to the drain.
        $client->publish($queue, ['warmup' => true, 'filler' => $filler]);
        $warm = $client->receive($queue, 2);
        if ($warm !== null) {
            $client->commit($queue, $warm);
        }

        $commits = [];
        $received = 0;
        $idle = 0;
        $idleElapsed = 0.0;

        if ($rate <= 0) {
            for ($i = 0; $i < $total; $i++) {
                $client->publish($queue, ['n' => $i, 'filler' => $filler]);
            }
        }

        $started = microtime(true);
        $producer = new WaitGroup();
        $producing = $rate > 0;

        if ($rate > 0) {
            // A second broker for the producer: publishing down the consumer's own
            // receive connection would be the very contention under measurement.
            $producer->add();
            Coroutine::create(function () use ($queue, $total, $filler, $rate, $producer, &$producing, &$failure): void {
                // done() and the flag both in finally: a throw here used to leave the
                // consumer waiting on a producer that had already given up.
                try {
                    $sender = broker($queue->name === 'bench_redis' ? 'redis' : 'nats');
                    $gap = 1.0 / $rate;

                    for ($i = 0; $i < $total; $i++) {
                        $sender->publish($queue, ['n' => $i, 'filler' => $filler]);
                        Coroutine::sleep($gap);
                    }

                    $sender->close();
                } catch (\Throwable $e) {
                    $failure ??= 'publish: ' . $e->getMessage();
                } finally {
                    $producing = false;
                    $producer->done();
                }
            });
        }

        $channel = new Channel($slots);
        $group = new WaitGroup();

        while ($received < $total && $idle < 3) {
            $channel->push(true);

            $t0 = hrtime(true);
            $message = $client->receive($queue, 1);
            $t1 = hrtime(true);

            if ($message === null) {
                $channel->pop();
                $idleElapsed += ($t1 - $t0) / 1_000_000_000;

                // An empty receive only counts towards giving up once the producer has
                // finished. In steady mode the queue is empty most of the time by
                // design, so counting these would abandon the run mid-stream and report
                // a rate over a fraction of the messages.
                if (!$producing) {
                    $idle++;
                }

                continue;
            }

            $idle = 0;
            $received++;

            $group->add();
            Coroutine::create(function () use ($client, $queue, $message, $channel, $group, $work, &$commits, &$failure): void {
                // The slot and the wait group are released in finally. A commit that
                // threw used to skip both, so the receive loop blocked on a slot that
                // never came back and group->wait() never returned -- a failed run that
                // looked like a hung one.
                try {
                    if ($work > 0) {
                        Coroutine::sleep($work);
                    }

                    $c0 = hrtime(true);
                    $client->commit($queue, $message);
                    $commits[] = (hrtime(true) - $c0) / 1000;
                } catch (\Throwable $e) {
                    $failure ??= 'commit: ' . $e->getMessage();
                } finally {
                    $channel->pop();
                    $group->done();
                }
            });
        }

        // Outstanding acknowledgments have to land before the clock stops, or the rate
        // counts messages this process had not finished acknowledging.
        $group->wait();
        $producer->wait();

        // Idle receives are the stop condition, not work; leaving them in understates
        // the rate. In steady mode they are also time the loop spent parked in a fetch,
        // which is exactly the window the commit percentiles are about.
        $elapsed = max(0.0, microtime(true) - $started - $idleElapsed);

        $out = [
            'drain' => $elapsed > 0 ? $received / $elapsed : 0.0,
            'p50' => pct($commits, 0.50),
            'p95' => pct($commits, 0.95),
            'max' => pct($commits, 1.0),
            'received' => $received,
            'error' => $failure,
        ];
    });

    $client->close();

    return $out;
}

$backends = $args['backend'] === 'both' ? ['redis', 'nats'] : [$args['backend']];
$levels = array_map('intval', explode(',', $args['coroutines']));
$total = (int) $args['messages'];
$payload = (int) $args['payload'];
$repeat = max(1, (int) $args['repeat']);
$work = (float) $args['work'];
$rate = (float) $args['rate'];

printf(
    "%s%d messages, %dB payload, work=%.3fs, rate=%s, median of %d runs\n\n",
    $args['label'] === '' ? '' : $args['label'] . ': ',
    $total,
    $payload,
    $work,
    $rate > 0 ? sprintf('%.0f/s', $rate) : 'backlog',
    $repeat,
);
printf("%-7s %5s %12s %11s %11s %11s\n", 'backend', 'coro', 'drain msg/s', 'commit p50', 'commit p95', 'commit max');
printf("%-7s %5s %12s %11s %11s %11s\n", '-------', '-----', '------------', '-----------', '-----------', '-----------');

foreach ($backends as $name) {
    foreach ($levels as $slots) {
        // Only runs that drained everything are eligible for the median. A partial run
        // reports a rate over a fraction of the workload, and averaging that in reads
        // as a slower broker rather than as an aborted sample.
        $complete = [];
        $dropped = [];
        $errors = [];

        for ($r = 0; $r < $repeat; $r++) {
            $sample = measure($name, $slots, $total, $payload, $work, $rate);

            if ($sample['error'] !== null) {
                $errors[] = $sample['error'];
            }

            if ($sample['received'] < $total) {
                $dropped[] = $sample['received'];

                continue;
            }

            $complete[] = $sample;
        }

        $note = '';
        if ($dropped !== []) {
            $note .= sprintf('   (!! %d/%d runs incomplete: drained %s of %d)', count($dropped), $repeat, implode(', ', $dropped), $total);
        }
        if ($errors !== []) {
            $note .= '   (!! ' . $errors[0] . ')';
        }

        if ($complete === []) {
            printf("%-7s %5d %12s %11s %11s %11s%s\n", $name, $slots, 'n/a', 'n/a', 'n/a', 'n/a', $note);

            continue;
        }

        $pick = static function (string $key) use ($complete): float {
            $series = array_map(static fn(array $s): float => $s[$key], $complete);
            sort($series);

            return $series[intdiv(count($series), 2)];
        };

        printf(
            "%-7s %5d %12.0f %9.2fms %9.2fms %9.2fms%s\n",
            $name,
            $slots,
            $pick('drain'),
            $pick('p50'),
            $pick('p95'),
            $pick('max'),
            $note,
        );
    }
}
