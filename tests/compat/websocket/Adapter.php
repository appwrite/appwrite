<?php

namespace Tests\Compat\Websocket;

require_once __DIR__ . '/Memory.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Coroutine\Http\Client as SwooleClient;
use Swoole\Coroutine\Socket;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as NativeServer;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\WebSocket\Client;
use Utopia\WebSocket\Server;

use function Swoole\Coroutine\run;

/**
 * A server on the in-process adapter, with what its hooks recorded.
 */
final class Rig
{
    /** @var \ArrayObject<int, mixed> */
    public \ArrayObject $log;

    /** @var array<int, true> Connections the open hook saw, for broadcasts. */
    public array $connections = [];

    public int $errors = 0;

    public function __construct(public Memory $adapter, public Server $server)
    {
        $this->log = new \ArrayObject();
    }
}

/**
 * A client with what its hooks (and its scripted transport) recorded.
 */
final class Probe
{
    /** @var \ArrayObject<int, mixed> */
    public \ArrayObject $log;

    public function __construct(public Client $client)
    {
        $this->log = new \ArrayObject();
    }
}

/**
 * A scripted connection: recv() returns the next scripted item (then fails
 * with SWOOLE_ERROR_CLIENT_NO_CONNECTION), push() and close() are recorded.
 * The twin of `Scripted` in crates/tools/compat/src/libs/websocket.rs.
 */
final class Scripted extends SwooleClient
{
    /**
     * @param list<mixed> $script
     * @param \ArrayObject<int, mixed> $log
     * @param array{0: int, 1: string}|null $pushFail
     */
    public function __construct(private array $script, private \ArrayObject $log, private ?array $pushFail)
    {
        parent::__construct('127.0.0.1', 1);
    }

    public function recv(float $timeout = 0): Frame|string|bool
    {
        $item = array_shift($this->script);
        if ($item === null) {
            $this->errCode = SWOOLE_ERROR_CLIENT_NO_CONNECTION;
            $this->errMsg = '';

            return false;
        }
        if (\is_array($item) && isset($item['opcode'])) {
            $frame = new Frame();
            $frame->opcode = $item['opcode'];
            $frame->data = $item['data'] ?? '';

            return $frame;
        }
        if (\is_array($item) && isset($item['code'])) {
            $this->errCode = $item['code'];
            $this->errMsg = $item['message'] ?? '';

            return false;
        }

        return '';
    }

    public function push(mixed $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $this->log[] = ['push', $data, $opcode];
        if ($this->pushFail !== null) {
            $this->errCode = $this->pushFail[0];
            $this->errMsg = $this->pushFail[1];

            return false;
        }

        return true;
    }

    public function close(): bool
    {
        $this->log[] = ['transport.close'];

        return true;
    }
}

/**
 * Maps tests/compat/websocket/spec.json operations onto utopia-php/websocket:
 * servers on the in-process adapter (Memory.php), clients on scripted
 * connections, and E2E runs against the Swoole adapter (server.php). Glue
 * only.
 */
final class Adapter implements Base, Benchmarks
{
    /** Benchmark inputs, the same as in crates/tools/compat/src/libs/websocket.rs. */
    private const string URL = 'wss://cloud.appwrite.io/v1/realtime?project=6650c0de&channels%5B%5D=account';

    private const int FANOUT = 1000;

    public function benchmarks(): array
    {
        return [
            'dispatch' => ['Deliver a message to the server\'s message hook, which replies to its connection', function (Session $s) {
                $adapter = new Memory();
                $server = new Server($adapter);
                $server->onMessage(function (int $connection, string $message) use ($server): void {
                    $server->send([$connection], 'pong');
                });
                $adapter->open(1, Request::create());

                return function () use ($adapter) {
                    $adapter->message(1, 'ping');
                    $frames = $adapter->frames;
                    $adapter->frames = [];

                    return $frames;
                };
            }],
            'fanout' => ['Send one message to 1,000 open connections', function (Session $s) {
                $adapter = new Memory();
                $server = new Server($adapter);
                $request = Request::create();
                for ($i = 1; $i <= self::FANOUT; $i++) {
                    $adapter->open($i, $request);
                }
                $ids = range(1, self::FANOUT);

                return function () use ($adapter, $server, $ids) {
                    $server->send($ids, '{"events":["databases.*"],"payload":{"$id":"6650c1f2a4b3e9d7c812"}}');
                    $frames = $adapter->frames;
                    $adapter->frames = [];

                    return $frames;
                };
            }],
            'client' => ['Create a client: parse a wss URL with a path and a query', fn (Session $s) => fn () => new Client(self::URL, ['timeout' => 10])],
        ];
    }

