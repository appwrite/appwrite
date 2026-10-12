<?php

namespace Tests\Compat\Nats;

require_once __DIR__ . '/Fake.php';
require_once __DIR__ . '/Errors.php';

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\NATS\Auth\CredentialsAuth;
use Utopia\NATS\Auth\NKeyAuth;
use Utopia\NATS\Auth\NoAuth;
use Utopia\NATS\Auth\TokenAuth;
use Utopia\NATS\Auth\UserPassAuth;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Headers;
use Utopia\NATS\Inbox;
use Utopia\NATS\JetStream\AccountInfo;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ApiError;
use Utopia\NATS\JetStream\Consumer;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\ConsumerInfo;
use Utopia\NATS\JetStream\ConsumerLimits;
use Utopia\NATS\JetStream\DeliverPolicy;
use Utopia\NATS\JetStream\DiscardPolicy;
use Utopia\NATS\JetStream\ExternalStream;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\JetStreamMessage;
use Utopia\NATS\JetStream\MessageBatch;
use Utopia\NATS\JetStream\MsgMetadata;
use Utopia\NATS\JetStream\OrderedConsumer;
use Utopia\NATS\JetStream\Placement;
use Utopia\NATS\JetStream\PubAck;
use Utopia\NATS\JetStream\PushSubscription;
use Utopia\NATS\JetStream\ReplayPolicy;
use Utopia\NATS\JetStream\Republish;
use Utopia\NATS\JetStream\RetentionPolicy;
use Utopia\NATS\JetStream\SequenceInfo;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\Stream;
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\JetStream\StreamInfo;
use Utopia\NATS\JetStream\StreamMessage;
use Utopia\NATS\JetStream\StreamSource;
use Utopia\NATS\JetStream\StreamState;
use Utopia\NATS\JetStream\SubjectTransform;
use Utopia\NATS\KeyValue\KeyValue;
use Utopia\NATS\KeyValue\KeyValueConfig;
use Utopia\NATS\KeyValue\KeyValueEntry;
use Utopia\NATS\KeyValue\KeyValueWatchOptions;
use Utopia\NATS\Message;
use Utopia\NATS\ObjectStore\ObjectLink;
use Utopia\NATS\ObjectStore\ObjectMeta;
use Utopia\NATS\ObjectStore\ObjectStore;
use Utopia\NATS\ObjectStore\ObjectStoreConfig;
use Utopia\NATS\Protocol\Parser;
use Utopia\NATS\Protocol\ServerOp;
use Utopia\NATS\Protocol\Writer;
use Utopia\NATS\Request;
use Utopia\NATS\ServerInfo;
use Utopia\NATS\Services\Endpoint;
use Utopia\NATS\Services\Service;
use Utopia\NATS\Services\ServiceException;
use Utopia\NATS\Subscription;
use Utopia\NATS\Transport\TcpTransport;
use Utopia\NATS\Transport\TlsTransport;
use Utopia\NATS\Transport\Transport;
use Utopia\NATS\Transport\WebSocketTransport;

/**
 * A connection with what its callbacks recorded, and its fake transport.
 */
final class Conn
{
    /** @var \ArrayObject<int, mixed> */
    public \ArrayObject $log;

    public Connection $connection;

    public function __construct(public ?Fake $fake = null)
    {
        $this->log = new \ArrayObject();
    }
}

/**
 * A subscription built on its own, with what its callbacks recorded.
 */
final class Sub
{
    /** @var \ArrayObject<int, mixed> */
    public \ArrayObject $log;

    public Subscription $subscription;

    public function __construct()
    {
        $this->log = new \ArrayObject();
    }
}

/**
 * Maps tests/compat/nats/spec.json operations onto utopia-php/nats: pure
 * values, connections on the fake transport (Fake.php), and connections to
 * the compat NATS servers (services.sh). Glue only.
 */
final class Adapter implements Base, Benchmarks
{
    /** The benchmark inputs, the same as in crates/tools/compat/src/libs/nats.rs. */
    private const string REPLY = '$JS.ACK.ORDERS.worker.1.42.17.1700000000000000000.3';

    public function benchmarks(): array
    {
        return [
            'parse_msg' => ['Parse a MSG frame with a reply subject (in-memory transport)', function (Session $s) {
                $fake = new Fake();
                $parser = new Parser($fake);
                $frame = "MSG orders.created 7 _INBOX.abc 64\r\n" . str_repeat('x', 64) . "\r\n";

                return function () use ($fake, $parser, $frame) {
                    $fake->push($frame);

                    return $parser->next(1.0);
                };
            }],
            'parse_hmsg' => ['Parse an HMSG frame with two headers (in-memory transport)', function (Session $s) {
                $fake = new Fake();
                $parser = new Parser($fake);
                $block = "NATS/1.0\r\nNats-Msg-Id: 42\r\nX-Trace: abc\r\n\r\n";
                $frame = 'HMSG orders.created 7 ' . \strlen($block) . ' ' . (\strlen($block) + 5) . "\r\n{$block}hello\r\n";

                return function () use ($fake, $parser, $frame) {
                    $fake->push($frame);

                    return $parser->next(1.0);
                };
            }],
            'headers' => ['Encode three headers and parse them back', fn (Session $s) => function () {
                $headers = (new Headers())->set('Nats-Msg-Id', 'order-1')->set('Nats-Expected-Stream', 'ORDERS')->add('X-Tag', 'a');

                return Headers::fromWire($headers->toWire());
            }],
            'publish' => ['Publish a 128-byte message with a reply subject (in-memory transport)', function (Session $s) {
                $fake = new Fake();
                $conn = Connection::connect(new ConnectionOptions(transportFactory: fn () => $fake));
                $payload = str_repeat('p', 128);

                return function () use ($conn, $fake, $payload) {
                    $conn->publish('orders.created', $payload, '_INBOX.reply');
                    $fake->written = '';
                    $fake->writes = [];
                };
            }],
            'request' => ['A request and its reply (in-memory transport)', function (Session $s) {
                $fake = new Fake();
                $fake->rules = [['when' => ['prefix' => 'PUB '], 'do' => [['reply' => ['subject' => 'work', 'payload' => 'ok']]]]];
                $conn = Connection::connect(new ConnectionOptions(transportFactory: fn () => $fake));

                return function () use ($conn, $fake) {
                    $reply = $conn->request('work');
                    $fake->written = '';
                    $fake->writes = [];

                    return $reply;
                };
            }],
            'metadata' => ['Parse a JetStream reply subject into message metadata', fn (Session $s) => fn () => MsgMetadata::fromReplySubject(self::REPLY)],
            'server_request' => ['A request answered by a subscriber of the same connection, on the NATS server', function (Session $s) {
                $conn = Connection::connect(new ConnectionOptions(servers: $s->service('nats')));
                $subject = 'bench.' . bin2hex(random_bytes(6));
                $conn->subscribe($subject, function (Message $m) use ($conn): void {
                    $conn->publish((string) $m->replyTo, 'pong');
                });

                return fn () => $conn->request($subject, 'ping');
            }],
        ];
    }

