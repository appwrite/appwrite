<?php

namespace Tests\Compat\Mqtt;

require_once __DIR__ . '/Memory.php';
require_once __DIR__ . '/Handlers.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\WebSocket\Frame;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Mqtt\Adapter\Swoole;
use Utopia\Mqtt\Adapter\Swoole\Tcp;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Adapter\Swoole\Timers\NoTimer;
use Utopia\Mqtt\Adapter\Swoole\Timers\TimingWheel;
use Utopia\Mqtt\Adapter\Swoole\Tls;
use Utopia\Mqtt\Adapter\Swoole\WebSocket;
use Utopia\Mqtt\Client;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Handler;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Auth;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Connect;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Filter;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Packet\Unsuback;
use Utopia\Mqtt\Packet\Unsubscribe;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;
use Utopia\Mqtt\Subscription\Store;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;
use Utopia\Telemetry\UpDownCounter;

use function Swoole\Coroutine\run;

/**
 * A broker on the in-process adapter, with its log.
 */
final class Rig
{
    /** @var \ArrayObject<int, mixed> */
    public \ArrayObject $log;

    /** @var \ArrayObject<int, mixed> */
    public \ArrayObject $timeline;

    public ?Server $server = null;

    public ?Metrics $telemetry = null;

    public function __construct(public Memory $adapter, public ?Timeline $timer)
    {
        $this->log = new \ArrayObject();
        $this->timeline = $timer->log ?? new \ArrayObject();
    }
}

/**
 * The telemetry a broker records: what each counter and up-down counter was
 * given, by name, in the order the names were first written (as
 * Utopia\Telemetry\Adapter\Test lists its instruments).
 */
final class Metrics implements Telemetry
{
    /** @var array<string, list<float|int>> */
    public array $counters = [];

    /** @var array<string, list<float|int>> */
    public array $upDownCounters = [];

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        $values = [];
        $record = function (float|int $amount) use ($name, &$values): void {
            $values[] = $amount;
            $this->counters[$name] = $values;
        };

        return new class ($record) extends Counter {
            public function __construct(private \Closure $record)
            {
            }

            public function add(float|int $amount, iterable $attributes = []): void
            {
                ($this->record)($amount);
            }
        };
    }

    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        $values = [];
        $record = function (float|int $amount) use ($name, &$values): void {
            $values[] = $amount;
            $this->upDownCounters[$name] = $values;
        };

        return new class ($record) extends UpDownCounter {
            public function __construct(private \Closure $record)
            {
            }

            public function add(float|int $amount, iterable $attributes = []): void
            {
                ($this->record)($amount);
            }
        };
    }

    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        throw new \LogicException('unused');
    }

    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        throw new \LogicException('unused');
    }

    public function createObservableGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): ObservableGauge
    {
        throw new \LogicException('unused');
    }

    public function collect(): bool
    {
        return true;
    }
}

/**
 * A timing wheel with what its hooks saw.
 */
final class Wheel
{
    /** @var \ArrayObject<int, mixed> */
    public \ArrayObject $log;

    public function __construct(public TimingWheel $wheel)
    {
        $this->log = new \ArrayObject();
    }
}

/**
 * Maps tests/compat/mqtt/spec.json operations onto utopia-php/mqtt: the
 * codec, the store, connections, brokers on the in-process adapter
 * (Memory.php), and E2E and interop runs against the Swoole adapter
 * (server.php) with the Client. Glue only.
 */
final class Adapter implements Base, Benchmarks
{
    /** Where services.sh writes the TLS listener's certificate and key. */
    private const string CERTS = '/tmp/compat-fs/mqtt-certs';

    /** Benchmark inputs, the same as in crates/tools/compat/src/libs/mqtt.rs. */
    private const int SUBSCRIPTIONS = 10_000;

    private const int FANOUT = 1000;

    public function benchmarks(): array
    {
        $properties = fn () => (new Properties())
            ->add(new Property(Property::USER, ['projectId' => '6650c0de']))
            ->add(new Property(Property::CONTENT_TYPE, 'application/json'))
            ->add(new Property(Property::MESSAGE_EXPIRY_INTERVAL, 3600));
        $payload = '{"events":["databases.*"],"payload":{"$id":"6650c1f2a4b3e9d7c812"}}';

        return [
            'decode' => ['Parse a v5 PUBLISH with three properties and decode it', function (Session $s) use ($properties, $payload) {
                $bytes = V5::publish('projects/6650c0de/databases/main/documents', $payload, 1, 42, $properties());

                return fn () => Publish::decode(Packet::parse($bytes), 5);
            }],
            'encode' => ['Encode a v5 PUBLISH with three properties', fn (Session $s) => fn () => V5::publish('projects/6650c0de/databases/main/documents', $payload, 1, 42, $properties())],
            'properties' => ['Parse a property block of three properties and merge its User Properties', function (Session $s) use ($properties) {
                $block = $properties()->encode();

                return fn () => Properties::parse($block, 0)[0]->user();
            }],
            'connect' => ['Decode a v5 CONNECT with authentication and a User Property', function (Session $s) {
                $bytes = V5::connect('device-6650c0de', 60, true, (new Properties())
                    ->add(new Property(Property::AUTHENTICATION_METHOD, 'appwrite-jwt'))
                    ->add(new Property(Property::AUTHENTICATION_DATA, str_repeat('t', 180)))
                    ->add(new Property(Property::USER, ['projectId' => '6650c0de'])));

                return fn () => Connect::decode(Packet::parse($bytes)->body);
            }],
            'match' => ['Find the subscribers of a topic among 10,000 subscriptions (exact, + and # filters)', function (Session $s) {
                $store = new Store();
                for ($i = 0; $i < self::SUBSCRIPTIONS; $i++) {
                    $store->subscribe('p' . ($i % 10), 'u', self::filter($i), $i, $i % 2);
                }

                return fn () => $store->getSubscribers('p3', 'sensors/room13/temp');
            }],
            'fanout' => ['Dispatch a QoS 0 PUBLISH through the broker to 1,000 subscribers on the in-process adapter', function (Session $s) use ($payload) {
                $adapter = new Memory();
                $server = new Server($adapter, new Deliver());
                $server->start();
                $connect = V5::connect('c', 0, true);
                $subscribe = V5::subscribe(1, ['news/+'], 0);
                for ($fd = 1; $fd <= self::FANOUT; $fd++) {
                    $adapter->open($fd);
                    $adapter->receive($fd, $connect);
                    $adapter->receive($fd, $subscribe);
                }
                $adapter->sent = [];
                $publish = V5::publish('news/tech', $payload, 0, 0);

                return function () use ($adapter, $publish) {
                    $adapter->receive(1, $publish);
                    $sent = $adapter->sent;
                    $adapter->sent = [];

                    return $sent;
                };
            }],
        ];
    }

