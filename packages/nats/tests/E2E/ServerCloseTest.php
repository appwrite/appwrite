<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Swoole\Runtime;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Tests\Support\ReadAheadTransport;
use Utopia\NATS\Transport\SwooleTransport;
use Utopia\NATS\Transport\TcpTransport;
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

    private string $url;
    private int $lameDucks = 0;
    private int $reconnects = 0;

    protected function setUp(): void
    {
        $this->url = getenv('NATS_SYSTEM_URL') ?: 'nats://127.0.0.1:14226';
        $host = parse_url($this->url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($this->url, PHP_URL_PORT) ?: 14226;

        $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($socket === false) {
            $this->markTestSkipped("System account server not reachable at {$this->url}");
        }
        fclose($socket);
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
            fn (): SwooleTransport => new SwooleTransport(),
        ));
    }

    /**
     * @param \Closure(Connection): mixed $operation
     * @param (\Closure(): Transport)|null $transport
     */
    private function assertRecovers(\Closure $operation, ?\Closure $transport = null): void
    {
        $transport ??= static fn (): TcpTransport => new TcpTransport();
        /** @var list<ReadAheadTransport> $transports */
        $transports = [];
        $client = Connection::connect(new ConnectionOptions(
            servers: $this->url,
            transportFactory: static function () use ($transport, &$transports): Transport {
                return $transports[] = new ReadAheadTransport($transport());
            },
            onReconnect: function (): void {
                $this->reconnects++;
            },
            onLameDuck: function (): void {
                $this->lameDucks++;
            },
        ));
        $system = Connection::connect(new ConnectionOptions(servers: $this->url, user: 'sys', pass: 'sys'));

        try {
            $this->assertStringContainsString('account_info_response', $client->request(self::SUBJECT)->data);

            $server = $client->getServerInfo()->serverId;
            $clientId = $client->getServerInfo()->clientId;
            $this->assertNotNull($clientId, 'The server must assign the client an id');

            $this->closeFromServer($system, $server, $clientId);
            $this->assertTrue($transports[0]->awaitClose(self::CLOSE_TIMEOUT), 'The server must close the client');

            $operation($client);

            $this->assertSame(1, $this->lameDucks, 'The lame-duck INFO must be processed');
            $this->assertSame(1, $this->reconnects, 'The closed connection must be replaced');
            $this->assertTrue($client->isConnected());
            $this->assertStringContainsString('account_info_response', $client->request(self::SUBJECT)->data);
        } finally {
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