    public function operations(): array
    {
        return [
            // --- Values ---
            'headers.build' => fn (array $a, Session $s) => self::headers(self::buildHeaders($a['steps'] ?? []), $a['query'] ?? []),
            'headers.from_wire' => fn (array $a, Session $s) => self::headers(Headers::fromWire($a['raw']), $a['query'] ?? []),
            'inbox.create' => function (array $a, Session $s) {
                $inbox = isset($a['prefix']) ? Inbox::create($a['prefix']) : Inbox::create();

                return self::inbox($inbox) + ['unique' => $inbox !== (isset($a['prefix']) ? Inbox::create($a['prefix']) : Inbox::create())];
            },
            'inbox.generate_id' => function (array $a, Session $s) {
                $id = Inbox::generateId();

                return ['length' => \strlen($id), 'alnum' => ctype_alnum($id), 'unique' => $id !== Inbox::generateId()];
            },
            'request.new' => function (array $a, Session $s) {
                $request = new Request($a['subject'], $a['data'] ?? '', isset($a['headers']) ? self::buildHeaders($a['headers']) : null);

                return ['subject' => $request->subject, 'data' => $request->data, 'headers' => self::headers($request->headers)];
            },
            'writer.connect' => fn (array $a, Session $s) => (new Writer())->connect($a['options']),
            'writer.pub' => fn (array $a, Session $s) => (new Writer())->pub($a['subject'], $a['payload'], $a['reply'] ?? null),
            'writer.hpub' => fn (array $a, Session $s) => (new Writer())->hpub($a['subject'], $a['headers'], $a['payload'], $a['reply'] ?? null),
            'writer.sub' => fn (array $a, Session $s) => (new Writer())->sub($a['subject'], $a['sid'], $a['queue'] ?? null),
            'writer.unsub' => fn (array $a, Session $s) => (new Writer())->unsub($a['sid'], $a['max'] ?? null),
            'writer.ping' => fn (array $a, Session $s) => (new Writer())->ping(),
            'writer.pong' => fn (array $a, Session $s) => (new Writer())->pong(),
            'parser.run' => fn (array $a, Session $s) => self::parse($a),
            'server_info.from_array' => fn (array $a, Session $s) => self::serverInfo(ServerInfo::fromArray(self::plain($a['data']))),
            'connection.closes_connection' => fn (array $a, Session $s) => Connection::closesConnection($a['message']),
            'connection.reconnects_after' => fn (array $a, Session $s) => Connection::reconnectsAfter($a['message']),
            'connection.map_server_error' => function (array $a, Session $s) {
                $error = Connection::mapServerError($a['message']);

                return [$error::class, $error->getMessage()];
            },
            'connection.reconnect_backoff' => fn (array $a, Session $s) => isset($a['factor'])
                ? Connection::reconnectBackoff($a['attempt'], (float) $a['base'], (float) $a['cap'], (float) $a['factor'])
                : Connection::reconnectBackoff($a['attempt'], (float) $a['base'], (float) $a['cap']),
            'connection.reconnect_buffer_accepts' => fn (array $a, Session $s) => Connection::reconnectBufferAccepts($a['current'], $a['incoming'], $a['cap']),
            'options.defaults' => fn (array $a, Session $s) => self::optionsDump(isset($a['servers']) ? new ConnectionOptions(servers: $a['servers']) : new ConnectionOptions()),
            'tls_transport.options' => function (array $a, Session $s) {
                $transport = new TlsTransport(self::plain($a['options'] ?? []));

                return self::sorted((new \ReflectionMethod(TlsTransport::class, 'buildSslOptions'))->invoke($transport));
            },
            'auth.fields' => fn (array $a, Session $s) => self::authenticate($a, $s),
            'nanos.from_seconds' => fn (array $a, Session $s) => StreamConfig::secondsToNanos((float) $a['seconds']),
            'nanos.to_seconds' => fn (array $a, Session $s) => StreamConfig::nanosToSeconds($a['nanos']),
            'config.new' => fn (array $a, Session $s) => self::configNew($a['type'], $a['args'] ?? []),
            'config.from' => fn (array $a, Session $s) => self::configFrom($a['type'], self::plain($a['data'])),
            'decode.info' => fn (array $a, Session $s) => self::decodeInfo($a['type'], $a['data']),
            'jetstream.check_error' => fn (array $a, Session $s) => JetStream::checkError(self::plain($a['data'])),
            'jetstream.prefix' => function (array $a, Session $s) {
                $fake = new Fake();
                $js = new JetStream(Connection::connect(new ConnectionOptions(transportFactory: fn () => $fake)), $a['domain'] ?? null, $a['prefix'] ?? null);

                return (new \ReflectionProperty(JetStream::class, 'apiPrefix'))->getValue($js);
            },
            'endpoint.new' => function (array $a, Session $s) {
                $endpoint = new Endpoint($a['name'], $a['subject'], fn (Message $m): string => '', $a['queue'] ?? 'q', self::plain($a['metadata'] ?? []));

                return ['info' => $endpoint->info(), 'stats' => $endpoint->stats()];
            },
            'service_exception.new' => function (array $a, Session $s) {
                $e = new ServiceException($a['code'], $a['message']);

                return [$e::class, $e->getErrorCode(), $e->getMessage()];
            },

            // --- Subscriptions on their own ---
            'subscription.new' => function (array $a, Session $s) {
                $sub = new Sub();
                $log = $sub->log;
                $callback = ($a['mode'] ?? 'queue') === 'callback' ? function (Message $m) use ($log): void {
                    $log[] = ['message', $m->data];
                } : null;
                $slow = ($a['slow'] ?? false) ? function (Subscription $s) use ($log): void {
                    $log[] = ['slow', $s->sid];
                } : null;
                $sub->subscription = new Subscription($a['sid'], $a['subject'], $a['queue'] ?? null, $callback, $a['msgs_limit'] ?? 65536, $a['bytes_limit'] ?? 67_108_864, $slow);

                return $s->handle($sub);
            },
            'subscription.deliver' => fn (array $a, Session $s) => self::sub($a, $s)->subscription->deliver(new Message($a['subject'] ?? 'foo', $a['data'])),
            'subscription.next' => fn (array $a, Session $s) => self::message(self::sub($a, $s)->subscription->nextMessage(0.0)),
            'subscription.set_max' => fn (array $a, Session $s) => self::sub($a, $s)->subscription->setMaxMessages($a['max']),
            'subscription.set_inactive' => fn (array $a, Session $s) => self::sub($a, $s)->subscription->setInactive(),
            'subscription.state' => function (array $a, Session $s) {
                $sub = self::sub($a, $s);

                return self::subState($sub->subscription) + ['sid' => $sub->subscription->sid, 'subject' => $sub->subscription->subject, 'queue' => $sub->subscription->queue, 'log' => $sub->log->getArrayCopy()];
            },

            // --- Connections over the fake transport ---
            'fake.connect' => function (array $a, Session $s) {
                $fake = new Fake(self::plain($a['info'] ?? []));
                $fake->rules = self::plain($a['rules'] ?? []);
                $conn = new Conn($fake);
                $log = $conn->log;
                $options = self::options($a['options'] ?? [], $conn, ['transportFactory' => function (string $scheme) use ($fake, $log) {
                    $log[] = ['transport', $scheme];

                    return $fake;
                }]);
                $conn->connection = Connection::connect($options);

                return $s->handle($conn);
            },
            'fake.push' => fn (array $a, Session $s) => self::fake($a, $s)->push($a['data']),
            'fake.rules' => function (array $a, Session $s) {
                self::fake($a, $s)->rules = self::plain($a['rules']);

                return;
            },
            'fake.answer_pings' => function (array $a, Session $s) {
                self::fake($a, $s)->answerPings = $a['answer'];

                return;
            },
            'fake.fail_reads' => function (array $a, Session $s) {
                self::fake($a, $s)->failReads = $a['error'] ?? null;

                return;
            },
            'fake.count' => fn (array $a, Session $s) => substr_count(substr(self::fake($a, $s)->written, $a['from'] ?? 0), $a['needle']),
            'fake.mark' => fn (array $a, Session $s) => \strlen(self::fake($a, $s)->written),
            'fake.written' => fn (array $a, Session $s) => self::norm(substr(self::fake($a, $s)->written, $a['from'] ?? 0)),
            'fake.writes' => fn (array $a, Session $s) => array_map(self::norm(...), self::fake($a, $s)->writes),
            'fake.connect_payload' => function (array $a, Session $s) {
                $written = self::fake($a, $s)->written;
                $start = strpos($written, 'CONNECT ');
                if ($start === false) {
                    throw new Fault('no CONNECT written');
                }
                $end = strpos($written, "\r\n", $start);

                return json_decode(substr($written, $start + 8, $end - $start - 8), true, 512, JSON_THROW_ON_ERROR);
            },
            'fake.state' => function (array $a, Session $s) {
                $fake = self::fake($a, $s);

                return ['connected' => $fake->isConnected(), 'connects' => $fake->connects, 'tls_upgrades' => \count($fake->tlsUpgrades)];
            },
            'fake.tls_options' => fn (array $a, Session $s) => self::sorted((new \ReflectionMethod(Connection::class, 'tlsOptions'))->invoke(self::conn($a, $s)->connection)),

            // --- Connections ---
            'conn.connect' => function (array $a, Session $s) {
                $conn = new Conn();
                $url = isset($a['url']) ? $a['url'] : $s->service($a['service'] ?? 'nats');
                $options = $a['options'] ?? [];
                $options['servers'] ??= $url;
                $conn->connection = Connection::connect(self::options($options, $conn));

                return $s->handle($conn);
            },
            'conn.publish' => fn (array $a, Session $s) => self::conn($a, $s)->connection->publish($a['subject'], $a['data'] ?? '', $a['reply'] ?? null, isset($a['headers']) ? self::buildHeaders($a['headers']) : null),
            'conn.subscribe' => function (array $a, Session $s) {
                $conn = self::conn($a, $s);
                $sub = $conn->connection->subscribe($a['subject'], self::callback($conn, $a), $a['queue'] ?? null);

                return $s->handle($sub);
            },
            'conn.queue_subscribe' => function (array $a, Session $s) {
                $conn = self::conn($a, $s);

                return $s->handle($conn->connection->queueSubscribe($a['subject'], $a['queue'], self::callback($conn, $a)));
            },
            'conn.unsubscribe' => function (array $a, Session $s) {
                $sub = $s->get($a['sub']);
                if ($a['via'] ?? false) {
                    $sub->unsubscribe($a['max'] ?? null);
                } else {
                    self::conn($a, $s)->connection->unsubscribe($sub, $a['max'] ?? null);
                }

                return;
            },
            'conn.next_message' => fn (array $a, Session $s) => self::message($s->get($a['sub'])->nextMessage(self::timeout($a))),
            'conn.sub_state' => fn (array $a, Session $s) => self::subState($s->get($a['sub'])),
            'conn.process_message' => function (array $a, Session $s) {
                $message = self::conn($a, $s)->connection->processMessage(self::timeout($a));

                return ($a['quiet'] ?? false) ? $message !== null : self::message($message);
            },
            'conn.wait' => fn (array $a, Session $s) => self::conn($a, $s)->connection->wait($a['count'] ?? 0, self::timeout($a)),
            'conn.request' => function (array $a, Session $s) {
                $reply = self::conn($a, $s)->connection->request($a['subject'], $a['data'] ?? '', self::timeout($a), isset($a['headers']) ? self::buildHeaders($a['headers']) : null);

                return ($a['json'] ?? false) ? ['json' => json_decode($reply->data, true, 512, JSON_THROW_ON_ERROR), 'headers' => self::headers($reply->headers)] : self::message($reply);
            },
            'conn.request_tally' => function (array $a, Session $s) {
                $bodies = [];
                for ($i = 0; $i < $a['count']; $i++) {
                    $bodies[] = self::conn($a, $s)->connection->request($a['subject'], (string) $i, self::timeout($a))->data;
                }
                $distinct = array_values(array_unique($bodies));
                sort($distinct);

                return ['total' => \count($bodies), 'distinct' => $distinct];
            },
            'conn.request_batch' => fn (array $a, Session $s) => self::requestBatch(self::conn($a, $s), $a),
            'conn.request_many' => function (array $a, Session $s) {
                $opts = [];
                foreach (['max', 'timeout', 'stall'] as $key) {
                    if (isset($a[$key])) {
                        $opts[$key] = $a[$key];
                    }
                }
                $start = microtime(true);
                $replies = self::conn($a, $s)->connection->requestMany($a['subject'], $a['data'] ?? '', $opts);
                $elapsed = microtime(true) - $start;
                $bodies = array_map(fn (Message $m): string => $m->data, $replies);
                sort($bodies);

                return ['bodies' => $bodies] + self::timing($elapsed, $a);
            },
            'conn.flush' => fn (array $a, Session $s) => self::conn($a, $s)->connection->flush(self::timeout($a)),
            'conn.drain' => function (array $a, Session $s) {
                $start = microtime(true);
                self::conn($a, $s)->connection->drain(self::timeout($a));

                return self::timing(microtime(true) - $start, $a);
            },
            'conn.close' => fn (array $a, Session $s) => self::conn($a, $s)->connection->close(),
            'conn.tick' => fn (array $a, Session $s) => self::conn($a, $s)->connection->tick(),
            'conn.state' => function (array $a, Session $s) {
                $c = self::conn($a, $s)->connection;

                return [
                    'connected' => $c->isConnected(),
                    'closed' => $c->isClosed(),
                    'reconnecting' => $c->isReconnecting(),
                    'status' => $c->getStatus(),
                    'subscriptions' => \count((new \ReflectionProperty(Connection::class, 'subscriptions'))->getValue($c)),
                ];
            },
            'conn.server_info' => fn (array $a, Session $s) => self::serverInfo(self::conn($a, $s)->connection->getServerInfo()),
            'conn.new_inbox' => fn (array $a, Session $s) => self::inbox(self::conn($a, $s)->connection->newInbox()),
            'conn.options' => function (array $a, Session $s) {
                $options = self::conn($a, $s)->connection->getOptions();

                return ['servers' => $options->servers, 'name' => $options->name, 'inbox_prefix' => $options->inboxPrefix];
            },
            'conn.log' => fn (array $a, Session $s) => self::conn($a, $s)->log->getArrayCopy(),

            // --- Transports ---
            'transport.round_trip' => fn (array $a, Session $s) => self::roundTrip($a, $s),

            // --- JetStream ---
            'js.new' => fn (array $a, Session $s) => $s->handle(self::conn($a, $s)->connection->jetStream($a['domain'] ?? null, $a['prefix'] ?? null)),
            'js.create_stream' => fn (array $a, Session $s) => self::stream($s, self::js($a, $s)->createStream(self::configNew('StreamConfig', $a['config'], false))),
            'js.update_stream' => fn (array $a, Session $s) => self::stream($s, self::js($a, $s)->updateStream(self::configNew('StreamConfig', $a['config'], false))),
            'js.create_or_update_stream' => fn (array $a, Session $s) => self::stream($s, self::js($a, $s)->createOrUpdateStream(self::configNew('StreamConfig', $a['config'], false))),
            'js.delete_stream' => fn (array $a, Session $s) => self::js($a, $s)->deleteStream($a['name']),
            'js.get_stream' => fn (array $a, Session $s) => self::stream($s, self::js($a, $s)->getStream($a['name'])),
            'js.get_stream_info' => fn (array $a, Session $s) => self::streamInfo(self::js($a, $s)->getStreamInfo($a['name'])),
            'js.get_stream_names' => fn (array $a, Session $s) => self::only(self::js($a, $s)->getStreamNames($a['subject'] ?? null), $a['contains']),
            'js.list_streams' => fn (array $a, Session $s) => self::only(array_map(fn (StreamInfo $i) => $i->config->name, self::js($a, $s)->listStreams($a['subject'] ?? null)), $a['contains']),
            'js.purge_stream' => fn (array $a, Session $s) => self::js($a, $s)->purgeStream($a['name'], $a['subject'] ?? null),
            'js.create_consumer' => fn (array $a, Session $s) => self::consumer($s, self::js($a, $s)->createConsumer($a['stream'], self::configNew('ConsumerConfig', $a['config'] ?? [], false))),
            'js.update_consumer' => fn (array $a, Session $s) => self::consumer($s, self::js($a, $s)->updateConsumer($a['stream'], self::configNew('ConsumerConfig', $a['config'] ?? [], false))),
            'js.delete_consumer' => fn (array $a, Session $s) => self::js($a, $s)->deleteConsumer($a['stream'], $a['name']),
            'js.get_consumer' => fn (array $a, Session $s) => self::consumer($s, self::js($a, $s)->getConsumer($a['stream'], $a['name'])),
            'js.get_consumer_names' => function (array $a, Session $s) {
                $names = self::js($a, $s)->getConsumerNames($a['stream']);
                sort($names);

                return $names;
            },
            'js.get_consumers' => function (array $a, Session $s) {
                $infos = array_map(fn (ConsumerInfo $i) => [$i->streamName, $i->name], self::js($a, $s)->getConsumers($a['stream']));
                sort($infos);

                return $infos;
            },
            'js.push_subscribe' => function (array $a, Session $s) {
                $conn = self::conn($a, $s);
                $log = $conn->log;
                $ack = $a['ack'] ?? true;
                $push = self::js($a, $s)->pushSubscribe($a['stream'], self::configNew('ConsumerConfig', $a['config'] ?? [], false), function (JetStreamMessage $m) use ($log, $ack): void {
                    $log[] = ['push', self::jsMessage($m)];
                    if ($ack) {
                        $m->ack();
                    }
                });

                return $s->handle($push);
            },
            'js.ordered_consumer' => fn (array $a, Session $s) => $s->handle(self::js($a, $s)->orderedConsumer(
                $a['stream'],
                DeliverPolicy::from($a['deliver_policy'] ?? 'all'),
                $a['filter'] ?? null,
                (float) ($a['heartbeat'] ?? 5.0),
            )),
            'js.get_message' => fn (array $a, Session $s) => self::streamMessage(self::js($a, $s)->getMessage($a['stream'], $a['seq'])),
            'js.get_last_message' => fn (array $a, Session $s) => self::streamMessage(self::js($a, $s)->getLastMessage($a['stream'], $a['subject'])),
            'js.delete_message' => fn (array $a, Session $s) => self::js($a, $s)->deleteMessage($a['stream'], $a['seq'], $a['no_erase'] ?? false),
            'js.publish' => fn (array $a, Session $s) => self::pubAck(self::js($a, $s)->publish(
                $a['subject'],
                $a['data'] ?? '',
                isset($a['headers']) ? self::buildHeaders($a['headers']) : null,
                $a['msg_id'] ?? null,
                $a['expected_last_msg_id'] ?? null,
                $a['expected_last_seq'] ?? null,
                $a['expected_last_subject_seq'] ?? null,
                $a['expected_stream'] ?? null,
                $a['ttl'] ?? null,
                $a['retry'] ?? 0,
            )),
            'js.publish_many' => function (array $a, Session $s) {
                $messages = [];
                foreach ($a['messages'] as $m) {
                    $message = ['subject' => $m['subject']];
                    foreach (['data' => 'data', 'msg_id' => 'msgId', 'expected_last_msg_id' => 'expectedLastMsgId', 'expected_last_seq' => 'expectedLastSeq', 'expected_last_subject_seq' => 'expectedLastSubjectSeq', 'expected_stream' => 'expectedStream', 'ttl' => 'ttl'] as $from => $to) {
                        if (isset($m[$from])) {
                            $message[$to] = $m[$from];
                        }
                    }
                    if (isset($m['headers'])) {
                        $message['headers'] = self::buildHeaders($m['headers']);
                    }
                    $messages[] = $message;
                }

                return array_map(self::pubAck(...), self::js($a, $s)->publishMany($messages, $a['timeout'] ?? null, $a['window'] ?? 256));
            },
            'js.create_key_value' => fn (array $a, Session $s) => $s->handle(self::js($a, $s)->createKeyValue(self::configNew('KeyValueConfig', $a['config'], false))),
            'js.get_key_value' => fn (array $a, Session $s) => $s->handle(self::js($a, $s)->getKeyValue($a['bucket'])),
            'js.delete_key_value' => fn (array $a, Session $s) => self::js($a, $s)->deleteKeyValue($a['bucket']),
            'js.get_object_store' => fn (array $a, Session $s) => $s->handle(self::js($a, $s)->getObjectStore($a['bucket'])),
            'js.delete_object_store' => fn (array $a, Session $s) => self::js($a, $s)->deleteObjectStore($a['bucket']),
            'js.account_info' => function (array $a, Session $s) {
                $info = self::js($a, $s)->accountInfo();

                return ['streams_ge_1' => $info->streams >= 1, 'memory_ge_0' => $info->memory >= 0, 'api_total_ge_0' => $info->apiTotal >= 0, 'raw_has_memory' => \array_key_exists('memory', $info->raw), 'limits_is_array' => \is_array($info->raw['limits'] ?? null)];
            },
            'js.ack_batch' => function (array $a, Session $s) {
                $messages = array_map(fn ($h) => $s->get($h), $a['messages']);
                $confirmed = [];
                self::js($a, $s)->ackBatch($messages, function (int $index, ?\Throwable $error) use (&$confirmed): void {
                    $confirmed[] = [$index, $error === null ? null : [$error::class, $error->getMessage()]];
                }, $a['timeout'] ?? null);
                sort($confirmed);

                return $confirmed;
            },

            'stream.get' => function (array $a, Session $s) {
                $stream = $s->get($a['stream']);

                return ['name' => $stream->getName(), 'config' => $stream->getConfig()->toArray(), 'state' => self::streamState($stream->getState())];
            },
            'stream.info' => fn (array $a, Session $s) => self::streamInfo($s->get($a['stream'])->info($a['refresh'] ?? false)),
            'stream.create_consumer' => fn (array $a, Session $s) => self::consumer($s, $s->get($a['stream'])->createConsumer(self::configNew('ConsumerConfig', $a['config'] ?? [], false))),
            'stream.get_consumer' => fn (array $a, Session $s) => self::consumer($s, $s->get($a['stream'])->getConsumer($a['name'])),
            'stream.delete_consumer' => fn (array $a, Session $s) => $s->get($a['stream'])->deleteConsumer($a['name']),
            'stream.purge' => fn (array $a, Session $s) => $s->get($a['stream'])->purge($a['subject'] ?? null),
            'stream.delete' => fn (array $a, Session $s) => $s->get($a['stream'])->delete(),

            'consumer.new' => fn (array $a, Session $s) => $s->handle(isset($a['prefix'])
                ? new Consumer(self::conn($a, $s)->connection, $a['stream'], ConsumerInfo::fromArray(self::plain($a['info'])), $a['prefix'])
                : new Consumer(self::conn($a, $s)->connection, $a['stream'], ConsumerInfo::fromArray(self::plain($a['info'])))),
            'consumer.fetch' => function (array $a, Session $s) {
                $start = microtime(true);
                $batch = $s->get($a['consumer'])->fetch($a['batch'] ?? 1, self::timeout($a), $a['no_wait'] ?? false, $a['max_bytes'] ?? null);

                return self::batch($s, $batch) + self::timing(microtime(true) - $start, $a);
            },
            'consumer.next' => function (array $a, Session $s) {
                $message = $s->get($a['consumer'])->next(self::timeout($a));

                return $message === null ? null : ['handle' => $s->handle($message)] + self::jsMessage($message);
            },
            'consumer.info' => fn (array $a, Session $s) => self::consumerInfo($s->get($a['consumer'])->info($a['refresh'] ?? false)),
            'consumer.names' => fn (array $a, Session $s) => [self::norm($s->get($a['consumer'])->getName()), $s->get($a['consumer'])->getStream()],

            'jsmsg.new' => fn (array $a, Session $s) => $s->handle(new JetStreamMessage(self::conn($a, $s)->connection, new Message($a['subject'] ?? 'foo', $a['data'] ?? '', $a['reply'] ?? null))),
            'jsmsg.ack' => fn (array $a, Session $s) => $s->get($a['msg'])->ack(),
            'jsmsg.ack_sync' => fn (array $a, Session $s) => $s->get($a['msg'])->ackSync(self::timeout($a)),
            'jsmsg.nak' => fn (array $a, Session $s) => $s->get($a['msg'])->nak($a['delay'] ?? null),
            'jsmsg.in_progress' => fn (array $a, Session $s) => $s->get($a['msg'])->inProgress(),
            'jsmsg.term' => fn (array $a, Session $s) => $s->get($a['msg'])->term($a['reason'] ?? null),
            'jsmsg.metadata' => fn (array $a, Session $s) => self::metadata($s->get($a['msg'])->metadata()),
            'jsmsg.get' => fn (array $a, Session $s) => self::jsMessage($s->get($a['msg'])),
            'batch.new' => function (array $a, Session $s) {
                $batch = new MessageBatch(self::conn($a, $s)->connection);
                foreach ($a['messages'] as $m) {
                    $batch->addMessage(new Message($m['subject'] ?? 'foo', $m['data'] ?? '', $m['reply'] ?? null));
                }

                return self::batch($s, $batch);
            },

            'push.get' => function (array $a, Session $s) {
                $push = $s->get($a['push']);

                return ['consumer' => self::norm($push->getConsumerName()), 'info_name' => self::norm($push->getConsumerInfo()->name), 'subject' => self::norm($push->getSubscription()->subject)];
            },
            'push.unsubscribe' => fn (array $a, Session $s) => $s->get($a['push'])->unsubscribe(),
            'push.handle_control' => fn (array $a, Session $s) => PushSubscription::handleControl(self::conn($a, $s)->connection, self::buildMessage($a['message'])),

            'ordered.next' => function (array $a, Session $s) {
                $message = $s->get($a['ordered'])->next(self::timeout($a));
                if ($message === null) {
                    return;
                }
                $meta = $message->metadata();

                return self::jsMessage($message) + ['stream_seq' => $meta->streamSequence, 'consumer_seq' => $meta->consumerSequence];
            },
            'ordered.name' => fn (array $a, Session $s) => ($a['raw'] ?? false) ? $s->get($a['ordered'])->getConsumerName() : self::norm($s->get($a['ordered'])->getConsumerName()),
            'ordered.stop' => fn (array $a, Session $s) => $s->get($a['ordered'])->stop(),
            'ordered.skip' => function (array $a, Session $s) {
                $property = new \ReflectionProperty(OrderedConsumer::class, 'expectedConsumerSeq');
                $ordered = $s->get($a['ordered']);
                $property->setValue($ordered, $property->getValue($ordered) + ($a['by'] ?? 1));

                return $property->getValue($ordered);
            },

            'kv.get' => fn (array $a, Session $s) => self::entry(self::kv($a, $s)->get($a['key'])),
            'kv.put' => fn (array $a, Session $s) => self::kv($a, $s)->put($a['key'], $a['value']),
            'kv.create' => fn (array $a, Session $s) => self::kv($a, $s)->create($a['key'], $a['value']),
            'kv.update' => fn (array $a, Session $s) => self::kv($a, $s)->update($a['key'], $a['value'], $a['revision']),
            'kv.delete' => fn (array $a, Session $s) => self::kv($a, $s)->delete($a['key']),
            'kv.purge' => fn (array $a, Session $s) => self::kv($a, $s)->purge($a['key']),
            'kv.keys' => function (array $a, Session $s) {
                $keys = self::kv($a, $s)->keys();
                sort($keys);

                return $keys;
            },
            'kv.status' => function (array $a, Session $s) {
                $status = self::kv($a, $s)->status();

                return ['bucket' => $status->bucket, 'values' => $status->values, 'bytes_gt_0' => $status->bytes > 0, 'history' => $status->history, 'ttl' => $status->ttl, 'stream' => $status->streamInfo->config->name];
            },
            'kv.get_revision' => fn (array $a, Session $s) => self::entry(self::kv($a, $s)->getRevision($a['key'], $a['seq'])),
            'kv.history' => fn (array $a, Session $s) => array_map(self::entry(...), self::kv($a, $s)->history($a['key'])),
            'kv.watch' => function (array $a, Session $s) {
                $conn = self::conn($a, $s);
                $log = $conn->log;
                $o = self::plain($a['options'] ?? null);
                $options = $o !== null ? new KeyValueWatchOptions(
                    includeHistory: $o['include_history'] ?? false,
                    updatesOnly: $o['updates_only'] ?? false,
                    ignoreDeletes: $o['ignore_deletes'] ?? false,
                    metaOnly: $o['meta_only'] ?? false,
                ) : null;
                $init = ($a['init'] ?? false) ? function () use ($log): void {
                    $log[] = ['init', \count($log)];
                } : null;

                return $s->handle(self::kv($a, $s)->watch($a['pattern'], function (KeyValueEntry $e) use ($log): void {
                    $log[] = ['entry', self::entry($e)];
                }, $options, $init));
            },
            'kv.purge_deletes' => fn (array $a, Session $s) => isset($a['threshold']) ? self::kv($a, $s)->purgeDeletes((float) $a['threshold']) : self::kv($a, $s)->purgeDeletes(),
            'kv.bucket' => fn (array $a, Session $s) => self::kv($a, $s)->getBucket(),
            'kv.new' => fn (array $a, Session $s) => $s->handle(new KeyValue(self::conn($a, $s)->connection, $s->get($a['js']), $a['bucket'])),

            'obj.new' => fn (array $a, Session $s) => $s->handle(new ObjectStore(self::conn($a, $s)->connection, $s->get($a['js']), $a['bucket'])),
            'obj.create_or_update' => fn (array $a, Session $s) => $s->handle(ObjectStore::createOrUpdate(self::conn($a, $s)->connection, $s->get($a['js']), self::configNew('ObjectStoreConfig', $a['config'], false))),
            'obj.put' => fn (array $a, Session $s) => self::objMeta(self::obj($a, $s)->put($a['name'], self::payload($a))),
            'obj.get' => function (array $a, Session $s) {
                $data = self::obj($a, $s)->get($a['name']);

                return ['size' => \strlen($data), 'sha256' => hash('sha256', $data), 'data' => \strlen($data) <= 256 ? $data : null];
            },
            'obj.get_meta' => fn (array $a, Session $s) => self::objMeta(self::obj($a, $s)->getMeta($a['name'])),
            'obj.delete' => fn (array $a, Session $s) => self::obj($a, $s)->delete($a['name']),
            'obj.list' => function (array $a, Session $s) {
                $names = array_map(fn (ObjectMeta $m) => $m->name, self::obj($a, $s)->list());
                sort($names);

                return $names;
            },
            'obj.status' => function (array $a, Session $s) {
                $info = self::obj($a, $s)->status();

                return ['name' => $info->config->name, 'messages' => $info->state->messages];
            },
            'obj.watch' => function (array $a, Session $s) {
                $log = self::conn($a, $s)->log;

                return $s->handle(self::obj($a, $s)->watch(function (ObjectMeta $m) use ($log): void {
                    $log[] = ['meta', self::objMeta($m)];
                }, $a['include_history'] ?? false));
            },
            'obj.add_link' => fn (array $a, Session $s) => self::objMeta(self::obj($a, $s)->addLink($a['name'], $a['target'])),
            'obj.add_bucket_link' => fn (array $a, Session $s) => self::objMeta(self::obj($a, $s)->addBucketLink($a['name'], $a['bucket'])),
            'obj.update_meta' => fn (array $a, Session $s) => self::objMeta(self::obj($a, $s)->updateMeta($a['name'], $a['description'] ?? null, $a['metadata'] ?? null)),
            'obj.seal' => fn (array $a, Session $s) => self::obj($a, $s)->seal(),
            'obj.bucket' => fn (array $a, Session $s) => self::obj($a, $s)->getBucket(),
            'obj.read_meta' => function (array $a, Session $s) {
                [$meta, $seq] = (new \ReflectionMethod(ObjectStore::class, 'readMetaWithSeq'))->invoke(self::obj($a, $s), $a['name']);

                return ['meta' => $meta === null ? null : $s->handle($meta), 'seq' => $seq];
            },
            'obj.write_version' => fn (array $a, Session $s) => self::objMeta((new \ReflectionMethod(ObjectStore::class, 'writeVersion'))->invoke(
                self::obj($a, $s),
                $a['name'],
                self::payload($a),
                isset($a['previous']) ? $s->get($a['previous']) : null,
                $a['seq'],
            )),
            'obj.delete_version' => fn (array $a, Session $s) => (new \ReflectionMethod(ObjectStore::class, 'deleteVersion'))->invoke(self::obj($a, $s), $s->get($a['meta']), $a['seq']),

            'service.new' => fn (array $a, Session $s) => $s->handle(new Service(self::conn($a, $s)->connection, $a['name'], $a['version'], $a['description'] ?? '', self::plain($a['metadata'] ?? []))),
            'service.add_endpoint' => fn (array $a, Session $s) => $s->get($a['service'])->addEndpoint($a['name'], $a['subject'], self::handler($a['handler']), $a['queue'] ?? null, self::plain($a['metadata'] ?? [])) === $s->get($a['service']),
            'service.register_endpoint' => fn (array $a, Session $s) => $s->get($a['service'])->registerEndpoint($a['name'], $a['subject'], self::handler($a['handler']), $a['queue'] ?? null, self::plain($a['metadata'] ?? [])),
            'service.add_group' => fn (array $a, Session $s) => $s->handle($s->get($a['service'])->addGroup($a['name'], $a['queue'] ?? null)),
            'group.add_group' => fn (array $a, Session $s) => $s->handle($s->get($a['group'])->addGroup($a['name'], $a['queue'] ?? null)),
            'group.add_endpoint' => fn (array $a, Session $s) => $s->get($a['group'])->addEndpoint($a['name'], self::handler($a['handler']), $a['subject'] ?? null, $a['queue'] ?? null, self::plain($a['metadata'] ?? [])) === $s->get($a['group']),
            'service.start' => fn (array $a, Session $s) => $s->get($a['service'])->start() === $s->get($a['service']),
            'service.run' => fn (array $a, Session $s) => $s->get($a['service'])->run(),
            'service.stop' => fn (array $a, Session $s) => $s->get($a['service'])->stop(),
            'service.get' => function (array $a, Session $s) {
                $service = $s->get($a['service']);

                return ['name' => $service->getName(), 'id_length' => \strlen($service->getId()), 'id_hex' => ctype_xdigit($service->getId()) && strtoupper($service->getId()) === $service->getId()];
            },
            'service.id' => fn (array $a, Session $s) => $s->get($a['service'])->getId(),
        ];
    }