    /** The benchmark store's filter number $i. */
    private static function filter(int $i): string
    {
        return match ($i % 4) {
            0 => 'sensors/room' . $i . '/temp',
            1 => 'sensors/+/temp',
            2 => 'sensors/room' . ($i % 50) . '/#',
            default => 'devices/' . $i . '/status',
        };
    }

    public function operations(): array
    {
        return [
            // Primitives.
            'length.decode' => fn (array $a, Session $s) => Packet::decodeLength($a['data'], $a['offset']),
            'length.encode' => fn (array $a, Session $s) => Packet::encodeLength($a['length']),
            'string.read' => fn (array $a, Session $s) => Packet::readString($a['data'], $a['offset']),
            'string.encode' => fn (array $a, Session $s) => Packet::encodeString($a['value']),
            'int16.read' => fn (array $a, Session $s) => Packet::readInt16($a['data'], $a['offset']),
            'byte.read' => fn (array $a, Session $s) => Packet::readByte($a['data'], $a['offset']),

            // Packets.
            'packet.parse' => function (array $a, Session $s) {
                $p = Packet::parse($a['data']);

                return ['type' => $p->type, 'flags' => $p->flags, 'body' => $p->body, 'name' => $p->name(), 'qos' => $p->qos(), 'dup' => $p->dup()];
            },
            'packet.new' => function (array $a, Session $s) {
                $p = new Packet($a['type'], $a['flags'], $a['body']);

                return ['type' => $p->type, 'flags' => $p->flags, 'body' => $p->body, 'name' => $p->name(), 'qos' => $p->qos(), 'dup' => $p->dup()];
            },
            'packet.clean_start' => fn (array $a, Session $s) => Packet::isCleanStart($a['body']),
            'packet.client_id' => fn (array $a, Session $s) => Packet::getClientId($a['body']),
            'packet.frames' => fn (array $a, Session $s) => Packet::frames($a['buffer']),
            'packet.ping' => fn (array $a, Session $s) => [Packet::pingreq(), Packet::pingresp()],
            'decode.all' => function (array $a, Session $s) {
                $data = self::data($a);
                $protocol = $a['protocol'];
                $out = ['length' => self::attempt(fn () => Packet::decodeLength($data, 1)), 'frames' => Packet::frames($data)];
                try {
                    $packet = Packet::parse($data);
                } catch (\Throwable $e) {
                    return $out + ['parse' => self::error($e)];
                }
                $body = $packet->body;

                return $out + [
                    'parse' => ['type' => $packet->type, 'flags' => $packet->flags, 'body' => $body, 'name' => $packet->name(), 'qos' => $packet->qos(), 'dup' => $packet->dup()],
                    'clean_start' => Packet::isCleanStart($body),
                    'client_id' => self::attempt(fn () => Packet::getClientId($body)),
                    'connect' => self::attempt(fn () => self::connect(Connect::decode($body))),
                    'auth' => self::attempt(fn () => self::auth(Auth::decode($body))),
                    'disconnect' => self::disconnect(Disconnect::decode($body)),
                    'publish' => self::attempt(function () use ($packet, $protocol) {
                        $p = Publish::decode($packet, $protocol);

                        return ['topic' => $p->topic, 'payload' => $p->payload, 'qos' => $p->qos, 'dup' => $p->dup, 'retain' => $p->retain, 'packet_id' => $p->packetId, 'properties' => self::properties($p->properties), 'user' => self::pairs($p->userProperties())];
                    }),
                    'puback' => (fn (Puback $p) => ['packet_id' => $p->packetId, 'reason_code' => $p->reasonCode])(Puback::decode($body)),
                    'subscribe' => self::attempt(function () use ($body, $protocol) {
                        $p = Subscribe::decode($body, $protocol);

                        return ['packet_id' => $p->packetId, 'filters' => array_map(fn (Filter $f) => [$f->topic, $f->qos], $p->filters()), 'properties' => self::properties($p->properties)];
                    }),
                    'unsubscribe' => self::attempt(function () use ($body, $protocol) {
                        $p = Unsubscribe::decode($body, $protocol);

                        return ['packet_id' => $p->packetId, 'filters' => $p->filters(), 'properties' => self::properties($p->properties)];
                    }),
                    'properties' => self::attempt(function () use ($body) {
                        [$properties, $offset] = Properties::parse($body, 0);

                        return ['properties' => self::properties($properties), 'offset' => $offset, 'user' => self::pairs($properties->user())];
                    }),
                    'skip' => self::attempt(fn () => Properties::skip($body, 0)),
                ];
            },

            // Properties.
            'property.wire_type' => fn (array $a, Session $s) => Property::wireType($a['id']),
            'property.encode' => fn (array $a, Session $s) => (new Property($a['id'], self::input($a['value'])))->encode(),
            'properties.encode' => fn (array $a, Session $s) => self::block($a['properties'])->encode(),
            'properties.parse' => function (array $a, Session $s) {
                [$properties, $offset] = Properties::parse($a['data'], $a['offset']);

                return ['properties' => self::properties($properties), 'offset' => $offset, 'user' => self::pairs($properties->user())];
            },
            'properties.get' => function (array $a, Session $s) {
                $properties = self::block($a['properties']);

                return array_map(fn (int $id) => self::value($properties->get($id)), $a['ids']);
            },
            'properties.skip' => fn (array $a, Session $s) => Properties::skip($a['data'], $a['offset']),

            // Decoded packets.
            'connect.decode' => fn (array $a, Session $s) => self::connect(Connect::decode($a['body'])),
            'auth.decode' => fn (array $a, Session $s) => self::auth(Auth::decode($a['body'])),
            'auth.new' => fn (array $a, Session $s) => self::auth(match ($a['kind']) {
                'success' => isset($a['method']) ? Auth::success($a['method']) : Auth::success(),
                'challenge' => Auth::challenge($a['method'], $a['data']),
                default => throw new Fault('unknown kind'),
            }),
            'connack.new' => function (array $a, Session $s) {
                $properties = isset($a['properties']) ? self::block($a['properties']) : null;
                $connack = match ($a['kind']) {
                    'accept' => Connack::accept($a['session_present'] ?? false, $properties),
                    'refuse' => Connack::refuse($a['reason_code'] ?? Connack::NOT_AUTHORIZED, $properties),
                    default => throw new Fault('unknown kind'),
                };

                return ['reason_code' => $connack->reasonCode, 'session_present' => $connack->sessionPresent, 'properties' => self::properties($connack->properties), 'accepted' => $connack->accepted()];
            },
            'disconnect.decode' => fn (array $a, Session $s) => self::disconnect(Disconnect::decode($a['body'])),
            'disconnect.new' => fn (array $a, Session $s) => self::disconnect(match ($a['kind']) {
                'refuse' => Disconnect::refuse($a['reason_code'] ?? Disconnect::NOT_AUTHORIZED, $a['reason'] ?? null),
                'normal' => Disconnect::normal($a['reason'] ?? null),
                default => throw new Fault('unknown kind'),
            }),
            'publish.decode' => function (array $a, Session $s) {
                $p = Publish::decode(Packet::parse($a['data']), $a['protocol']);

                return ['topic' => $p->topic, 'payload' => $p->payload, 'qos' => $p->qos, 'dup' => $p->dup, 'retain' => $p->retain, 'packet_id' => $p->packetId, 'properties' => self::properties($p->properties), 'user' => self::pairs($p->userProperties())];
            },
            'puback.decode' => function (array $a, Session $s) {
                $p = Puback::decode($a['body']);

                return ['packet_id' => $p->packetId, 'reason_code' => $p->reasonCode];
            },
            'subscribe.decode' => function (array $a, Session $s) {
                $p = Subscribe::decode($a['body'], $a['protocol']);

                return ['packet_id' => $p->packetId, 'filters' => array_map(fn (Filter $f) => [$f->topic, $f->qos], $p->filters()), 'properties' => self::properties($p->properties)];
            },
            'unsubscribe.decode' => function (array $a, Session $s) {
                $p = Unsubscribe::decode($a['body'], $a['protocol']);

                return ['packet_id' => $p->packetId, 'filters' => $p->filters(), 'properties' => self::properties($p->properties)];
            },
            'filter.new' => function (array $a, Session $s) {
                $f = new Filter($a['topic'], $a['qos']);

                return [$f->topic, $f->qos];
            },
            'will.new' => function (array $a, Session $s) {
                $w = new Packet\Will($a['topic'], $a['payload'], $a['qos'], $a['retain']);

                return [$w->topic, $w->payload, $w->qos, $w->retain];
            },
            'suback.build' => function (array $a, Session $s) {
                $suback = new Suback();
                foreach ($a['steps'] as $step) {
                    $returned = match ($step[0]) {
                        'grant' => $suback->grant($step[1]),
                        'deny' => isset($step[1]) ? $suback->deny($step[1]) : $suback->deny(),
                        default => throw new Fault('unknown step'),
                    };
                    if ($returned !== $suback) {
                        throw new Fault('not chainable');
                    }
                }

                return $suback->codes();
            },
            'unsuback.build' => function (array $a, Session $s) {
                $unsuback = new Unsuback();
                foreach ($a['steps'] as $step) {
                    $returned = match ($step[0]) {
                        'success' => $unsuback->success(),
                        'fail' => isset($step[1]) ? $unsuback->fail($step[1]) : $unsuback->fail(),
                        default => throw new Fault('unknown step'),
                    };
                    if ($returned !== $unsuback) {
                        throw new Fault('not chainable');
                    }
                }

                return $unsuback->codes();
            },

            // Encoders.
            'v3.connect' => fn (array $a, Session $s) => V3::connect($a['client_id'], $a['keep_alive'] ?? 60, $a['clean_session'] ?? true, $a['username'] ?? '', $a['password'] ?? ''),
            'v3.subscribe' => fn (array $a, Session $s) => V3::subscribe($a['packet_id'], $a['filters'], $a['qos'] ?? 0),
            'v3.unsubscribe' => fn (array $a, Session $s) => V3::unsubscribe($a['packet_id'], $a['filters']),
            'v3.connack' => fn (array $a, Session $s) => V3::connack($a['code']),
            'v3.publish' => fn (array $a, Session $s) => V3::publish($a['topic'], $a['payload'], $a['qos'], $a['packet_id'], $a['dup'] ?? false),
            'v3.puback' => fn (array $a, Session $s) => V3::puback($a['packet_id']),
            'v3.suback' => fn (array $a, Session $s) => V3::suback($a['packet_id'], $a['codes']),
            'v3.unsuback' => fn (array $a, Session $s) => V3::unsuback($a['packet_id']),
            'v3.disconnect' => fn (array $a, Session $s) => V3::disconnect(),
            'v5.connect' => fn (array $a, Session $s) => V5::connect($a['client_id'], $a['keep_alive'] ?? 60, $a['clean_start'] ?? true, self::optional($a)),
            'v5.subscribe' => fn (array $a, Session $s) => V5::subscribe($a['packet_id'], $a['filters'], $a['qos'] ?? 0, self::optional($a)),
            'v5.unsubscribe' => fn (array $a, Session $s) => V5::unsubscribe($a['packet_id'], $a['filters'], self::optional($a)),
            'v5.connack' => fn (array $a, Session $s) => V5::connack($a['code'], self::optional($a)),
            'v5.publish' => fn (array $a, Session $s) => V5::publish($a['topic'], $a['payload'], $a['qos'], $a['packet_id'], self::optional($a), $a['dup'] ?? false),
            'v5.puback' => fn (array $a, Session $s) => V5::puback($a['packet_id']),
            'v5.suback' => fn (array $a, Session $s) => V5::suback($a['packet_id'], $a['codes'], self::optional($a)),
            'v5.unsuback' => fn (array $a, Session $s) => V5::unsuback($a['packet_id'], $a['count'], self::optional($a)),
            'v5.disconnect' => fn (array $a, Session $s) => V5::disconnect($a['code'], self::optional($a)),
            'v5.auth' => fn (array $a, Session $s) => V5::auth($a['code'], self::optional($a)),

            // The subscription store.
            'store.new' => fn (array $a, Session $s) => $s->handle(new Store()),
            'store.subscribe' => fn (array $a, Session $s) => self::store($a, $s)->subscribe($a['prefix'], $a['user'], $a['topic'], $a['fd'], $a['qos'] ?? 1),
            'store.unsubscribe' => fn (array $a, Session $s) => self::store($a, $s)->unsubscribe($a['topic'], $a['fd']),
            'store.close' => fn (array $a, Session $s) => self::store($a, $s)->close($a['fd']),
            'store.connection' => fn (array $a, Session $s) => self::record(self::store($a, $s)->getConnection($a['fd'])),
            'store.subscribers' => fn (array $a, Session $s) => self::fds(self::store($a, $s)->getSubscribers($a['prefix'], $a['topic'])),
            'store.script' => function (array $a, Session $s) {
                $store = new Store();
                $out = [];
                foreach ($a['steps'] as $step) {
                    $out[] = match ($step['op']) {
                        'subscribe' => $store->subscribe($step['prefix'], $step['user'], $step['topic'], $step['fd'], $step['qos']),
                        'unsubscribe' => $store->unsubscribe($step['topic'], $step['fd']),
                        'close' => $store->close($step['fd']),
                        'connection' => self::record($store->getConnection($step['fd'])),
                        'subscribers' => self::fds($store->getSubscribers($step['prefix'], $step['topic'])),
                        default => throw new Fault('unknown step'),
                    };
                }

                return $out;
            },

            // Connections.
            'connection.new' => fn (array $a, Session $s) => $s->handle(isset($a['rig']) ? new Connection($a['fd'], self::rig($a, $s)->adapter) : new Connection($a['fd'])),
            'connection.set' => function (array $a, Session $s) {
                $c = self::connection($a, $s);
                foreach (['protocol' => 'protocol', 'prefix' => 'prefix', 'clean_start' => 'cleanStart', 'identity' => 'identity', 'active' => 'active', 'keep_alive' => 'keepAlive', 'expires_at' => 'expiresAt', 'wheel_slot' => 'wheelSlot'] as $key => $property) {
                    if (\array_key_exists($key, $a)) {
                        $c->{$property} = $key === 'identity' ? (array) $a[$key] : $a[$key];
                    }
                }

                return;
            },
            'connection.state' => function (array $a, Session $s) {
                $c = self::connection($a, $s);

                return ['fd' => $c->fd, 'protocol' => $c->protocol, 'prefix' => $c->prefix, 'client_id' => $c->getClientId(), 'clean_start' => $c->cleanStart, 'identity' => $c->identity === [] ? null : $c->identity, 'active' => $c->active, 'keep_alive' => $c->keepAlive, 'expires_at' => $c->expiresAt, 'wheel_slot' => $c->wheelSlot, 'opened' => $c->openedAt > 0];
            },
            'connection.publish' => fn (array $a, Session $s) => self::connection($a, $s)->publish($a['topic'], $a['payload'], $a['qos'] ?? 0, $a['dup'] ?? false, $a['sequence'] ?? null),
            'connection.puback' => fn (array $a, Session $s) => self::connection($a, $s)->puback($a['packet_id']),
            'connection.disconnect' => fn (array $a, Session $s) => self::connection($a, $s)->disconnect($a['reason'] ?? 0, $a['reason_string'] ?? null),
            'connection.update_expires_at' => fn (array $a, Session $s) => self::connection($a, $s)->updateExpiresAt($a['now'], $a['multiplier']),
            'connection.next_packet_id' => fn (array $a, Session $s) => self::connection($a, $s)->nextPacketId(),
            'connection.resume' => fn (array $a, Session $s) => self::connection($a, $s)->resume($a['topic'], $a['cursor']),
            'connection.track' => fn (array $a, Session $s) => self::connection($a, $s)->track($a['packet_id'], $a['topic'], $a['sequence']),
            'connection.acknowledge' => fn (array $a, Session $s) => self::connection($a, $s)->acknowledge($a['packet_id']),
            'connection.set_client_id' => fn (array $a, Session $s) => self::connection($a, $s)->setClientId($a['client_id']),
            'connection.client_id' => fn (array $a, Session $s) => self::connection($a, $s)->getClientId(),
            'connection.script' => function (array $a, Session $s) {
                $c = new Connection(1);
                $out = [];
                foreach ($a['steps'] as $step) {
                    $out[] = match ($step['op']) {
                        'track' => $c->track($step['packet_id'], $step['topic'], $step['sequence']),
                        'resume' => $c->resume($step['topic'], $step['cursor']),
                        'acknowledge' => $c->acknowledge($step['packet_id']),
                        'next' => $c->nextPacketId(),
                        default => throw new Fault('unknown step'),
                    };
                }

                return $out;
            },

            // The in-process adapter and the broker on it.
            'memory.new' => function (array $a, Session $s) {
                $timer = ($a['timer'] ?? 'timeline') === 'timeline' ? new Timeline(new \ArrayObject()) : null;

                return $s->handle(new Rig(new Memory($timer ?? new NoTimer()), $timer));
            },
            'memory.fail' => fn (array $a, Session $s) => self::rig($a, $s)->adapter->fail($a['operation'], $a['message']),
            'memory.open' => fn (array $a, Session $s) => self::rig($a, $s)->adapter->open($a['fd']),
            'memory.receive' => fn (array $a, Session $s) => self::rig($a, $s)->adapter->receive($a['fd'], $a['data']),
            'memory.disconnect' => fn (array $a, Session $s) => self::rig($a, $s)->adapter->disconnect($a['fd']),
            'memory.tick' => fn (array $a, Session $s) => self::rig($a, $s)->timer?->tick($a['id']),
            'memory.state' => fn (array $a, Session $s) => self::state(self::rig($a, $s)),
            'server.new' => function (array $a, Session $s) {
                $rig = self::rig($a, $s);
                $rig->server = new Server($rig->adapter, new Recorder($rig->log));

                return;
            },
            'server.telemetry' => function (array $a, Session $s) {
                $rig = self::rig($a, $s);
                $rig->telemetry = new Metrics();

                return $rig->server->setTelemetry($rig->telemetry) === $rig->server;
            },
            'server.on' => function (array $a, Session $s) {
                $rig = self::rig($a, $s);
                $log = $rig->log;
                $server = $rig->server;

                return match ($a['hook']) {
                    'start' => $server->onStart(function () use ($log): void {
                        $log[] = ['start'];
                    }),
                    'worker_start' => $server->onWorkerStart(function (int $worker) use ($log): void {
                        $log[] = ['workerStart', $worker];
                    }),
                    'stop' => $server->onStop(function () use ($log): void {
                        $log[] = ['stop'];
                    }),
                    default => throw new Fault('unknown hook'),
                } === $server;
            },
            'server.error' => function (array $a, Session $s) {
                $rig = self::rig($a, $s);
                $log = $rig->log;

                return $rig->server->error(function (\Throwable $error, string $stage) use ($log): void {
                    $log[] = ['error', $error::class, $error->getMessage(), $stage];
                }) === $rig->server;
            },
            'server.start' => fn (array $a, Session $s) => self::rig($a, $s)->server->start(),
            'server.stop' => fn (array $a, Session $s) => self::rig($a, $s)->server->stop(),
            'server.subscribers' => function (array $a, Session $s) {
                $out = [];
                foreach (self::rig($a, $s)->server->subscribers($a['prefix'], $a['topic']) as $fd => [$connection, $qos]) {
                    $out[] = [$fd, $connection->fd, $qos];
                }

                return $out;
            },
            'server.log' => fn (array $a, Session $s) => self::rig($a, $s)->log->getArrayCopy(),
            'server.metrics' => fn (array $a, Session $s) => self::metrics(self::rig($a, $s)->telemetry),
            'server.session' => function (array $a, Session $s) {
                $timer = new Timeline(new \ArrayObject());
                $rig = new Rig(new Memory($timer), $timer);
                $rig->server = new Server($rig->adapter, new Recorder($rig->log));
                $rig->telemetry = new Metrics();
                $rig->server->setTelemetry($rig->telemetry);
                $log = $rig->log;
                $rig->server->error(function (\Throwable $error, string $stage) use ($log): void {
                    $log[] = ['error', $error::class, $error->getMessage(), $stage];
                });
                $rig->server->start();
                foreach ($a['open'] ?? [1, 2] as $fd) {
                    $rig->adapter->open($fd);
                }
                $outcomes = [];
                foreach ($a['packets'] as $packet) {
                    [$fd, $data] = isset($packet['fd']) ? [$packet['fd'], self::data($packet)] : $packet;
                    try {
                        $rig->adapter->receive($fd, $data);
                        $outcomes[] = null;
                    } catch (\Throwable $e) {
                        $outcomes[] = [$e::class, $e->getMessage()];
                    }
                }

                return ['outcomes' => $outcomes, 'state' => self::state($rig), 'metrics' => self::metrics($rig->telemetry)];
            },

            // Timers.
            'wheel.new' => function (array $a, Session $s) {
                $wheel = isset($a['now_offset'])
                    ? new TimingWheel($a['interval'] ?? 20, $a['multiplier'] ?? 1.5, time() + $a['now_offset'])
                    : new TimingWheel($a['interval'] ?? 20, $a['multiplier'] ?? 1.5);
                $w = new Wheel($wheel);
                $log = $w->log;
                $chained = [
                    $wheel->onTick(function (int $id) use ($log): void {
                        $log[] = ['tick', $id];
                    }) === $wheel,
                    $wheel->onClear(function (int $id) use ($log): void {
                        $log[] = ['clear', $id];
                    }) === $wheel,
                ];

                return ['wheel' => $s->handle($w), 'interval' => $wheel->interval, 'multiplier' => $wheel->multiplier, 'chained' => $chained];
            },
            'wheel.schedule' => function (array $a, Session $s) {
                self::wheel($a, $s)->wheel->schedule($a['id'], $a['keep_alive']);
                // The heartbeat is a Swoole timer: never let it outlive the call.
                \Swoole\Timer::clearAll();

                return;
            },
            'wheel.clear' => fn (array $a, Session $s) => self::wheel($a, $s)->wheel->clear($a['id']),
            'wheel.drain' => fn (array $a, Session $s) => self::wheel($a, $s)->wheel->drain(time() + $a['offset']),
            'wheel.log' => fn (array $a, Session $s) => self::wheel($a, $s)->log->getArrayCopy(),
            'notimer.run' => function (array $a, Session $s) {
                $timer = new NoTimer();
                $chained = [$timer->onTick(fn () => null) === $timer, $timer->onClear(fn () => null) === $timer];
                $timer->schedule(1, 10);
                $timer->clear(1);

                return $chained;
            },

            // The Swoole adapter's transports and construction.
            'transport.new' => function (array $a, Session $s) {
                $host = $a['host'] ?? null;
                $t = match ($a['kind']) {
                    'tcp' => new Tcp(...self::named($a, ['host', 'port', 'max_packet_size' => 'maxPacketSize'])),
                    'tls' => new Tls(...self::named($a, ['host', 'port', 'cert', 'key', 'max_packet_size' => 'maxPacketSize'])),
                    'websocket' => new WebSocket(...self::named($a, ['host', 'port', 'max_packet_size' => 'maxPacketSize'])),
                    default => throw new Fault('unknown transport'),
                };
                $settings = $t->getSettings();

                return [
                    'host' => $t->host,
                    'port' => $t->port,
                    'tls' => ($t->getSockType() & SWOOLE_SSL) === SWOOLE_SSL,
                    'websocket' => $settings['open_websocket_protocol'] ?? false,
                    'max_packet_size' => $settings['package_max_length'],
                    'cert' => $settings['ssl_cert_file'] ?? null,
                    'key' => $settings['ssl_key_file'] ?? null,
                ];
            },
            'swoole.new' => function (array $a, Session $s) {
                if ($a['transports'] !== []) {
                    throw new Fault('swoole.new only constructs without transports (a Swoole server binds when built)');
                }
                new Swoole([]);

                return;
            },

            // The client.
            'client.new' => function (array $a, Session $s) {
                $client = isset($a['timeout']) ? new Client($a['url'], ['timeout' => $a['timeout']]) : new Client($a['url']);
                $read = fn (string $name) => (new \ReflectionProperty(Client::class, $name))->getValue($client);

                // The timeout is a private int (the default 30) or float: compared as seconds.
                return ['client' => $s->handle($client), 'host' => $read('host'), 'port' => $read('port'), 'tls' => $read('ssl'), 'timeout' => (float) $read('timeout')];
            },
            'client.hooks' => function (array $a, Session $s) {
                $client = self::client($a, $s);
                $noop = fn (): null => null;

                return $client->onOpen($noop)->onReceive($noop)->onClose($noop)->onError($noop) === $client;
            },
            'client.send' => fn (array $a, Session $s) => self::client($a, $s)->send($a['data']),
            'client.receive' => fn (array $a, Session $s) => self::client($a, $s)->receive(),
            'client.is_connected' => fn (array $a, Session $s) => self::client($a, $s)->isConnected(),
            'client.close' => fn (array $a, Session $s) => self::client($a, $s)->close(),
            'client.connect' => function (array $a, Session $s) {
                $client = self::client($a, $s);
                $log = [];
                $client->onError(function (\Throwable $e) use (&$log): void {
                    $log[] = ['error', $e::class, $e->getMessage()];
                });
                $client->onOpen(function () use (&$log): void {
                    $log[] = ['open'];
                });
                $error = null;
                run(function () use ($client, &$error): void {
                    try {
                        $client->connect();
                    } catch (\Throwable $e) {
                        $error = [$e::class, $e->getMessage()];
                    }
                });

                return ['error' => $error, 'log' => $log, 'connected' => $client->isConnected()];
            },

            // Brokers on the network.
            'e2e.run' => fn (array $a, Session $s) => self::e2e($a['mode'] ?? 'fixture', $a['steps']),
            'broker.start' => fn (array $a, Session $s) => ['runtime' => 'php', 'tcp' => null, 'ws' => null, 'tls' => null, 'broker' => null],
            'broker.stop' => fn (array $a, Session $s) => null,
            'client.session' => function (array $a, Session $s) {
                $broker = $a['broker'];
                $endpoints = $broker['runtime'] === 'php'
                    ? ['tcp' => $s->service('mqtt-tcp'), 'ws' => $s->service('mqtt-ws'), 'tls' => $s->service('mqtt-tls')]
                    : array_map(fn (int $port) => $s->service('mqtt-host') . ':' . $port, ['tcp' => $broker['tcp'], 'ws' => $broker['ws'], 'tls' => $broker['tls']]);

                return self::session($endpoints, $a['steps']);
            },
        ];
    }

