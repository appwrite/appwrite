<?php

declare(strict_types=1);

namespace Tests\Compat\Agents;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Fault;
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client as HttpClient;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;

/**
 * Keeps every request an adapter sends, as the adapter built it. Its twin is
 * `Recorded` in crates/tools/compat/src/libs/agents.rs.
 */
abstract class Recording implements ClientInterface, StreamingClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * The requests, each as {method, uri, headers: [[name, line]], body}. The
     * Host header PSR-7 derives from the URI is left out: the URI is there.
     *
     * @return list<array<string, mixed>>
     */
    public function recorded(): array
    {
        $out = [];
        foreach ($this->requests as $request) {
            $headers = [];
            foreach (array_keys($request->getHeaders()) as $name) {
                if (strcasecmp((string) $name, 'Host') !== 0) {
                    $headers[] = [(string) $name, $request->getHeaderLine((string) $name)];
                }
            }

            $out[] = [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'headers' => $headers,
                'body' => (string) $request->getBody(),
            ];
        }

        return $out;
    }
}

/**
 * Answers from a queue of canned responses, delivering streamed bodies to the
 * sink in the given chunks, as tests/Adapters/Client.php of the library does.
 */
final class Queue extends Recording
{
    /**
     * @param list<array{status?: int, body?: string, chunks?: list<string>}> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = $this->next();

        return new Response($response['status'] ?? 200, body: new Stream($response['body'] ?? ''));
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $this->requests[] = $request;
        $response = $this->next();
        foreach ($response['chunks'] ?? [] as $chunk) {
            $sink($chunk);
        }

        return new Response($response['status'] ?? 200);
    }

    /**
     * @return array{status?: int, body?: string, chunks?: list<string>}
     */
    private function next(): array
    {
        return array_shift($this->responses) ?? throw new Fault('no response queued for this request');
    }
}

/**
 * Sends every request to the `agents-http` fixture server instead of the
 * provider: the URI becomes `<fixture>/serve/<ns><path>?<query>`, sent by the
 * client an adapter builds for itself (keep-alive cURL with the timeout).
 */
final class Forward extends Recording
{
    private HttpClient $client;

    public function __construct(private string $base, int $timeout)
    {
        $this->client = new HttpClient((new Curl())->withConnectionReuse()->withTimeout($timeout / 1000));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->client->sendRequest($this->redirect($request));
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->client->stream($this->redirect($request), $sink);
    }

    private function redirect(RequestInterface $request): RequestInterface
    {
        $uri = $request->getUri();
        $target = $this->base . $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

        return $request->withUri(Uri::parse($target));
    }
}
