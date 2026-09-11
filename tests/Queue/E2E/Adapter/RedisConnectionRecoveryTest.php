<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Connection\Redis;

/**
 * A Redis connection must survive the server going away and coming back.
 *
 * Talks to the compose redis (REDIS_URL, default redis://127.0.0.1:16379)
 * through a TCP proxy the test can stop and start, which is what a broker
 * failover looks like from a worker: the socket closes, reconnects are
 * refused for a while, then the address serves again.
 */
final class RedisConnectionRecoveryTest extends TestCase
{
    private string $redisHost;
    private int $redisPort;
    private int $proxyPort;
    private string $controlFile;

    /** @var resource|null */
    private $proxy;

    protected function setUp(): void
    {
        $url = getenv('REDIS_URL') ?: 'redis://127.0.0.1:16379';
        $this->redisHost = parse_url($url, PHP_URL_HOST) ?: '127.0.0.1';
        $this->redisPort = parse_url($url, PHP_URL_PORT) ?: 16379;
        $this->proxyPort = $this->freePort();
        $this->controlFile = sys_get_temp_dir() . '/queue-proxy-' . $this->proxyPort;

        $this->startProxy();
    }

    protected function tearDown(): void
    {
        $this->stopProxy();
        @unlink($this->controlFile);
    }

    public function testCommandsRecoverOnceTheServerIsBack(): void
    {
        $connection = new Redis('127.0.0.1', $this->proxyPort);
        $key = 'tests.recovery.' . uniqid();

        $this->assertTrue($connection->set($key, 'before'));

        $this->stopProxy();

        try {
            $connection->remove($key);
            $this->fail('a command with the server gone must fail');
        } catch (\RedisException) {
        }

        $this->startProxy();

        $this->assertTrue($connection->remove($key), 'the first command after the server is back must succeed');
        $this->assertTrue($connection->set($key, 'after'));
        $this->assertSame('after', $connection->get($key), 'later commands must keep working');

        $connection->remove($key);
    }

    public function testPushIsNotReplayedWhenItsReplyIsLost(): void
    {
        // A read timeout, or a client whose reply never comes waits forever.
        $connection = new Redis('127.0.0.1', $this->proxyPort, readTimeout: 1);
        $queue = 'tests.recovery.queue.' . uniqid();

        $this->assertTrue($connection->leftPush($queue, 'one'));

        $this->loseNextReply();

        try {
            $connection->leftPush($queue, 'two');
            $this->fail('a push whose reply was lost must fail');
        } catch (\RedisException) {
        }

        $this->assertSame(2, $connection->listSize($queue), 'the server ran the push once; a recovery must not run it again');

        $connection->remove($queue);
    }

    /**
     * Make the proxy forward the next client chunk and then close the client
     * socket before the reply: the server executes the command, the client
     * cannot know that it did.
     */
    private function loseNextReply(): void
    {
        touch($this->controlFile);
    }

    private function startProxy(): void
    {
        $script = __DIR__ . '/../../servers/Proxy/proxy.php';
        $command = [PHP_BINARY, $script, (string) $this->proxyPort, $this->redisHost, (string) $this->redisPort, $this->controlFile];
        $this->proxy = proc_open($command, [], $pipes);

        $this->assertIsResource($this->proxy, 'proxy must start');
        $this->waitFor(fn(): bool => $this->portAccepts($this->proxyPort), 'proxy to listen');
    }

    private function stopProxy(): void
    {
        if (!\is_resource($this->proxy)) {
            return;
        }

        proc_terminate($this->proxy, 9);
        proc_close($this->proxy);
        $this->proxy = null;

        $this->waitFor(fn(): bool => !$this->portAccepts($this->proxyPort), 'proxy port to close');
    }

    private function portAccepts(int $port): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }

    private function waitFor(callable $condition, string $what): void
    {
        for ($i = 0; $i < 100; $i++) {
            if ($condition()) {
                return;
            }

            usleep(20_000);
        }

        $this->fail("timed out waiting for {$what}");
    }
}