    /**
     * The bytes a fuzz input describes: `data`, or `base` with `edits` (each
     * {op: set|insert|delete, at, byte}, positions wrapped to the length)
     * applied in order, then cut to `cut` bytes.
     */
    private static function data(array $a): string
    {
        if (isset($a['data'])) {
            return $a['data'];
        }
        $data = $a['base'];
        foreach ($a['edits'] ?? [] as $edit) {
            $length = \strlen($data);
            switch ($edit['op']) {
                case 'set':
                    if ($length > 0) {
                        $data[$edit['at'] % $length] = \chr($edit['byte']);
                    }
                    break;
                case 'insert':
                    $at = $edit['at'] % ($length + 1);
                    $data = substr($data, 0, $at) . \chr($edit['byte']) . substr($data, $at);
                    break;
                case 'delete':
                    if ($length > 0) {
                        $at = $edit['at'] % $length;
                        $data = substr($data, 0, $at) . substr($data, $at + 1);
                    }
                    break;
            }
        }

        return isset($a['cut']) ? substr($data, 0, $a['cut']) : $data;
    }

    private static function error(\Throwable $e): array
    {
        return ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
    }

    /** The callable's result, or its exception as {$error: {class, message}}. */
    private static function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    /** The named constructor arguments present in $a. */
    private static function named(array $a, array $names): array
    {
        $out = [];
        foreach ($names as $key => $name) {
            $key = \is_int($key) ? $name : $key;
            if (\array_key_exists($key, $a)) {
                $out[$name] = $a[$key];
            }
        }

        return $out;
    }

