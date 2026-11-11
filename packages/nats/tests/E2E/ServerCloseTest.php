<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Swoole\Runtime;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Transport\SwooleTransport;
use Utopia\NATS\Transport\Transport;

use function Swoole\Coroutine\run;

/**
 * A parked client whose server enters lame-duck mode and then drops the
 * connection receives INFO {"ldm":true} followed by EOF, with no -ERR, while
 * nothing is reading the socket. The next operation must notice the close and
 * reconnect instead of writing into the dead socket and failing.
 */
final class ServerCloseTest extends TestCase
{
    private const string SUBJECT = '$JS.API.INFO';
    private const float CLOSE_TIMEOUT = 2.0;
    private const int RELAY_GRACE = 100_000;

    private string $url;
    private string $host;
    private int $port;
    private int $lameDucks = 0;
    private int $reconnects = 0;

    protected function setUp(): void
    {
        $this->url = getenv('NATS_SYSTEM_URL') ?: 'nats://127.0.0.1:14225';
        $this->host = parse_url($this->url, PHP_URL_HOST) ?: '127.0.0.1';
        $this->port = parse_url($this->url, PHP_URL_PORT) ?: 14225;

        $probe = @fsockopen($this->host, $this->port, $errno, $errstr, 1.0);
        if ($probe === false) {
            $this->markTestSkipped("System account server not reachable at {$this->url}");
        }
        fclose($probe);
    }

    public function testRequestAfterServerCloseReconnects(): void
    {
        $this->assertRecovers(fn (Connection $client) => $client->request(self::SUBJECT));
    }

    public function testFlushAfterServerCloseReconnects(): void
    {
        $this->assertRecovers(fn (Connection $client) => $client->flush());
    }

    public function testRequestAfterServerCloseReconnectsWithHookedRuntime(): void
    {
        $this->requireSwoole();

        $flags = Runtime::getHookFlags();
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            $this->runInCoroutine(fn () => $this->assertRecovers(
                fn (Connection $client) => $client->request(self::SUBJECT),
            ));
        } finally {
            Runtime::setHookFlags($flags);
        }
    }

    public function testRequestAfterServerCloseReconnectsWithSwooleTransport(): void
    {
        $this->requireSwoole();

        $this->runInCoroutine(fn () => $this->assertRecovers(
            fn (Connection $client) => $client->request(self::SUBJECT),
            fn (): Transport => new SwooleTransport(),
        ));
    }

    /**
     * @param \Closure(Connection): mixed $operation
     * @param (\Closure(string): Transport)|null $transportFactory
     */
    private function assertRecovers(\Closure $operation, ?\Closure $transportFactory = null): void
    {
        $client = Connection::connect(new ConnectionOptions(
            servers: $this->url,
            transportFactory: $transportFactory,
            onReconnect: function (): void {
                $this->reconnects++;
            },
            onLameDuck: function (): void {
                $this->lameDucks++;
            },
        ));
        $system = Connection::connect(new ConnectionOptions(servers: $this->url, user: 'sys', pass: 'sys'));
        $probe = $this->openProbe();

        try {
            $this->assertStringContainsString('account_info_response', $client->request(self::SUBJECT)->data);

            $server = $client->getServerInfo()->serverId;
            $clientId = $client->getServerInfo()->clientId;
            $this->assertNotNull($clientId, 'The server must assign the client an id');

            $this->closeFromServer($system, $server, $clientId);
            $this->closeFromServer($system, $server, $probe['clientId']);
            $this->awaitClose($probe['socket']);

            $operation($client);

            $this->assertSame(1, $this->lameDucks, 'The lame-duck INFO must be processed');
            $this->assertSame(1, $this->reconnects, 'The closed connection must be replaced');
            $this->assertTrue($client->isConnected());
            $this->assertStringContainsString('account_info_response', $client->request(self::SUBJECT)->data);
        } finally {
            fclose($probe['socket']);
            $system->close();
            $client->close();
        }
    }

    /**
     * Send the lame-duck INFO to one connection and then kick it: the byte
     * sequence a client sees from a server shutting down in lame-duck mode.
     */
    private function closeFromServer(Connection $system, string $server, int $clientId): void
    {
        $payload = json_encode(['cid' => $clientId], JSON_THROW_ON_ERROR);
        foreach (['LDM', 'KICK'] as $verb) {
            $response = json_decode(
                $system->request("\$SYS.REQ.SERVER.{$server}.{$verb}", $payload)->data,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertIsArray($response);
            $this->assertArrayNotHasKey('error', $response, "{$verb} of client {$clientId} must succeed");
        }
    }

    /**
     * A raw connection kicked after the client: once it reads EOF, the
     * server has closed the client too.
     *
     * @return array{socket: resource, clientId: int}
     */
    private function openProbe(): array
    {
        $socket = stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, self::CLOSE_TIMEOUT);
        $this->assertNotFalse($socket, "Probe connect failed: {$errstr}");
        stream_set_timeout($socket, (int) self::CLOSE_TIMEOUT);

        $info = (string) fgets($socket);
        $this->assertStringStartsWith('INFO ', $info);
        $fields = json_decode(substr($info, 5), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($fields);
        $this->assertIsInt($fields['client_id'] ?? null);

        fwrite($socket, "CONNECT {\"verbose\":false,\"protocol\":1}\r\nPING\r\n");
        $this->assertSame("PONG\r\n", fgets($socket));

        return ['socket' => $socket, 'clientId' => $fields['client_id']];
    }

    /** @param resource $socket */
    private function awaitClose($socket): void
    {
        stream_set_timeout($socket, 0, 100_000);
        $deadline = microtime(true) + self::CLOSE_TIMEOUT;
        while (!feof($socket) && microtime(true) < $deadline) {
            fread($socket, 65536);
        }
        $this->assertTrue(feof($socket), 'The server must close the kicked probe');

        // Port-publishing proxies (docker-proxy, Docker Desktop) relay each
        // connection independently, so the client's close can trail the probe's.
        usleep(self::RELAY_GRACE);
    }

    private function requireSwoole(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required');
        }
    }

    /** @param \Closure(): void $test */
    private function runInCoroutine(\Closure $test): void
    {
        $failure = null;
        run(function () use ($test, &$failure): void {
            try {
                $test();
            } catch (\Throwable $error) {
                $failure = $error;
            }
        });
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }
}