    public function operations(): array
    {
        return [
            'server.new' => fn (array $a, Session $s) => $s->handle(self::rig()),
            'server.on' => function (array $a, Session $s) {
                self::on(self::server($a, $s), $a['hook']);

                return;
            },
            'server.error' => function (array $a, Session $s) {
                $rig = self::server($a, $s);
                $index = $rig->errors++;
                $rig->server->error(function (\Throwable $error, string $stage) use ($rig, $index): void {
                    $rig->log[] = ['error', $index, $error::class, $error->getMessage(), $stage];
                });

                return;
            },
            'server.start' => fn (array $a, Session $s) => self::server($a, $s)->server->start(),
            'server.shutdown' => fn (array $a, Session $s) => self::server($a, $s)->server->shutdown(),
            'server.send' => fn (array $a, Session $s) => self::server($a, $s)->server->send($a['connections'], $a['message']),
            'server.close' => fn (array $a, Session $s) => self::server($a, $s)->server->close($a['connection'], $a['code']),
            'server.log' => fn (array $a, Session $s) => self::server($a, $s)->log->getArrayCopy(),

            'memory.fail' => fn (array $a, Session $s) => self::server($a, $s)->adapter->fail($a['stage'], $a['message']),
            'memory.config' => function (array $a, Session $s) {
                $adapter = self::server($a, $s)->adapter;
                if (\array_key_exists('package_max_length', $a)) {
                    $adapter->setPackageMaxLength($a['package_max_length']);
                }
                if (\array_key_exists('compression', $a)) {
                    $adapter->setCompressionEnabled($a['compression']);
                }
                if (\array_key_exists('workers', $a)) {
                    $adapter->setWorkerNumber($a['workers']);
                }

                return $adapter->settings();
            },
            'memory.open' => fn (array $a, Session $s) => self::server($a, $s)->adapter->open($a['connection'], self::request($a['request'] ?? [])),
            'memory.message' => fn (array $a, Session $s) => self::server($a, $s)->adapter->message($a['connection'], $a['data']),
            'memory.disconnect' => fn (array $a, Session $s) => self::server($a, $s)->adapter->disconnect($a['connection']),
            'memory.request' => function (array $a, Session $s) {
                $reply = self::server($a, $s)->adapter->request(self::request($a['request'] ?? []));

                return ['status' => $reply->status, 'body' => $reply->body];
            },
            'memory.state' => fn (array $a, Session $s) => self::state(self::server($a, $s)->adapter),
            'server.dispatch' => function (array $a, Session $s) {
                $rig = self::rig();
                foreach (['start', 'worker_start', 'worker_stop', 'open', 'close', 'message', 'request'] as $hook) {
                    self::on($rig, $hook);
                }
                $log = $rig->log;
                $rig->server->error(function (\Throwable $error, string $stage) use ($log): void {
                    $log[] = ['error', 0, $error::class, $error->getMessage(), $stage];
                });
                $rig->adapter->open(1, self::request([]));
                $rig->adapter->open(2, self::request([]));
                foreach ($a['messages'] as $message) {
                    $rig->adapter->message(1, $message);
                }

                return ['log' => $log->getArrayCopy(), 'state' => self::state($rig->adapter)];
            },
            'client.script' => function (array $a, Session $s) {
                $probe = new Probe(new Client('ws://localhost:8080'));
                self::hooks($probe);
                self::attach($probe, $a['script'], null);
                $probe->client->listen();

                return ['log' => $probe->log->getArrayCopy(), 'connected' => $probe->client->isConnected()];
            },

            'client.new' => function (array $a, Session $s) {
                $client = isset($a['options']) ? new Client($a['url'], self::options((array) $a['options'])) : new Client($a['url']);
                $read = fn (string $name) => (new \ReflectionProperty(Client::class, $name))->getValue($client);
                $headers = [];
                foreach ($read('headers') as $name => $value) {
                    // The name as sent: PHP turns a numeric key into an int.
                    $headers[] = [(string) $name, $value];
                }

                return [
                    'client' => $s->handle(new Probe($client)),
                    'host' => $read('host'),
                    'port' => $read('port'),
                    'path' => $read('path'),
                    'headers' => $headers,
                    'timeout' => $read('timeout'),
                ];
            },
            'client.hooks' => fn (array $a, Session $s) => self::hooks(self::client($a, $s)),
            'client.attach' => fn (array $a, Session $s) => self::attach(self::client($a, $s), $a['script'], $a['push_fail'] ?? null),
            'client.connect' => fn (array $a, Session $s) => self::coroutine(fn () => self::client($a, $s)->client->connect()),
            'client.listen' => fn (array $a, Session $s) => self::client($a, $s)->client->listen(),
            'client.receive' => fn (array $a, Session $s) => self::client($a, $s)->client->receive(),
            'client.send' => fn (array $a, Session $s) => self::client($a, $s)->client->send($a['data']),
            'client.close' => fn (array $a, Session $s) => self::client($a, $s)->client->close(),
            'client.is_connected' => fn (array $a, Session $s) => self::client($a, $s)->client->isConnected(),
            'client.log' => fn (array $a, Session $s) => self::client($a, $s)->log->getArrayCopy(),

            'swoole.config' => function (array $a, Session $s) {
                $out = self::php(['tests/compat/websocket/server.php', 'config', serialize($a)]);
                $result = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
                if (isset($result['error'])) {
                    $class = $result['error']['class'];

                    throw new $class($result['error']['message']);
                }

                return $result['ok'];
            },
            'e2e.run' => fn (array $a, Session $s) => self::e2e($a['steps'], (float) ($a['send_timeout'] ?? 1.0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(Memory $adapter): array
    {
        return [
            'started' => $adapter->started,
            'connections' => array_keys($adapter->open),
            'frames' => $adapter->frames,
            'closed' => $adapter->closed,
        ];
    }

    /**
     * Registers a recording hook for each client event; whether each
     * registration returned the client.
     *
     * @return list<bool>
     */
    private static function hooks(Probe $probe): array
    {
        $log = $probe->log;
        $c = $probe->client;

        return [
            $c->onMessage(function ($data) use ($log): void {
                $log[] = ['message', $data];
            }) === $c,
            $c->onClose(function () use ($log): void {
                $log[] = ['close'];
            }) === $c,
            $c->onError(function (\Throwable $error) use ($log): void {
                $log[] = ['error', $error::class, $error->getMessage()];
            }) === $c,
            $c->onOpen(function () use ($log): void {
                $log[] = ['open'];
            }) === $c,
            $c->onPing(function ($data) use ($log): void {
                $log[] = ['ping', $data];
            }) === $c,
            $c->onPong(function ($data) use ($log): void {
                $log[] = ['pong', $data];
            }) === $c,
        ];
    }

    /**
     * As ClientTest does: a connected client on a stand-in Swoole client.
     *
     * @param list<mixed> $script
     * @param array{0: int, 1: string}|null $pushFail
     */
    private static function attach(Probe $probe, array $script, ?array $pushFail): void
    {
        (new \ReflectionProperty(Client::class, 'connected'))->setValue($probe->client, true);
        (new \ReflectionProperty(Client::class, 'client'))->setValue($probe->client, new Scripted($script, $probe->log, $pushFail));
    }

    private static function rig(): Rig
    {
        $adapter = new Memory();

        return new Rig($adapter, new Server($adapter));
    }

    /**
     * Registers the recording hook named `$hook` (the commands of the E2E
     * fixture for messages).
     */
    private static function on(Rig $rig, string $hook): void
    {
        $log = $rig->log;
        $server = $rig->server;
        match ($hook) {
            'start' => $server->onStart(function () use ($log): void {
                $log[] = ['start'];
            }),
            'worker_start' => $server->onWorkerStart(function (int $worker) use ($log): void {
                $log[] = ['workerStart', $worker];
            }),
            'worker_stop' => $server->onWorkerStop(function (int $worker) use ($log): void {
                $log[] = ['workerStop', $worker];
            }),
            'open' => $server->onOpen(function (int $connection, Request $request) use ($rig, $log): void {
                $rig->connections[$connection] = true;
                $log[] = ['open', $connection, $request->server['request_method'], $request->server['request_uri']];
            }),
            'close' => $server->onClose(function (int $connection) use ($rig, $log): void {
                unset($rig->connections[$connection]);
                $log[] = ['close', $connection];
            }),
            'message' => $server->onMessage(function (int $connection, string $message) use ($rig, $log, $server): void {
                $log[] = ['message', $connection, $message];
                match ($message) {
                    'ping' => $server->send([$connection], 'pong'),
                    'pong' => $server->send([$connection], 'ping'),
                    'broadcast' => $server->send(array_keys($rig->connections), 'broadcast'),
                    'disconnect' => (function () use ($server, $connection): void {
                        $server->send([$connection], 'disconnect');
                        $server->close($connection, 1000);
                    })(),
                    default => str_starts_with($message, 'echo:') ? $server->send([$connection], substr($message, 5)) : null,
                };
            }),
            'request' => $server->onRequest(function (Request $request, Reply $reply) use ($log): void {
                $method = $request->server['request_method'];
                $path = $request->server['request_uri'];
                $log[] = ['request', $method, $path, (string) $request->rawContent()];
                $reply->status = $path === '/missing' ? 404 : 200;
                $reply->body = $method . ' ' . $path;
            }),
            default => throw new Fault("unknown hook {$hook}"),
        };
    }

    /**
     * A request parsed by Swoole from `{method, uri, headers, body}`.
     *
     * @param array<string, mixed> $spec
     */
    private static function request(array $spec): Request
    {
        $body = (string) ($spec['body'] ?? '');
        $head = '';
        foreach ($spec['headers'] ?? [] as [$name, $value]) {
            $head .= "{$name}: {$value}\r\n";
        }
        if ($body !== '') {
            $head .= 'Content-Length: ' . \strlen($body) . "\r\n";
        }
        $request = Request::create();
        $raw = ($spec['method'] ?? 'GET') . ' ' . ($spec['uri'] ?? '/') . " HTTP/1.1\r\n" . $head . "\r\n" . $body;
        if ($request->parse($raw) === false) {
            throw new Fault('swoole cannot parse the request');
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{headers?: array<string, string>, timeout?: float}
     */
    private static function options(array $options): array
    {
        $out = [];
        if (isset($options['headers'])) {
            $out['headers'] = [];
            foreach ($options['headers'] as [$name, $value]) {
                $out['headers'][$name] = $value;
            }
        }
        if (isset($options['timeout'])) {
            $out['timeout'] = $options['timeout'];
        }

        return $out;
    }

    private static function server(array $a, Session $s): Rig
    {
        $rig = $s->get($a['server']);

        return $rig instanceof Rig ? $rig : throw new Fault('not a server');
    }

    private static function client(array $a, Session $s): Probe
    {
        $probe = $s->get($a['client']);

        return $probe instanceof Probe ? $probe : throw new Fault('not a client');
    }

    /**
     * Runs `$fn` in a coroutine and returns its result or rethrows.
     */
    private static function coroutine(callable $fn): mixed
    {
        $result = null;
        $error = null;
        run(function () use ($fn, &$result, &$error): void {
            try {
                $result = $fn();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    /**
     * Runs a PHP script to completion and returns its output.
     *
     * @param list<string> $args
     */
    private static function php(array $args): string
    {
        $process = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/usr/src/code');
        if (!\is_resource($process)) {
            throw new Fault('cannot start php');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return $out;
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
     * Starts server.php on the Swoole adapter, runs the steps against it with
     * clients, and returns what each step observed.
     *
     * @param list<array<string, mixed>> $steps
     * @return list<mixed>
     */
    private static function e2e(array $steps, float $sendTimeout): array
    {
        $port = self::port();
        $process = proc_open([PHP_BINARY, 'tests/compat/websocket/server.php', 'serve', (string) $port, (string) $sendTimeout], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/usr/src/code');
        if (!\is_resource($process)) {
            throw new Fault('cannot start the fixture server');
        }
        $out = [];
        try {
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
                throw new Fault('the fixture server did not start');
            }
            run(function () use ($steps, $port, &$out): void {
                $clients = [];
                $slow = [];
                foreach ($steps as $step) {
                    try {
                        $out[] = self::step($step, $port, $clients, $slow);
                    } catch (\Throwable $e) {
                        $out[] = ['error' => $e::class, 'message' => $e->getMessage()];
                    }
                }
                foreach ($clients as $client) {
                    $client->close();
                }
                foreach ($slow as $socket) {
                    $socket->close();
                }
                self::http($port, '/shutdown');
            });
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                for ($i = 0; $i < 40 && proc_get_status($process)['running']; $i++) {
                    usleep(50_000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
            }
            proc_close($process);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, Client> $clients
     * @param array<string, Socket> $slow
     */
    private static function step(array $step, int $port, array &$clients, array &$slow): mixed
    {
        $kind = (string) array_key_first($step);
        $name = $step[$kind];

        return match ($kind) {
            'connect' => (function () use ($name, $port, &$clients) {
                $clients[$name] = new Client('ws://127.0.0.1:' . $port, ['timeout' => 10]);
                $clients[$name]->connect();

                return;
            })(),
            'send' => $clients[$name]->send($step['data']),
            'receive' => $clients[$name]->receive(),
            'close' => $clients[$name]->close(),
            'connected' => $clients[$name]->isConnected(),
            'http' => self::http($port, $name),
            'batch' => (function () use ($name, $step, $clients, $slow) {
                $target = $clients[$name] ?? $slow[$name];
                foreach (self::messages($step['count'], $step['size']) as $message) {
                    self::frame($target, 'buffer:' . $message);
                }
                self::frame($target, 'flush');

                return;
            })(),
            'wait_batches' => (function () use ($name, $port) {
                $deadline = microtime(true) + 2;
                do {
                    Coroutine::sleep(0.01);
                    $info = json_decode(self::http($port, '/info')['body'], true);
                } while ($info['batches_sent'] < $name && microtime(true) < $deadline);

                return $info['batches_sent'] >= $name;
            })(),
            'drain' => (function () use ($name, $step, $clients) {
                foreach (self::messages($step['count'], $step['size']) as $message) {
                    if ($clients[$name]->receive() !== $message) {
                        return false;
                    }
                }

                return true;
            })(),
            'slow' => (function () use ($name, $port, &$slow) {
                $socket = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
                $socket->setOption(SOL_SOCKET, SO_RCVBUF, 1024);
                $socket->connect('127.0.0.1', $port, 2);
                $socket->sendAll("GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
                $headers = '';
                while (!str_contains($headers, "\r\n\r\n")) {
                    $chunk = $socket->recv(4096, 2);
                    if ($chunk === false || $chunk === '') {
                        return false;
                    }
                    $headers .= $chunk;
                }
                $slow[$name] = $socket;

                return str_contains($headers, '101 Switching Protocols');
            })(),
            'probe' => (function () use ($name, $slow) {
                $socket = $slow[$name];
                $ping = NativeServer::pack('ping', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_MASK);
                $deadline = microtime(true) + 6;
                do {
                    Coroutine::sleep(0.05);
                    if ($socket->sendAll($ping, 0.2) === false) {
                        $error = $socket->errCode;
                        break;
                    }
                    $error = $socket->getOption(SOL_SOCKET, SO_ERROR);
                } while ($error === 0 && microtime(true) < $deadline);

                return \in_array($error, [SOCKET_ECONNRESET, SOCKET_EPIPE], true) ? 'disconnected' : 'alive';
            })(),
            'drop' => (function () use ($name, &$slow) {
                $slow[$name]->close();
                unset($slow[$name]);

                return;
            })(),
            default => throw new Fault("unknown step {$kind}"),
        };
    }

    /**
     * @return list<string>
     */
    private static function messages(int $count, int $size): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = $i . ':' . str_repeat('x', $size);
        }

        return $messages;
    }

    private static function frame(Client|Socket $target, string $message): void
    {
        if ($target instanceof Client) {
            $target->send($message);

            return;
        }
        $target->sendAll(NativeServer::pack($message, WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_MASK));
    }

    /**
     * @return array{status: int, body: string}
     */
    private static function http(int $port, string $path): array
    {
        $http = new HttpClient('127.0.0.1', $port);
        $http->set(['timeout' => 2]);
        $http->get($path);
        $out = ['status' => $http->statusCode, 'body' => (string) $http->body];
        $http->close();

        return $out;
    }
}