    /** A map as [key, value] pairs, keys as strings. */
    private static function pairs(array $map): array
    {
        return Recorder::pairs($map);
    }

    /** A property value: a User Property's array as {pair: [[k, v], ...]}. */
    private static function value(mixed $value): mixed
    {
        return \is_array($value) ? ['pair' => self::pairs($value)] : $value;
    }

    /** A property value from JSON: {pair: [[k, v], ...]} is an array. */
    private static function input(mixed $value): mixed
    {
        if (\is_array($value) && \array_key_exists('pair', $value)) {
            $map = [];
            foreach ($value['pair'] as [$k, $v]) {
                $map[$k] = $v;
            }

            return $map;
        }

        return $value;
    }

    /** @return list<array{0: int, 1: mixed}>|null */
    private static function properties(?Properties $properties): ?array
    {
        return $properties === null ? null : array_map(fn (Property $p) => [$p->id, self::value($p->value)], $properties->all());
    }

    private static function block(array $spec): Properties
    {
        $properties = new Properties();
        foreach ($spec as $property) {
            if ($properties->add(new Property($property['id'], self::input($property['value']))) !== $properties) {
                throw new Fault('not chainable');
            }
        }

        return $properties;
    }

    private static function optional(array $a): ?Properties
    {
        return isset($a['properties']) ? self::block($a['properties']) : null;
    }

