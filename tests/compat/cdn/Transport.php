<?php

namespace Tests\Compat\Cdn;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Fault;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

/**
 * A recorded transport for the providers, the twin of
 * `packages/cdn/tests/TestClient.php`: answers each request with the next
 * queued response (a 500 without a body once the queue is empty, as
 * TestClient does) and keeps every request it was sent. No provider is ever
 * called. Its twin is `Recorded` in crates/tools/compat/src/libs/cdn.rs.
 *
 * A queued `{"error": "<message>"}` fails the send with a PSR-18 client
 * exception instead of answering; `{"json": value}` answers with
 * `json_encode(value)` as the body.
 */
final class Transport implements ClientInterface
{
    /** @var list<array<mixed>> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    /**
     * Responses come from a case's JSON, each an object with any of
     * `status`, `headers`, `body`, `json` (encoded as the body) or `error`.
     *
     * @param list<mixed> $responses
     */
    public function queue(array $responses): void
    {
        foreach ($responses as $response) {
            if (!\is_array($response)) {
                throw new Fault('a queued response must be an object');
            }
            $this->responses[] = $response;
        }
    }

    /**
     * The requests sent since the last call: header names lower-cased and
     * sorted, without `Host`.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string}>
     */
    public function take(): array
    {
        $requests = $this->requests;
        $this->requests = [];

        return $requests;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            if (strtolower((string) $name) !== 'host') {
                $headers[strtolower((string) $name)] = $request->getHeaderLine((string) $name);
            }
        }
        ksort($headers);
        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $headers,
            'body' => (string) $request->getBody(),
        ];

        $next = array_shift($this->responses) ?? ['status' => 500];
        if (isset($next['error'])) {
            throw new class ((string) $next['error']) extends \RuntimeException implements ClientExceptionInterface {
            };
        }
        $body = \array_key_exists('json', $next) ? json_encode($next['json'], JSON_THROW_ON_ERROR) : (string) ($next['body'] ?? '');
        $response = new Response((int) ($next['status'] ?? 200), body: new Stream($body));
        foreach ($next['headers'] ?? [] as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }

        return $response;
    }
}

/**
 * The `cdn-http` fixture server (fixtures/server.php), for providers on the
 * library's default client: queues responses for this run's namespace and
 * reads back the requests it received.
 */
final class Fixture
{
    public function __construct(private readonly string $endpoint, private readonly string $ns)
    {
    }

    /** The API base a provider is given: every request lands in this run's namespace. */
    public function base(): string
    {
        return $this->endpoint . '/' . $this->ns;
    }

    /** @param list<array<string, mixed>> $responses */
    public function queue(array $responses): void
    {
        $this->call('/_queue/' . $this->ns, json_encode($responses, JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    public function take(): array
    {
        return json_decode($this->call('/_take/' . $this->ns, null), true, flags: JSON_THROW_ON_ERROR);
    }

    private function call(string $path, ?string $body): string
    {
        $context = stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $body ?? '',
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($this->endpoint . $path, false, $context);
        if ($result === false) {
            throw new Fault('the cdn-http fixture server is not reachable (tests/compat/cdn/services.sh up)');
        }

        return $result;
    }
}
