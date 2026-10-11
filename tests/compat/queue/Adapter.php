<?php

namespace Tests\Compat\Queue;

require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/Functions.php';
require_once __DIR__ . '/Memory.php';
require_once __DIR__ . '/Drain.php';

use Swoole\Coroutine;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\PhpStd\Typed;
use Tests\Compat\Session;
use Utopia\DSN\DSN;
use Utopia\NATS\Connection as NatsConnection;
use Utopia\NATS\JetStream\DiscardPolicy;
use Utopia\NATS\JetStream\StorageType;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Adapter\KubernetesJob;
use Utopia\Queue\Broker\Background;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Broker\Pool;
use Utopia\Queue\Broker\Provisioning;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Codec;
use Utopia\Queue\Codec\Compat;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Connection\RedisCluster;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;
use Utopia\Servers\Hook;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

/**
 * Maps tests/compat/queue/spec.json operations onto utopia-php/queue. Glue
 * only: the fixtures (a memory consumer, a recording hook) are test doubles
 * like the ones the PHP tests use.
 */
final class Adapter implements Base, Benchmarks
{
    /** A message as Appwrite's audits queue carries one; BENCH_PAYLOAD in the Rust adapter. */
    private const string BENCH_PAYLOAD = '{"project":{"$id":"6650c1f2a8e4d","name":"Demo","region":"fra"},"user":{"$id":"6650c20b3f1a2","name":"Ada Lovelace","email":"ada@example.com"},"event":"users.6650c20b3f1a2.create","resource":"user/6650c20b3f1a2","mode":"admin","userAgent":"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15","ip":"203.0.113.7","hostname":"cloud.appwrite.io","data":{"id":1,"tags":["a","b","c"],"active":true,"score":4.5,"note":"café ✓"},"time":1700000000}';

    public function benchmarks(): array
    {
        $payload = json_decode(self::BENCH_PAYLOAD, true);
        $envelope = ['pid' => '6650c2a91b6f53.12345678', 'queue' => 'v1-audits', 'timestamp' => 1700000000, 'payload' => $payload];
        $queue = fn (Session $s): Queue => new Queue('bench', "{$s->ns}.bench");
        $broker = function (Session $s): Redis {
            // The system clock, not a pinned one: the brokers call time() and uniqid() for real.
            Clock::release();

            return new Redis(self::connection(['kind' => 'redis'], $s), new Locking(self::connection(['kind' => 'redis'], $s)));
        };

        return [
            'encode' => ['Encode an audits envelope to JSON bytes, as a broker publishes it', function (Session $s) use ($envelope) {
                $json = new Json();

                return fn () => $json->encode($envelope);
            }],
            'decode' => ['Decode an audits envelope from JSON bytes into a Message, as a broker receives it', function (Session $s) use ($envelope) {
                $json = new Json();
                $bytes = $json->encode($envelope);

                return fn () => new Message($json->decode($bytes));
            }],
            'igbinary' => ['Encode an audits envelope with igbinary and decode it back', function (Session $s) use ($envelope) {
                $igbinary = new Igbinary();

                return fn () => $igbinary->decode($igbinary->encode($envelope));
            }],
            'publish' => ['Publish an audits message onto a Redis queue: the envelope encoded and one LPUSH', function (Session $s) use ($broker, $queue, $payload) {
                $redis = $broker($s);
                $q = $queue($s);

                return fn () => $redis->publish($q, $payload);
            }],
            'consume' => ['Publish, receive and commit one message on a Redis queue: an LPUSH and the reserve, claim and settle scripts', function (Session $s) use ($broker, $queue, $payload) {
                $redis = $broker($s);
                $q = $queue($s);

                return function () use ($redis, $q, $payload): void {
                    $redis->publish($q, $payload);
                    foreach ($redis->receive($q, 0) as $message) {
                        $redis->commit($q, $message);
                    }
                };
            }],
        ];
    }

    /** @var array<int, \ArrayObject<int, array{string, string}>> what each NATS broker reported, by object id */
    private static array $reports = [];