    private static function connect(Connect $c): array
    {
        return [
            'protocol_level' => $c->protocolLevel,
            'client_id' => $c->clientId,
            'clean_start' => $c->cleanStart,
            'keep_alive' => $c->keepAlive,
            'username' => $c->username,
            'password' => $c->password,
            'will' => $c->will === null ? null : [$c->will->topic, $c->will->payload, $c->will->qos, $c->will->retain],
            'auth_method' => $c->authMethod,
            'auth_data' => $c->authData,
            'properties' => self::properties($c->properties),
            'user' => self::pairs($c->userProperties()),
        ];
    }

    private static function auth(Auth $a): array
    {
        return ['reason_code' => $a->reasonCode, 'method' => $a->method, 'data' => $a->data, 'properties' => self::properties($a->properties), 'user' => self::pairs($a->userProperties())];
    }

    private static function disconnect(Disconnect $d): array
    {
        return ['reason_code' => $d->reasonCode, 'properties' => self::properties($d->properties)];
    }

    private static function record(?array $record): ?array
    {
        return $record === null ? null : ['prefix' => $record['prefix'], 'user_id' => $record['userId'], 'subs' => self::pairs($record['subs'])];
    }

    /** @param array<int, int> $fds */
    private static function fds(array $fds): array
    {
        $out = [];
        foreach ($fds as $fd => $qos) {
            $out[] = [$fd, $qos];
        }

        return $out;
    }