    // --- Arguments ---

    /** JSON objects decoded the way json_decode(..., true) decodes them: `{}` is []. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return array_map(self::plain(...), (array) $value);
        }
        if (\is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }

    private static function timeout(array $a): ?float
    {
        return isset($a['timeout']) ? (float) $a['timeout'] : null;
    }

    /** @return array<string, mixed> */
    private static function timing(float $elapsed, array $a): array
    {
        $out = [];
        if (isset($a['within'])) {
            $out['within'] = $elapsed < $a['within'];
        }
        if (isset($a['at_least'])) {
            $out['at_least'] = $elapsed >= $a['at_least'];
        }

        return $out;
    }

    private static function payload(array $a): string
    {
        if (isset($a['repeat'])) {
            return str_repeat($a['data'], $a['repeat']);
        }

        return $a['data'] ?? '';
    }

    private static function buildHeaders(array $steps): Headers
    {
        $headers = new Headers();
        foreach ($steps as $step) {
            if (isset($step['op'])) {
                $step = [$step['op'], $step['name'] ?? '', $step['value'] ?? ''];
            }
            $result = match ($step[0]) {
                'set' => $headers->set($step[1], $step[2]),
                'add' => $headers->add($step[1], $step[2]),
                'delete' => $headers->delete($step[1]),
                'status' => isset($step[2]) ? $headers->setStatus($step[1], $step[2]) : $headers->setStatus($step[1]),
                default => throw new Fault("unknown header step {$step[0]}"),
            };
            if ($result !== $headers) {
                throw new Fault('a header setter did not return the headers');
            }
        }

        return $headers;
    }

