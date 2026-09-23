<?php

declare(strict_types=1);

namespace Utopia\Tests\Client;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Pool;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as Connections;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;

final class PoolTest extends TestCase
{
    public function testItBorrowsAConnectionToSendARequest(): void
    {
        $pool = new Pool($this->connections(fn(): \Utopia\Tests\Client\FakeClient => new FakeClient(200)));

        $this->assertSame(200, $pool->sendRequest($this->request())->getStatusCode());
    }

    public function testItBorrowsAConnectionToStreamARequest(): void
    {
        $pool = new Pool($this->connections(fn(): \Utopia\Tests\Client\FakeClient => new FakeClient(200)));
        $received = '';

        $response = $pool->stream($this->request(), function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame('chunk', $received);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testItReclaimsTheConnectionSoItCanBeReused(): void
    {
        $created = 0;
        $pool = new Pool($this->connections(function () use (&$created): FakeClient {
            $created++;

            return new FakeClient(200);
        }, size: 1));

        $pool->sendRequest($this->request());
        $pool->sendRequest($this->request());

        $this->assertSame(1, $created);
    }

    /**
     * Mirrors how a caller builds a pool: a factory returning a concrete client
     * type flows through to `new Pool()` without needing the intersection type.
     *
     * @template T of \Psr\Http\Client\ClientInterface&StreamingClientInterface
     *
     * @param \Closure(): T $init
     *
     * @return Connections<T>
     */
    private function connections(\Closure $init, int $size = 4): Connections
    {
        return new Connections(new Stack(), 'test', $size, $init, timeout: 0.0);
    }

    private function request(): RequestInterface
    {
        return new Request\Factory()->createRequest(Method::GET, 'https://example.com');
    }
}

final readonly class FakeClient implements \Psr\Http\Client\ClientInterface, StreamingClientInterface
{
    public function __construct(private int $status) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response($this->status);
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $sink('chunk');

        return new Response($this->status);
    }
}
