<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

/**
 * Drives Adapter::call() against a local `php -S` server.
 */
final class AdapterCallTest extends TestCase
{
    /** @var resource|null */
    private $server;

    private string $endpoint = '';

    private function serve(string $router): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            $this->markTestSkipped('No free local port');
        }
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->endpoint = 'http://' . $address;
        $server = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/Fixtures/' . $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->server = \is_resource($server) ? $server : null;

        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(100_000);
        }

        $this->fail('The local server did not start');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testServerErrorDoesNotWriteRequestParamsToOutput(): void
    {
        $this->serve('server-error.php');
        $adapter = new ProviderGitea(new Cache(new None()));
        $adapter->setEndpoint($this->endpoint);

        $this->expectOutputString('');

        $response = $adapter->request('POST', '/repos/owner/repository/hooks', [
            'config' => ['url' => 'https://example.com/hook', 'secret' => 'webhook-secret'],
        ]);

        $this->assertIsArray($response['headers']);
        $this->assertSame(500, $response['headers']['status-code']);
        $this->assertSame(['message' => 'Internal Server Error'], $response['body']);
    }

    public function testRequestNamesClientAsUserAgent(): void
    {
        $this->serve('server-echo.php');
        $adapter = new ProviderGitea(new Cache(new None()));
        $adapter->setEndpoint($this->endpoint);

        $response = $adapter->request('GET', '/user', []);

        $this->assertIsArray($response['body']);
        $this->assertSame(ProviderGitea::USER_AGENT, $response['body']['user-agent'] ?? null);
    }

    public function testBitbucketRequestSkipsXsrfCheck(): void
    {
        $this->serve('server-echo.php');
        $adapter = new ProviderBitbucket(new Cache(new None()));
        $adapter->setEndpoint($this->endpoint);

        $response = $adapter->request('POST', '/repositories/owner/repository/hooks', [
            'url' => 'https://example.com/hook',
        ]);

        $this->assertIsArray($response['body']);
        $this->assertSame('no-check', $response['body']['x-atlassian-token'] ?? null);
        $this->assertSame('application/json', $response['body']['content-type'] ?? null);
    }
}
