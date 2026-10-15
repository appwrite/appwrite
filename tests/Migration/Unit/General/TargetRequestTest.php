<?php

namespace Utopia\Tests\Unit\General;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Tests\Unit\Network\Server;

final class TargetRequestTest extends TestCase
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

    public function testRedirectIsNotFollowedByDefault(): void
    {
        $target = $this->target($this->server->getURL());

        try {
            $target->request('GET', '/redirect?to=/echo');
            $this->fail('Expected the redirect to be refused');
        } catch (\Exception $error) {
            $this->assertSame(302, $error->getCode());
        }

        $requests = $this->server->getRequests();
        $this->assertCount(1, $requests, \implode("\n", $requests));
        $this->assertStringStartsWith('GET /redirect?', $requests[0]);
    }

    public function testRedirectIsFollowedWhenEnabled(): void
    {
        $target = $this->target($this->server->getURL())->setFollowRedirects(true);

        $response = $target->request('GET', '/redirect?to=/echo');

        $this->assertIsArray($response);
        $this->assertSame('/echo', $response['uri']);
        $this->assertCount(2, $this->server->getRequests());
    }

    public function testFollowedRedirectToAnotherProtocolIsRefused(): void
    {
        $target = $this->target($this->server->getURL())->setFollowRedirects(true);

        $this->expectException(\Exception::class);

        $target->request('GET', '/redirect?to='.\rawurlencode('file:///etc/hosts'));
    }

    public function testResolverPinsTheConnection(): void
    {
        $port = $this->server->getPort();
        $urls = [];
        $target = $this->target('http://pinned.invalid:'.$port)->setResolver(function (string $url) use ($port, &$urls): array {
            $urls[] = $url;

            return ['pinned.invalid:'.$port.':127.0.0.1'];
        });

        $response = $target->request('GET', '/echo', params: ['a' => 'b']);

        $this->assertIsArray($response);
        $this->assertSame('pinned.invalid:'.$port, $response['host']);
        $this->assertSame(['http://pinned.invalid:'.$port.'/echo?a=b'], $urls);
    }

    public function testResolverBypassesTheEnvironmentProxy(): void
    {
        $port = $this->server->getPort();
        $target = $this->target('http://pinned.invalid:'.$port)->setResolver(fn (string $url): array => ['pinned.invalid:'.$port.':127.0.0.1']);

        \putenv('http_proxy=http://127.0.0.1:1');

        try {
            $response = $target->request('GET', '/echo');
        } finally {
            \putenv('http_proxy');
        }

        $this->assertIsArray($response);
        $this->assertSame('pinned.invalid:'.$port, $response['host']);
    }

    public function testResolverCanRefuseTheURL(): void
    {
        $target = $this->target($this->server->getURL())->setResolver(function (string $url): array {
            throw new \Exception('Refused');
        });

        try {
            $target->request('GET', '/echo');
            $this->fail('Expected the resolver to refuse the request');
        } catch (\Exception $error) {
            $this->assertSame('Refused', $error->getMessage());
        }

        $this->assertSame([], $this->server->getRequests());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protocols(): iterable
    {
        yield 'gopher' => ['gopher://127.0.0.1:1/'];
        yield 'dict' => ['dict://127.0.0.1:1/'];
        yield 'file' => ['file:///etc/hosts'];
    }

    #[DataProvider('protocols')]
    public function testOnlyHttpProtocolsAreAllowed(string $endpoint): void
    {
        try {
            $this->target($endpoint)->request('GET', '');
            $this->fail('Expected '.$endpoint.' to be refused');
        } catch (\Exception $error) {
            $this->assertInstanceOf(ClientExceptionInterface::class, $error->getPrevious());
        }
    }

    private function target(string $endpoint): RequestTarget
    {
        return new RequestTarget($endpoint);
    }
}
