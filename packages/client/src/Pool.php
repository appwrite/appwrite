<?php

declare(strict_types=1);

namespace Utopia\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Pools\Pool as Connections;

/**
 * A client that borrows a pooled client for the duration of each request and
 * reclaims it once the request completes, so concurrent callers share a bounded
 * set of underlying connections. The pool's resources must themselves be both a
 * PSR-18 and a streaming client.
 *
 * @template T of ClientInterface&StreamingClientInterface
 */
final readonly class Pool implements ClientInterface, StreamingClientInterface
{
    /**
     * @param Connections<T> $connections
     */
    public function __construct(
        private Connections $connections,
    ) {
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->lease($request, fn (ClientInterface $client): ResponseInterface => $client->sendRequest($request));
    }

    /**
     * @param callable(string): void $sink
     *
     * @throws ClientExceptionInterface
     */
    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return $this->lease($request, fn (StreamingClientInterface $client): ResponseInterface => $client->stream($request, $sink));
    }

    /**
     * @param callable(T): ResponseInterface $callback
     *
     * @throws ClientExceptionInterface
     */
    private function lease(RequestInterface $request, callable $callback): ResponseInterface
    {
        try {
            return $this->connections->use($callback);
        } catch (\Exception $exception) {
            // The pool reports having no free connection as a plain Exception, which callers can't tell from a request failure.
            if ($exception::class !== \Exception::class) {
                throw $exception;
            }

            throw new ConnectionException($request, $exception->getMessage(), 0, $exception);
        }
    }
}