    public function operations(): array
    {
        $operations = [
            'clock.set' => function (array $a): null {
                self::clock(['continue' => true, ...$a]);

                return null;
            },
            'queue.new' => function (array $a): array {
                $queue = self::queue($a);

                return [$queue->name, $queue->namespace, $queue->jobTtl];
            },
            'message.new' => fn (array $a) => Typed::dump(new Message(Typed::decode($a['envelope']))->asArray()),
            'message.set' => function (array $a): mixed {
                $m = new Message(Typed::decode($a['envelope']));
                isset($a['pid']) && $m->setPid($a['pid']);
                isset($a['queue']) && $m->setQueue($a['queue']);
                isset($a['timestamp']) && $m->setTimestamp($a['timestamp']);
                isset($a['payload']) && $m->setPayload(Typed::decode($a['payload']));
                isset($a['attempts']) && $m->setAttempts($a['attempts']);
                \array_key_exists('sequence', $a) && $m->setSequence($a['sequence']);
                isset($a['receipt']) && $m->setReceipt($a['receipt']);
                isset($a['terminal']) && $m->terminal($a['terminal']);

                return Typed::dump([
                    'pid' => $m->getPid(),
                    'queue' => $m->getQueue(),
                    'timestamp' => $m->getTimestamp(),
                    'payload' => $m->getPayload(),
                    'attempts' => $m->getAttempts(),
                    'sequence' => $m->getSequence(),
                    'receipt' => $m->getReceipt(),
                    'terminal' => $m->isTerminal(),
                    'array' => $m->asArray(),
                ]);
            },

            'codec.encode' => fn (array $a) => self::codec($a['codec'])->encode(Typed::decode($a['value'])),
            'codec.decode' => fn (array $a) => Typed::dump(self::codec($a['codec'])->decode($a['bytes'])),
            'codec.content_type' => fn (array $a) => self::codec($a['codec'])->contentType(),
            'codec.round_trip' => function (array $a): array {
                $codec = self::codec($a['codec']);
                $bytes = $codec->encode(Typed::decode($a['value']));

                return ['bytes' => $bytes, 'value' => Typed::dump($codec->decode($bytes))];
            },

            'connection.new' => fn (array $a, Session $s) => $s->handle(self::connection($a, $s)),
            'connection.call' => fn (array $a, Session $s) => self::call($s->get($a['connection']), $a['method'], $a['args'] ?? []),

            'redis.cmd' => function (array $a, Session $s): mixed {
                $reply = self::redis($s, $a['service'] ?? 'redis', $a['auth'] ?? null)->rawCommand(...$a['args']);

                return Typed::dump($reply === false ? null : $reply);
            },

            'broker.redis' => function (array $a, Session $s): array {
                self::clock($a);
                $receive = isset($a['receive']) ? $s->get($a['receive']) : self::connection(['kind' => 'redis'], $s);
                $commands = isset($a['commands']) ? $s->get($a['commands']) : new Locking(self::connection(['kind' => 'redis'], $s));

                return $s->handle(new Redis($receive, $commands, self::codec($a['codec'] ?? 'json'), $a['reap_after'] ?? 90_000));
            },
            'broker.round_trip' => function (array $a, Session $s): array {
                Clock::fix(1700000000, 'fz');
                $connection = self::connection(['kind' => 'redis'], $s);
                $broker = new Redis($connection, new Locking(self::connection(['kind' => 'redis'], $s)), self::codec($a['codec'] ?? 'json'));
                $queue = new Queue('fuzz', $s->ns);
                $broker->publish($queue, Typed::decode($a['payload']));
                $raw = $connection->listRange("{$s->ns}.queue.fuzz", 1, 0)[0] ?? null;
                $message = $broker->receive($queue, 0)[0] ?? null;
                if ($message instanceof Message) {
                    $broker->commit($queue, $message);
                }

                return ['bytes' => $raw, 'payload' => $message instanceof Message ? Typed::dump($message->getPayload()) : null];
            },
            'broker.pool' => function (array $a, Session $s): array {
                self::clock($a);
                $make = fn (): Redis => new Redis(self::connection(['kind' => 'redis'], $s), new Locking(self::connection(['kind' => 'redis'], $s)), self::codec($a['codec'] ?? 'json'), $a['reap_after'] ?? 90_000);
                $pool = new UtopiaPool(new Stack(), 'compat', $a['size'] ?? 1, $make(...), 1);

                return $s->handle(new Pool($a['publisher'] ?? true ? $pool : null, $a['consumer'] ?? true ? $pool : null));
            },
            'broker.publish' => fn (array $a, Session $s) => self::publisher($a, $s)->publish(self::queue($a['queue']), Typed::decode($a['payload'])),
            'broker.publish_many' => fn (array $a, Session $s) => self::publisher($a, $s)->publishMany(self::queue($a['queue']), array_map(Typed::decode(...), $a['payloads'])),
            'broker.receive' => function (array $a, Session $s): array {
                $messages = self::consumer($a, $s)->receive(self::queue($a['queue']), $a['timeout'] ?? 0, $a['n'] ?? 1);

                return self::delivered($messages, $s);
            },
            'broker.commit' => fn (array $a, Session $s) => self::consumer($a, $s)->commit(self::queue($a['queue']), self::message($a, $s)),
            'broker.commit_all' => function (array $a, Session $s): array {
                // Every commit at once, each in its own coroutine: settlements that
                // wait together leave together, and each keeps its own outcome.
                $broker = self::consumer($a, $s);
                $queue = self::queue($a['queue']);
                $results = [];
                $group = new \Swoole\Coroutine\WaitGroup();
                foreach (self::messages($a, $s) as $index => $message) {
                    $group->add();
                    Coroutine::create(function () use ($broker, $queue, $message, $index, &$results, $group): void {
                        try {
                            $broker->commit($queue, $message);
                            $results[$index] = true;
                        } catch (\Throwable $error) {
                            $results[$index] = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
                        } finally {
                            $group->done();
                        }
                    });
                }
                $group->wait();
                ksort($results);

                return array_values($results);
            },
            'broker.reject' => fn (array $a, Session $s) => self::consumer($a, $s)->reject(self::queue($a['queue']), self::message($a, $s)),
            'broker.release' => fn (array $a, Session $s) => self::consumer($a, $s)->release(self::queue($a['queue']), ...self::messages($a, $s)),
            'broker.extend' => fn (array $a, Session $s) => self::consumer($a, $s)->extend(self::queue($a['queue']), ...self::messages($a, $s)),
            'broker.extend_interval' => fn (array $a, Session $s) => self::consumer($a, $s)->extendInterval(),
            'broker.maintain' => fn (array $a, Session $s) => self::consumer($a, $s)->maintain(),
            'broker.retry' => fn (array $a, Session $s) => self::consumer($a, $s)->retry(self::queue($a['queue']), $a['limit'] ?? null, $a['max_attempts'] ?? null, $a['newer_than'] ?? null),
            'broker.reap' => fn (array $a, Session $s) => self::consumer($a, $s)->reap(self::queue($a['queue']), $a['older_than'] ?? 90000, $a['limit'] ?? null, $a['max_attempts'] ?? null, $a['newer_than'] ?? null, ...(isset($a['scan']) ? ['scan' => $a['scan']] : [])),
            'broker.queue_size' => fn (array $a, Session $s) => self::publisher($a, $s)->getQueueSize(self::queue($a['queue']), $a['failed'] ?? false),
            'broker.failed_count' => fn (array $a, Session $s) => self::publisher($a, $s)->getFailedCount(self::queue($a['queue'])),
            'broker.in_flight_ceiling' => fn (array $a, Session $s) => self::consumer($a, $s)->inFlightCeiling(),
            'broker.close' => fn (array $a, Session $s) => self::consumer($a, $s)->close(),
            'broker.reconnects' => function (array $a, Session $s): array {
                self::clock($a);
                $events = [];
                $dead = new RedisConnection('localhost', 1);
                $broker = new Redis($dead, $dead);
                $broker->setReconnectCallback(function (Queue $queue, \Throwable $error, int $attempt, int $sleep) use (&$events): void {
                    $events[] = [$queue->name, $error::class, $attempt, $sleep >= 0];
                });
                $broker->setReconnectSuccessCallback(function (Queue $queue, int $attempts) use (&$events): void {
                    $events[] = [$queue->name, 'success', $attempts];
                });
                $received = [];
                for ($i = 0; $i < ($a['times'] ?? 1); $i++) {
                    $received[] = \count($broker->receive(self::queue($a['queue']), 0));
                }

                return ['received' => $received, 'events' => $events];
            },

            'background.new' => fn (array $a, Session $s) => $s->handle(new Background(
                $s->get($a['broker']),
                $a['capacity'] ?? 512,
                $a['coroutines'] ?? 1,
                $a['timeout'] ?? -1,
                maxBatchInterval: $a['batch_interval'] ?? null,
                maxBatchSize: $a['batch_size'] ?? null,
            )),
            'background.run' => function (array $a, Session $s): mixed {
                /** @var Background $background */
                $background = $s->get($a['background']);
                $queue = self::queue($a['queue']);
                $run = function () use ($background, $queue, $a): void {
                    if ($a['start'] ?? true) {
                        $background->start();
                    }
                    foreach ($a['payloads'] as $payload) {
                        $background->enqueue($queue, Typed::decode($payload));
                    }
                    $background->shutdown();
                };
                if (Coroutine::getCid() >= 0) {
                    $run();
                } else {
                    Coroutine\run($run);
                }

                return null;
            },
            'background.size' => fn (array $a, Session $s) => $s->get($a['background'])->getQueueSize(self::queue($a['queue']), $a['failed'] ?? false),
            'background.failed_count' => fn (array $a, Session $s) => $s->get($a['background'])->getFailedCount(self::queue($a['queue'])),
            'background.retry' => fn (array $a, Session $s) => $s->get($a['background'])->retry(self::queue($a['queue']), $a['limit'] ?? null),
            'background.publish' => fn (array $a, Session $s) => $s->get($a['background'])->publish(self::queue($a['queue']), Typed::decode($a['payload'])),
            'background.publish_many' => fn (array $a, Session $s) => $s->get($a['background'])->publishMany(self::queue($a['queue']), array_map(Typed::decode(...), $a['payloads'])),

            'memory.new' => fn (array $a, Session $s) => $s->handle(Memory::make($a)),
            'memory.log' => fn (array $a, Session $s) => $s->get($a['consumer'])->log,

            'server.configure' => fn (array $a, Session $s) => self::configure($a, $s),
            'server.run' => fn (array $a, Session $s) => self::run($a, $s),
            'nats.broker' => function (array $a, Session $s): array {
                self::clock($a);
                $url = $s->service('queue-nats');
                /** @var \ArrayObject<int, array{string, string}> $reports */
                $reports = new \ArrayObject();
                $field = $a['message_id'] ?? null;
                $broker = new Nats(
                    ($a['live'] ?? false) ? NatsConnection::connect($url) : fn (): NatsConnection => NatsConnection::connect($url),
                    ackWait: $a['ack_wait'] ?? 30.0,
                    maxDeliver: $a['max_deliver'] ?? 5,
                    backoff: $a['backoff'] ?? null,
                    storage: ($a['memory'] ?? false) ? StorageType::Memory : StorageType::File,
                    deadMaxAge: $a['dead_max_age'] ?? null,
                    duplicateWindow: $a['duplicate_window'] ?? 120.0,
                    messageId: $field === null ? null : static fn (array $payload): string => (string) ($payload[$field] ?? ''),
                    onError: static function (\Throwable $error) use ($reports): void {
                        $reports[] = [$error::class, $error->getMessage()];
                    },
                    maxAge: $a['max_age'] ?? null,
                    maxMsgSize: $a['max_msg_size'] ?? null,
                    maxMsgs: $a['max_msgs'] ?? -1,
                    maxBytes: $a['max_bytes'] ?? -1,
                    discard: ($a['discard_new'] ?? false) ? DiscardPolicy::New : DiscardPolicy::Old,
                    maxAckPending: $a['max_ack_pending'] ?? null,
                    maxWaiting: $a['max_waiting'] ?? null,
                    inactiveThreshold: $a['inactive_threshold'] ?? null,
                    provisioning: ($a['require'] ?? false) ? Provisioning::Require : Provisioning::Ensure,
                    codec: self::codec($a['codec'] ?? 'json'),
                    releaseDelay: $a['release_delay'] ?? 5.0,
                );
                self::$reports[spl_object_id($broker)] = $reports;

                return $s->handle($broker);
            },
            'nats.provision' => fn (array $a, Session $s) => $s->get($a['broker'])->provision(self::queue($a['queue'])),
            'nats.duplicates' => fn (array $a, Session $s) => $s->get($a['broker'])->duplicates(),
            'nats.reconnect' => fn (array $a, Session $s) => $s->get($a['broker'])->reconnect(),
            'nats.reports' => fn (array $a, Session $s) => (self::$reports[spl_object_id($s->get($a['broker']))] ?? new \ArrayObject())->getArrayCopy(),
            'nats.stream' => function (array $a, Session $s): ?array {
                $js = NatsConnection::connect($s->service('queue-nats'))->jetStream();
                try {
                    $info = $js->getStreamInfo(self::stream($a['queue'], $a['dead'] ?? false));
                } catch (\Utopia\NATS\Exception\JetStreamException) {
                    return null;
                }
                $c = $info->config;

                return [
                    'subjects' => $c->subjects, 'retention' => $c->retention->value, 'max_msgs' => $c->maxMsgs, 'max_bytes' => $c->maxBytes,
                    'max_msg_size' => $c->maxMsgSize ?? -1, 'max_age' => (float) ($c->maxAge ?? 0), 'storage' => $c->storage->value,
                    'replicas' => $c->replicas, 'discard' => $c->discard->value, 'duplicate_window' => (float) ($c->duplicateWindow ?? 0),
                    'identity' => $c->metadata['utopia_queue_identity'] ?? null, 'messages' => $info->state->messages,
                ];
            },
            'nats.consumer' => function (array $a, Session $s): ?array {
                $js = NatsConnection::connect($s->service('queue-nats'))->jetStream();
                try {
                    $info = $js->getConsumer(self::stream($a['queue'], $a['dead'] ?? false), $a['consumer'])->info(true);
                } catch (\Utopia\NATS\Exception\JetStreamException) {
                    return null;
                }
                $c = $info->config;

                return [
                    'max_deliver' => $c->maxDeliver, 'ack_wait' => (float) $c->ackWait, 'max_ack_pending' => $c->maxAckPending,
                    'max_waiting' => $c->maxWaiting, 'backoff' => array_map(floatval(...), $c->backoff ?? []),
                    'spare' => isset($c->metadata['utopia_queue_spare_delivery']), 'pending' => $info->numPending,
                ];
            },
            'nats.message' => function (array $a, Session $s): ?array {
                $js = NatsConnection::connect($s->service('queue-nats'))->jetStream();
                try {
                    $m = $js->getMessage(self::stream($a['queue'], $a['dead'] ?? false), $a['seq']);
                } catch (\Utopia\NATS\Exception\JetStreamException) {
                    return null;
                }

                return ['subject' => $m->subject, 'data' => $m->data, 'content_type' => $m->headers?->get('Content-Type'), 'id' => $m->headers?->get('Nats-Msg-Id')];
            },
            'nats.delete' => function (array $a, Session $s): null {
                $js = NatsConnection::connect($s->service('queue-nats'))->jetStream();
                foreach (array_merge(array_map(fn (string $q): string => self::stream($q, false), $a['queues']), array_map(fn (string $q): string => self::stream($q, true), $a['queues'])) as $stream) {
                    try {
                        $js->deleteStream($stream);
                    } catch (\Throwable) {
                    }
                }

                return null;
            },
            'adapter.consume' => fn (array $a, Session $s) => self::consume($a, $s),
            'adapter.maintain' => function (array $a, Session $s): array {
                $reports = [];
                foreach ($a['consumers'] as $handle) {
                    $adapter = new KubernetesJob($s->get($handle), 1);
                    $adapter->maintain(($a['report'] ?? true) ? function (?Message $message, \Throwable $error) use (&$reports, $a): void {
                        $reports[] = [$message?->getPid(), $error->getMessage()];
                        if ($a['fail_report'] ?? false) {
                            throw new \RuntimeException('report failed');
                        }
                    } : null);
                }

                return $reports;
            },
        ];

        // Every operation runs in a coroutine: the background publisher and the
        // workers need one, and a Redis socket first used inside one cannot be
        // used outside it afterwards.
        // `clock` names the pinned clock (by prefix) the operation runs on: an
        // interop case's two sides each keep their own, whether they run in two
        // processes or, when recording, in one.
        return array_map(static fn (\Closure $operation): \Closure => static function (array $a, Session $s) use ($operation): mixed {
            Clock::scope($s->ns);
            if (isset($a['clock'])) {
                Clock::activate($a['clock']);
            }

            return self::coroutine(static fn (): mixed => $operation($a, $s));
        }, $operations);
    }