    private static function state(Rig $rig): array
    {
        return [
            'started' => $rig->adapter->started,
            'open' => array_keys($rig->adapter->open),
            'sent' => $rig->adapter->sent,
            'closed' => $rig->adapter->closed,
            'timer' => $rig->timeline->getArrayCopy(),
            'log' => $rig->log->getArrayCopy(),
        ];
    }

    private static function metrics(?Metrics $telemetry): array
    {
        if ($telemetry === null) {
            return [];
        }
        $out = [];
        foreach ($telemetry->counters as $name => $values) {
            $out[] = ['counter', $name, $values];
        }
        foreach ($telemetry->upDownCounters as $name => $values) {
            $out[] = ['up_down_counter', $name, $values];
        }

        return $out;
    }

    private static function store(array $a, Session $s): Store
    {
        $store = $s->get($a['store']);

        return $store instanceof Store ? $store : throw new Fault('not a store');
    }

    private static function connection(array $a, Session $s): Connection
    {
        $c = $s->get($a['connection']);

        return $c instanceof Connection ? $c : throw new Fault('not a connection');
    }

    private static function rig(array $a, Session $s): Rig
    {
        $rig = $s->get($a['rig']);

        return $rig instanceof Rig ? $rig : throw new Fault('not a rig');
    }