    private static function buildMessage(array $m): Message
    {
        return new Message($m['subject'] ?? 'foo', $m['data'] ?? '', $m['reply'] ?? null, isset($m['headers']) ? self::buildHeaders($m['headers']) : null);
    }

    /** @param array<string, mixed> $extra */
    private static function options(array $a, Conn $conn, array $extra = []): ConnectionOptions
    {
        $log = $conn->log;
        $args = [];
        foreach ([
            'servers', 'name', 'user', 'pass', 'token', 'nkey', 'nkeySeed', 'credentialsFile', 'tls', 'tlsCaFile', 'tlsCertFile', 'tlsKeyFile',
            'tlsVerify', 'tlsServerName', 'allowReconnect', 'maxReconnectAttempts', 'reconnectBufSize', 'subPendingMsgsLimit', 'subPendingBytesLimit',
            'maxPingsOut', 'verbose', 'pedantic', 'echo', 'noRandomize', 'inboxPrefix',
        ] as $key) {
            if (\array_key_exists($key, $a)) {
                $args[$key] = $a[$key];
            }
        }
        foreach (['reconnectWait', 'maxReconnectWait', 'reconnectJitter', 'connectTimeout', 'requestTimeout', 'drainTimeout', 'pingInterval'] as $key) {
            if (\array_key_exists($key, $a)) {
                $args[$key] = (float) $a[$key];
            }
        }
        if (isset($a['tokenProvider'])) {
            $calls = 0;
            $args['tokenProvider'] = function () use (&$calls, $log): string {
                $calls++;
                $log[] = ['tokenProvider', $calls];

                return "token-{$calls}";
            };
        }
        if (isset($a['jwtProvider'])) {
            $jwt = $a['jwtProvider'];
            $args['jwtProvider'] = fn (): string => $jwt;
        }
        foreach ($a['hooks'] ?? [] as $hook => $spec) {
            $args[$hook] = function (...$arguments) use ($hook, $spec, $log, $conn): void {
                $entry = [$hook];
                if ($hook === 'onError') {
                    $entry[] = [$arguments[0]::class, $arguments[0]->getMessage()];
                }
                if ($hook === 'onSlowConsumer') {
                    $entry[] = $arguments[0]->sid;
                }
                if (\is_array($spec) && ($spec['connected'] ?? false)) {
                    $entry[] = $conn->connection->isConnected();
                }
                $log[] = $entry;
                if (\is_array($spec) && isset($spec['throw'])) {
                    throw Errors::make($spec['throw']);
                }
            };
        }

        return new ConnectionOptions(...array_merge($args, $extra));
    }