    /**
     * The stream a queue name maps to (`Q_<NAME>`, `_DEAD` for the dead one),
     * so cases can name streams whose names embed the run's namespace.
     */
    private static function stream(string $queue, bool $dead): string
    {
        $token = '';
        foreach (str_split($queue) as $byte) {
            $token .= ctype_alnum($byte) || $byte === '_' || $byte === '-' ? strtoupper($byte) : '_';
        }

        return 'Q_' . $token . ($dead ? '_DEAD' : '');
    }

    /** Runs `$operation` in a coroutine, rethrowing what it throws. */
    private static function coroutine(\Closure $operation): mixed
    {
        if (Coroutine::getCid() >= 0) {
            return $operation();
        }
        $result = null;
        $error = null;
        Coroutine\run(static function () use ($operation, &$result, &$error): void {
            try {
                $result = $operation();
            } catch (\Throwable $thrown) {
                $error = $thrown;
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }

        return $result;
    }

    /** Pins (or releases) the brokers' clock for a case. */
    private static function clock(array $a): void
    {
        isset($a['time']) ? Clock::fix($a['time'], $a['prefix'] ?? 'pid', $a['continue'] ?? false) : Clock::release();
    }

    private static function redis(Session $s, string $service = 'redis', ?string $auth = null): \Redis
    {
        $url = parse_url($s->service($service));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);
        if ($auth !== null) {
            $redis->auth($auth);
        }

        return $redis;
    }

    private static function queue(array $a): Queue
    {
        return new Queue($a['name'], $a['namespace'] ?? 'utopia-queue', $a['job_ttl'] ?? 0);
    }

    private static function codec(string $name): Codec
    {
        return match ($name) {
            'json' => new Json(),
            'igbinary' => new Igbinary(),
            'compat' => new Compat(),
            'compat-igbinary' => new Compat(new Igbinary()),
            default => throw new Fault("unknown codec {$name}"),
        };
    }

    private static function connection(array $a, Session $s): Connection
    {
        $url = parse_url($s->service($a['service'] ?? 'redis'));
        $host = $url['host'] ?? 'redis';
        $port = $url['port'] ?? 6379;

        return match ($a['kind']) {
            'redis' => new RedisConnection($a['host'] ?? $host, $a['port'] ?? $port, $a['user'] ?? null, $a['password'] ?? null),
            'redis-dsn' => RedisConnection::fromDSN(new DSN(str_replace('{host}', "{$host}:{$port}", $a['dsn']))),
            'locking' => new Locking(new RedisConnection($host, $port)),
            'cluster' => new RedisCluster($a['seeds'] ?? explode(',', substr($s->service('queue-redis-cluster'), \strlen('redis-cluster://'))), user: $a['user'] ?? null, password: $a['password'] ?? null),
            'cluster-dsn' => RedisCluster::fromDSN(new DSN(str_replace('{cluster}', str_replace(',', ';', substr($s->service('queue-redis-cluster'), \strlen('redis-cluster://'))), $a['dsn']))),
            default => throw new Fault("unknown connection {$a['kind']}"),
        };
    }

    private static function call(Connection $c, string $method, array $args): mixed
    {
        $result = match ($method) {
            'execute' => $c->execute($args[0], $args[1], $args[2]),
            'rightPushArray', 'leftPushArray' => $c->{$method}($args[0], Typed::decode($args[1])),
            'setArray' => $c->setArray($args[0], Typed::decode($args[1]), ...\array_slice($args, 2)),
            default => $c->{$method}(...$args),
        };

        return Typed::dump($result);
    }

    private static function publisher(array $a, Session $s): Synchronous
    {
        return $s->get($a['broker']);
    }

    private static function consumer(array $a, Session $s): mixed
    {
        return $s->get($a['broker']);
    }

    private static function message(array $a, Session $s): Message
    {
        $message = $s->get($a['message']);
        if ($a['terminal'] ?? false) {
            $message->terminal();
        }

        return $message;
    }

    /** @return list<Message> */
    private static function messages(array $a, Session $s): array
    {
        return array_map(fn (array $h): Message => $s->get($h), $a['messages']);
    }

    /** @param list<Message> $messages */
    private static function delivered(array $messages, Session $s): array
    {
        $out = ['handles' => [], 'messages' => []];
        foreach ($messages as $message) {
            $out['handles'][] = $s->handle($message);
            $out['messages'][] = Typed::dump([...$message->asArray(), 'receipt' => $message->getReceipt()]);
        }

        return $out;
    }

    /**
     * The long-running loop (Adapter\Swoole::consume) over one queue until
     * `stop_after` messages were handled (or five seconds passed): which
     * messages ran, the most that ran at once, what succeeded and what was
     * reported. Handlers hold for `hold_ms`; pids in `fail` throw.
     */
    private static function consume(array $a, Session $s): array
    {
        $consumer = $s->get($a['consumer']);
        $adapter = new Workers($consumer, 1);
        $state = ['handled' => [], 'succeeded' => [], 'errors' => [], 'active' => 0, 'max' => 0];
        $spec = ['queue' => self::queue($a['queue']), 'coroutines' => $a['coroutines'] ?? 1, 'prefetch' => $a['prefetch'] ?? ($a['coroutines'] ?? 1), 'consumer' => $consumer];
        $run = function () use ($adapter, $spec, $a, &$state): void {
            $deadline = Coroutine::create(function () use ($adapter): void {
                Coroutine::sleep(5);
                $adapter->stop();
            });
            $adapter->consume(
                function (Message $message) use ($adapter, $a, &$state): void {
                    $state['max'] = max($state['max'], ++$state['active']);
                    if (($a['hold_ms'] ?? 0) > 0) {
                        Coroutine::sleep($a['hold_ms'] / 1000);
                    }
                    $state['active']--;
                    $state['handled'][] = $message->getPid();
                    if (\count($state['handled']) >= $a['stop_after']) {
                        $adapter->stop();
                    }
                    if (\in_array($message->getPid(), $a['fail'] ?? [], true)) {
                        throw new \RuntimeException('boom');
                    }
                },
                function (Message $message) use (&$state): void {
                    $state['succeeded'][] = $message->getPid();
                },
                function (?Message $message, \Throwable $error) use (&$state): void {
                    $state['errors'][] = [$message?->getPid(), $error->getMessage()];
                },
                [$spec],
            );
            if (\is_int($deadline)) {
                Coroutine::cancel($deadline);
            }
        };
        Coroutine::getCid() >= 0 ? $run() : Coroutine\run($run);
        if ($a['sort'] ?? false) {
            sort($state['handled']);
            sort($state['succeeded']);
        }

        return ['handled' => $state['handled'], 'succeeded' => $state['succeeded'], 'errors' => $state['errors'], 'max' => $state['max']];
    }

    private static function configure(array $a, Session $s): array
    {
        $consumer = isset($a['consumer']) ? $s->get($a['consumer']) : Memory::make([]);
        $adapter = new Drain(($a['shared'] ?? true) ? $consumer : fn (string $queue): Consumer => $consumer, 1);
        $server = new Server($adapter);
        $out = ['jobs' => []];
        foreach ($a['jobs'] ?? [] as $job) {
            try {
                $server->job($job['queue'], $job['coroutines'] ?? 1, $job['prefetch'] ?? null);
                $out['jobs'][] = [$job['queue'], $server->coroutines($job['queue']), $server->prefetch($job['queue'])];
            } catch (\Throwable $error) {
                $out['jobs'][] = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
            }
        }
        $out['registered'] = array_keys($server->jobs());
        $out['unregistered'] = [$server->coroutines('none'), $server->prefetch('none')];
        if ($a['start'] ?? false) {
            try {
                foreach (array_keys($server->jobs()) as $queue) {
                    $server->jobs()[$queue]->action(fn () => null);
                }
                $server->start();
                $out['start'] = 'ok';
            } catch (\Throwable $error) {
                $out['start'] = ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
            }
        }

        return $out;
    }

    /**
     * Runs a worker to completion (KubernetesJob: drain each queue, then
     * return) and reports what every hook and job saw, in order.
     */
    private static function run(array $a, Session $s): array
    {
        $consumer = $s->get($a['consumer']);
        $events = [];
        $adapter = ($a['slow'] ?? false) ? new DrainSlow($consumer, 1, $a['namespace'] ?? 'utopia-queue') : new Drain(($a['shared'] ?? true) ? $consumer : fn (string $queue): Consumer => $consumer, 1, $a['namespace'] ?? 'utopia-queue');
        $server = new Server($adapter);
        $server->resources()->set('greeting', fn (): string => 'hello');
        $telemetry = ($a['telemetry'] ?? false) ? new TestTelemetry() : null;
        if ($telemetry !== null) {
            $server->setTelemetry($telemetry);
        }
        $record = function (string $name, array $spec) use (&$events, $server): \Closure {
            return function (...$args) use ($name, $spec, &$events, $server): void {
                $seen = [];
                foreach ($args as $arg) {
                    $seen[] = match (true) {
                        $arg instanceof Message => 'message:' . $arg->getPid(),
                        $arg instanceof \Throwable => 'error:' . $arg::class . ':' . $arg->getMessage(),
                        default => Typed::dump($arg),
                    };
                    // `context`: register the message's pid in its context (only for `when`, if given).
                    if ($arg instanceof Message && isset($spec['context']) && ($spec['when'] ?? $arg->getPid()) === $arg->getPid()) {
                        $pid = $arg->getPid();
                        $server->context()->set($spec['context'], fn (): string => $pid);
                    }
                }
                // `has`: what the context holds under that name, or null.
                if (isset($spec['has'])) {
                    $seen[] = $server->context()->has($spec['has']) ? $server->context()->get($spec['has']) : null;
                }
                $events[] = [$name, $seen];
                $fail = $spec['fail'] ?? null;
                if ($fail === 'terminal') {
                    foreach ($args as $arg) {
                        if ($arg instanceof Message) {
                            $arg->terminal();
                        }
                    }
                    throw new \RuntimeException('marked terminal');
                }
                if (\is_array($fail)) {
                    throw match ($fail['class']) {
                        'PermanentFailure' => new PermanentFailure($fail['message']),
                        'TypeError' => new \TypeError($fail['message']),
                        'ValueError' => new \ValueError($fail['message']),
                        'Error' => new \Error($fail['message']),
                        'Exception' => new \Exception($fail['message']),
                        default => new \RuntimeException($fail['message']),
                    };
                }
            };
        };
        $declare = function (Hook $hook, array $spec, string $name) use ($record): void {
            $hook->groups($spec['groups'] ?? ['*']);
            foreach ($spec['params'] ?? [] as $param) {
                $validator = match ($param['validator'] ?? 'text') {
                    'integer' => new Integer(true),
                    'any' => new \Utopia\Validator\Wildcard(),
                    default => new Text($param['length'] ?? 256, 0),
                };
                $hook->param($param['key'], $param['default'] ?? null, $validator, '', $param['optional'] ?? false, aliases: $param['aliases'] ?? []);
            }
            foreach ($spec['inject'] ?? [] as $injection) {
                $hook->inject($injection);
            }
            $hook->action($record($name, $spec));
        };
        foreach ($a['jobs'] as $spec) {
            $job = $server->job($spec['queue'], $spec['coroutines'] ?? 1, $spec['prefetch'] ?? null);
            if (isset($spec['hook'])) {
                $job->hook($spec['hook']);
            }
            $declare($job, $spec, 'job:' . $spec['queue']);
            $job->groups($spec['groups'] ?? []);
        }
        foreach (['init', 'shutdown', 'error', 'workerStart', 'workerStop'] as $kind) {
            foreach ($a['hooks'][$kind] ?? [] as $index => $spec) {
                $declare($server->{$kind}(), $spec, "{$kind}{$index}");
            }
        }
        try {
            $server->start();
        } catch (\Throwable $error) {
            $events[] = ['start', ['error:' . $error::class . ':' . $error->getMessage()]];
        }
        if ($telemetry !== null) {
            $events[] = ['telemetry', self::telemetry($telemetry)];
        }

        return $events;
    }

    /** What a run recorded: each duration histogram's size and sign, each depth gauge observed twice. */
    private static function telemetry(TestTelemetry $telemetry): array
    {
        $out = [];
        foreach (['messaging.process.wait.duration', 'messaging.process.duration'] as $name) {
            /** @var list<float|int> $values */
            $values = isset($telemetry->histograms[$name]) ? get_object_vars($telemetry->histograms[$name])['values'] ?? [] : [];
            $out[$name] = ['count' => \count($values), 'non_negative' => array_filter($values, fn (float|int $v): bool => $v < 0) === []];
        }
        foreach (['messaging.queue.depth', 'messaging.queue.failed.depth'] as $name) {
            $observed = [];
            for ($round = 0; $round < 2; $round++) {
                foreach ($telemetry->observableGauges[$name]->callbacks ?? [] as $callback) {
                    $callback(function (float|int $value, iterable $attributes = []) use (&$observed): void {
                        $observed[] = [$value, iterator_to_array($attributes)];
                    });
                }
            }
            $out[$name] = $observed;
        }

        return $out;
    }
}
