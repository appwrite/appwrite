<?php

namespace Utopia\Migration\Tests\Sources;

use Appwrite\AppwriteException;
use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Sources\Appwrite\Client;
use Utopia\Migration\Tests\Network\Server;

final class AppwriteClientTest extends TestCase
{
    private Server $server;

    #[Override]
    protected function setUp(): void
    {
        $this->server = new Server();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testRedirectIsNotFollowed(): void
    {
        $client = $this->client($this->server->getURL());

        try {
            $client->call(Client::METHOD_GET, '/redirect', params: ['to' => '/echo']);
            $this->fail('Expected the redirect to be refused');
        } catch (AppwriteException $error) {
            $this->assertSame(302, $error->getCode());
        }

        $requests = $this->server->getRequests();
        $this->assertCount(1, $requests, \implode("\n", $requests));
        $this->assertStringStartsWith('GET /redirect?', $requests[0]);
    }

    public function testLocationResponseReturnsTheRedirectTarget(): void
    {
        $client = $this->client($this->server->getURL());

        $location = $client->call(Client::METHOD_GET, '/redirect', params: ['to' => '/echo'], responseType: 'location');

        $this->assertSame('/echo', $location);
        $this->assertCount(1, $this->server->getRequests());
    }

    public function testResolverPinsTheConnection(): void
    {
        $port = $this->server->getPort();
        $endpoint = 'http://pinned.invalid:'.$port;
        $urls = [];

        $client = $this->client($endpoint)->setResolver(function (string $url) use ($port, &$urls): array {
            $urls[] = $url;

            return ['pinned.invalid:'.$port.':127.0.0.1'];
        });

        $first = $client->call(Client::METHOD_GET, '/echo', params: ['a' => 'b']);
        $second = $client->call(Client::METHOD_GET, '/echo');

        $this->assertIsArray($first);
        $this->assertSame('pinned.invalid:'.$port, $first['host']);
        $this->assertSame('/echo?a=b', $first['uri']);
        $this->assertIsArray($second);
        $this->assertSame('pinned.invalid:'.$port, $second['host']);
        $this->assertSame([$endpoint], $urls);
    }

    public function testResolverBypassesTheEnvironmentProxy(): void
    {
        $port = $this->server->getPort();
        $client = $this->client('http://pinned.invalid:'.$port)->setResolver(fn (string $url): array => ['pinned.invalid:'.$port.':127.0.0.1']);

        \putenv('http_proxy=http://127.0.0.1:1');

        try {
            $response = $client->call(Client::METHOD_GET, '/echo');
        } finally {
            \putenv('http_proxy');
        }

        $this->assertIsArray($response);
        $this->assertSame('pinned.invalid:'.$port, $response['host']);
    }

    public function testResolverRunsAgainWhenTheEndpointChanges(): void
    {
        $port = $this->server->getPort();
        $urls = [];

        $client = $this->client('http://first.invalid:'.$port)->setResolver(function (string $url) use ($port, &$urls): array {
            $urls[] = $url;
            $host = \parse_url($url, PHP_URL_HOST);

            return [$host.':'.$port.':127.0.0.1'];
        });

        $client->call(Client::METHOD_GET, '/echo');
        $client->setEndpoint('http://second.invalid:'.$port);
        $response = $client->call(Client::METHOD_GET, '/echo');

        $this->assertIsArray($response);
        $this->assertSame('second.invalid:'.$port, $response['host']);
        $this->assertSame(['http://first.invalid:'.$port, 'http://second.invalid:'.$port], $urls);
    }

    public function testResolverCanRefuseTheEndpoint(): void
    {
        $client = $this->client($this->server->getURL())->setResolver(function (string $url): array {
            throw new \Exception('Refused');
        });

        try {
            $client->call(Client::METHOD_GET, '/echo');
            $this->fail('Expected the resolver to refuse the request');
        } catch (\Exception $error) {
            $this->assertSame('Refused', $error->getMessage());
        }

        $this->assertSame([], $this->server->getRequests());
    }

    public function testSourcePassesItsResolverToTheClient(): void
    {
        $port = $this->server->getPort();
        $source = new Appwrite(
            'project',
            'http://pinned.invalid:'.$port.'/v1',
            'key',
            fn () => throw new \Exception('No database'),
        );
        $source->setResolver(fn (string $url): array => ['pinned.invalid:'.$port.':127.0.0.1']);

        try {
            $source->report([Resource::TYPE_USER]);
            $this->fail('Expected the fixture to answer 404');
        } catch (\Exception $error) {
            $this->assertSame(404, $error->getCode());
        }

        $requests = $this->server->getRequests();
        $this->assertCount(1, $requests, \implode("\n", $requests));
        $this->assertStringStartsWith('GET /v1/users', $requests[0]);
        $this->assertStringEndsWith(' pinned.invalid:'.$port, $requests[0]);
    }

    private function client(string $endpoint): Client
    {
        $client = new Client();
        $client->setEndpoint($endpoint);

        return $client;
    }
}
