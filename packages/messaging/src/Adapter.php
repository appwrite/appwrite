<?php

namespace Utopia\Messaging;

use Closure;
use Exception;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Pools\Adapter\Swoole as SwoolePoolAdapter;
use Utopia\Pools\Pool as ConnectionPool;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;

abstract class Adapter
{
    /**
     * Upper bound on the connection pool size used by requestMulti().
     */
    private const int MAX_CONCURRENT_REQUESTS = 25;

    /**
     * Name of the connection pool used by requestMulti().
     */
    private const string CONNECTION_POOL_NAME = 'messaging';

    /**
     * Counter tracking sent messages, labelled by result, type and provider.
     */
    private Counter $sendCounter;

    /**
     * @param  Telemetry|null  $telemetry Telemetry adapter to record metrics with; defaults to a no-op adapter.
     * @param  (Closure(): ClientInterface)|null  $clientFactory Factory producing the PSR-18 clients
     *         used for HTTP requests — called once per request() and once per pooled requestMulti()
     *         connection, so it must return a new (or safely shareable) client on each call. Defaults
     *         to utopia-php/client's cURL adapter configured for HTTP/2 with the request()/requestMulti()
     *         timeouts applied. A custom factory owns its own timeout configuration, and its clients
     *         must be able to negotiate HTTP/2 for push adapters — APNs rejects HTTP/1.1 connections,
     *         so a bare `new Client(new CurlAdapter())` will not work; configure the adapter with
     *         `new CurlAdapter(options: [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0])`.
     */
    public function __construct(?Telemetry $telemetry = null, private readonly ?Closure $clientFactory = null)
    {
        $this->sendCounter = ($telemetry ?? new NoTelemetry())->createCounter('messaging.send');
    }

    /**
     * Get the name of the adapter.
     */
    abstract public function getName(): string;

    /**
     * Get the type of the adapter.
     */
    abstract public function getType(): string;

    /**
     * Get the type of the message the adapter can send.
     */
    abstract public function getMessageType(): string;

    /**
     * Get the maximum number of messages that can be sent in a single request.
     */
    abstract public function getMaxMessagesPerRequest(): int;