    private static function callback(Conn $conn, array $a): ?\Closure
    {
        $log = $conn->log;
        $c = $conn->connection;

        return match ($a['mode'] ?? 'queue') {
            'queue' => null,
            'silent' => fn (Message $m) => null,
            'record' => function (Message $m) use ($log): void {
                $log[] = ['msg', self::message($m)];
            },
            'count' => function (Message $m) use ($log): void {
                $log[] = ['count'];
            },
            'echo' => function (Message $m) use ($c, $a, $log): void {
                $log[] = ['msg', self::message($m)];
                if ($m->replyTo !== null) {
                    $c->publish($m->replyTo, ($a['prefix'] ?? '') . $m->data);
                }
            },
            'throw' => fn (Message $m) => throw Errors::make($a['error']),
            default => throw new Fault("unknown subscription mode {$a['mode']}"),
        };
    }

    private static function handler(array $spec): \Closure
    {
        return match ($spec['kind']) {
            'echo' => fn (Message $m): string => ($spec['prefix'] ?? '') . $m->data,
            'fixed' => fn (Message $m): string => $spec['payload'],
            'throw' => fn (Message $m): string => throw Errors::make($spec['error']),
            default => throw new Fault("unknown handler {$spec['kind']}"),
        };
    }

    /** @return list<mixed> */
    private static function requestBatch(Conn $conn, array $a): array
    {
        $requests = array_map(fn (array $r) => new Request($r['subject'], $r['data'] ?? '', isset($r['headers']) ? self::buildHeaders($r['headers']) : null), $a['requests']);
        $results = [];
        $mode = $a['callback'] ?? 'record';
        $conn->connection->requestBatch($requests, function (int $index, Message|\Throwable $result) use (&$results, $mode, $a): void {
            $results[] = [$index, $result instanceof Message ? self::message($result) : ['error' => [$result::class, $result->getMessage()]]];
            if ($mode === 'throw') {
                throw Errors::make($a['error']);
            }
            if ($mode === 'sleep_first' && $index === 0) {
                usleep((int) ($a['sleep_ms'] * 1000));
            }
        }, self::timeout($a));

        return $results;
    }