    private static function wheel(array $a, Session $s): Wheel
    {
        $w = $s->get($a['wheel']);

        return $w instanceof Wheel ? $w : throw new Fault('not a wheel');
    }

    private static function client(array $a, Session $s): Client
    {
        $c = $s->get($a['client']);

        return $c instanceof Client ? $c : throw new Fault('not a client');
    }

    private static function port(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new Fault('no free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Starts server.php on the Swoole adapter in its own process (`fixture`:
     * the E2E fixture; `broker`: a Server with the Recorder handler), runs
     * the steps against it and stops it.
     *
     * @param list<array<string, mixed>> $steps
     * @return list<mixed>
     */
    private static function e2e(string $mode, array $steps): array
    {
        $ports = ['tcp' => self::port(), 'ws' => self::port(), 'tls' => self::port()];
        $args = [PHP_BINARY, 'tests/compat/mqtt/server.php', $mode, '127.0.0.1', (string) $ports['tcp'], (string) $ports['ws']];
        if (is_file(self::CERTS . '/server.pem')) {
            array_push($args, (string) $ports['tls'], self::CERTS . '/server.pem', self::CERTS . '/server.key');
        }
        $process = proc_open($args, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/usr/src/code');
        if (!\is_resource($process)) {
            throw new Fault('cannot start the fixture broker');
        }
        try {
            foreach (\count($args) > 6 ? $ports : ['tcp' => $ports['tcp'], 'ws' => $ports['ws']] as $port) {
                $ready = false;
                for ($i = 0; $i < 100 && !$ready; $i++) {
                    $socket = @fsockopen('127.0.0.1', $port);
                    if ($socket !== false) {
                        fclose($socket);
                        $ready = true;
                    } else {
                        usleep(50_000);
                    }
                }
                if (!$ready) {
                    throw new Fault('the fixture broker did not start');
                }
            }

            return self::session(array_map(fn (int $port) => '127.0.0.1:' . $port, $ports), $steps);
        } finally {
            proc_terminate($process, 15);
            for ($i = 0; $i < 40 && proc_get_status($process)['running']; $i++) {
                usleep(50_000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }

    /**
     * Runs steps against a broker with clients: `connect` (a Client on the
     * tcp or tls endpoint, with hooks recording into the client's log),
     * `send`, `receive`, `connected`, `close`, `listen`, `log`, and the
     * WebSocket steps `ws_connect`, `ws_send`, `ws_receive`, `ws_close`.
     *
     * @param array<string, string> $endpoints
     * @param list<array<string, mixed>> $steps
     * @return list<mixed>
     */
    private static function session(array $endpoints, array $steps): array
    {
        $out = [];
        run(function () use ($endpoints, $steps, &$out): void {
            /** @var array<string, array{0: Client, 1: \ArrayObject<int, mixed>}> $clients */
            $clients = [];
            /** @var array<string, array{0: HttpClient, 1: string}> $sockets */
            $sockets = [];
            foreach ($steps as $step) {
                try {
                    $out[] = self::step($step, $endpoints, $clients, $sockets);
                } catch (\Throwable $e) {
                    $out[] = ['error' => $e::class, 'message' => $e->getMessage()];
                }
            }
            foreach ($clients as [$client]) {
                $client->close();
            }
            foreach ($sockets as [$socket]) {
                $socket->close();
            }
        });

        return $out;
    }

    private static function step(array $step, array $endpoints, array &$clients, array &$sockets): mixed
    {
        $kind = (string) array_key_first($step);
        $name = $step[$kind];

        return match ($kind) {
            'connect' => (function () use ($step, $name, $endpoints, &$clients) {
                $tls = ($step['transport'] ?? 'tcp') === 'tls';
                $endpoint = $endpoints[$tls ? 'tls' : 'tcp'];
                $client = new Client(($tls ? 'mqtts://' : 'mqtt://') . $endpoint, ['timeout' => $step['timeout'] ?? 5]);
                /** @var \ArrayObject<int, list<mixed>> $log */
                $log = new \ArrayObject();
                $client->onOpen(function () use ($log): void {
                    $log[] = ['open'];
                })->onReceive(function ($data) use ($log): void {
                    $log[] = ['receive', $data];
                })->onClose(function () use ($log): void {
                    $log[] = ['close'];
                })->onError(function (\Throwable $e) use ($log): void {
                    $log[] = ['error', $e::class];
                });
                $clients[$name] = [$client, $log];
                $client->connect();

                return;
            })(),
            'send' => $clients[$name][0]->send($step['data']),
            'receive' => $clients[$name][0]->receive(),
            'connected' => $clients[$name][0]->isConnected(),
            'close' => $clients[$name][0]->close(),
            'listen' => $clients[$name][0]->listen(),
            'log' => $clients[$name][1]->getArrayCopy(),
            'ws_connect' => (function () use ($step, $name, $endpoints, &$sockets) {
                [$host, $port] = explode(':', $endpoints['ws']);
                $socket = new HttpClient($host, (int) $port);
                $socket->set(['timeout' => 5]);
                if (isset($step['protocol'])) {
                    $socket->setHeaders(['Sec-WebSocket-Protocol' => $step['protocol']]);
                }
                $upgraded = $socket->upgrade('/mqtt');
                $sockets[$name] = [$socket, ''];

                return ['upgraded' => $upgraded, 'protocol' => $socket->headers['sec-websocket-protocol'] ?? null];
            })(),
            'ws_send' => $sockets[$name][0]->push($step['data'], WEBSOCKET_OPCODE_BINARY),
            'ws_receive' => (function () use ($name, &$sockets) {
                while (true) {
                    [$packets, $rest] = Packet::frames($sockets[$name][1]);
                    if ($packets !== []) {
                        $sockets[$name][1] = substr($sockets[$name][1], \strlen($packets[0]));

                        return $packets[0];
                    }
                    $frame = $sockets[$name][0]->recv(5);
                    if (!$frame instanceof Frame) {
                        return;
                    }
                    $sockets[$name][1] .= (string) $frame->data;
                }
            })(),
            'ws_close' => (function () use ($name, &$sockets) {
                $sockets[$name][0]->close();
                unset($sockets[$name]);

                return;
            })(),
            'sleep' => (function () use ($name) {
                Coroutine::sleep($name / 1000);

                return;
            })(),
            default => throw new Fault("unknown step {$kind}"),
        };
    }
}