    /**
     * Send a message.
     *
     * @return array{
     *     deliveredTo: int,
     *     type: string,
     *     results: array<array<string, mixed>>
     * } | array<string, array{
     *     deliveredTo: int,
     *     type: string,
     *     results: array<array<string, mixed>>
     * }> GEOSMS adapter returns an array of results keyed by adapter name.
     *
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    public function send(Message $message): array
    {
        if (!is_a($message, $this->getMessageType())) {
            throw new InvalidArgumentException(
                InvalidArgumentException::MESSAGE_TYPE,
                "{$this->getName()} cannot send " . $message::class . ' messages.',
            );
        }
        if (method_exists($message, 'getTo') && \count($message->getTo()) > $this->getMaxMessagesPerRequest()) {
            throw new InvalidArgumentException(
                InvalidArgumentException::TOO_MANY_RECIPIENTS,
                "{$this->getName()} can only send {$this->getMaxMessagesPerRequest()} messages per request.",
            );
        }
        if (!method_exists($this, 'process')) {
            throw new \Exception('Adapter does not implement process method.');
        }

        try {
            $response = $this->process($message);
        } catch (\Throwable $error) {
            $this->recordSend($message, method_exists($message, 'getTo') ? \count($message->getTo()) : 1, 0);
            throw $error;
        }

        $this->recordResponse($message, $response);

        return $response;
    }

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->sendCounter = $telemetry->createCounter('messaging.send');
    }

    private function recordSend(Message $message, int $recipients, int $delivered): void
    {
        if ($delivered > 0) {
            $this->sendCounter->add($delivered, $this->telemetryAttributes($message, [
                'result' => 'success',
            ]));
        }

        $failed = $recipients - $delivered;
        if ($failed > 0) {
            $this->sendCounter->add($failed, $this->telemetryAttributes($message, [
                'result' => 'failure',
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function telemetryAttributes(Message $message, array $attributes = []): array
    {
        if ($message->getOrigin() !== null) {
            $attributes['origin'] = $message->getOrigin();
        }

        return $attributes + [
            'type' => $this->getType(),
            'provider' => strtolower($this->getName()),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function recordResponse(Message $message, array $response): void
    {
        $results = $response['results'] ?? [];
        if (empty($results)) {
            return;
        }

        $delivered = 0;
        $failed = 0;

        foreach ($results as $result) {
            ($result['status'] ?? '') === 'success' ? $delivered++ : $failed++;
        }

        $this->recordSend($message, $delivered + $failed, $delivered);
    }

    /**
     * Send a single HTTP request.
     *
     * @param  string  $method The HTTP method to use.
     * @param  string  $url The URL to send the request to.
     * @param  array<string>  $headers An array of headers to send with the request.
     * @param  array<string, mixed>|null  $body The body of the request.
     * @param  int  $timeout The timeout in seconds.
     * @return array{
     *     url: string,
     *     statusCode: int,
     *     response: array<string, mixed>|string|null,
     *     headers: array<string, string>,
     *     error: string,
     *     errorCode: int
     * }
     *
     * @throws Exception If the request fails.
     */
    protected function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $body = null,
        int $timeout = 30,
        int $connectTimeout = 10,
    ): array {
        $client = $this->clientFactory instanceof \Closure
            ? ($this->clientFactory)()
            : $this->defaultClient($timeout, $connectTimeout);

        $request = $this->buildRequest($method, $url, $headers, $body);

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            return [
                'url' => $url,
                'statusCode' => 0,
                'response' => null,
                'headers' => [],
                'error' => $error->getMessage(),
                'errorCode' => $error->getCode(),
            ];
        }

        return $this->buildResult($response, $url);
    }

    /**
     * Send multiple concurrent HTTP requests using Swoole coroutines over a
     * bounded connection pool.
     *
     * @param  array<string>  $urls
     * @param  array<string>  $headers
     * @param  array<array<string, mixed>>  $bodies
     * @return array<array{
     *     index: int,
     *     url: string,
     *     statusCode: int,
     *     response: array<string, mixed>|string|null,
     *     headers: array<string, string>,
     *     error: string,
     *     errorCode: int
     * }>
     *
     * @throws Exception
     */
    protected function requestMulti(
        string $method,
        array $urls,
        array $headers = [],
        array $bodies = [],
        int $timeout = 30,
        int $connectTimeout = 10,
    ): array {
        if ($urls === []) {
            throw new \Exception('No URLs provided. Must provide at least one URL.');
        }

        $urlCount = \count($urls);
        $bodyCount = \count($bodies);

        if (!($urlCount === $bodyCount || $urlCount === 1 || $bodyCount === 1)) {
            throw new \Exception('URL and body counts must be equal or one must equal 1.');
        }

        if ($bodyCount > 0 && $urlCount > $bodyCount) {
            $bodies = array_pad($bodies, $urlCount, $bodies[0]);
        } elseif ($urlCount < $bodyCount) {
            $urls = array_pad($urls, $bodyCount, $urls[0]);
        }

        $requests = [];
        foreach ($urls as $i => $url) {
            $requests[$i] = $this->buildRequest($method, $url, $headers, $bodies[$i] ?? null);
        }

        $results = [];

        $run = function () use ($requests, $timeout, $connectTimeout, &$results): void {
            $pool = new ConnectionPool(
                adapter: new SwoolePoolAdapter(),
                name: self::CONNECTION_POOL_NAME,
                size: max(1, min(\count($requests), self::MAX_CONCURRENT_REQUESTS)),
                init: $this->clientFactory ?? $this->defaultClient($timeout, $connectTimeout)->withConnectionReuse(...),
                // A slot per request, so acquisition never queues; the request
                // timeouts belong to the client, not to getting hold of one.
                timeout: 0.0,
            );

            $group = new WaitGroup();

            foreach ($requests as $index => $request) {
                $group->add();

                Coroutine::create(function () use ($pool, $request, $index, &$results, $group): void {
                    try {
                        $results[$index] = $pool->use(fn (ClientInterface $client): array => $this->buildResult($client->sendRequest($request), (string) $request->getUri()));
                    } catch (\Throwable $error) {
                        // Throwable rather than the PSR client exception: pool
                        // acquisition and factory failures must also land in
                        // this slot's result — an uncaught throwable in a
                        // coroutine is fatal and would drop the slot entirely.
                        $results[$index] = [
                            'url' => (string) $request->getUri(),
                            'statusCode' => 0,
                            'response' => null,
                            'headers' => [],
                            'error' => $error->getMessage(),
                            'errorCode' => (int) $error->getCode(),
                        ];
                    } finally {
                        $group->done();
                    }
                });
            }

            $group->wait();
        };

        // Fan out directly when already inside a coroutine runtime (e.g.
        // Swoole servers/workers); otherwise bootstrap a scheduler for the
        // duration of the batch.
        if (Coroutine::getCid() > 0) {
            $run();
        } else {
            \Swoole\Coroutine\run($run);
        }

        $responses = [];
        foreach ($results as $index => $result) {
            $responses[] = ['index' => $index] + $result;
        }

        return $responses;
    }

    /**
     * Build the default HTTP client used when none was injected.
     *
     * cURL rather than Swoole's HTTP client: APNs only accepts HTTP/2, which
     * Coroutine\Http\Client cannot negotiate (curl falls back to HTTP/1.1 for
     * servers without it). Swoole's native-curl hook keeps requestMulti()
     * sends concurrent per coroutine.
     */
    private function defaultClient(int $timeout, int $connectTimeout): Client
    {
        return new Client(new CurlAdapter(options: [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]))
            ->withTimeout((float) $timeout)
            ->withConnectTimeout((float) $connectTimeout);
    }

    /**
     * Build a PSR-7 request, encoding the body based on the request headers:
     * JSON, form-urlencoded, or multipart/form-data (mirroring curl's
     * handling of array CURLOPT_POSTFIELDS) in that order of precedence.
     *
     * @param  array<string>  $headers Headers as "Name: value" strings.
     * @param  array<string, mixed>|null  $body
     */
    private function buildRequest(string $method, string $url, array $headers, ?array $body): RequestInterface
    {
        $factory = new RequestFactory();

        $headerMap = [];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (\count($parts) === 2) {
                $headerMap[trim($parts[0])] = trim($parts[1]);
            }
        }

        // On the request rather than the client so injected PSR-18 clients
        // send the same identity.
        if (!array_any(array_keys($headerMap), fn (string $name): bool => strtolower($name) === 'user-agent')) {
            $headerMap['User-Agent'] = "Appwrite {$this->getName()} Message Sender";
        }

        if ($body === null) {
            return $factory->query($method, $url, [], $headerMap);
        }

        foreach ($headers as $header) {
            if (str_contains($header, 'application/json')) {
                return $factory->json($method, $url, $body, $headerMap);
            }
            if (str_contains($header, 'application/x-www-form-urlencoded')) {
                return $factory->form($method, $url, $body, $headerMap);
            }
        }

        // Drop any bare multipart Content-Type so the factory can set one
        // carrying the boundary.
        foreach (array_keys($headerMap) as $name) {
            if (strtolower($name) === 'content-type') {
                unset($headerMap[$name]);
            }
        }

        return $factory->multipart($method, $url, $body, $headerMap);
    }

    /**
     * Map a PSR-7 response to the array shape adapters consume.
     *
     * @return array{
     *     url: string,
     *     statusCode: int,
     *     response: array<string, mixed>|string|null,
     *     headers: array<string, string>,
     *     error: string,
     *     errorCode: int
     * }
     */
    private function buildResult(ResponseInterface $response, string $url): array
    {
        $body = (string) $response->getBody();

        try {
            $body = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Ignore
        }

        $headers = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        return [
            'url' => $url,
            'statusCode' => $response->getStatusCode(),
            'response' => $body,
            'headers' => $headers,
            'error' => '',
            'errorCode' => 0,
        ];
    }
}