    // --- Handles ---

    private static function conn(array $a, Session $s): Conn
    {
        $conn = $s->get($a['conn']);

        return $conn instanceof Conn ? $conn : throw new Fault('expected a connection handle');
    }

    private static function fake(array $a, Session $s): Fake
    {
        return self::conn($a, $s)->fake ?? throw new Fault('not a fake connection');
    }

    private static function sub(array $a, Session $s): Sub
    {
        return $s->get($a['sub']);
    }

    private static function js(array $a, Session $s): JetStream
    {
        return $s->get($a['js']);
    }

    private static function kv(array $a, Session $s): KeyValue
    {
        return $s->get($a['kv']);
    }

    private static function obj(array $a, Session $s): ObjectStore
    {
        return $s->get($a['obj']);
    }

    // --- Results ---

    /**
     * Replaces the random 22-character ids (inbox tokens, ephemeral consumer
     * names) that start the string or follow a dot with `*`.
     */
    private static function norm(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $out = '';
        $length = \strlen($value);
        $i = 0;
        while ($i < $length) {
            if (ctype_alnum($value[$i])) {
                $start = $i;
                while ($i < $length && ctype_alnum($value[$i])) {
                    $i++;
                }
                $run = substr($value, $start, $i - $start);
                $out .= ($i - $start === 22 && ($start === 0 || $value[$start - 1] === '.' || $value[$start - 1] === '"')) ? '*' : $run;
            } else {
                $out .= $value[$i];
                $i++;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function headers(?Headers $headers, array $query = []): ?array
    {
        if ($headers === null) {
            return null;
        }
        $all = [];
        foreach ($headers as $name => $values) {
            $all[] = [(string) $name, $values];
        }
        $out = [
            'status' => $headers->getStatus(),
            'description' => $headers->getDescription(),
            'all' => $all,
            'count' => \count($headers),
            'all_count' => \count($headers->all()),
            'wire' => $headers->toWire(),
        ];
        foreach ($query as $name) {
            $out['query'][] = [$name, $headers->get($name), $headers->getAll($name), $headers->has($name)];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function message(?Message $m): ?array
    {
        if ($m === null) {
            return null;
        }

        return ['subject' => self::norm($m->subject), 'data' => $m->data, 'reply' => self::norm($m->replyTo), 'headers' => self::headers($m->headers), 'sid' => $m->sid];
    }

    /** @return array<string, mixed> */
    private static function inbox(string $inbox): array
    {
        $dot = strrpos($inbox, '.');

        return ['prefix' => substr($inbox, 0, (int) $dot), 'length' => \strlen($inbox) - $dot - 1, 'alnum' => ctype_alnum(substr($inbox, $dot + 1))];
    }

    /** @return array<string, mixed> */
    private static function subState(Subscription $sub): array
    {
        return ['active' => $sub->isActive(), 'received' => $sub->getReceived(), 'pending_count' => $sub->getPendingCount(), 'pending_bytes' => $sub->getPendingBytes(), 'has_callback' => $sub->hasCallback()];
    }

    /** @return array<string, mixed> */
    private static function serverInfo(ServerInfo $i): array
    {
        return [
            'server_id' => $i->serverId, 'server_name' => $i->serverName, 'version' => $i->version, 'proto' => $i->proto, 'host' => $i->host, 'port' => $i->port,
            'headers' => $i->headersSupported, 'auth_required' => $i->authRequired, 'tls_required' => $i->tlsRequired, 'tls_available' => $i->tlsAvailable,
            'max_payload' => $i->maxPayload, 'connect_urls' => $i->connectUrls, 'nonce' => $i->nonce, 'jetstream' => $i->jetstream, 'client_id' => $i->clientId, 'client_ip' => $i->clientIp,
        ];
    }

    /** @return array<string, mixed> */
    private static function optionsDump(ConnectionOptions $o): array
    {
        return [
            'servers' => $o->servers, 'name' => $o->name, 'user' => $o->user, 'pass' => $o->pass, 'token' => $o->token, 'nkey' => $o->nkey, 'nkeySeed' => $o->nkeySeed,
            'credentialsFile' => $o->credentialsFile, 'tls' => $o->tls, 'tlsCaFile' => $o->tlsCaFile, 'tlsCertFile' => $o->tlsCertFile, 'tlsKeyFile' => $o->tlsKeyFile,
            'tlsVerify' => $o->tlsVerify, 'tlsServerName' => $o->tlsServerName, 'allowReconnect' => $o->allowReconnect, 'maxReconnectAttempts' => $o->maxReconnectAttempts,
            'reconnectWait' => $o->reconnectWait, 'maxReconnectWait' => $o->maxReconnectWait, 'reconnectJitter' => $o->reconnectJitter, 'reconnectBufSize' => $o->reconnectBufSize,
            'subPendingMsgsLimit' => $o->subPendingMsgsLimit, 'subPendingBytesLimit' => $o->subPendingBytesLimit, 'connectTimeout' => $o->connectTimeout,
            'requestTimeout' => $o->requestTimeout, 'drainTimeout' => $o->drainTimeout, 'pingInterval' => $o->pingInterval, 'maxPingsOut' => $o->maxPingsOut,
            'verbose' => $o->verbose, 'pedantic' => $o->pedantic, 'echo' => $o->echo, 'noRandomize' => $o->noRandomize, 'inboxPrefix' => $o->inboxPrefix,
        ];
    }

    /** @param array<string, mixed> $map */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    /** @return list<string> */
    private static function only(array $names, string $contains): array
    {
        $names = array_values(array_filter($names, fn (string $n) => str_contains($n, $contains)));
        sort($names);

        return $names;
    }

    private static function parse(array $a): array
    {
        $fake = new Fake();
        $fake->push($a['inbound']);
        $parser = new Parser($fake);
        $ops = [];
        $error = null;
        for ($i = 0; $i < ($a['limit'] ?? 64); $i++) {
            try {
                [$op, $data] = $parser->next(0.01);
                $ops[] = [$op->value, self::opData($op, $data)];
            } catch (\Throwable $e) {
                $error = self::error($e);

                break;
            }
        }
        $then = null;
        if (isset($a['then'])) {
            $fake->push($a['then']);
            try {
                [$op, $data] = $parser->next(0.01);
                $then = [$op->value, self::opData($op, $data)];
            } catch (\Throwable $e) {
                $then = self::error($e);
            }
        }

        return ['ops' => $ops, 'error' => $error, 'then' => $then, 'buffered' => $parser->hasBufferedData()];
    }

    private static function opData(ServerOp $op, mixed $data): mixed
    {
        return match ($op) {
            ServerOp::Msg, ServerOp::HMsg => [$data['subject'], $data['sid'], $data['replyTo'], $data['payload'], $data['headers']],
            default => $data,
        };
    }

    /** @return array<string, mixed> */
    private static function error(\Throwable $e): array
    {
        $out = ['class' => $e::class, 'message' => $e->getMessage()];
        if ($e->getPrevious() !== null) {
            $out['previous'] = $e->getPrevious()::class;
        }

        return $out;
    }

    private static function authenticate(array $a, Session $s): array
    {
        $auth = match ($a['kind']) {
            'none' => new NoAuth(),
            'token' => new TokenAuth($a['token']),
            'user_pass' => new UserPassAuth($a['user'], $a['pass']),
            'nkey' => new NKeyAuth($a['public'] ?? '', $a['seed']),
            'credentials' => new CredentialsAuth(self::credentials($a, $s)),
            default => throw new Fault("unknown auth {$a['kind']}"),
        };
        $out = [];
        if ($auth instanceof NKeyAuth && ($a['public_key'] ?? false)) {
            $out['public_key'] = $auth->publicKey();
        }
        $out['fields'] = $auth->authenticate($a['nonce'] ?? null);

        return $out;
    }

    private static function credentials(array $a, Session $s): string
    {
        if (!isset($a['contents'])) {
            return $a['path'];
        }
        $dir = "/tmp/compat-fs/{$s->ns}";
        @mkdir($dir, 0777, true);
        $path = "{$dir}/user.creds";
        file_put_contents($path, $a['contents']);

        return $path;
    }

    private static function roundTrip(array $a, Session $s): array
    {
        $kind = $a['kind'];
        $url = parse_url($s->service($a['service']));
        $tls = self::plain($a['tls'] ?? []);
        $transport = match ($kind) {
            'tcp' => new TcpTransport(),
            'tls' => new TlsTransport($tls),
            'ws' => new WebSocketTransport(false, $tls, $a['path'] ?? '/'),
            default => throw new Fault("unknown transport {$kind}"),
        };
        $out = [];
        $step = function (string $name, callable $fn) use (&$out): mixed {
            try {
                $result = $fn();
                $out[] = [$name, $result];

                return $result;
            } catch (\Throwable $e) {
                $out[] = [$name, self::error($e)];

                return null;
            }
        };
        $step('connect', fn () => $transport->connect($url['host'], $url['port'], 2.0));
        $step('connected', fn () => $transport->isConnected());
        if (\is_array($out[0][1])) {
            return $out;
        }
        $step('info', fn () => str_starts_with($transport->readLine(2.0), 'INFO '));
        if ($a['upgrade'] ?? false) {
            $before = \count($out);
            $step('upgrade', fn () => $transport->upgradeTls($tls));
            if (\is_array($out[$before][1])) {
                return $out;
            }
        }
        $step('write', fn () => $transport->write("CONNECT {\"verbose\":false}\r\nPING\r\n"));
        $step('pong', function () use ($transport) {
            $line = $transport->readLine(2.0);
            while (str_starts_with($line, 'INFO ') || $line === "+OK\r\n") {
                $line = $transport->readLine(2.0);
            }

            return $line;
        });
        $step('ping', fn () => $transport->write("PING\r\n"));
        $step('read', fn () => $transport->read(64, 2.0));
        $step('close', fn () => $transport->close());
        $step('connected_after', fn () => $transport->isConnected());
        $step('write_after', fn () => $transport->write("PING\r\n"));
        $step('read_after', fn () => $transport->read(64, 0.1));

        return $out;
    }

    // --- JetStream values ---

    /** @param array<string, mixed> $args */
    private static function configNew(string $type, mixed $args, bool $array = true): mixed
    {
        $args = self::plain($args);
        $enum = fn (string $key, string $class) => isset($args[$key]) ? ($args[$key] = $class::from($args[$key])) : null;
        $float = function (string $key) use (&$args): void {
            if (isset($args[$key])) {
                $args[$key] = (float) $args[$key];
            }
        };
        switch ($type) {
            case 'StreamConfig':
                foreach (['retention' => RetentionPolicy::class, 'storage' => StorageType::class, 'discard' => DiscardPolicy::class] as $k => $c) {
                    if (isset($args[$k])) {
                        $args[$k] = $c::from($args[$k]);
                    }
                }
                foreach (['maxAge', 'duplicateWindow', 'subjectDeleteMarkerTtl'] as $k) {
                    $float($k);
                }
                if (isset($args['mirror'])) {
                    $args['mirror'] = self::configNew('StreamSource', $args['mirror'], false);
                }
                if (isset($args['sources'])) {
                    $args['sources'] = array_map(fn ($x) => self::configNew('StreamSource', $x, false), $args['sources']);
                }
                foreach (['republish' => 'Republish', 'subjectTransform' => 'SubjectTransform', 'placement' => 'Placement', 'consumerLimits' => 'ConsumerLimits'] as $k => $t) {
                    if (isset($args[$k])) {
                        $args[$k] = self::configNew($t, $args[$k], false);
                    }
                }
                $value = new StreamConfig(...$args);

                return $array ? $value->toArray() : $value;
            case 'ConsumerConfig':
                foreach (['deliverPolicy' => DeliverPolicy::class, 'ackPolicy' => AckPolicy::class, 'replayPolicy' => ReplayPolicy::class] as $k => $c) {
                    if (isset($args[$k])) {
                        $args[$k] = $c::from($args[$k]);
                    }
                }
                foreach (['ackWait', 'inactiveThreshold', 'idleHeartbeat'] as $k) {
                    $float($k);
                }
                if (isset($args['backoff'])) {
                    $args['backoff'] = array_map(fn ($b) => (float) $b, $args['backoff']);
                }
                $value = new ConsumerConfig(...$args);

                return $array ? $value->toArray() : $value;
            case 'ConsumerLimits':
                $float('inactiveThreshold');
                $value = new ConsumerLimits(...$args);

                return $array ? $value->toArray() : $value;
            case 'Placement':
                $value = new Placement(...$args);

                return $array ? $value->toArray() : $value;
            case 'Republish':
                $value = new Republish(...$args);

                return $array ? $value->toArray() : $value;
            case 'SubjectTransform':
                $value = new SubjectTransform(...$args);

                return $array ? $value->toArray() : $value;
            case 'ExternalStream':
                $value = new ExternalStream(...$args);

                return $array ? $value->toArray() : $value;
            case 'StreamSource':
                if (isset($args['subjectTransforms'])) {
                    $args['subjectTransforms'] = array_map(fn ($x) => self::configNew('SubjectTransform', $x, false), $args['subjectTransforms']);
                }
                if (isset($args['external'])) {
                    $args['external'] = self::configNew('ExternalStream', $args['external'], false);
                }
                $value = new StreamSource(...$args);

                return $array ? $value->toArray() : $value;
            case 'KeyValueConfig':
                if (isset($args['storage'])) {
                    $args['storage'] = StorageType::from($args['storage']);
                }
                $float('ttl');
                $value = new KeyValueConfig(...$args);

                return $array ? $value->toStreamConfig()->toArray() : $value;
            case 'ObjectStoreConfig':
                if (isset($args['storage'])) {
                    $args['storage'] = StorageType::from($args['storage']);
                }
                $float('ttl');
                $value = new ObjectStoreConfig(...$args);

                return $array ? $value->toStreamConfig()->toArray() : $value;
            case 'ObjectLink':
                $value = new ObjectLink(...$args);

                return $array ? $value->toArray() : $value;
            case 'ObjectMeta':
                if (isset($args['link'])) {
                    $args['link'] = self::configNew('ObjectLink', $args['link'], false);
                }
                $value = new ObjectMeta(...$args);

                return $array ? $value->toArray() : $value;
            case 'KeyValueEntry':
                return self::entry(new KeyValueEntry(...$args));
            case 'KeyValueWatchOptions':
                $value = new KeyValueWatchOptions(...$args);

                return [$value->includeHistory, $value->updatesOnly, $value->ignoreDeletes, $value->metaOnly];
            default:
                throw new Fault("unknown config {$type}");
        }
    }

    private static function configFrom(string $type, array $data): array
    {
        return match ($type) {
            'StreamConfig' => (function () use ($data) {
                $c = StreamConfig::fromArray($data);

                return ['array' => $c->toArray(), 'max_age' => $c->maxAge, 'duplicate_window' => $c->duplicateWindow, 'subject_delete_marker_ttl' => $c->subjectDeleteMarkerTtl, 'limits_ttl' => $c->consumerLimits?->inactiveThreshold];
            })(),
            'ConsumerConfig' => (function () use ($data) {
                $c = ConsumerConfig::fromArray($data);

                return ['array' => $c->toArray(), 'ack_wait' => $c->ackWait, 'inactive_threshold' => $c->inactiveThreshold, 'idle_heartbeat' => $c->idleHeartbeat, 'backoff' => $c->backoff];
            })(),
            'ConsumerLimits' => ['array' => ConsumerLimits::fromArray($data)->toArray()],
            'Placement' => ['array' => Placement::fromArray($data)->toArray()],
            'Republish' => ['array' => Republish::fromArray($data)->toArray()],
            'SubjectTransform' => ['array' => SubjectTransform::fromArray($data)->toArray()],
            'ExternalStream' => ['array' => ExternalStream::fromArray($data)->toArray()],
            'StreamSource' => ['array' => StreamSource::fromArray($data)->toArray()],
            'ObjectLink' => ['array' => ObjectLink::fromArray($data)->toArray()],
            'ObjectMeta' => ['array' => ObjectMeta::fromArray($data)->toArray()],
            default => throw new Fault("unknown config {$type}"),
        };
    }

    private static function decodeInfo(string $type, mixed $data): mixed
    {
        return match ($type) {
            'MsgMetadata' => self::metadata(MsgMetadata::fromReplySubject($data)),
            'StreamMessage' => self::streamMessage(StreamMessage::fromArray(self::plain($data))),
            'StreamInfo' => array_merge(self::streamInfo(StreamInfo::fromArray(self::plain($data))), ['created' => StreamInfo::fromArray(self::plain($data))->created]),
            'StreamState' => self::streamState(StreamState::fromArray(self::plain($data)), true),
            'ConsumerInfo' => self::consumerInfo(ConsumerInfo::fromArray(self::plain($data)), true),
            'SequenceInfo' => self::sequence(SequenceInfo::fromArray(self::plain($data)), true),
            'AccountInfo' => (function () use ($data) {
                $i = AccountInfo::fromArray(self::plain($data));

                return [$i->memory, $i->storage, $i->streams, $i->consumers, $i->limits, $i->apiTotal, $i->apiErrors, $i->domain, $i->raw];
            })(),
            'ApiError' => (function () use ($data) {
                $e = ApiError::fromArray(self::plain($data));

                return [$e->code, $e->errCode, $e->description];
            })(),
            'PubAck' => self::pubAck(PubAck::fromArray(self::plain($data))),
            default => throw new Fault("unknown type {$type}"),
        };
    }

    private static function metadata(MsgMetadata $m): array
    {
        return ['stream' => $m->stream, 'consumer' => self::norm($m->consumer), 'delivered' => $m->numDelivered, 'stream_seq' => $m->streamSequence, 'consumer_seq' => $m->consumerSequence, 'timestamp' => $m->timestamp, 'pending' => $m->numPending, 'domain' => $m->domain];
    }

    private static function streamMessage(StreamMessage $m): array
    {
        return ['subject' => $m->subject, 'seq' => $m->sequence, 'data' => $m->data, 'time' => $m->time !== null, 'headers' => self::headers($m->headers)];
    }

    private static function streamState(StreamState $s, bool $times = false): array
    {
        $out = ['messages' => $s->messages, 'bytes' => $times ? $s->bytes : $s->bytes > 0, 'first_seq' => $s->firstSeq, 'last_seq' => $s->lastSeq, 'consumer_count' => $s->consumerCount, 'num_deleted' => $s->numDeleted, 'num_subjects' => $s->numSubjects];
        if ($times) {
            $out['first_ts'] = $s->firstTs;
            $out['last_ts'] = $s->lastTs;
        }

        return $out;
    }

    private static function streamInfo(StreamInfo $i): array
    {
        return ['config' => $i->config->toArray(), 'state' => self::streamState($i->state), 'created' => $i->created !== ''];
    }

    private static function sequence(SequenceInfo $s, bool $times = false): array
    {
        $out = ['consumer_seq' => $s->consumerSeq, 'stream_seq' => $s->streamSeq];
        if ($times) {
            $out['last_active'] = $s->lastActive;
        }

        return $out;
    }

    private static function consumerInfo(ConsumerInfo $i, bool $raw = false): array
    {
        $config = $i->config->toArray();
        if (!$raw) {
            foreach (['name', 'durable_name', 'deliver_subject'] as $k) {
                if (isset($config[$k])) {
                    $config[$k] = self::norm($config[$k]);
                }
            }
        }

        return [
            'stream_name' => $i->streamName, 'name' => $raw ? $i->name : self::norm($i->name), 'config' => $config, 'created' => $raw ? $i->created : $i->created !== '',
            'num_ack_pending' => $i->numAckPending, 'num_redelivered' => $i->numRedelivered, 'num_waiting' => $i->numWaiting, 'num_pending' => $i->numPending,
            'delivered' => self::sequence($i->delivered, $raw), 'ack_floor' => self::sequence($i->ackFloor, $raw), 'push_bound' => $i->pushBound, 'cluster' => $i->cluster, 'metadata' => $i->metadata,
        ];
    }

    private static function pubAck(PubAck $a): array
    {
        return ['stream' => $a->stream, 'seq' => $a->sequence, 'domain' => $a->domain, 'duplicate' => $a->duplicate];
    }

    private static function stream(Session $s, Stream $stream): array
    {
        return ['stream' => $s->handle($stream), 'info' => self::streamInfo($stream->info())];
    }

    private static function consumer(Session $s, Consumer $consumer): array
    {
        return ['consumer' => $s->handle($consumer), 'info' => self::consumerInfo($consumer->info())];
    }

    private static function jsMessage(JetStreamMessage $m): array
    {
        return ['subject' => $m->getSubject(), 'data' => $m->getData(), 'headers' => self::headers($m->getHeaders()), 'reply' => $m->message->replyTo !== null];
    }

    private static function batch(Session $s, MessageBatch $batch): array
    {
        $messages = [];
        foreach ($batch as $m) {
            $messages[] = ['handle' => $s->handle($m)] + self::jsMessage($m);
        }

        return ['count' => \count($batch), 'listed' => \count($batch->getMessages()), 'messages' => $messages];
    }

    private static function entry(KeyValueEntry $e): array
    {
        return ['bucket' => $e->bucket, 'key' => $e->key, 'value' => $e->value, 'revision' => $e->revision, 'created' => $e->created !== null, 'operation' => $e->operation->value];
    }

    private static function objMeta(ObjectMeta $m): array
    {
        $out = $m->toArray();
        if (isset($out['nuid']) && $out['nuid'] !== '') {
            $out['nuid'] = '*';
        }
        if (isset($out['mtime'])) {
            $out['mtime'] = '*';
        }

        return $out;
    }
}
